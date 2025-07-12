<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (isset($_GET['message'])) {
	$msg = $_GET['message'];
	echo "<script>alert('$msg')</script>";
	unset($_GET['message']);
}

session_start();
require_once('/etc/freepbx.conf');
require_once('fafs-include.php');
$sid = session_id();
$dt = date("Y-m-d H:i:s");
$today = date("Y-m-d");
$exten = $_COOKIE['MyExtension'];
if (strlen($exten) != 4) {
	$exten = $_SESSION['MyExtension'];
} elseif (strlen($exten) != 4) {
	$exten = $_GET['exten'];
} elseif (strlen($exten) != 4) {
	echo "ERROR: The system lost track of your extension or queues!  Please log out and log in again.<br>";
	exit;
}


$source = $_REQUEST['source'];
//logit("IFWFC Start SID: $sid");
$dbmsg = array();
$dbmsg['ext'] = $exten;
$dbmsg['app'] = 'WFC-IF';
$dbmsg['act'] = 'WFC_IF Loading';
$dbmsg['cls'] = 'INFO';
$dbmsg['txt'] = "";
$dbmsg['data'] = $_SESSION;

# change status return from elsewhere
/*if (isset($_REQUEST['source'])) {
	$src=$_REQUEST['source'];
	if ($src=='outbound') {
		$interface="Local/$exten@from-queue/n";
		$res=$astman->QueuePause($interface,FALSE,'Available');
		sleep(1);
		$res=$astman->QueuePause($interface,TRUE,'Break');
		unset($_REQUEST['source']);
	}
}
*/

/* TEST =========================
if (isset($_SESSION['Message'])) {
	$msg=$_SESSION['Message'];
	$alert= "<br><script type='text/javascript'>alert('$msg');</script><br>";
	unset($_SESSION['Message']);
} else {
	$alert="<br>'Message' is NOT set.<br>";
}
$ses=$_SESSION;
foreach($ses as $k => $v) {
	if (!is_array($v)) {
		$alert .= "$k = $v<br>";
	}
}
END =========================
*/

$mysqli = new mysqli($myDBIP, $myUser, $myPW, $myDb);

if ($mysqli->connect_errno) {
	$sqler = $mysqli->connect_error;
	logit("$exten IWFC ERROR: Could not connect to MySQL: $sqler");
	//todo add alert
	exit();
}
// check for logout
$sql = "select count(*) from fafs_live_agents where AgentID=$exten;";
logit("$exten WFC-IF - check login SQL: $sql");
if ($result = $mysqli->query($sql)) {
	$row = $result->fetch_row();
	$ct = $row[0];
	if ($ct != 1) {
		logit("$exten FORCE LOGOUT Agent $exten ($ct)");
		$dbmsg['act'] = 'Force logout';
		$dbmsg['cls'] = 'INFO';
		$dbmsg['txt'] = "Agent $exten not in fafs_live_agents";
		$dbmsg['data'] = $_SESSION;
		dblog($dbmsg);
		logit("$exten FORCE LOGOUT Agent NOT IN LIVE AGENTS ($ct)");
		//echo "<script>javascript:window.close();</script>";
		header("location: https://$myIP/fafs/fafs-force-logout.php?source=WFC-IF&reason=No_LA_Rec");
		//header("parent.location: https://$myIP/fafs/index.php");
		//echo "<script>parent.parent.changeURL('https://$myIP/fafs/index.php');</script>";
		die;
	} else {
		logit("Agent $exten is logged in (CT = $ct)");
	}
} else {
	$err = $mysqli->error;
	logit("$exten Logout Check SQL ERROR $err");
}
/* we let the updater take care of extension monitoring
// make sure extension still active
$cmd="asterisk -rx 'sip show peers' | grep '$exten'";
$status=`$cmd`;
//if (empty($status) || strpos($status,'Unspecified')  || strpos($status,'UNREACHABLE') || strpos($status,'UNKNOWN')) {
if (strpos($status,'OK')===FALSE) {
	$estr="EXTENSION $exten NOT CONNECTED. You are being Paused!";
	logit("$exten FORCE LOGOUT Agent $exten ($status)");
	$dbmsg['act']='Force logout';
	$dbmsg['cls']='WARNING';
	$dbmsg['txt']="Extension $exten not reachable";
	$dbmsg['data']=$_SESSION;
	dblog($dbmsg);
	echo "<script>alert('$estr');</script>";
	//$resp=file_get_contents("https://$myIP/fafs/fafs-set-presence.php?exten=$exten&reason=Available&status=Waiting&pause=0&source=WFC-IF")
	$resp=file_get_contents("https://$myIP/fafs/fafs-set-presence.php?exten=$exten&source=WFC-IF&reason=Tech-Extension");
} else {
	//logit("$exten LOGIN: EXTCHK: $status");
}
*/
$agent_chan = $SESSION['CallData']['agent_chan'];
sleep(2);
$res = $astman->Hangup($agent_chan);
logit("WIF Hangup result: $res", 'AgentPBX_');
// handle queues

