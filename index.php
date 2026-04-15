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
    'strings' => [
        'loading' => get_string('loading', 'local_courseexams'),
        'empty' => get_string('emptyresults', 'local_courseexams'),
        'invalidcourseid' => get_string('invalidcourseid', 'local_courseexams'),
        'coursefullname' => get_string('coursefullname', 'local_courseexams'),
        'exams' => get_string('exams', 'local_courseexams'),
        'upcomingexams' => get_string('upcomingexams', 'local_courseexams'),
        'pastorhiddenexams' => get_string('pastorhiddenexams', 'local_courseexams'),
        'searchminimumchars' => get_string('searchminimumchars', 'local_courseexams'),
        'searchnoresults' => get_string('searchnoresults', 'local_courseexams'),
        'searchresults' => get_string('searchresults', 'local_courseexams'),
        'archivedexams' => get_string('archivedexams', 'local_courseexams'),
        'showarchivedexams' => get_string('showarchivedexams', 'local_courseexams'),
        'overrides' => get_string('overrides', 'local_courseexams'),
        'questions' => get_string('questions', 'local_courseexams'),
        'slot' => get_string('slot', 'local_courseexams'),
        'number' => get_string('number', 'local_courseexams'),
        'question' => get_string('question', 'local_courseexams'),
        'type' => get_string('type', 'local_courseexams'),
        'maxmark' => get_string('maxmark', 'local_courseexams'),
        'page' => get_string('page', 'local_courseexams'),
        'refreshedat' => get_string('refreshedat', 'local_courseexams'),
        'links' => get_string('links', 'local_courseexams'),
        'schedule' => get_string('schedule', 'local_courseexams'),
        'settings' => get_string('settings', 'local_courseexams'),
        'expandrow' => get_string('expandrow', 'local_courseexams'),
    ],
];

$PAGE->requires->js(new moodle_url('/local/courseexams/view.js'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_courseexams/index', [
    'courseid' => $courseid > 0 ? $courseid : '',
    'title' => get_string('pageheading', 'local_courseexams'),
    'subtitle' => get_string('pagedescription', 'local_courseexams'),
    'buttonlabel' => get_string('loadcourse', 'local_courseexams'),
    'fieldlabel' => get_string('searchcourse', 'local_courseexams'),
    'fieldplaceholder' => get_string('searchcourseplaceholder', 'local_courseexams'),
    'refreshhint' => get_string('autorefreshhint', 'local_courseexams'),
    'configjson' => json_encode($jsconfig),
]);
echo $OUTPUT->footer();
