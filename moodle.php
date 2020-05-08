<?php
error_reporting(E_ALL);
header('Content-Type: text/plain');

if (php_sapi_name() == 'cli') {
    define('CLI_SCRIPT', true);
}

define('NO_OUTPUT_BUFFERING', true);
define('NO_DEBUG_DISPLAY', true);

require_once(dirname(__FILE__) . '/../config.php');

global $CFG, $DB, $PAGE, $OUTPUT, $SITE, $USER;

require_once($CFG->dirroot . '/admin/tool/dataprivacy/lib.php');

use tool_dataprivacy\api;
use core\task\manager;
use tool_dataprivacy\data_request;
use tool_dataprivacy\task\process_data_request_task;
use core_privacy\local\request\contextlist_collection;
use core_privacy\local\request\core_user_data_provider;
use core_privacy\local\request\context_aware_provider;
use core_privacy\local\request\user_preference_provider;
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
        $email = optional_param('email', '', PARAM_NOTAGS);
        $ticket = getallheaders()['Authorization'] ?? optional_param('ticket', '', PARAM_TEXT);
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

    $requestedby = json_decode($contents)->name ?? null;
    $USER = $DB->get_record('user', array('id' => 2));

    if (($email && $DB->count_records('user', array('email' => $email)) > 1) || ($username && $DB->count_records('user', array('username' => $username)) > 1)) {
        http_response_code(409);
        die();
    }

    $user1 = $username ? core_user::get_user_by_username($username) : null;
    $user2 = $email ? core_user::get_user_by_email($email) : null;

    if (!$user1 && !$user2) {
        http_response_code(204);
        die();
    }

    if ($username && $email && ($user1 != $user2)) {
        // The requested user could not be found or credentials point to different users.
        http_response_code(400);
        die();
    }

    $user = $user1 ?: $user2;

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
            $datarequest = api::create_data_request($user->id, $op, 'Autocreated on behalf of '.$requestedby, data_request::DATAREQUEST_CREATION_AUTO);
            $requestid = $datarequest->get('id');
            api::approve_data_request($requestid);

            $adhoctasks = manager::get_adhoc_tasks(process_data_request_task::class);
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

            $contextlistcollection = get_contexts_for_userid($requestpersistent->get('userid'), $manager);
            $approvedclcollection = api::get_approved_contextlist_collection_for_collection(
                $contextlistcollection,
                $foruser,
                $request->type
            );
            $completestatus = api::DATAREQUEST_STATUS_COMPLETE;
            $deleteuser = false;
            $usercontext = \context_user::instance($foruser->id, IGNORE_MISSING);

            // Export the data.
            $exportedcontent = export_user_data($approvedclcollection, $manager);

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


// These functions are taken from manager.php since they're protected
// and we want to get rid debug messages.

function get_contexts_for_userid($userid, $manager)
{
    $components = get_component_list();
    $a = (object) [
        'total' => count($components),
        'progress' => 0,
        'component' => '',
        'datetime' => userdate(time()),
    ];
    $clcollection = new contextlist_collection($userid);

    foreach ($components as $component) {
        $a->component = $component;
        $a->progress++;
        $a->datetime = userdate(time());
        $contextlist = handled_component_class_callback(
            $component,
            core_user_data_provider::class,
            'get_contexts_for_userid',
            [$userid],
            $manager
        );
        if ($contextlist === null) {
            $contextlist = new \core_privacy\local\request\contextlist();
        }

        // Each contextlist is tied to its respective component.
        $contextlist->set_component($component);

        // Add contexts that the component may not know about.
        // Example of these include activity completion which modules do not know about themselves.
        $contextlist = \core_privacy\local\request\helper::add_shared_contexts_to_contextlist_for($userid, $contextlist);

        if (count($contextlist)) {
            $clcollection->add_contextlist($contextlist);
        }
    }

    return $clcollection;
}

function export_user_data(contextlist_collection $contextlistcollection, $manager)
{
    $a = (object) [
        'total' => count($contextlistcollection),
        'progress' => 0,
        'component' => '',
        'datetime' => userdate(time()),
    ];

    // Export for the various components/contexts.
    foreach ($contextlistcollection as $approvedcontextlist) {

        if (!$approvedcontextlist instanceof \core_privacy\local\request\approved_contextlist) {
            throw new \moodle_exception('Contextlist must be an approved_contextlist');
        }

        $component = $approvedcontextlist->get_component();
        $a->component = $component;
        $a->progress++;
        $a->datetime = userdate(time());

        // Core user data providers.
        if (component_implements($component, core_user_data_provider::class, $manager)) {
            if (count($approvedcontextlist)) {
                // This plugin has data it knows about. It is responsible for storing basic data about anything it is
                // told to export.
                handled_component_class_callback(
                    $component,
                    core_user_data_provider::class,
                    'export_user_data',
                    [$approvedcontextlist],
                    $manager
                );
            }
        } else if (!component_implements($component, context_aware_provider::class, $manager)) {
            // This plugin does not know that it has data - export the shared data it doesn't know about.
            \core_privacy\local\request\helper::export_data_for_null_provider($approvedcontextlist);
        }
    }

    // Check each component for non contextlist items too.
    $components = get_component_list();
    $a->total = count($components);
    $a->progress = 0;
    $a->datetime = userdate(time());
    foreach ($components as $component) {
        $a->component = $component;
        $a->progress++;
        $a->datetime = userdate(time());
        // Core user preference providers.
        handled_component_class_callback(
            $component,
            user_preference_provider::class,
            'export_user_preferences',
            [$contextlistcollection->get_userid()],
            $manager
        );

        // Contextual information providers. Give each component a chance to include context information based on the
        // existence of a child context in the contextlist_collection.
        handled_component_class_callback(
            $component,
            context_aware_provider::class,
            'export_context_data',
            [$contextlistcollection],
            $manager
        );
    }
    $location = \core_privacy\local\request\writer::with_context(\context_system::instance())->finalise_content();

    return $location;
}

function component_implements(string $component, string $interface, $manager): bool
{
    $providerclass = $manager->get_provider_classname_for_component($component);
    if (class_exists($providerclass)) {
        $rc = new \ReflectionClass($providerclass);
        return $rc->implementsInterface($interface);
    }
    return false;
}

function handled_component_class_callback(string $component, string $interface, string $methodname, array $params, $manager)
{
    try {
        return $manager->component_class_callback($component, $interface, $methodname, $params);
    } catch (\Throwable $e) {
        debugging($e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
        component_class_callback_failed($e, $component, $interface, $methodname, $params);
        return null;
    }
}

function get_component_list()
{
    $components = array_keys(array_reduce(\core_component::get_component_list(), function ($carry, $item) {
        return array_merge($carry, $item);
    }, []));
    $components[] = 'core';

    return $components;
}

function component_class_callback_failed(
    \Throwable $e,
    string $component,
    string $interface,
    string $methodname,
    array $params
) {
    if ($this->observer) {
        call_user_func_array([$this->observer, 'handle_component_failure'], func_get_args());
    }
}