$new_queues = array();
$sql = "select qexten from fafs_queue_agents where extension='$exten';";
//logit("IWC get ACD:$sql");
$myq = "";
if ($result = $mysqli->query($sql)) {
	while ($row = $result->fetch_row()) {
		$new_queues[] = $row[0];
		//echo "added acd $row[0] <br>";
		$myq .= "'" . $row[0] . "',";
	}
	//remove last comma
	$myq = substr($myq, 0, strlen($myq) - 1);
} else {
	logit("$exten IWFC Error: " . $mysqli->error . "\n");
}
//logit("IWFC: MyQ=$myq");
$result->Close();
//echo "queues set<br>";

if (isset($_SESSION['MyQueues'])) {
	// existing queue logins
	foreach ($_SESSION['MyQueues'] as $q) {
		if (!in_array($q, $new_queues)) {
			$qres = $astman->QueueRemove($q, "Local/$exten@from-queue/n");
			$jqres = json_encode($qres);
			//logit("WFCIF $exten removed queue $q: $jqres");
		}
	}
}

//echo "done removing queues<br>";

$_SESSION['MyQueues'] = $new_queues;
foreach ($_SESSION['MyQueues'] as $q) {
	$qres = $astman->QueueAdd($q, "Local/$exten@from-queue/n", 0);
	$jqres = json_encode($qres);
	//logit("WFCIF $exten added queue $q: $jqres");
}

// update live_agents queues & callid
$myqs = str_replace("'", "", $myq);
$sql = "select count(*) from asteriskcdrdb.fafs_cid_did where dt > '$today' and agentid='$exten';";
if ($result = $mysqli->query($sql)) {
	$row = $result->fetch_row();
	$callCount = $row[0];
	$result->close();
	logit("WFCIF $exten Got $callCount calls for today");
} else {
	$err = $mysqli->error;
	logit("WFCIF $exten Error getting callCount: $err\n\t$sql");
}
$sql = "update fafs_live_agents set CurrentUID='',CallsSinceLogin='$callCount', Queues='$myqs' where AgentID='$exten';";
$mysqli->query($sql);
$nr = $mysqli->affected_rows;
if ($nr != 1 and $nr != 0) {
	logit("$exten ERROR: Live_Agents update (27) NR=$nr: " . $mysqli->error . "\n\t$sql");
}
if ($source == 'Outbound') {
	$url = "https://$myIP/fafs/fafs-set-presence.php?exten=$exten&reason=Available&status=Waiting&pause=0&source=WFC-IF";
	$context = stream_context_create([
		'http' => [
			'timeout' => 10,
			'ignore_errors' => true
		]
	]);
	$resp = file_get_contents($url, false, $context);
	if ($resp === FALSE) {
		logit("$exten WFCIF Error: Failed to call fafs-set-presence.php for Outbound");
	} else {
		logit("$exten WFCIF: Set presence response: $resp");
	}
}

//create queue display table
$myqtable = "<table><tr><th>Queue</th><th>CampID-ACD-DID</th></tr>";
//$sql="select distinct q.extension as `QEXTEN`, i.extension as `DID`, q.descr as `Description` from queues_config q left join incoming i on q.extension = substr(i.destination,12,6) where q.extension in($myq);";
//$sql="select distinct q.extension as `QEXTEN`, i.Description as `Description` from queues_config q inner join fafs_qnum_did_campid i on q.extension=i.QueueID where q.extension in($myq) and i.CampaignID <> '1';";
$sql = "select QueueID, Description from fafs_qnum_did_campid where QueueID in($myq);";

//logit("IWFC: GetQ SQL: $sql");
$result = $mysqli->query($sql);
if ($result) {
	$i = 0;
	while ($row = $result->fetch_row()) {
		if ($i < 26) {
			$myqtable .= "<tr><td>$row[0]</td><td>$row[1]</td><td>$row[2]</td></tr>";
			$i++;
		}
	}
} else {
	$myqtable .= "Error: Queue query failed. " . $mysqli->error . "<br>";
	logit("$exten WFCIF Error: Queue query failed. " . $mysqli->error . "\n\t$sql");
}

$myqtable .= "</table>";
//logit("IWFC: MyQueues Table: $myqtable");


