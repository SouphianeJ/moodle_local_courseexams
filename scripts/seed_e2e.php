<?php
define('CLI_SCRIPT', true);

require_once($argv[1] ?? '/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');
require_once($CFG->dirroot . '/lib/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/format/gift/format.php');

global $DB;

$course = $DB->get_record('course', ['shortname' => 'E2E_EXAMS_20260414']);
if (!$course) {
    $course = create_course((object) [
        'fullname' => 'E2E Exams Dashboard Validation',
        'shortname' => 'E2E_EXAMS_20260414',
        'category' => 1,
        'visible' => 1,
        'numsections' => 4,
        'summary' => 'Course used to validate the standalone course exams dashboard plugin.',
    ]);
}

$teacher = upsert_user('e2eteacher', 'Teacher', 'Dashboard', 'e2eteacher@example.test', 'TeacherPass!2026');
$student = upsert_user('e2estudent', 'Student', 'Dashboard', 'e2estudent@example.test', 'StudentPass!2026');

$context = context_course::instance($course->id);
$teacherrole = $DB->get_record('role', ['shortname' => 'teacher'], '*', MUST_EXIST);
$studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);

if (!$DB->record_exists('role_assignments', ['roleid' => $teacherrole->id, 'userid' => $teacher->id, 'contextid' => $context->id])) {
    role_assign($teacherrole->id, $teacher->id, $context->id);
}

if (!$DB->record_exists('role_assignments', ['roleid' => $studentrole->id, 'userid' => $student->id, 'contextid' => $context->id])) {
    role_assign($studentrole->id, $student->id, $context->id);
}

$manual = enrol_get_plugin('manual');
$instance = null;
foreach (enrol_get_instances($course->id, true) as $candidate) {
    if ($candidate->enrol === 'manual') {
        $instance = $candidate;
        break;
    }
}
if (!$instance) {
    $manual->add_default_instance($course);
    foreach (enrol_get_instances($course->id, true) as $candidate) {
        if ($candidate->enrol === 'manual') {
            $instance = $candidate;
            break;
        }
    }
}

$manual->enrol_user($instance, $teacher->id, $teacherrole->id);
$manual->enrol_user($instance, $student->id, $studentrole->id);

$groupa = ensure_group($course->id, 'Dashboard Group A', $teacher->id);
$groupb = ensure_group($course->id, 'Dashboard Group B', $student->id);

$generator = new testing_data_generator();
$questioncategory = ensure_question_category($context->id, 'Dashboard E2E Questions');

$assignone = ensure_module($generator, 'assign', [
    'course' => $course->id,
    'section' => 1,
    'name' => 'Assignment Alpha',
    'intro' => 'Alpha assignment for dashboard validation.',
    'allowsubmissionsfromdate' => strtotime('+1 day'),
    'duedate' => strtotime('+3 days'),
    'cutoffdate' => strtotime('+5 days'),
    'gradingduedate' => strtotime('+8 days'),
    'grade' => 100,
]);

$assigntwo = ensure_module($generator, 'assign', [
    'course' => $course->id,
    'section' => 2,
    'name' => 'Assignment Beta Hidden',
    'intro' => 'Hidden beta assignment for dashboard validation.',
    'allowsubmissionsfromdate' => strtotime('+2 days'),
    'duedate' => strtotime('+6 days'),
    'cutoffdate' => strtotime('+7 days'),
    'gradingduedate' => strtotime('+10 days'),
    'grade' => 50,
    'visible' => 0,
]);

$quizone = ensure_module($generator, 'quiz', [
    'course' => $course->id,
    'section' => 1,
    'name' => 'Quiz Gamma',
    'intro' => 'Gamma quiz for dashboard validation.',
    'timeopen' => strtotime('+1 day'),
    'timeclose' => strtotime('+2 days'),
    'timelimit' => 1800,
    'attempts' => 2,
    'grade' => 20,
    'questionsperpage' => 2,
]);

$quiztwo = ensure_module($generator, 'quiz', [
    'course' => $course->id,
    'section' => 3,
    'name' => 'Quiz Delta',
    'intro' => 'Delta quiz for dashboard validation.',
    'timeopen' => strtotime('+4 days'),
    'timeclose' => strtotime('+5 days'),
    'timelimit' => 2700,
    'attempts' => 1,
    'grade' => 30,
    'questionsperpage' => 1,
]);

$quizonerecord = $DB->get_record('quiz', ['id' => $quizone->instance], '*', MUST_EXIST);
$quiztworecord = $DB->get_record('quiz', ['id' => $quiztwo->instance], '*', MUST_EXIST);

