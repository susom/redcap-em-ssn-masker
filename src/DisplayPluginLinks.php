<?php

namespace Stanford\SSNMasker;

use REDCap;

/** @var \Stanford\SSNMasker\SSNMasker $module */

$url = $module->getUrl('src/Viewer.php', true, true);
echo "<br><br>This is the SSN Viewer Link: <br>".$url;

