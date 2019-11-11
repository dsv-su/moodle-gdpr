<?php

error_reporting(E_ALL);

if (php_sapi_name() == 'cli') {define('CLI_SCRIPT', true);}

require_once(dirname(__FILE__).'/../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $SITE, $USER;

require_once($CFG->dirroot . '/admin/tool/dataprivacy/lib.php');

use tool_dataprivacy\api;
use tool_dataprivacy\data_request;

//Un-comment this to be able to run without auth! e.g. from CLI
//$USER = $DB->get_record('user', array('id' => 2));

if (!is_siteadmin($USER->id)) {
    die();
}

if (php_sapi_name() == 'cli') {
  $opts = "o:u:e:";
  $input = getopt($opts);
  var_dump($input);
  $op = $input['o'];
  $username = $input['u'];
  $email = $input['e'];
} else {
    // op: export = 1, delete = 2
    $op = required_param('op', PARAM_INT);
    $username = optional_param('username', '', PARAM_TEXT);
    $email = optional_param('mail', '', PARAM_NOTAGS);
}

$user1 = $username ? core_user::get_user_by_username($username.'@su.se') : null;
$user2 = $email ? core_user::get_user_by_email($email) : null;
if ($user1 && $user2 && ($user1 !== $user2)) {
    echo 'username and email point to different users';
    die();
}
$user = $user1 ?? $user2;

if (!$user) {
    // We should throw error back
    // Deleted user won't be found (if deletion request was completed);
    echo "No user was found\n\r";
}

// Let's find a completed request.
$requests = api::get_data_requests($user->id, array(), $op);
if (empty($requests)) {
    die();
}

// Exclude manually created data requests.
foreach ($requests as $key => $request) {
	if ($request->get('comments') !== 'Autocreated') {
		unset($requests[$key]);
	}
}

// Get the newest request.
$newest_request = end($requests);
if ($op == 1 && $newest_request->get('status') == api::DATAREQUEST_STATUS_DOWNLOAD_READY) {
    $fs = get_file_storage();
    $usercontext = \context_user::instance($user->id, IGNORE_MISSING);
    $file = $fs->get_file($usercontext->id, 'tool_dataprivacy', 'export', $newest_request->get('id'), '/', 'export.zip');
    $file->readfile();
}

die();

?>
