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
    function redcap_add_edit_records_page($project_id, $instrument, $event_id){
        echo 'Record Limiter';

        $sql = 'SELECT project_id, status, record_count
                FROM redcap_projects 
                    left join redcap_record_counts using (project_id) 
                where project_id = ?';

        $result = $this->query($sql, [$project_id]);
        $result = $result->fetch_assoc();

        $this->initializeJavascriptModuleObject();
        if($result['status'] == 0 && $result['record_count'] > 1){
            echo '-- Limiting Record --';
            $this->tt_addToJavascriptModuleObject("is_record_limited",True);
        } else{
            $this->tt_addToJavascriptModuleObject("is_record_limited",False);
        }
        ?>
        
        <script type="text/javascript">
            window.addEventListener('load', function(){
                let module = <?=$this->getJavascriptModuleObjectName()?>;
                let is_record_limited = module.tt('is_record_limited');
                let add_new_button = document.getElementsByClassName("btn btn-xs btn-rcgreen fs13")[0];
                add_new_button.disabled = is_record_limited;
            });
        </script>

        <?php
    }
}