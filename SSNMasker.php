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

            $this->emError("Does not have SSN access: logged in as ". $sunet_id );
            die ("You do not have access to this feature.  Please contact the project administrators");
        }

        //$this->emDebug($approved_users,$approved_users_2,$group, $other_group);
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

    function wipeSSN($project_id, $record, $ssn_field, $ssn, $sunet_id) {


        $data = array(
            'request_id'                => $record,
            $ssn_field                  => "WIPED"
        );

        $q = REDCap::saveData('json',json_encode(array($data)));
        if (!empty($q['errors'])) {
            $this->emError($q, "Error wiping SSN");
            $errors[] = "An error occurred when clearing the SSN value.  
            Please notify <a href='mailto:redcap-help@list.stanford.edu'>redcap-help@list.stanford.edu</a>
            with the current timestamp and record number that you were working on.
            The SSN may not have been securely cleared from the database.";
        } else {
            // Flush logs
            $errors[] = $this->updateLogSql('sql_log', $ssn_field, $ssn, $sunet_id, $project_id, $record);
            $errors[] = $this->updateLogSql('data_values', $ssn_field, $ssn, $sunet_id, $project_id,$record);

            $errors[] = "The SSN for record $record has been wiped, including all log history.";
        }
        return array_filter($errors);
    }

    function updateLogSql($column_name, $ssn_field, $ssn, $sunet_id, $project_id,$record) {
        $sql = "
          UPDATE redcap_log_event
            SET sql_log = REPLACE($column_name, '\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'', '\'" . $ssn_field . "\', \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error,"ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: ". $error;
        }

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