if (!$DB->record_exists('quiz_slots', ['quizid' => $quizonerecord->id])) {
    $questions = ensure_dashboard_questions($course, $questioncategory);
    quiz_add_quiz_question($questions['Quiz Gamma MCQ 1'], $quizonerecord, 1, 5.0);
    quiz_add_quiz_question($questions['Quiz Gamma TF 1'], $quizonerecord, 1, 5.0);
    quiz_add_quiz_question($questions['Quiz Gamma MCQ 2'], $quizonerecord, 2, 10.0);
}

if (!$DB->record_exists('quiz_slots', ['quizid' => $quiztworecord->id])) {
    $questions = ensure_dashboard_questions($course, $questioncategory);
    quiz_add_quiz_question($questions['Quiz Delta TF 1'], $quiztworecord, 1, 10.0);
    quiz_add_quiz_question($questions['Quiz Delta MCQ 1'], $quiztworecord, 2, 20.0);
}

ensure_assign_override($assignone->instance, ['userid' => $teacher->id], [
    'allowsubmissionsfromdate' => strtotime('+12 hours'),
    'duedate' => strtotime('+4 days'),
    'cutoffdate' => strtotime('+6 days'),
]);
ensure_assign_override($assigntwo->instance, ['groupid' => $groupa->id], [
    'allowsubmissionsfromdate' => strtotime('+1 day'),
    'duedate' => strtotime('+8 days'),
    'cutoffdate' => strtotime('+9 days'),
]);

ensure_quiz_override($quizonerecord->id, ['userid' => $teacher->id], [
    'timeopen' => strtotime('+10 hours'),
    'timeclose' => strtotime('+3 days'),
    'timelimit' => 3600,
    'attempts' => 3,
]);
ensure_quiz_override($quiztworecord->id, ['groupid' => $groupb->id], [
    'timeopen' => strtotime('+4 days'),
    'timeclose' => strtotime('+6 days'),
    'timelimit' => 5400,
    'attempts' => 2,
]);

echo json_encode([
    'courseid' => (int)$course->id,
    'teacher' => ['username' => 'e2eteacher', 'password' => 'TeacherPass!2026'],
    'student' => ['username' => 'e2estudent', 'password' => 'StudentPass!2026'],
    'groups' => [
        'a' => ['id' => (int)$groupa->id, 'name' => $groupa->name],
        'b' => ['id' => (int)$groupb->id, 'name' => $groupb->name],
    ],
    'modules' => [
        'assignments' => [
            ['cmid' => (int)$assignone->cmid, 'name' => 'Assignment Alpha'],
            ['cmid' => (int)$assigntwo->cmid, 'name' => 'Assignment Beta Hidden'],
        ],
        'quizzes' => [
            ['cmid' => (int)$quizone->cmid, 'name' => 'Quiz Gamma'],
            ['cmid' => (int)$quiztwo->cmid, 'name' => 'Quiz Delta'],
        ],
    ],
], JSON_PRETTY_PRINT) . PHP_EOL;

function upsert_user(string $username, string $firstname, string $lastname, string $email, string $password): stdClass {
    global $DB;

    $user = $DB->get_record('user', ['username' => $username, 'mnethostid' => 1]);
    if ($user) {
        $user->firstname = $firstname;
        $user->lastname = $lastname;
        $user->email = $email;
        $user->auth = 'manual';
        $user->confirmed = 1;
        $user->suspended = 0;
        $user->password = hash_internal_user_password($password);
        user_update_user($user, false, false);
        return $DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST);
    }

    $user = (object) [
        'username' => $username,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $email,
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => 1,
        'password' => $password,
    ];

    $userid = user_create_user($user, false, false);
    return $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
}

function ensure_group(int $courseid, string $name, int $userid): stdClass {
    global $DB;

    $group = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $name]);
    if (!$group) {
        $groupid = groups_create_group((object) [
            'courseid' => $courseid,
            'name' => $name,
        ]);
        $group = $DB->get_record('groups', ['id' => $groupid], '*', MUST_EXIST);
    }

    if (!groups_is_member($group->id, $userid)) {
        groups_add_member($group->id, $userid);
    }

    return $group;
}

function ensure_question_category(int $contextid, string $name): stdClass {
    global $DB;

    $category = $DB->get_record('question_categories', ['contextid' => $contextid, 'name' => $name]);
    if ($category) {
        return $category;
    }

    $record = [
        'name' => $name,
        'info' => '',
        'infoformat' => FORMAT_HTML,
        'contextid' => $contextid,
        'parent' => question_get_top_category($contextid, true)->id,
        'sortorder' => 999,
        'stamp' => make_unique_id_code(),
    ];
    $record['id'] = $DB->insert_record('question_categories', $record);
    return (object) $record;
}

