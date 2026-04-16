<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/grade/export/lib.php');
require_once($CFG->dirroot . '/grade/export/xls/grade_export_xls.php');

use local_courseexams\local\service\exam_catalog;

$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

require_login();

$service = new exam_catalog();
$export = $service->build_single_exam_grade_export($courseid, $cmid, (int)$USER->id);

$event = \gradeexport_xls\event\grade_exported::create([
    'context' => context_course::instance($courseid),
]);
$event->trigger();

$export->print_grades();
die();
