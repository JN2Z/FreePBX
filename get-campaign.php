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

// Connect to database
$mysqli = new mysqli($dbhost, $dbuser, $dbpass, $dbname);
if ($mysqli->connect_errno) {
    $myagi->verbose("GET-CAMPAIGN: MySQL Connect failed: " . $mysqli->connect_error);
    // $myagi->set_variable('CAMPAIGN_ID', 'UNK'); // Default fallback
    exit();
}

// Query campaign ID
$sql = "SELECT CampaignId FROM fafs_qnum_did_campid WHERE DIDNumber='$did' LIMIT 1";
$myagi->verbose("GET-CAMPAIGN: Query: $sql");

if ($result = $mysqli->query($sql)) {
    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        $campaign_id = $row['CampaignId'];
        $myagi->verbose("GET-CAMPAIGN: Found campaign: $campaign_id for DID: $did");
        $myagi->set_variable('CAMPAIGN_ID', $campaign_id);
    } else {
        $myagi->verbose("GET-CAMPAIGN: No campaign found for DID: $did");
        $myagi->set_variable('CAMPAIGN_ID', 'UNK'); // Unknown campaign
    }
    $result->close();
} else {
    $myagi->verbose("GET-CAMPAIGN: Query failed: " . $mysqli->error);
    $myagi->set_variable('CAMPAIGN_ID', 'ERR'); // Error fallback
}

$mysqli->close();
$myagi->verbose("GET-CAMPAIGN: Completed");
?>