#!/usr/bin/php
<?php

require_once "phpagi.php";
require_once "fafs-include.php";

$myagi = new AGI();
$myagi->verbose("GET-CAMPAIGN: Starting");

// Get the DID number from AGI
$did = $myagi->request['agi_arg_1']; // DID passed as argument
if (empty($did)) {
    $did = $myagi->request['agi_extension']; // Fallback to extension
}

$myagi->verbose("GET-CAMPAIGN: Looking up campaign for DID: $did");

// Optimized: Use persistent connection (reuses open connections safely, no leaks)
$mysqli = getDbConnection('asterisk'); // 'asterisk' as per $myDb global

if ($mysqli->connect_errno) {
    $myagi->verbose("GET-CAMPAIGN ERROR: MySQL Connect failed: " . $mysqli->connect_error);
    $myagi->set_variable('CAMPAIGN_ID', 'ERROR_DB_FAIL'); // No fallback - log and error out
    exit();
}

// Query campaign ID - No fallback; log failures explicitly
$sql = "SELECT CampaignId FROM fafs_qnum_did_campid WHERE DIDNumber='$did' LIMIT 1";
$myagi->verbose("GET-CAMPAIGN: Query: $sql");

if ($result = $mysqli->query($sql)) {
    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        $campaign_id = $row['CampaignId'];
        $myagi->verbose("GET-CAMPAIGN: Found campaign: $campaign_id for DID: $did");
        $myagi->set_variable('CAMPAIGN_ID', $campaign_id);
    } else {
        $myagi->verbose("GET-CAMPAIGN ERROR: No campaign found for DID: $did - Check fafs_qnum_did_campid table");
        $myagi->set_variable('CAMPAIGN_ID', 'ERROR_NO_CAMPAIGN'); // Log and set error
    }
    $result->close();
} else {
    $myagi->verbose("GET-CAMPAIGN ERROR: Query failed: " . $mysqli->error);
    $myagi->set_variable('CAMPAIGN_ID', 'ERROR_DB_FAIL'); // Log and set error
    exit();
}

// Optimized: No explicit close needed for persistent connection (MySQL manages)
// $mysqli->close();

$myagi->verbose("GET-CAMPAIGN: Completed");
?>