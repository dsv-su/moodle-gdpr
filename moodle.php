<?php
error_reporting(E_ALL);
header('Content-Type: text/plain');

if (php_sapi_name() == 'cli') {define('CLI_SCRIPT', true);}

require_once(dirname(__FILE__).'/../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $SITE, $USER;

require_once($CFG->dirroot . '/admin/tool/dataprivacy/lib.php');

use tool_dataprivacy\api;
use core\task\manager;
use tool_dataprivacy\data_request;
use tool_dataprivacy\contextlist_context;
use core\task\adhoc_task;

//Un-comment this to be able to run without auth!
$USER = $DB->get_record('user', array('id' => 2));

if (!is_siteadmin($USER->id) && (php_sapi_name() !== 'cli')) {
    http_response_code(401);
    die();
}

if (php_sapi_name() == 'cli') {
  $opts = "o:u:e:";
  $input = getopt($opts);
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
if ($username && $email && ($user1 !== $user2)) {
    // The requested user could not be found or credentials point to different users.
    http_response_code(400);
    die();
}

$user = $user1 ?? $user2;

if (!$user) {
    // The requested user could not be found.
    http_response_code(404);
    die();
}

// Let's find a completed request.
$request = api::get_data_requests($user->id, array(), $op, data_request::DATAREQUEST_CREATION_AUTO, 'timecreated DESC', 0, 1);

if ($op == 1) {
    if (!empty($request) && $request->get('status') == api::DATAREQUEST_STATUS_DOWNLOAD_READY && $request->get('timecreated') > (time() - 43200)) {
        $fs = get_file_storage();
        $usercontext = \context_user::instance($user->id, IGNORE_MISSING);
        $file = $fs->get_file($usercontext->id, 'tool_dataprivacy', 'export', $newest_request->get('id'), '/', 'export.zip');
        // The request has succeeded. The client can read the result of the request in the body and the headers of the response.
        http_response_code(200);
        $file->readfile();
    } else {
        // No completed request found, let's try to create one.
        if (!(api::has_ongoing_request($user->id, $op))) {
            // Now ongoing requests. Creating one.
            $datarequest = api::create_data_request($user->id, $op, 'Autocreated', data_request::DATAREQUEST_CREATION_AUTO);
            $requestid = $datarequest->get('id');
            api::approve_data_request($requestid);
        }
        // Data request has been accepted for processing, but the processing has not been completed. 
        http_response_code(202);
    }
}
