<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_courseexams\local\service\exam_catalog;

require_login();
require_sesskey();

$courseid = required_param('courseid', PARAM_INT);

$response = [
    'status' => 'error',
    'message' => get_string('unknownerror', 'local_courseexams'),
];

try {
    $service = new exam_catalog();
    $response = [
        'status' => 'ok',
        'data' => $service->get_course_overview($courseid, (int)$USER->id),
    ];
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