foreach ($_SESSION['MyQueues'] as $q) {
	$astman->QueueAdd($q, "Local/$exten@from-queue/n", 0);
}
$ifs = <<<MKUP
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script type="text/javascript">
// Add comprehensive console logging for debugging
console.log('FAFS Waiting Iframe loaded - Extension: $exten');

// Function to safely access parent elements with logging
function safeParentAccess() {
    try {
        console.log('Attempting to access parent elements...');
        var el = window.parent.parent.document.getElementById('callData');
        if (el) {
            console.log('Found callData element, hiding it');
            el.style.display = "none";	
            el.innerHTML='';
        } else {
            console.warn('callData element not found in parent');
        }
        
        var pr = window.parent.parent.document.getElementById('presence');
        if (pr) {
            console.log('Found presence element, enabling it');
            pr.disabled = false;
        } else {
            console.warn('presence element not found in parent');
        }
        
        var acdsel = window.parent.parent.document.getElementById('ifCall');
        if (acdsel) {
            console.log('Found ifCall element, hiding it');
            acdsel.style.display = "none";	
        } else {
            console.warn('ifCall element not found in parent');
        }
    } catch (error) {
        console.error('Error accessing parent elements:', error);
    }
}

// Function to refresh queues with logging
function refreshQueues() {
    console.log('Refresh Queues button clicked');
    try {
        console.log('Reloading current page...');
        document.location.href = document.location.href;
    } catch (error) {
        console.error('Error refreshing queues:', error);
        alert('Error refreshing queues: ' + error.message);
    }
}

// Function to go to outbound with logging
function goToOutbound() {
    console.log('Outbound button clicked');
    try {
        var url = 'fafs-outbound.php?exten=$exten';
        console.log('Redirecting to:', url);
        document.location.href = url;
    } catch (error) {
        console.error('Error going to outbound:', error);
        alert('Error going to outbound: ' + error.message);
    }
}

// Function to repull call with logging
function repullCall() {
    console.log('Re-Pull Call button clicked');
    try {
        var url = 'fafs-repull-call.php?exten=$exten';
        console.log('Redirecting to:', url);
        document.location.href = url;
    } catch (error) {
        console.error('Error repulling call:', error);
        alert('Error repulling call: ' + error.message);
    }
}

// Function to open no form pop with logging
function openNoFormPop() {
    console.log('No Form Pop button clicked');
    try {
        var ifwfc = parent.document.getElementById('ifwfc');
        if (ifwfc) {
            var url = 'https://dashboard.fafs.me/Admin/PBX/CallFormNew?AgentID=$exten';
            console.log('Setting ifwfc src to:', url);
            ifwfc.src = url;
        } else {
            console.error('ifwfc element not found in parent');
            alert('Error: ifwfc element not found');
        }
    } catch (error) {
        console.error('Error opening no form pop:', error);
        alert('Error opening no form pop: ' + error.message);
    }
}

// Initialize when page loads
$(document).ready(function() {
    console.log('Document ready, initializing FAFS waiting iframe...');
    safeParentAccess();
});

// Add error handling for unhandled errors
window.onerror = function(msg, url, lineNo, columnNo, error) {
    console.error('JavaScript error:', {
        message: msg,
        url: url,
        lineNo: lineNo,
        columnNo: columnNo,
        error: error
    });
    return false;
};
</script>
MKUP;

$ifs_btn = <<<IFSBTN
<button onclick="refreshQueues();" style="padding: 8px 16px; margin: 2px; background-color: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer;">Refresh Queues</button>
&nbsp;&nbsp;&nbsp;
<button onclick="goToOutbound();" style="padding: 8px 16px; margin: 2px; background-color: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer;">Outbound</button>
<button onclick="repullCall();" style="padding: 8px 16px; margin: 2px; background-color: #FF9800; color: white; border: none; border-radius: 4px; cursor: pointer;">Re-Pull Call</button>
<button onclick="openNoFormPop();" style="padding: 8px 16px; margin: 2px; background-color: #9C27B0; color: white; border: none; border-radius: 4px; cursor: pointer;">No Form Pop</button>
IFSBTN;

session_write_close();
echo '<html><head><meta http-equiv="refresh" content="30">';
echo $ifs;
echo '</head><body>';
//echo "SID: $sid<br>";
echo "v.1.0 &nbsp &nbsp " . date("Y-m-d H:i") . "<br>";
echo "Extension: $exten<br/><br/>";

echo "<br>$ifs_btn<br><br>";
echo $myqtable;
# echo "<br><br>sid: $sid";
# echo $alert;
echo "</body></html>";
//$jsrsp=json_encode($_SESSION);
//logit("WFC-IF: $jsrsp");

// CRITICAL FIX: Close database connection
if (isset($mysqli)) {
	$mysqli->close();
	logit("WFC-IF: Database connection closed");
}
exit(0);
