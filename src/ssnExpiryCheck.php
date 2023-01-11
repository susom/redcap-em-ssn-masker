<?php
namespace Stanford\SSNMasker;
/** @var \Stanford\SSNMasker\SSNMasker $module */

use REDCap;
use Project;
use Exception;

$project_id = $module->getProjectId();


try {
// 1. Retrieve all the record_ids where current date is past the 'expiry-date_field
    /* SQL Query
    select rd1.record, rd1.project_id, rd1.value, rd2.value
from redcap_data AS rd1
JOIN redcap_data AS rd2
ON rd1.project_id=rd2.project_id and rd1.event_id=rd2.event_id and rd1.record=rd2.record
where rd1.project_id = 17094 -- and rd1.event_id=105818
and rd1.field_name="hd_faculty_startdate" and DATE(rd1.value) < CURDATE()
and rd2.field_name = "faculty_ssn" and rd2.value != "WIPED";
    */

    $ssn_field = $module->getProjectSetting('ssn-field');
    $expire_test_date = $module->getProjectSetting('expiry-test-date');
    $expire_date_field = $module->getProjectSetting('expiry-date-field');

    $records_to_expire = $module->findRecordsToExpireSSN($project_id, $expire_date_field, $ssn_field, $expire_test_date);

    //reset counters
    $ctr = 0;
    $wiped = array();
    if (!empty($records_to_expire)) {
        $rec_id = REDCap::getRecordIdField();

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

            $wipe_errors = $module->wipeSSN($project_id, $record, $ssn_field, $ssn, "cronSSNExpiry");
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


} catch (Exception $e) {
    $module->emError("Exception while wiping SSN (record=" . $record . ") for project $project_id", $e->getMessage());
    //TODO log into logEvent??
    return;
}
