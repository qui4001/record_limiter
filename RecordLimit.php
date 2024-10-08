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
        $current_user = $user_info->getUsername();

        // ignore script if current user is a superuser
        // before we make changes, we look through redcap_user_rights.user if made any change to redcap_user_rights.page = 'UserRights/edit_user.php' where
        // pk='test4' or data_value=user = 'test4'
        // we extract the record_create value from sql_log
        // we don't make any change if this last change was made by an superuser redcap_user_information.admin_rights
        // or we do

        $project_query = 'SELECT project_id, status, record_count
                FROM redcap_projects 
                    left join redcap_record_counts using (project_id) 
                where project_id = ?';
        $project_result = $this->query($project_query, [$project_id])->fetch_assoc();

        $create_record_result = $this->query("select record_create from redcap_user_rights where username = ? and project_id = ?", [$current_user, $project_id])->fetch_assoc();
        if($project_result['status'] == 0 && $project_result['record_count'] > 1){
            // Apply limit
            if ($create_record_result['record_create'] == 1){
                echo " - Limiting";
                $enable_limit = $this->query(
                    "update redcap_user_rights set record_create = ? WHERE username = ? and project_id = ?",
                    [0, $current_user, $project_id]
                );
    
                $temp_query = "update redcap_user_rights set record_create = 0 WHERE username = '$current_user' and project_id = $project_id";
                \REDCap::logEvent("Updated User rights " . $current_user, "user = '" . $current_user. "'", $temp_query, NULL, NULL, $project_id);
            } else {
                // Limit was applied already
                echo " - Limiting already";
            }
        } else {
            // Remove limit
            if ($create_record_result['record_create'] == 0){
                echo " - Removing limit";
                $disable_limit = $this->query(
                    "update redcap_user_rights set record_create = ? WHERE username = ? and project_id = ?",
                    [1, $current_user, $project_id]
                );
    
                $temp_query = "update redcap_user_rights set record_create = 1 WHERE username = '$current_user' and project_id = $project_id";
                \REDCap::logEvent("Updated User rights " . $current_user, "user = '" . $current_user. "'", $temp_query, NULL, NULL, $project_id);
            } else {
                // Limit was removed already
                echo " - Removed limit already";
            }
        }
    }
}