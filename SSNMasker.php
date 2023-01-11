<?php

namespace Stanford\SSNMasker;

use ExternalModules\ExternalModules;
use REDCap;

class SSNMasker extends \ExternalModules\AbstractExternalModule {


    /******************************************************************************************************************/
    /* HOOK METHODS                                                                                                   */
    /******************************************************************************************************************/
    public function redcap_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance) {
        $target_form = $this->getProjectSetting('ssn-form');

        //$this->emDebug($target_form . " vs " . $instrument);

        if ($instrument == $target_form) {
            //if the ssn field has been entered, then populate the ssn field on the admin data form.
            $ssn_field = $this->getProjectSetting('ssn-field');
            $ssn_url_field = $this->getProjectSetting('ssn-url-field');

            //if either are empty, bail
            if (empty($ssn_field) || empty($ssn_url_field)) {
                $this->emError("SSN field and SSN URL field not set.  Unable to set ssn url for this project ($project_id).");
                $this->exitAfterHook();
            }

            $results = $this->getFieldValues($record, $event_id, array($ssn_field, $ssn_url_field));
            $ssn_value = current($results)[$ssn_field];
            //$this->emDebug("retrieving for record $record: ", $ssn_field . ": " . $ssn_value, $ssn_url_field, $results);

            if (!empty($ssn_value)) {
                //if ssn has been set, then construct the ssn_url and save it to the admin data form
                //$api_url = $this->getUrl('src/Viewer.php',true,true);
                $api_url = $this->getUrl('src/Viewer.php',true,false);
                $ssn_url = $api_url."&record=".$record;

                $data = array(
                    REDCap::getRecordIdField() => $record,
                    'redcap_event_name'        => REDCap::getEventNames(true,false, $event_id),
                    $ssn_url_field             => $ssn_url
                );

                $response = REDCap::saveData('json', json_encode(array($data)));

                if (!empty($response['errors'])) {
                    $msg = "Error saving SSN URL field to admin_data form";
                    $this->emError($msg, $response['errors'], $data);

                    REDCap::logEvent(
                        $msg,
                        "Unable to set the SSN Url field for this record: ".$response['errors'],
                        NULL,
                        $record
                    );

                }

            }

        }
    }

    /******************************************************************************************************************/
    /* CLASS METHODS                                                                                                   */
    /******************************************************************************************************************/
    function getFieldValues($record, $event_id, $target_fields) {
        $params = array(
            'return_format' => 'json',
            'records' => $record,
            'fields' => $target_fields,
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);

        //$this->emDebug($results);
        return $results;
    }

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

    /**
     *
     *
     * @param $project_id
     * @param $start_date_field
     * @param $ssn_field
     * @return false|mixed
     *
     */
    function findRecordsToExpireSSN($project_id, $start_date_field, $ssn_field, $test_date=null) {
        /* SQL Query
           select rd1.record, rd1.project_id, rd1.value, rd2.value
from redcap_data AS rd1
JOIN redcap_data AS rd2
ON rd1.project_id=rd2.project_id and rd1.event_id=rd2.event_id and rd1.record=rd2.record
where rd1.project_id = 17094 -- and rd1.event_id=105818
and rd1.field_name="hd_faculty_startdate" and DATE(rd1.value) < CURDATE()
and rd2.field_name = "faculty_ssn" and rd2.value != "WIPED";
    */
       if ($test_date) {
           $threshold = " and rd1.field_name='%s' and DATE(rd1.value) < '$test_date';";
       } else {
           $threshold = " and rd1.field_name='%s' and DATE(rd1.value) < CURDATE();";
       }
        $sql = sprintf(
            "select rd1.record from redcap_data AS rd1 JOIN redcap_data AS rd2
 ON rd1.project_id=rd2.project_id and rd1.event_id=rd2.event_id and rd1.record=rd2.record
 where rd1.project_id = %d
 and rd2.field_name = '%s' and rd2.value != 'WIPED'" . $threshold,
            db_real_escape_string($project_id),
            db_real_escape_string($ssn_field),
            db_real_escape_string($start_date_field)
        );

        $q = db_query($sql);
        while ($row = db_fetch_assoc($q)) {
            $expire_list[] = $row['record'];
        }

            //return db_result($q,0);
        return $expire_list;
    }

    function hasGroupWiped($project_id, $record, $group) {
        $sql = "SELECT count(*) FROM redcap_log_event WHERE 
          -- page = 'PLUGIN' and 
          project_id = $project_id 
          and pk = '" . db_real_escape_string($record) . "' 
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
            $errors[] = $this->updateDataValues('data_values', $ssn_field, $ssn, $sunet_id, $project_id,$record);

            $errors[] = "The SSN for record $record has been wiped, including all log history.";
        }
        return array_filter($errors);
    }

    function updateDataValues($column_name, $ssn_field, $ssn, $sunet_id, $project_id,$record) {
        $sql = "
          UPDATE redcap_log_event
            SET data_values = REPLACE($column_name, '" . $ssn_field . " = \'" . db_real_escape_string($ssn) . "\'', '" . $ssn_field . " = \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%" . $ssn_field . " = \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error,"ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: ". $error;
        }

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

    function updateLogSqlOld($column_name, $ssn_field, $ssn, $sunet_id, $project_id,$record) {
        switch ($column_name) {
            case 'sql_log':
                $target_string =   "'\'".  $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\''";
                $replace_string =  "'\'" . $ssn_field . "\', \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\''";
                break;
            case 'data_values':
                $target_string =  $ssn_field . " = \'" . db_real_escape_string($ssn);
                $replace_string = $ssn_field . " = \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\''";
                break;
        }

        $sql = "
          UPDATE redcap_log_event
            SET $column_name = REPLACE($column_name, ". $target_string . " , ".$replace_string . ")
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%" . $target_string . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error,"ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: ". $error;
        }

    }


    /******************************************************************************************************************/
    /* CRON METHODS                                                                                                   */
    /******************************************************************************************************************/

    /**
     * @return void
     * This cron function is called daily. The expected start date will be checked and if past, the SSN will be deleted
     * using the existing cleanup methods.
     *
     */
    public function cronSSNMaskExpiry() {
        //find all the projects that are using the SSN Mask EM
        $enabled = ExternalModules::getEnabledModules($this->PREFIX);

        while($row = $enabled->fetch_assoc()){

            $proj_id = $row['project_id'];

            // Create the API URL to start the process
            $ssnExpiryCheckURL = $this->getUrl('src/ssnExpiryCheck.php?pid=' . $proj_id, true, true);


        }
    }


    /******************************************************************************************************************/
    /* EMLOGGER METHODS                                                                                                   */
    /******************************************************************************************************************/
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