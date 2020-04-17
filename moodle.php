<?php
error_reporting(E_ALL);
header('Content-Type: text/plain');

if (php_sapi_name() == 'cli') {
    define('CLI_SCRIPT', true);
}

require_once(dirname(__FILE__) . '/../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $SITE, $USER;

require_once($CFG->dirroot . '/admin/tool/dataprivacy/lib.php');

use tool_dataprivacy\api;
use core\task\manager;
use tool_dataprivacy\data_request;
use tool_dataprivacy\contextlist_context;
use core\task\adhoc_task;

try {
    if (php_sapi_name() == 'cli') {
        $opts = "o:u::e::t:";
        $input = getopt($opts);
        $op = $input['o'] ?? null;
        $username = $input['u'] ?? null;
        $email = $input['e'] ?? null;
        $ticket = $input['t'] ?? null;
    } else {
        // op: export = 1, delete = 2
        $op = required_param('op', PARAM_INT);
        $username = optional_param('username', '', PARAM_TEXT);
        $email = optional_param('mail', '', PARAM_NOTAGS);
        $ticket = getallheaders()['Authorization'] ?? null;
    }

    if (!$op || !$ticket || (!$username && !$email)) {
        http_response_code(400);
        die();
    }

    $ch = curl_init();
    $apiurl = 'https://toker-test.dsv.su.se/verify';

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $ticket);
    curl_setopt($ch, CURLOPT_URL, $apiurl);
    $contents = curl_exec($ch);
    $headers  = curl_getinfo($ch);
    curl_close($ch);

    // Check auth.
    if ($headers['http_code'] !== 200 || !in_array('urn:mace:swami.se:gmai:dsv-user:gdpr', json_decode($contents)->entitlements)) {
        // Throw unauthorized code.
        http_response_code(401);
        die();
    }

    $USER = $DB->get_record('user', array('id' => 2));

    if ($DB->count_records('user', array('email' => $email)) > 1 || $DB->count_records('user', array('email' => $username)) > 1) {
        http_response_code(409);
        die();
    }

    $user1 = $username ? core_user::get_user_by_username($username) : null;
    $user2 = $email ? core_user::get_user_by_email($email) : null;

    if ($username && $email && ($user1 !== $user2)) {
        // The requested user could not be found or credentials point to different users.
        http_response_code(400);
        die();
    }

    $user = $user1 ?? $user2;

    if (!$user) {
        // The requested user could not be found.
        http_response_code(204);
        die();
    }

    // Let's find a completed request.
    $request = api::get_data_requests($user->id, array(), $op, data_request::DATAREQUEST_CREATION_AUTO, 'timecreated DESC', 0, 1);
    $request = reset($request);

    if ($op == 1) {
        if (!empty($request) && $request->get('status') == api::DATAREQUEST_STATUS_DOWNLOAD_READY && $request->get('timecreated') > (time() - 3600)) {
            serveFile($user, $request);
            http_response_code(200);
        } else {
            // No recently completed request found, let's try to create one.
            $datarequest = api::create_data_request($user->id, $op, 'Autocreated', data_request::DATAREQUEST_CREATION_AUTO);
            $requestid = $datarequest->get('id');
            api::approve_data_request($requestid);

            $adhoctasks = manager::get_adhoc_tasks(initiate_data_request_task::class);
            foreach ($adhoctasks as $adhoctask) {
                if ($adhoctask->get_custom_data()->requestid == $requestid) {
                    $DB->delete_records('task_adhoc', ['id' => $adhoctask->get_id()]);
                }
            }

            $requestpersistent = new data_request($requestid);
            $request = $requestpersistent->to_record();
            // Grab the manager.
            // We set an observer against it to handle failures.
            $manager = new \core_privacy\manager();
            $manager->set_observer(new \tool_dataprivacy\manager_observer());
            $foruser = core_user::get_user($request->userid);
            // Process the request
            api::update_request_status($requestid, api::DATAREQUEST_STATUS_PROCESSING);
            $contextlistcollection = $manager->get_contexts_for_userid($requestpersistent->get('userid'));
            $approvedclcollection = api::get_approved_contextlist_collection_for_collection(
                $contextlistcollection,
                $foruser,
                $request->type
            );
            $completestatus = api::DATAREQUEST_STATUS_COMPLETE;
            $deleteuser = false;
            $usercontext = \context_user::instance($foruser->id, IGNORE_MISSING);

            // Export the data.
            $exportedcontent = $manager->export_user_data($approvedclcollection);

            $fs = get_file_storage();
            $filerecord = new \stdClass;
            $filerecord->component = 'tool_dataprivacy';
            $filerecord->contextid = $usercontext->id;
            $filerecord->userid    = $foruser->id;
            $filerecord->filearea  = 'export';
            $filerecord->filename  = 'export.zip';
            $filerecord->filepath  = '/';
            $filerecord->itemid    = $requestid;
            $filerecord->license   = $CFG->sitedefaultlicense;
            $filerecord->author    = fullname($foruser);
            // Save somewhere.
            $thing = $fs->create_file_from_pathname($filerecord, $exportedcontent);
            $completestatus = api::DATAREQUEST_STATUS_DOWNLOAD_READY;
            api::update_request_status($requestid, $completestatus);

            // Flush output
            ob_end_flush();
            ob_start();
            $thing->readfile();
            http_response_code(200);

            unset($USER);
        }
    }
} catch (Exception $e) {
    var_dump($e->getMessage());
    unset($USER);
    http_response_code(500);
}

function serveFile($user, $request)
{
    $fs = get_file_storage();
    $usercontext = \context_user::instance($user->id, IGNORE_MISSING);
    $file = $fs->get_file($usercontext->id, 'tool_dataprivacy', 'export', $request->get('id'), '/', 'export.zip');
    // The request has succeeded. The client can read the result of the request in the body and the headers of the response.
    $file->readfile();
}
