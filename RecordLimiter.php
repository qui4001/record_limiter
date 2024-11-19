<?php
namespace WeillCornellMedicine\RecordLimiter;

// Default goes to redcap admin? is it possible to exceptions to new emails?
// Do I need to EM log deletion of porject level when copying project?
// * admin email is in redcap_config table (send emial to study team?)
// Add limit info on Add record page yellow banner
// set system level value for email cadence and bulk upload threshold
// * disable data and api export(redcap_module_api_before) once limit is hit
// check how to handle string input for record limit text field
// add record_limiter prefix to config.json to prevent global config variable collision
// what is the diff between system_value and value

// No needed because we are disabling api import/export using the same hook
// use hook redcap_module_api_before to send out email if project has records more than limit

class RecordLimiter extends \ExternalModules\AbstractExternalModule
{
    private $em_id;

    public function __construct(){
        parent::__construct();

        $em_dir = $this->getModuleDirectoryName();
        $em_prefix = substr($em_dir, 0, strrpos($em_dir, '_'));
        $em_id_query = $this->query("select external_module_id from redcap_external_modules where directory_prefix = ?", [$em_prefix]);
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
            $current_user = $user_info->getUsername();
            
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

            if($project_result['status'] == 0 && $project_result['record_count'] >= $record_limit){
                // revoke
                $pre_inst_export = array();

                while($row = $project_users_query->fetch_assoc()){
                    // Apply limit 1 > 0
                    if($project->getRights($row['username'])['record_create'] == 1)
                        $project->setRights($row['username'], ['record_create' => 0]);

                    // 1 Full Access > 0 No Access 
                    if($project->getRights($row['username'])['user_rights'] == 1){
                        $project->setRights($row['username'], ['user_rights' => 0]);
                        $this->log('user_rights');
                        // we don't take away record_delete when restoring
                        if($project->getRights($row['username'])['record_delete'] == 0){
                            $project->setRights($row['username'], ['record_delete' => 1]);
                        }
                    }

                    $pre_inst_export_pairs = explode('][', $row['data_export_instruments']);
                    $revoked_inst_export = '';
                    $temp = array();
                    foreach($pre_inst_export_pairs as $pair){
                        $p = str_replace(['[',']'], '', $pair);
                        $kv = explode(',', $p);
                        $temp += [$kv[0] => (int)$kv[1]];
                        $revoked_inst_export .= '['. $kv[0] . ',' . 0 .']';
                    }
                    $pre_inst_export += [$row['username'] => $temp];

                    $this->query("UPDATE redcap_user_rights 
                                set data_export_instruments = ? 
                                where username = ? and project_id = ?", [$revoked_inst_export, $row['username'], $project_id]);
                    
                }
                $this->log(json_encode($pre_inst_export));
                echo '<div class="red">You have reached maximum number of records allowed in development status; (max allowed '.$record_limit.'). Please either move project to production or delete records to continue testing.</div>'; 
            } else {
                // restore
                while($row = $project_users_query->fetch_assoc()){
                    // Remove limit 0 > 1
                    if($project->getRights($row['username'])['record_create'] == 0)
                        $project->setRights($row['username'], ['record_create' => 1]);

                    // 0 No Access > 1 Full Access
                    $em_user_rights_query = $this->query(
                        "select *
                        from redcap_external_modules_log
                        where 
                            external_module_id = ? and 
                            project_id = ? and 
                            message = 'user_rights' and
                            ui_id not in (select ui_id from redcap_user_information where super_user = 1)",
                        [$this->em_id, $project_id]
                    );
                    
                    if($em_user_rights_query->num_rows == 1){
                        $project->setRights($row['username'], ['user_rights' => 1]);

                        $em_log_id = $em_user_rights_query->fetch_assoc()['log_id'];
                        $this->query(
                            "delete
                            from redcap_external_modules_log
                            where log_id = ?",
                            [$em_log_id]
                        );
                    }
                }

                $em_inst_restore = $this->query(
                    "select *
                    from redcap_external_modules_log
                    where 
                        external_module_id = ? and 
                        project_id = ? and 
                        message != 'user_rights' and
                        ui_id not in (select ui_id from redcap_user_information where super_user = 1)",
                    [$this->em_id, $project_id]
                );

                if($em_inst_restore->num_rows == 1){
                    $instruments = \REDCap::getInstrumentNames();
                    $result = $em_inst_restore->fetch_assoc();
                    $em_inst_restore_msg = json_decode($result['message'], True);
                    foreach($em_inst_restore_msg as $user => $all_inst){
                        $temp = '';
                        foreach($all_inst as $inst_name => $inst_val){
                            if(array_key_exists($inst_name, $instruments))
                                $temp .= '['.$inst_name.','. $inst_val .']';
                            else
                                $temp .= '['.$inst_name.','. 1 .']'; // if a new instrument was added; we give them right to export data
                        }
                        
                        $this->query("UPDATE redcap_user_rights
                            set data_export_instruments = ? 
                            where username = ? and project_id = ?", [$temp, $user, $project_id]);
                    }
                    
                    $em_log_id = $result['log_id'];
                    $this->query(
                        "delete
                        from redcap_external_modules_log
                        where log_id = ?",
                        [$em_log_id]
                    );
                }

            } # revoke
        } # Superuser
    } # redcap_every_page_top
} # class RecordLimiter