<?php
namespace WeillCornellMedicine\RecordLimiter;

// xdebug trace; on dev
// superuser needs a header saying instrument info should not change
//  check if superuser has modified instrument information to ensure restoration is valid
//  force restore as a option? (Could make REDCap unstable)
// Do I need to EM log deletion of porject level when copying project?
// Add limit info on Add record page yellow banner
// check how to handle string input for record limit text field
// add record_limiter prefix to config.json to prevent global config variable collision
// what is the diff between system_value and value
// Relationship between api import/export and api delete
// delete log during RL global deletion(during unloading from control panel)
// delete log when project limit is increased

// if superuser grants right(i.e. record create) to reg user during RL activation; 
// that user won'd be able to utilize that right, because RL will kick in with page referesh; 
// to solve it superuser needs to increaseproject level limit

// add superuser banner listing user-rights and project properties RL is handling

class RecordLimiter extends \ExternalModules\AbstractExternalModule
{
    private $em_id;

    public function __construct(){
        parent::__construct();

        $em_dir = $this->getModuleDirectoryName(); // record_limiter_v1.0.0
        $em_prefix = substr($em_dir, 0, strrpos($em_dir, '_')); // record_limiter
        $em_id_query = $this->query("select external_module_id from redcap_external_modules where directory_prefix = ?", [$em_prefix]); // $this->PREFIX
        $this->em_id = $em_id_query->fetch_assoc()['external_module_id'];
    }

    function redcap_module_project_save_after($project_id, $msg_flag, $project_title, $user_id){
        // unknown interaction with record_cache_complete flag
        if($msg_flag == 'copiedproject'){            
            $this->query("
                delete
                from redcap_external_module_settings 
                where 
                    project_id = ? and 
                    `key` = 'project_record_limit' and
                    external_module_id = ?", [$project_id, $this->em_id]
            );
        }
    }

    function redcap_every_page_top($project_id){
        $record_limit = $this->getProjectSettings($project_id)['project_record_limit'];
        if($record_limit == null){
            $record_limit = $this->getSystemSettings()['system_record_limit'];
            if($record_limit == null)
                $record_limit = 10;
            else
                $record_limit = (int)$record_limit['system_value'];
        } else
            $record_limit = (int)$record_limit;
        
        $user_info = $this->getUser();

        if(!$user_info->isSuperUser()){
            // find record count of this project
            $project_query = $this->query("SELECT project_id, status, record_count
                                            FROM redcap_projects 
                                                left join redcap_record_counts using (project_id) 
                                            where project_id = ?", [$project_id]);
            $project_result = $project_query->fetch_assoc();

            // find users who will have their rights revoked
            $project_users_query = $this->query("SELECT username, project_id, record_create, user_rights, data_export_instruments, api_export
                                                from redcap_user_rights 
                                                where project_id = ? and 
                                                    username not in (select username from redcap_user_information where super_user = 1)", [$project_id]);
            $project = $this->getProject();

            $already_active = $this->query("SELECT count(*) count from redcap_external_modules_log where project_id = ?", [$project_id]);
            $already_active = $already_active->fetch_assoc()['count'];

            if($project_result['status'] == 0 && $project_result['record_count'] >= $record_limit){
                // revoke

                while($row = $project_users_query->fetch_assoc()){
                    // {
                    //     "username": "test2",
                    //     "rights": {
                    //       "record_create": 1,
                    //       "user_rights": 1,
                    //       "data_export_instruments": "[form_1,1][inst_2,3]"
                    //     }
                    // }

                    $bkup = array();
                    $bkup += ['username' => $row['username']];
                    $rights = array();
                    $bkup += array('rights' => &$rights);

                    // record_create 1 > 0
                    if($project->getRights($row['username'])['record_create'] == 1){
                        $project->setRights($row['username'], ['record_create' => 0]);
                        $rights += ['record_create' => (int)1];
                    }

                    // design 1 > 0
                    if($project->getRights($row['username'])['design'] == 1){
                        $project->setRights($row['username'], ['design' => 0]);
                        $rights += ['design' => (int)1];
                    }

                    // api_export 1 > 0
                    if($project->getRights($row['username'])['api_export'] == 1){
                        $project->setRights($row['username'], ['api_export' => 0]);
                        $rights += ['api_export' => (int)1];
                    }

                    // api_import 1 > 0
                    if($project->getRights($row['username'])['api_import'] == 1){
                        $project->setRights($row['username'], ['api_import' => 0]);
                        $rights += ['api_import' => (int)1];
                    }

                    // 1 Full Access > 0 No Access 
                    if($project->getRights($row['username'])['user_rights'] == 1){
                        $project->setRights($row['username'], ['user_rights' => 0]);
                        $rights += ['user_rights' => (int)1];
                        if($project->getRights($row['username'])['record_delete'] == 0)
                            $project->setRights($row['username'], ['record_delete' => 1]);                        
                    }

                    // data_import_tool 1 > 0
                    if($project->getRights($row['username'])['data_import_tool'] == 1){
                        $project->setRights($row['username'], ['data_import_tool' => 0]);
                        $rights += ['data_import_tool' => (int)1];
                    }

                    $rights += ['data_export_instruments' => $row['data_export_instruments']];
                    $pre_inst_export_pairs = explode('][', $row['data_export_instruments']);
                    $revoked_inst_export = '';
                    $temp = array();
                    foreach($pre_inst_export_pairs as $pair){
                        $p = str_replace(['[',']'], '', $pair);
                        $kv = explode(',', $p);
                        $temp += [$kv[0] => (int)$kv[1]];
                        $revoked_inst_export .= '['. $kv[0] . ',' . 0 .']';
                    }

                    if($already_active == 0){
                        $this->query("UPDATE redcap_user_rights 
                                    set data_export_instruments = ? 
                                    where username = ? and project_id = ?", 
                                    [$revoked_inst_export, $row['username'], $project_id]);

                        $this->log(json_encode($bkup), ['project_id' => $project_id]);
                    }
                }
                echo '<div class="red">
                You have reached maximum number of records allowed in development status; (max allowed '.$record_limit.'). </br> 
                Please either move project to production or delete records to continue testing. </br>
                If record deletection right is not available, reach out to the project administrator.
                </div>'; 
                return "revoked";
            } else {
                // restore

                $old_rights = $this->query(
                    "select log_id, message
                    from redcap_external_modules_log
                    where 
                        external_module_id = ? and 
                        project_id = ?",
                    [$this->em_id, $project_id]
                );

                while($row = $old_rights->fetch_assoc()){
                    $message = json_decode($row['message'], True);

                    $username = $message['username'];
                    foreach($message['rights'] as $right_name => $old_value){
                        $this->query("UPDATE redcap_user_rights set $right_name = ? where username = ? and project_id = ?",[$old_value, $username, $project_id]);

                        $this->query(
                            "delete
                            from redcap_external_modules_log
                            where log_id = ?",
                            [$row['log_id']]
                        );

                    }
                }
                return "restored";
            } # revoke
        } # Superuser
    } # redcap_every_page_top

    // API Export to download existing record
    // API Import/Update needed for add new record or update existing recor to the project
    function redcap_module_api_before($project_id, $post){
        if($this->redcap_every_page_top($project_id) == "revoked" && $post['content'] == 'record' && in_array($post['action'], ['export', 'import']))
            return "RecordLimiter disabled API import/export.";
    }
} # class RecordLimiter