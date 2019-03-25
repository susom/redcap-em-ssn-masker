<?php

namespace Stanford\SSNMasker;

/** @var \Stanford\SSNMasker\SSNMasker $module */

use REDCap;

/**
 * View the SSN for the specified record
 *
 */

$module->emDebug("Starting SSN Masker");


/**
// Get a list of authorized users
$q = REDCap::getData(cfg::$config_pid, 'json', cfg::$config_record_id);
$results = json_decode($q,true);
if (!empty($results['error'])) {
    Plugin::log("Error loading config data from " . cfg::$config_pid . ": " . print_r($results,true), "ERROR");
    exit();
}
$result = $results[0];
// Replace comma with \n
$approved_users = empty($result['ssn_viewers'])   ? array() : array_map('trim', explode("\n",$result['ssn_viewers']));
$approved_users2 = empty($result['ssn_viewers2']) ? array() : array_map('trim', explode("\n",$result['ssn_viewers2']));




$group = $other_group = null;
if (in_array($sunet_id, $approved_users)) {
    $group = "1";
    $other_group = "2";
} else if (in_array($sunet_id, $approved_users2)) {
    $group = "2";
    $other_group = "1";
} else {
    die ("You do not have access to this feature.  Please contact the project administrators");
}
 */


$approved_users = $array = preg_split("/\r\n|\n|\r|','/", $module->getProjectSetting('approved-users'));
$approved_users_2 = $array = preg_split("/\r\n|\n|\r|','/", $module->getProjectSetting('approved-users-2'));

$sunet_id = $_SERVER['WEBAUTH_USER'];
//$sunet_id = 'test1';

list($group,$other_group) = $module->checkIfAuthorizedUser($sunet_id);

$module->emDebug($group,$other_group, "GROUP");

//if ( (defined('SUPER_USER') && SUPER_USER) OR (in_array($sunet_id, $approved_users)) ) {

$record = $_REQUEST['record'];

$ssn_field = $module->getProjectSetting('ssn-field');
$fac_name_field =  $module->getProjectSetting('fac-name-field');

$errors = array();

if (empty($record)) {
    $errors[] = "You must supply a valid record.";
} else {
    $q = REDCap::getData(
        'json',
        $record,
        array(REDCap::getRecordIdField(),$ssn_field,$fac_name_field));
    $results = json_decode($q,true);
    if (!empty($results['errors'])) {
        $errors[] = json_encode($result);
    } elseif (empty($results)) {
        $errors[] = "Record $record does not appear to be valid.";
    } else {
        $result = $results[0];
        $ssn = $result[$ssn_field];
        $name = "Dr. " . $result[$fac_name_field];
    }
}

