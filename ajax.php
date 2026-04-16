<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_courseexams\local\service\exam_catalog;

require_login();
require_sesskey();

$action = optional_param('action', 'course_overview', PARAM_ALPHANUMEXT);

$response = [
    'status' => 'error',
    'message' => get_string('unknownerror', 'local_courseexams'),
];

try {
    $service = new exam_catalog();

    if ($action === 'search_courses') {
        $query = required_param('query', PARAM_RAW_TRIMMED);
        $response = [
            'status' => 'ok',
            'data' => [
                'courses' => $service->search_courses($query, (int)$USER->id),
            ],
        ];
    } else if ($action === 'toggle_visibility') {
        $courseid = required_param('courseid', PARAM_INT);
        $cmid = required_param('cmid', PARAM_INT);
        $response = [
            'status' => 'ok',
            'data' => $service->toggle_exam_visibility($courseid, $cmid, (int)$USER->id),
        ];
    } else if ($action === 'update_datetime') {
        $courseid = required_param('courseid', PARAM_INT);
        $cmid = required_param('cmid', PARAM_INT);
        $field = required_param('field', PARAM_ALPHANUMEXT);
        $timestamp = required_param('timestamp', PARAM_INT);
        $response = [
            'status' => 'ok',
            'data' => $service->update_exam_datetime($courseid, $cmid, (int)$USER->id, $field, $timestamp),
        ];
    } else if ($action === 'update_value') {
        $courseid = required_param('courseid', PARAM_INT);
        $cmid = required_param('cmid', PARAM_INT);
        $field = required_param('field', PARAM_ALPHANUMEXT);
        $value = required_param('value', PARAM_FLOAT);
        $response = [
            'status' => 'ok',
            'data' => $service->update_exam_value($courseid, $cmid, (int)$USER->id, $field, (float)$value),
        ];
    } else if ($action === 'archived_exams') {
        $courseid = required_param('courseid', PARAM_INT);
        $overview = $service->get_course_overview($courseid, (int)$USER->id, true);
        $response = [
            'status' => 'ok',
            'data' => [
                'generated' => $overview['generated'],
                'course' => $overview['course'],
                'summary' => $overview['summary'],
                'archivedexams' => $overview['archivedexams'],
            ],
        ];
    } else {
        $courseid = required_param('courseid', PARAM_INT);
        $overview = $service->get_course_overview($courseid, (int)$USER->id, false);
        $response = [
            'status' => 'ok',
            'data' => [
                'generated' => $overview['generated'],
                'course' => $overview['course'],
                'summary' => $overview['summary'],
                'upcomingexams' => $overview['upcomingexams'],
            ],
        ];
    }
} catch (moodle_exception $exception) {
    $response['errorcode'] = $exception->errorcode;

    if ($exception->errorcode === 'accessdeniednoteacher') {
        $response['message'] = get_string('accessdeniednoteacher', 'local_courseexams');
        http_response_code(403);
    } else if ($exception->errorcode === 'invalidcourseid') {
        $response['message'] = get_string('invalidcourseid', 'local_courseexams');
        http_response_code(404);
    } else {
        $response['message'] = get_string('unknownerror', 'local_courseexams');
        error_log('[local_courseexams] Unexpected moodle_exception in ajax.php: ' . $exception);
        http_response_code(400);
    }
} catch (Throwable $throwable) {
    $response['message'] = get_string('unknownerror', 'local_courseexams');
    $response['errorcode'] = 'unexpected_error';
    error_log('[local_courseexams] Unexpected throwable in ajax.php: ' . $throwable);
    http_response_code(500);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
die();
