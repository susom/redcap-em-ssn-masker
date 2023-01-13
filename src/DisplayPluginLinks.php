<?php

namespace Stanford\SSNMasker;

use REDCap;

/** @var \Stanford\SSNMasker\SSNMasker $module */

$project_id = $module->getProjectId();

$url = $module->getUrl('src/Viewer.php', true, true);
echo "<br><br>This is the SSN Viewer Link api: <br>".$url;

$url = $module->getUrl('src/Viewer.php', true, false);
echo "<br><br>This is the SSN Viewer Link not api endpoint: <br>".$url;

$url = $module->getUrl('src/ssnExpiryCheck.php?pid=' . $project_id, true, true);
echo "<br><br>This is the cron Expiry check endpoint: <br>".$url;