if (isset($_POST['submit']) AND $_POST['submit'] == 'WIPE') {
    global $username;

    // Mark that group x has wiped SSN
    REDCap::logEvent("SSN Wipe Approved For Group $group","","",$record);

    $module->emDebug($approved_users, "Approved Users");
    $module->emDebug($approved_users_2,  "Approved Users2");



    // Check if other group has also wiped so we can delete
    if (count($approved_users_2) > 0 AND !($module->hasGroupWiped($record, $other_group))) {
        // Waiting for other group to wipe!
        REDCap::logEvent("SSN Wipe Delayed - Waiting for Group $other_group","","",$record);
        $errors[] = "The SSN will be Wiped when someone from group $other_group completes their workflow.";
    } else {
        // Wipe it!

        $data = array(
            'request_id'                => $record,
            $ssn_field                  => "WIPED"
        );
        $q = REDCap::saveData('json',json_encode(array($data)));
        if (!empty($q['errors'])) {
            $module->emError($q, "Error wiping SSN");
            $errors[] = "An error occurred when clearning the SSN value.  
            Please notify <a href='mailto:redcap-help@list.stanford.edu'>redcap-help@list.stanford.edu</a>
            with the current timestamp and record number that you were working on.
            The SSN may not have been securely cleared from the database.";
        } else {
            // Flush logs
            $sql = "
          UPDATE redcap_log_event
            SET sql_log = REPLACE(sql_log, '\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'', '\'" . $ssn_field . "\', \'---cleared by " . $username . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND sql_log like '%\'" . $ssn_field . "\', \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
            //print "<pre>" . $sql . "</pre>";
            db_query($sql);

            $sql = "
          UPDATE redcap_log_event
            SET data_values = REPLACE(data_values, '". $ssn_field . " = \'" . db_real_escape_string($ssn) . "\'', '" . $ssn_field . " = \'---cleared by " . $username . " on " . date('Y-m-d H:i:s') . "---\'')
          WHERE 
            project_id = " . intval($project_id) . "
            AND pk = '" . db_real_escape_string($record) . "'
            AND data_values LIKE '%" . $ssn_field . " = \'" . db_real_escape_string($ssn) . "\'%' LIMIT 100";
            //print "<pre>" . $sql . "</pre>";
            db_query($sql);

            $errors[] = "The SSN for record $record has been wiped, including all log history.";
        }
    }
}

if (isset($_POST['submit']) AND $_POST['submit'] == 'VIEW') {
    $ssn_value = $ssn;
    REDCap::logEvent("SSN Viewed by $sunet_id","","",$record);
} else {
    $ssn_value = "***********";
}

if (isset($ssn) and $ssn == "WIPED") {
    $errors[] = "$name - Record $record - SSN has already been erased.";
    $ssn_value = "ALREADY ERASED";
}

if (isset($ssn) and $ssn == "") {
    $errors[] = "$name - Record $record - SSN is blank (was it ever filled out?)";
    $ssn_value = "MISSING";
}









?>
<!DOCTYPE html>
<html>
    <head>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <!-- Bootstrap core CSS -->
        <link href='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/css/bootstrap.min.css' rel='stylesheet' media='screen'>
        <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
        <!--[if lt IE 9]>
        <script src='https://oss.maxcdn.com/html5shiv/3.7.2/html5shiv.min.js'></script>
        <script src='https://oss.maxcdn.com/respond/1.4.2/respond.min.js'></script>
        <![endif]-->
        <style>

            form { overflow:hidden; }
            .ssn_text {
                font-size: 22px;
                padding: 5px;
                width: 200px;

                background-color: white;
                margin: 10px auto;
            }
            .alert {
                margin-top: 20px;
                font-size: 18px;
                text-align:center;
            }

            .page-footer {
                margin-top:20px;
            }
        </style>
    </head>
<body>
<div class='container'>
    <?php
        if (count($errors) > 0) {
            foreach($errors as $error) print "<div class='alert alert-danger'>" . $error . "</div>";
        } else {
            ?>
                <div class="jumbotron">
                    <div class="page-header text-center">
                        <h1>Faculty Onboarding SSN</h1>
                    </div>
                    <div class='row'>
                        <form class='form-horizontal' role='form' method='POST' name='ssn_update_form'>
                            <div class="text-center">
                                <div>
                                    <h3>View and Wipe SSN for <?php echo "$name [Record $record]" ?></h3>
                                </div>
                                <div>
                                    Logged in as <?php print $sunet_id ?>
                                </div>
                                <div class="text-left" style="margin: 20px 200px;">
                                    In an effort to reduce our exposure to sensitive information, please follow these steps:
                                    <ul>
                                        <li>Click on the 'Reveal' button to display the SSN</li>
                                        <li>Copy and paste the value to your internal system.</li>
                                        <li>Then press the 'Delete SSN' button to clear the value from this system.</li>
                                    </ul>
                                </div>
                                <div>
                                    <i>Please note that once the value is wiped, it can not be recovered.</i>
                                </div>
                                <div>
                                    <div id="ssn" class="button ssn_text"><?php echo $ssn_value ?></div>
                                    <input type="hidden" name="record" value="<?php echo $record ?>"/>
                                </div>
                                <div>
                                    <button id="reveal" class="btn btn-primary" name="submit" value="VIEW">
                                        Reveal SSN
                                    </button>
                                    <button class="btn btn-danger" name="submit" value="WIPE">
                                        <img src="<?php echo APP_PATH_WEBROOT ?>Resources/images/delete.png" class="imgfix">Delete SSN from system
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php
        }
?>
</div>
<!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
<script src='https://ajax.googleapis.com/ajax/libs/jquery/1.11.3/jquery.min.js'></script>
<!-- Include all compiled plugins (below), or include individual files as needed -->
<script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.5/js/bootstrap.min.js'></script>
</body>
</html>

<script>
//    $(document).ready(function() {
//        $('#reveal').click(reveal);
//    });
//
//    function reveal(e) {
//        $('#ssn').text($('#ssn').attr('value'));
//        .preventDefault();
//        return false;
//    }
</script>
