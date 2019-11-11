<?php
error_reporting(E_ALL);
header('Content-Type: text/plain');

if (php_sapi_name() == 'cli') {define('CLI_SCRIPT', true);}

require_once(dirname(__FILE__).'/../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $SITE, $USER;

require_once($CFG->dirroot . '/admin/tool/dataprivacy/lib.php');

use tool_dataprivacy\api;
use tool_dataprivacy\task\initiate_data_request_task; // could be removed in M3.7
use core\task\manager;
use tool_dataprivacy\data_request;
use tool_dataprivacy\contextlist_context;
use core\task\adhoc_task;

// This function is not needed in M3.7
function preprocess_request($requestid) {
	$request = new data_request($requestid);

    mtrace('Generating the contexts containing personal data for the user...');
    ob_start();
    api::update_request_status($requestid, api::DATAREQUEST_STATUS_PREPROCESSING);

    // Add the list of relevant contexts to the request, and mark all as pending approval.
    $privacymanager = new \core_privacy\manager();
    $privacymanager->set_observer(new \tool_dataprivacy\manager_observer());

    $contextlistcollection = $privacymanager->get_contexts_for_userid($request->get('userid'));
    api::add_request_contexts_with_status($contextlistcollection, $requestid, contextlist_context::STATUS_PENDING);

    // When the preparation of the contexts finishes, update the request status to awaiting approval.
    api::update_request_status($requestid, api::DATAREQUEST_STATUS_AWAITING_APPROVAL);
    ob_clean();
    mtrace('Context generation complete...');
} 

//Un-comment this to be able to run without auth!
//$USER = $DB->get_record('user', array('id' => 2));

if (!is_siteadmin($USER->id) && (php_sapi_name() !== 'cli')) {
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
    echo 'username and email point to different users';
    die();
}
$user = $user1 ?? $user2;

mtrace("Processing user $user->username with moodle id $user->id\r\n");

if (!$user) {
	echo "No user found\r\n";
}

if (!(api::has_ongoing_request($user->id, $op))) {
    $datarequest = api::create_data_request($user->id, $op, 'Autocreated', data_request::DATAREQUEST_CREATION_AUTO);
    echo "Data request created\r\n";
    $requestid = $datarequest->get('id');

    // This is only needed for Moodle 3.5. In 3.7 there's no preprocessing and we can approve right away.
    /*$adhoctasks = manager::get_adhoc_tasks(initiate_data_request_task::class);
    foreach ($adhoctasks as $adhoctask) {
        if ($adhoctask->get_custom_data()->requestid == $requestid) {
            $DB->delete_records('task_adhoc', ['id' => $adhoctask->get_id()]);
            echo "Automatic adhoc task removed\r\n";
        }
    }*/

	//preprocess_request($requestid);
    //echo "Request preprocessed\r\n";
    api::approve_data_request($requestid);
    echo "Request approved\r\n";
} else {
    echo "Data request couldn't be created\r\n";
}
