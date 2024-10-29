<?php
namespace WeillCornellMedicine\RecordLimiter;

// Look into other flags such as data import tool
// check how to handle string input for record limit text field (error email perhaps?)
// add record_limiter prefix to prevent global config variable collision
// what is the diff between system_value and value
// blocked user might not have the right to delete record; give the user right to delete when RecordLimiter is active
// draft project
// ues module log to find if module has acted on a project to many times
// User rights are checked at the beginning, so even if we toggle user right, it won't take effect unitl next upload attempt
// simplest solution is to read log and send emails if cross threshold, or if a dev project uploads more tha 2 standard deviation than average
// Setting the limit to 0 will show a diff message saying Admin has locked record addition
// Rollback the last transaction that caused the data to cross limit
// Hijack the upload API and data import tool, and have them use those tools provided by EM
// Use redcap_module_project_save_after to set project level record limit to null so system level record can kick in
// disable export once limit is hit
// error_log("This is a log message.");
// update redcap_external_module_settings table in redcap_module_project_save_after hook

class RecordLimiter extends \ExternalModules\AbstractExternalModule
{
    function updateUserRight($field_name, $field_value, $user_name, $project_id){
        $local_query = $this->query(
            "update redcap_user_rights set $field_name = ? WHERE username = ? and project_id = ?",
            [$field_value, $user_name, $project_id]
        );

        if($local_query){
            $temp_query = "update redcap_user_rights set $field_name = $field_value WHERE username = '$user_name' and project_id = $project_id";
            \REDCap::logEvent("Updated User rights " . $user_name, "user = '" . $user_name. "'", $temp_query, NULL, NULL, $project_id);    
        } else {
            // queryLogs
            $this->log('Failed to SET ' . $field_name. '=' .$field_value.' for ' .$user_name. ' in ' .$project_id);
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
            
            $project_query = $this->query("SELECT project_id, status, record_count
                                            FROM redcap_projects 
                                                left join redcap_record_counts using (project_id) 
                                            where project_id = ?", [$project_id]);
            $project_result = $project_query->fetch_assoc();

            $project_users_query = $this->query("SELECT username, project_id, record_create, user_rights 
                                                from redcap_user_rights 
                                                where project_id = ? and 
                                                    username not in (select username from redcap_user_information where super_user = 1)", [$project_id]);
            $project = $this->getProject();

            if($project_result['status'] == 0 && $project_result['record_count'] >= $record_limit){
                // revoke
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
                }
                echo '<div class="red">You have reached maximum number of records allowed in development status; (max allowed '.$record_limit.'). Please either move project to production or delete records to continue testing.</div>'; 
            } else {
                // restore
                while($row = $project_users_query->fetch_assoc()){
                    // Remove limit 0 > 1
                    if($project->getRights($row['username'])['record_create'] == 0)
                        $project->setRights($row['username'], ['record_create' => 1]);

                    // 0 No Access > 1 Full Access
                    $em_dir = $this->getModuleDirectoryName();
                    $em_prefix = substr($em_dir, 0, strrpos($em_dir, '_'));
                    $em_id_query = $this->query("select external_module_id from redcap_external_modules where directory_prefix = ?", [$em_prefix]);
                    $em_id = $em_id_query->fetch_assoc()['external_module_id'];

                    $em_user_rights_query = $this->query(
                        "select *
                        from redcap_external_modules_log
                        where 
                            external_module_id = ? and 
                            project_id = ? and 
                            message = 'user_rights' and
                            ui_id not in (select ui_id from redcap_user_information where super_user = 1)",
                        [$em_id, $project_id]
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
            }
        }
    }
}