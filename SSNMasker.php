<?php

namespace Stanford\SSNMasker;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

require_once "emLoggerTrait.php";

class SSNMasker extends AbstractExternalModule
{

    use emLoggerTrait;

    /******************************************************************************************************************/
    /* HOOK METHODS                                                                                                   */
    /******************************************************************************************************************/
    public function redcap_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance)
    {

        $target_form = $this->getProjectSetting('ssn-form');
        //$this->emDebug($target_form . " vs " . $instrument);

        if ($instrument == $target_form) {
            //if the ssn field has been entered, then populate the ssn field on the admin data form.
            $ssn_field = $this->getProjectSetting('ssn-field');
            $ssn_url_field = $this->getProjectSetting('ssn-url-field');

            //if either are empty, bail
            if (empty($ssn_field) || empty($ssn_url_field)) {
                $this->emError("SSN field and SSN URL field not set.  Unable to set ssn url for this project ($project_id).");
                return;
            }

            $results = $this->getFieldValues($record, $event_id, array($ssn_field, $ssn_url_field));
            $ssn_value = current($results)[$ssn_field];
            //$this->emDebug("retrieving for record $record: ", $ssn_field . ": " . $ssn_value, $ssn_url_field, $results);

            if (!empty($ssn_value)) {
                //if ssn has been set, then construct the ssn_url and save it to the admin data form
                //$api_url = $this->getUrl('src/Viewer.php',true,true);
                $api_url = $this->getUrl('src/Viewer.php', true, false);
                $ssn_url = $api_url . "&record=" . $record;

                $data = array(
                    REDCap::getRecordIdField() => $record,
                    'redcap_event_name' => REDCap::getEventNames(true, false, $event_id),
                    $ssn_url_field => $ssn_url
                );

                $response = REDCap::saveData('json', json_encode(array($data)));

                if (!empty($response['errors'])) {
                    $msg = "Error saving SSN URL field to admin_data form";
                    $this->emError($msg, $response['errors'], $data);

                    REDCap::logEvent(
                        $msg,
                        "Unable to set the SSN Url field for this record: " . $response['errors'],
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
    /**
     * @param $record string
     * @param $event_id string
     * @param $target_fields array
     * @return array
     */
    function getFieldValues($record, $event_id, $target_fields)
    {
        $params = array(
            'return_format' => 'json',
            'records' => $record,
            'fields' => $target_fields,
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);
        return $results;
    }


    /**
     * Determine if sunet is member of group 1 or group 2
     * @param $sunet_id string
     * @param $approved_users array
     * @param $approved_users_2 array
     * @return string[]|void
     */
    function checkIfAuthorizedUser($sunet_id, $approved_users, $approved_users_2)
    {

        $group = $other_group = null;

        if (in_array($sunet_id, $approved_users)) {
            $group = "1";
            $other_group = "2";
        } else if (in_array($sunet_id, $approved_users_2)) {
            $group = "2";
            $other_group = "1";
        } else {
            $this->emError("Does not have SSN access: logged in as " . $sunet_id);
            die ("You do not have access to this feature.  Please contact the project administrators");
        }

        //$this->emDebug($approved_users,$approved_users_2,$group, $other_group);
        return array($group, $other_group);

    }

    /**
     * @param $project_id
     * @param $start_date_field
     * @param $ssn_field
     * @return false|mixed
     *
     * Method used in daily cron job. This returns the list of records for which start date has passed
     * and the SSN is still visible.
     */
    function findRecordsToExpireSSN($project_id, $start_date_field, $ssn_field, $test_date = null)
    {
        /* SQL Query
           select rd1.record, rd1.project_id, rd1.value, rd2.value
            from redcap_data AS rd1
            JOIN redcap_data AS rd2
            ON rd1.project_id=rd2.project_id and rd1.event_id=rd2.event_id and rd1.record=rd2.record
            where rd1.project_id = 17094 -- and rd1.event_id=105818
            and rd1.field_name="hd_faculty_startdate" and DATE(rd1.value) < CURDATE()
            and rd2.field_name = "faculty_ssn" and rd2.value != "WIPED";
        */

        //ADDED for TESTING purposes: if a data field was entered, use that date instead of current date
         $query = $this->createQuery();

        $query->add("SELECT rd1.record from redcap_data AS rd1
            JOIN redcap_data AS rd2 
            ON rd1.project_id=rd2.project_id and rd1.event_id=rd2.event_id and rd1.record=rd2.record
            WHERE rd1.project_id = ? and rd2.field_name = ? and rd2.value != 'WIPED'",
                    [$project_id,$ssn_field]);

        //ADDED for TESTING purposes: if a data field was entered, use that date instead of current date
        if ($test_date) {
            $query->add(" and rd1.field_name=? and DATE(rd1.value)<?;",
                        [$start_date_field, $test_date]);
        } else {
            $query->add(" and rd1.field_name=? and DATE(rd1.value) < CURDATE();",
                        [$start_date_field]);
        }

        $this->emDebug($query);

        $result = $query->execute();

        $expire_list = [];
        while($row = $result->fetch_assoc()) {
            $expire_list[] = $row['record'];
        }

        $this->emDebug($expire_list);
        return $expire_list;
    }


    /**
     * Determines if a project_id was wiped
     * @param $project_id
     * @param $record
     * @param $group
     * @return false|mixed
     */
    function hasGroupWiped($project_id, $record, $group)
    {
        $log_event_table = REDCap::getLogEventTable($project_id);
        $sql = "SELECT count(*) FROM $log_event_table WHERE 
          -- page = 'PLUGIN' and 
          project_id = $project_id 
          and pk = '" . db_real_escape_string($record) . "' 
          and description = 'SSN Wipe Approved For Group " . intval($group) . "'";
        $q = db_query($sql);
        return db_result($q, 0);
    }

    function wipeSSN($project_id, $record, $ssn_field, $ssn, $sunet_id)
    {

        $data = array(
            'request_id' => $record,
            $ssn_field => "WIPED"
        );

        $q = REDCap::saveData('json', json_encode(array($data)));
        if (!empty($q['errors'])) {
            $this->emError($q, "Error wiping SSN");
            $errors[] = "An error occurred when clearing the SSN value.  
            Please notify <a href='mailto:redcap-help@list.stanford.edu'>redcap-help@list.stanford.edu</a>
            with the current timestamp and record number that you were working on.
            The SSN may not have been securely cleared from the database.";
        } else {
            // Flush logs
            $errors[] = $this->updateLogSql('sql_log', $ssn_field, $ssn, $sunet_id, $project_id, $record);
            $errors[] = $this->updateDataValues('data_values', $ssn_field, $ssn, $sunet_id, $project_id, $record);
            $errors[] = "The SSN for record $record has been wiped, including all log history.";
        }
        return array_filter($errors);
    }

    function updateDataValues($column_name, $ssn_field, $ssn, $sunet_id, $project_id, $record)
    {
        $log_event_table = REDCap::getLogEventTable($project_id);
        $sql = "
          UPDATE $log_event_table
            SET data_values = REPLACE($column_name, '" . $ssn_field . " = \'" .
            db_real_escape_string($ssn) . "\'', '" . $ssn_field . " = \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%" . $ssn_field . " = \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $this->emDebug("Sql for UpdateDataValues", $sql);
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error, "ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: " . $error;
        }

    }

    /**
     * @param $project_id
     * @param $column_name
     * @param $ssn_field
     * @param $ssn
     * @param $sunet
     * @param $record
     * @return mixed
     *
     * This method attempts to clear the SSN entry from the sql_log field in the redcap_lgo_event table.
     * sql_logs have two types of entries: INSERTS and UPDATES
     * 1. INSERT entries look like this:
     *    INSERT INTO redcap_data (project_id, event_id, record, field_name, value, instance) VALUES (44, 192, '44', 'faculty_ssn', '111111122', NULL)
     * 2. UPDATE entries look like this:
     *    UPDATE redcap_data SET value = '111111123' WHERE project_id = 44 AND record = '45' AND field_name = 'faculty_ssn' AND event_id = 192 AND instance is null
     *
     * The escaped ('\') apostrophe will drive you crazy but we need to escape the apostrophe because that is how entry is entered in the sql_log.
     * For example: the entry looks like this
     *    ... VALUES (44, 192, '44', 'faculty_ssn', '111111122', NULL)"
     * So in order to find and replace the correct ssn, we need to add in the apostrophe, the comma and the space.
     */
    function updateLogSqlBROKEN($project_id, $column_name, $ssn_field, $ssn, $sunet, $record)
    {
        // 1. get the log event table for this project
        $log_event_table = REDCap::getLogEventTable($project_id);

        // 2. HANDLE THE INSERT CASE: setup the sql strings to use the recommended  query method
        /* ORIGINAL SQL
         UPDATE redcap_log_event8
            SET sql_log = REPLACE(sql_log, '\'faculty_ssn\', \'111111123\'', '\'faculty_ssn\', \'---cleared by cronSSNExpiry on 2023-01-12 12:43:42---\'')
          WHERE
            project_id = 44
            AND pk = '45'
            AND sql_log like '%\'faculty_ssn\', \'111111123\'%' LIMIT 100
        */
        $sql_string = "
        UPDATE ?
            SET sql_log = REPLACE(?, '\'?\', \'?\'', '\'---cleared by ? on ". date('Y-m-d H:i:s')." ---\'')
          WHERE 
            project_id = ?
            AND pk = \'?\'
            AND ? like '%\'?\', \'?\'%' LIMIT 100";

        $sql_args = [
            $log_event_table,
            $column_name,
            \PDO::quote($ssn_field),
            $ssn,
            $ssn_field,
            $sunet,
            $project_id,
            $record,
            $column_name,
            $ssn_field,
            $ssn
        ];
        try {
            $result = $this->query($sql_string, $sql_args);
            $this->emDebug("UpdateLogSql", $sql_string, $result);
        } catch (\Exception $e) {
            $this->emError("Exception while running updateLogSql update", $e->getMessage(), $e->getTraceAsString());
            return "Error wiping SSN - ask administrator to review logs: " . $e->getTraceAsString();
        }
    }

    function updateLogSql($column_name, $ssn_field, $ssn, $sunet_id, $project_id, $record)
    {
        $log_event_table = REDCap::getLogEventTable($project_id);
        $sql = "
          UPDATE $log_event_table
            SET sql_log = REPLACE($column_name, '\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'', '\'" . $ssn_field . "\', \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $this->emDebug("UpdateLogSql", $sql);
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error, "ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: " . $error;
        }

    }

    function updateLogSqlOld($column_name, $ssn_field, $ssn, $sunet_id, $project_id, $record)
    {
        switch ($column_name) {
            case 'sql_log':
                $target_string = "'\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\''";
                $replace_string = "'\'" . $ssn_field . "\', \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\''";
                break;
            case 'data_values':
                $target_string = $ssn_field . " = \'" . db_real_escape_string($ssn);
                $replace_string = $ssn_field . " = \'---cleared by " . $sunet_id . " on " . date('Y-m-d H:i:s') . "---\''";
                break;
        }

        $sql = "
          UPDATE redcap_log_event
            SET $column_name = REPLACE($column_name, " . $target_string . " , " . $replace_string . ")
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND " . db_real_escape_string($column_name) . " like '%" . $target_string . "\'%' LIMIT 100";
        //print "01: <pre>" . $sql . "</pre>";
        $q = db_query($sql);

        if ($error = db_error()) {
            $this->emError($error, "ERROR RUNNING SQL ");
            return "Error wiping SSN - ask administrator to review logs: " . $error;
        }

    }


    /**
     * @return void
     *
     * This method does not work in cron as call to REDCap::getRecordIdField needs to be in project context??
     * In the meantime, use the url call.
     */
    private function ssnExpiryCheck() {
        $project_id = $this->getProjectId();
        $this->emDebug("Using project $project_id");

        try {
            // 1. Retrieve all the record_ids where current date is past the 'expiry-date_field
            $ssn_field = $this->getProjectSetting('ssn-field');
            $expire_test_date = $this->getProjectSetting('expiry-test-date');
            $expire_date_field = $this->getProjectSetting('expiry-date-field');

            //if ssn_field is not set, then don't proceed
            if ($ssn_field==null) return;

            $records_to_expire = $this->findRecordsToExpireSSN($project_id, $expire_date_field, $ssn_field, $expire_test_date);

            //reset counters
            $ctr = 0;
            $wiped = array();
            if (!empty($records_to_expire)) {
                $rec_id = \REDCap::getRecordIdField();  //we don't have project context??

            // 2. Get the salient data for each of those records.
                $params = [
                    "project_id" => $project_id,
                    "fields" => array($rec_id, $ssn_field),
                    "records" => $records_to_expire,
                    "return_format" => 'json'
                ];
                $q = json_decode(REDCap::getData($params), true);


            // 3. Call wipeSSN($project_id, $record, $ssn_field, $ssn, $sunet_id (just for logs so just enter as "expiryCron")
            // Wipe it!
                foreach ($q as $i => $j) {
                    $record = $j[$rec_id];
                    $ssn = $j[$ssn_field];

                    $wipe_errors = $this->wipeSSN($project_id, $record, $ssn_field, $ssn, "cronSSNExpiry");
                    if (str_contains(implode("", $wipe_errors), 'Error')) {
                        REDCap::logEvent("Error wiping SSN", "Cron job was unable to wipe SSN for this record. Please contact admin to review logs.", "", $record);
                    } else {
                        $ctr++;
                        $wiped[] = $record;
                    }
                }
            }
            if ($ctr == 0) {
                REDCap::logEvent("SSNMasker Cron", "Cron job to wipe SSN found no records with SSN.");
            } else {
                REDCap::logEvent("SSNMasker Cron", "Cron job wiped $ctr SSN. These records were affected: ". implode(", ", $wiped));
            }

        } catch (\Exception $e) {
            $this->emError("Exception while wiping SSN for project $project_id", $e->getMessage(), $e->getTraceAsString());
            //TODO log into logEvent??
            return;
        }

    }



    /******************************************************************************************************************/
    /* CRON METHODS                                                                                                   */
    /******************************************************************************************************************/
    /**
     * @return string
     * This cron function is called daily. The expected start date will be checked and if past, the SSN will be deleted
     * using the existing cleanup methods.
     *
     */
    public function cronSSNMaskExpiry($cronInfo)
    {
        $originalPid = $_GET['pid'];

        foreach($this->getProjectsWithModuleEnabled() as $localProjectId){
            $_GET['pid'] = $localProjectId;

            // Giving up on this. i don't seem to have project context
            // Project specific method calls go here.
            //$this->ssnExpiryCheck();

            // Backup plan to make web call
            $ssnExpiryCheckURL = $this->getUrl('src/ssnExpiryCheck.php?pid=' . $localProjectId, true, true);

            // Call the project through the API so it will be in project context.
            $response = http_get($ssnExpiryCheckURL);
        }

        // Put the pid back the way it was before this cron job (likely doesn't matter, but is good housekeeping practice)
        $_GET['pid'] = $originalPid;
        $this->emDebug("Cron completed");
        return "The \"{$cronInfo['cron_description']}\" cron job completed successfully.";
    }
}