function ensure_dashboard_questions(stdClass $course, stdClass $questioncategory): array {
    global $DB;

    $expected = [
        'Quiz Gamma MCQ 1',
        'Quiz Gamma TF 1',
        'Quiz Gamma MCQ 2',
        'Quiz Delta TF 1',
        'Quiz Delta MCQ 1',
    ];

    [$insql, $inparams] = $DB->get_in_or_equal($expected, SQL_PARAMS_NAMED);
    $sql = "SELECT q.id, q.name
              FROM {question} q
             WHERE q.category = :categoryid
               AND q.name {$insql}";
    $params = array_merge(['categoryid' => $questioncategory->id], $inparams);
    $records = $DB->get_records_sql($sql, $params);

    $byname = [];
    foreach ($records as $record) {
        $byname[$record->name] = (int)$record->id;
    }

    if (count($byname) === count($expected)) {
        return $byname;
    }

    $gift = <<<'GIFT'
::Quiz Gamma MCQ 1::Which city is the capital of France? {=Paris ~Madrid ~Rome ~Berlin}

::Quiz Gamma TF 1::Moodle is a learning management system.{TRUE}

::Quiz Gamma MCQ 2::Which option is a primary color? {=Blue ~Black ~White ~Grey}

::Quiz Delta TF 1::An assignment can have a cut-off date.{TRUE}

::Quiz Delta MCQ 1::How many minutes are there in one hour? {=60 ~30 ~45 ~90}
GIFT;

    $tmpfile = tempnam(sys_get_temp_dir(), 'gift_');
    file_put_contents($tmpfile, $gift);

    $importer = new qformat_gift();
    $importer->setCategory($questioncategory);
    $importer->setCourse($course);
    $importer->setFilename($tmpfile);
    $importer->setRealfilename('dashboard_e2e.gift.txt');
    $importer->setMatchgrades('error');
    $importer->setCatfromfile(false);
    $importer->setContextfromfile(false);
    $importer->displayprogress = false;

    $result = $importer->importprocess();
    @unlink($tmpfile);

    if (!$result) {
        throw new RuntimeException('Failed to import GIFT questions for dashboard E2E.');
    }

    $records = $DB->get_records_sql($sql, $params);
    $byname = [];
    foreach ($records as $record) {
        $byname[$record->name] = (int)$record->id;
    }

    return $byname;
}

function ensure_module(testing_data_generator $generator, string $modname, array $record): stdClass {
    global $DB;

    if ($modname === 'assign') {
        $instance = $DB->get_record('assign', ['course' => $record['course'], 'name' => $record['name']]);
    } else if ($modname === 'quiz') {
        $instance = $DB->get_record('quiz', ['course' => $record['course'], 'name' => $record['name']]);
    } else {
        $instance = null;
    }

    if ($instance) {
        $cm = get_coursemodule_from_instance($modname, $instance->id, $record['course'], false, MUST_EXIST);
        return (object) [
            'instance' => (int)$instance->id,
            'cmid' => (int)$cm->id,
        ];
    }

    $created = $generator->create_module($modname, $record);
    $cmid = property_exists($created, 'cmid') ? (int)$created->cmid : (int)get_coursemodule_from_instance($modname, $created->id, $record['course'], false, MUST_EXIST)->id;
    $instanceid = property_exists($created, 'id') ? (int)$created->id : (int)$created->instance;

    return (object) [
        'instance' => $instanceid,
        'cmid' => $cmid,
    ];
}

function ensure_assign_override(int $assignid, array $identity, array $fields): void {
    global $DB;

    $existing = $DB->get_record('assign_overrides', ['assignid' => $assignid] + $identity);
    $record = (object) ([
        'assignid' => $assignid,
        'sortorder' => 0,
        'userid' => 0,
        'groupid' => 0,
    ] + $identity + $fields);

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('assign_overrides', $record);
    } else {
        $DB->insert_record('assign_overrides', $record);
    }
}

function ensure_quiz_override(int $quizid, array $identity, array $fields): void {
    global $DB;

    $existing = $DB->get_record('quiz_overrides', ['quiz' => $quizid] + $identity);
    $record = (object) ([
        'quiz' => $quizid,
        'userid' => 0,
        'groupid' => 0,
    ] + $identity + $fields);

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('quiz_overrides', $record);
    } else {
        $DB->insert_record('quiz_overrides', $record);
    }
}
