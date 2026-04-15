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
    $response['message'] = $exception->getMessage();
    $response['errorcode'] = $exception->errorcode;

    if ($exception->errorcode === 'accessdeniednoteacher') {
        http_response_code(403);
    } else if ($exception->errorcode === 'invalidcourseid') {
        http_response_code(404);
    } else {
        http_response_code(400);
    }
} catch (Throwable $throwable) {
    $response['message'] = $throwable->getMessage();
    $response['errorcode'] = 'unexpected_error';
    http_response_code(500);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($response);
die();
