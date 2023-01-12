<?php
namespace Stanford\SSNMasker;
/** @var \Stanford\SSNMasker\SSNMasker $module */

use REDCap;
use Project;
use Exception;

$project_id = $module->getProjectId();


try {
// 1. Retrieve all the record_ids where current date is past the 'expiry-date_field

    $ssn_field = $module->getProjectSetting('ssn-field');
    $expire_test_date = $module->getProjectSetting('expiry-test-date');
    $expire_date_field = $module->getProjectSetting('expiry-date-field');

    //if ssn_field is not set, then don't proceed
    if ($ssn_field == null) exit();

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
        REDCap::logEvent("SSNMasker Cron", "Cron job wiped $ctr SSN. These records were affected: " . implode(", ", $wiped));
    }


} catch (Exception $e) {
    $module->emError("Exception while wiping SSN for project $project_id", $e->getMessage(), $e->getTraceAsString());
    //TODO log into logEvent??
    return;
}
