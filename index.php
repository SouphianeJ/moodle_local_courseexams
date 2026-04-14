<?php
require_once(__DIR__ . '/../../config.php');

$courseid = optional_param('courseid', 0, PARAM_INT);

require_login();

$pageurlparams = [];
if ($courseid > 0) {
    $pageurlparams['courseid'] = $courseid;
}

$PAGE->set_url(new moodle_url('/local/courseexams/index.php', $pageurlparams));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_courseexams'));
$PAGE->set_heading(get_string('pluginname', 'local_courseexams'));
$PAGE->requires->css(new moodle_url('/local/courseexams/styles.css'));

$jsconfig = [
    'ajaxurl' => (new moodle_url('/local/courseexams/ajax.php'))->out(false),
    'sesskey' => sesskey(),
    'initialcourseid' => $courseid,
    'pollintervalms' => 20000,
    'strings' => [
        'loading' => get_string('loading', 'local_courseexams'),
        'empty' => get_string('emptyresults', 'local_courseexams'),
        'invalidcourseid' => get_string('invalidcourseid', 'local_courseexams'),
    ],
];

$PAGE->requires->js(new moodle_url('/local/courseexams/view.js'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_courseexams/index', [
    'courseid' => $courseid > 0 ? $courseid : '',
    'title' => get_string('pageheading', 'local_courseexams'),
    'subtitle' => get_string('pagedescription', 'local_courseexams'),
    'buttonlabel' => get_string('loadcourse', 'local_courseexams'),
    'fieldlabel' => get_string('courseid', 'local_courseexams'),
    'refreshhint' => get_string('autorefreshhint', 'local_courseexams'),
    'configjson' => json_encode($jsconfig),
]);
echo $OUTPUT->footer();
