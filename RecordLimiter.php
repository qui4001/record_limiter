<?php
namespace WeillCornellMedicine\RecordLimiter;

// user_rights = '0' no access
// user_rights = '2' Read Only
// user_rights = '1' Full access

class RecordLimiter extends \ExternalModules\AbstractExternalModule
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
        $record_limit = $this->getProjectSettings($project_id)['project_record_limit'];
        if($record_limit == null){
            $record_limit = $this->getSystemSettings()['system_record_limit'];
            if($record_limit == null)
                $record_limit = 10;
            else
                $record_limit = (int)$record_limit['system_value']; // what is the diff between system_value and value
        } else
            $record_limit = (int)$record_limit;
        
        $user_info = $this->getUser(); 

        if(!$user_info->isSuperUser()){
            $current_user = $user_info->getUsername();
            $project_query = 'SELECT project_id, status, record_count
                                FROM redcap_projects 
                                    left join redcap_record_counts using (project_id) 
                                where project_id = ?';
            $project_result = $this->query($project_query, [$project_id])->fetch_assoc();

            $project_users_query = $this->query("select username, project_id, record_create, user_rights 
                                                from redcap_user_rights 
                                                where project_id = ? and 
                                                    username not in (select username from redcap_user_information where super_user = 1)", [$project_id]);
            if($project_result['status'] == 0 && $project_result['record_count'] >= $record_limit){
                // revoke
                while($row = $project_users_query->fetch_assoc()){
                    // Apply limit 1 > 0
                    if ($row['record_create'] == 1)
                        $this->run('record_create', 0, $row['username'], $row['project_id']);        

                    // 1 Full Access > 0 No Access 
                    if ($row['user_rights'] == 1)
                        $this->run('user_rights', 0, $row['username'], $row['project_id']);
                }
                echo '<div class="red"><b>Record Limiter</b> is revoking right to <u>create record</u> (max allowed '.$record_limit.') and edit <u>user right</u> for all users in this dev project. To restore them either delete records or move to production.</div>';      
            } else {
                // restore
                while($row = $project_users_query->fetch_assoc()){
                    // Remove limit 0 > 1
                    if ($row['record_create'] == 0)
                        $this->run('record_create', 1, $row['username'], $row['project_id']);

                    // 0 No Access > 1 Full Access
                    if ($row['user_rights'] == 0)
                        $this->run('user_rights', 1, $row['username'], $row['project_id']);  
                }
            }
        }
    }
}