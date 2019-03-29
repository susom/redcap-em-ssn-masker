<?php

namespace Stanford\SSNMasker;

use ExternalModules\ExternalModules;
use REDCap;

class SSNMasker extends \ExternalModules\AbstractExternalModule {


    function checkIfAuthorizedUser($sunet_id,  $approved_users, $approved_users_2) {

        $group = $other_group = null;

        if (in_array($sunet_id, $approved_users)) {
            $group = "1";
            $other_group = "2";
        } else if (in_array($sunet_id, $approved_users_2)) {
            $group = "2";
            $other_group = "1";
        } else {

            $this->emError("Does not have SSN access: logged in as ".$sunet_id );
            die ("You do not have access to this feature.  Please contact the project administrators");
        }

        $this->emDebug($approved_users,$approved_users_2,$group, $other_group);
        return array($group, $other_group);

    }


    function hasGroupWiped($record, $group) {
        $sql = "SELECT count(*) FROM redcap_log_event WHERE 
          -- page = 'PLUGIN' and 
          pk = '" . db_real_escape_string($record) . "' 
          and description = 'SSN Wipe Approved For Group " . intval($group) ."'";
        $q = db_query($sql);
        return db_result($q,0);
    }


    function emLog()
    {
        global $module;
        $emLogger = ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($module->PREFIX, func_get_args(), "INFO");
    }

    function emDebug()
    {
        // Check if debug enabled
        if ($this->getSystemSetting('enable-system-debug-logging') || (!empty($_GET['pid']) && $this->getProjectSetting('enable-project-debug-logging'))) {
            $emLogger = ExternalModules::getModuleInstance('em_logger');
            $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
        }
    }

    function emError()
    {
        $emLogger = ExternalModules::getModuleInstance('em_logger');
        $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }

}