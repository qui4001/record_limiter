<?php
namespace WeillCornellMedicine\RecordLimit;

// Have Note saying; you have 10+ records in red
// do we need per project record limiation?
// there are other ways to add record (Record Status Dashboard, Export update)
// We could request a hook for record status dashboard, but how much ground we are not covering.
// https://redcap.ctsc.weill.cornell.edu/redcap_protocols/redcap_v14.0.43/Plugins/index.php?HooksMethod=redcap_add_edit_records_page

// find the last update for a given user

class RecordLimit extends \ExternalModules\AbstractExternalModule
{
    function run($field_name, $field_value, $user_name, $project_id){
        $enable_limit = $this->query(
            "update redcap_user_rights set $field_name = ? WHERE username = ? and project_id = ?",
            [$field_value, $user_name, $project_id]
        );

        $temp_query = "update redcap_user_rights set $field_name = $field_value WHERE username = '$user_name' and project_id = $project_id";
        \REDCap::logEvent("Updated User rights " . $user_name, "user = '" . $user_name. "'", $temp_query, NULL, NULL, $project_id);
    }

    function redcap_every_page_top($project_id){
        
        echo 'Record Limiter';
        // redcap_log_events10 user column tells us who made the last change
        // redcap_user_information tells us if an user is superuser
        // set record_create = '1' to redcap_user_rights to disable user rights
        // Remove rights 
        // user_rights = '0' no access
        // user_rights = '2' Read Only
        // user_rights = '1' Full access
        $user_info = $this->getUser();

        // ignore script if current user is a superuser
        // before we make changes, we look through redcap_user_rights.user if made any change to redcap_user_rights.page = 'UserRights/edit_user.php' where
        // pk='test4' or data_value=user = 'test4'
        // we extract the record_create value from sql_log
        // we don't make any change if this last change was made by an superuser redcap_user_information.admin_rights
        // or we do
        if(!$user_info->isSuperUser()){
            $current_user = $user_info->getUsername();
            $project_query = 'SELECT project_id, status, record_count
                FROM redcap_projects 
                    left join redcap_record_counts using (project_id) 
                where project_id = ?';
            $project_result = $this->query($project_query, [$project_id])->fetch_assoc();

            $all_rights_query = $this->query("select record_create, user_rights from redcap_user_rights where username = ? and project_id = ?", [$current_user, $project_id]);
            $all_rights_result = $all_rights_query->fetch_assoc();
            if($project_result['status'] == 0 && $project_result['record_count'] > 1){
                // Apply limit 1 > 0
                if ($all_rights_result['record_create'] == 1)
                    $this->run('record_create', 0, $current_user, $project_id);        
                
                // 1 Full Access > 0 No Access 
                if ($all_rights_result['user_rights'] == 1)
                    $this->run('user_rights', 0, $current_user, $project_id);        

            } else {
                // Remove limit 0 > 1
                if ($all_rights_result['record_create'] == 0)
                    $this->run('record_create', 1, $current_user, $project_id);

                // 0 No Access > 1 Full Access
                if ($all_rights_result['user_rights'] == 0)
                    $this->run('user_rights', 1, $current_user, $project_id);  
            }
        }
    }
}