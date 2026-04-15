<?php
namespace local_courseexams\local\service;

defined('MOODLE_INTERNAL') || die();

use context_course;
use moodle_exception;

class exam_catalog {
    public function get_course_overview(int $courseid, int $userid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*');
        if (!$course) {
            throw new moodle_exception('invalidcourseid', 'local_courseexams');
        }

        $context = context_course::instance($courseid);
        $roles = get_user_roles($context, $userid, true);
        $allowedroles = ['teacher', 'editingteacher'];
        $hasteacherrole = false;

        foreach ($roles as $role) {
            if (in_array($role->shortname, $allowedroles, true)) {
                $hasteacherrole = true;
                break;
            }
        }

        if (!$hasteacherrole) {
            throw new moodle_exception('accessdeniednoteacher', 'local_courseexams');
        }

        $modinfo = get_fast_modinfo($course);
        $exams = [];
        $summary = [
            'totalexams' => 0,
            'assigncount' => 0,
            'quizcount' => 0,
            'visiblecount' => 0,
            'hiddencount' => 0,
            'overridecount' => 0,
            'quizquestioncount' => 0,
            'upcomingcount' => 0,
            'pastorhiddencount' => 0,
        ];
        $now = time();

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress || !in_array($cm->modname, ['assign', 'quiz'], true)) {
                continue;
            }

            if ($cm->modname === 'assign') {
                $exam = $this->build_assign_exam($course, $cm);
                $summary['assigncount']++;
            } else {
                $exam = $this->build_quiz_exam($course, $cm);
                $summary['quizcount']++;
                $summary['quizquestioncount'] += count($exam['questions']);
            }

            $summary['totalexams']++;
            $summary['overridecount'] += $exam['overrides']['total'];

            if ($exam['visible']) {
                $summary['visiblecount']++;
            } else {
                $summary['hiddencount']++;
            }

            if (!empty($exam['visible']) && ($exam['endtimestamp'] <= 0 || $exam['endtimestamp'] > $now)) {
                $summary['upcomingcount']++;
            } else {
                $summary['pastorhiddencount']++;
            }

            $exams[] = $exam;
        }

        usort($exams, function(array $left, array $right): int {
            if ($left['section']['number'] === $right['section']['number']) {
                return [$left['sortdate'], $left['cmid']] <=> [$right['sortdate'], $right['cmid']];
            }

            return $left['section']['number'] <=> $right['section']['number'];
        });

        return [
            'generated' => $this->format_datetime_bundle(time()),
            'course' => [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'shortname' => $course->shortname,
                'categoryid' => (int)$course->category,
            ],
            'summary' => $summary,
            'exams' => $exams,
        ];
    }

    private function build_assign_exam(\stdClass $course, \cm_info $cm): array {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $sectionlabel = $this->get_section_label($course, (int)$cm->sectionnum);
        $overrides = $this->get_assign_overrides((int)$assign->id);

        return [
            'type' => 'assign',
            'type_label' => get_string('assignmentlabel', 'local_courseexams'),
            'name' => format_string($cm->name, true, ['context' => $cm->context]),
            'activityurl' => $cm->url ? $cm->url->out(false) : '',
            'editurl' => $this->get_activity_edit_url((int)$cm->id),
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$assign->id,
            'visible' => (int)$cm->visible,
            'visible_label' => $cm->visible ? get_string('visiblelabel', 'local_courseexams') : get_string('hiddenlabel', 'local_courseexams'),
            'section' => [
                'number' => (int)$cm->sectionnum,
                'label' => $sectionlabel,
            ],
            'sortdate' => max((int)$assign->allowsubmissionsfromdate, (int)$assign->duedate, 0),
            'endtimestamp' => $this->resolve_assign_endtimestamp($assign),
            'meta' => [
                ['label' => get_string('allowsubmissionsfromdate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->allowsubmissionsfromdate)],
                ['label' => get_string('duedate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->duedate)],
                ['label' => get_string('cutoffdate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->cutoffdate)],
                ['label' => get_string('gradingduedate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->gradingduedate)],
                ['label' => get_string('grade', 'local_courseexams'), 'value' => $this->format_whole_number($assign->grade ?? null)],
                ['label' => get_string('teamsubmission', 'local_courseexams'), 'value' => !empty($assign->teamsubmission) ? get_string('yeslabel', 'local_courseexams') : get_string('nolabel', 'local_courseexams')],
                ['label' => get_string('submissiontypes', 'local_courseexams'), 'value' => $this->get_assign_submission_modes((int)$assign->id)],
                ['label' => get_string('testexam', 'local_courseexams'), 'value' => get_string('linklabel', 'local_courseexams'), 'linkurl' => $cm->url ? $cm->url->out(false) : ''],
            ],
            'overrides' => $overrides,
            'questions' => [],
        ];
    }

    private function build_quiz_exam(\stdClass $course, \cm_info $cm): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $sectionlabel = $this->get_section_label($course, (int)$cm->sectionnum);
        $questions = $this->get_quiz_questions((int)$quiz->id);
        $overrides = $this->get_quiz_overrides((int)$quiz->id);

        return [
            'type' => 'quiz',
            'type_label' => get_string('quizlabel', 'local_courseexams'),
            'name' => format_string($cm->name, true, ['context' => $cm->context]),
            'activityurl' => $cm->url ? $cm->url->out(false) : '',
            'editurl' => $this->get_activity_edit_url((int)$cm->id),
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$quiz->id,
            'visible' => (int)$cm->visible,
            'visible_label' => $cm->visible ? get_string('visiblelabel', 'local_courseexams') : get_string('hiddenlabel', 'local_courseexams'),
            'section' => [
                'number' => (int)$cm->sectionnum,
                'label' => $sectionlabel,
            ],
            'sortdate' => max((int)$quiz->timeopen, (int)$quiz->timeclose, 0),
            'endtimestamp' => max((int)$quiz->timeclose, 0),
            'meta' => [
                ['label' => get_string('timeopen', 'local_courseexams'), 'value' => $this->format_datetime((int)$quiz->timeopen)],
                ['label' => get_string('timeclose', 'local_courseexams'), 'value' => $this->format_datetime((int)$quiz->timeclose)],
                ['label' => get_string('timelimit', 'local_courseexams'), 'value' => $this->format_duration((int)$quiz->timelimit)],
                ['label' => get_string('attempts', 'local_courseexams'), 'value' => empty($quiz->attempts) ? get_string('unlimited', 'local_courseexams') : (string)$quiz->attempts],
                ['label' => get_string('grademax', 'local_courseexams'), 'value' => $this->format_whole_number($quiz->grade ?? null)],
                ['label' => get_string('questionsperpage', 'local_courseexams'), 'value' => (string)($quiz->questionsperpage ?? '')],
                ['label' => get_string('testexam', 'local_courseexams'), 'value' => get_string('linklabel', 'local_courseexams'), 'linkurl' => $cm->url ? $cm->url->out(false) : ''],
            ],
            'overrides' => $overrides,
            'questions' => $questions,
        ];
    }

    private function get_assign_submission_modes(int $assignid): string {
        global $DB;

        $plugins = $DB->get_records('assign_plugin_config', [
            'assignment' => $assignid,
            'subtype' => 'assignsubmission',
            'name' => 'enabled',
        ]);
        $enabled = [];

        foreach ($plugins as $plugin) {
            if ((int)$plugin->value === 1) {
                $enabled[] = $plugin->plugin;
            }
        }

        return $enabled ? implode(', ', $enabled) : '-';
    }

    private function resolve_assign_endtimestamp(\stdClass $assign): int {
        $timestamps = [
            (int)$assign->cutoffdate,
            (int)$assign->duedate,
            (int)$assign->allowsubmissionsfromdate,
        ];

        foreach ($timestamps as $timestamp) {
            if ($timestamp > 0) {
                return $timestamp;
            }
        }

        return 0;
    }

    private function get_assign_overrides(int $assignid): array {
        global $DB;

        $records = $DB->get_records('assign_overrides', ['assignid' => $assignid], 'sortorder ASC, id ASC');
        $userids = [];
        $groupids = [];

        foreach ($records as $record) {
            if (!empty($record->userid)) {
                $userids[] = (int)$record->userid;
            }
            if (!empty($record->groupid)) {
                $groupids[] = (int)$record->groupid;
            }
        }

        $users = $userids ? $DB->get_records_list('user', 'id', array_values(array_unique($userids)), '', 'id,firstname,lastname') : [];
        $groups = $groupids ? $DB->get_records_list('groups', 'id', array_values(array_unique($groupids)), '', 'id,name') : [];

        $useritems = [];
        $groupitems = [];

        foreach ($records as $record) {
            $item = [
                'name' => '',
                'open' => $this->format_datetime((int)$record->allowsubmissionsfromdate),
                'due' => $this->format_datetime((int)$record->duedate),
                'cutoff' => $this->format_datetime((int)$record->cutoffdate),
            ];

            if (!empty($record->userid)) {
                $user = $users[$record->userid] ?? null;
                $item['name'] = $user ? fullname($user) : '#' . $record->userid;
                $useritems[] = $item;
            } else if (!empty($record->groupid)) {
                $group = $groups[$record->groupid] ?? null;
                $item['name'] = $group ? $group->name : '#' . $record->groupid;
                $groupitems[] = $item;
            }
        }

        return [
            'total' => count($records),
            'summary' => [
                get_string('individualoverrides', 'local_courseexams') . ': ' . count($useritems),
                get_string('groupoverrides', 'local_courseexams') . ': ' . count($groupitems),
            ],
            'details' => [
                [
                    'title' => get_string('individualoverrides', 'local_courseexams'),
                    'count' => count($useritems),
                    'columns' => [
                        ['key' => 'name', 'label' => get_string('userlabel', 'local_courseexams')],
                        ['key' => 'open', 'label' => get_string('allowsubmissionsfromdate', 'local_courseexams')],
                        ['key' => 'due', 'label' => get_string('duedate', 'local_courseexams')],
                        ['key' => 'cutoff', 'label' => get_string('cutoffdate', 'local_courseexams')],
                    ],
                    'items' => $useritems,
                ],
                [
                    'title' => get_string('groupoverrides', 'local_courseexams'),
                    'count' => count($groupitems),
                    'columns' => [
                        ['key' => 'name', 'label' => get_string('grouplabel', 'local_courseexams')],
                        ['key' => 'open', 'label' => get_string('allowsubmissionsfromdate', 'local_courseexams')],
                        ['key' => 'due', 'label' => get_string('duedate', 'local_courseexams')],
                        ['key' => 'cutoff', 'label' => get_string('cutoffdate', 'local_courseexams')],
                    ],
                    'items' => $groupitems,
                ],
            ],
        ];
    }

    private function get_quiz_overrides(int $quizid): array {
        global $DB;

        $records = $DB->get_records('quiz_overrides', ['quiz' => $quizid], 'id ASC');
        $userids = [];
        $groupids = [];

        foreach ($records as $record) {
            if (!empty($record->userid)) {
                $userids[] = (int)$record->userid;
            }
            if (!empty($record->groupid)) {
                $groupids[] = (int)$record->groupid;
            }
        }

        $users = $userids ? $DB->get_records_list('user', 'id', array_values(array_unique($userids)), '', 'id,firstname,lastname') : [];
        $groups = $groupids ? $DB->get_records_list('groups', 'id', array_values(array_unique($groupids)), '', 'id,name') : [];

        $useritems = [];
        $groupitems = [];

        foreach ($records as $record) {
            $item = [
                'name' => '',
                'open' => $this->format_datetime((int)$record->timeopen),
                'close' => $this->format_datetime((int)$record->timeclose),
                'timelimit' => $this->format_duration((int)$record->timelimit),
                'attempts' => empty($record->attempts) ? get_string('unlimited', 'local_courseexams') : (string)$record->attempts,
            ];

            if (!empty($record->userid)) {
                $user = $users[$record->userid] ?? null;
                $item['name'] = $user ? fullname($user) : '#' . $record->userid;
                $useritems[] = $item;
            } else if (!empty($record->groupid)) {
                $group = $groups[$record->groupid] ?? null;
                $item['name'] = $group ? $group->name : '#' . $record->groupid;
                $groupitems[] = $item;
            }
        }

        return [
            'total' => count($records),
            'summary' => [
                get_string('individualoverrides', 'local_courseexams') . ': ' . count($useritems),
                get_string('groupoverrides', 'local_courseexams') . ': ' . count($groupitems),
            ],
            'details' => [
                [
                    'title' => get_string('individualoverrides', 'local_courseexams'),
                    'count' => count($useritems),
                    'columns' => [
                        ['key' => 'name', 'label' => get_string('userlabel', 'local_courseexams')],
                        ['key' => 'open', 'label' => get_string('timeopen', 'local_courseexams')],
                        ['key' => 'close', 'label' => get_string('timeclose', 'local_courseexams')],
                        ['key' => 'timelimit', 'label' => get_string('timelimit', 'local_courseexams')],
                        ['key' => 'attempts', 'label' => get_string('attempts', 'local_courseexams')],
                    ],
                    'items' => $useritems,
                ],
                [
                    'title' => get_string('groupoverrides', 'local_courseexams'),
                    'count' => count($groupitems),
                    'columns' => [
                        ['key' => 'name', 'label' => get_string('grouplabel', 'local_courseexams')],
                        ['key' => 'open', 'label' => get_string('timeopen', 'local_courseexams')],
                        ['key' => 'close', 'label' => get_string('timeclose', 'local_courseexams')],
                        ['key' => 'timelimit', 'label' => get_string('timelimit', 'local_courseexams')],
                        ['key' => 'attempts', 'label' => get_string('attempts', 'local_courseexams')],
                    ],
                    'items' => $groupitems,
                ],
            ],
        ];
    }

    private function get_quiz_questions(int $quizid): array {
        $quizobj = \quiz::create($quizid);
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        $questions = [];

        for ($slot = 1; $slot <= $structure->get_question_count(); $slot++) {
            $question = $structure->get_question_in_slot($slot);
            $questions[] = [
                'slot' => $slot,
                'displayednumber' => $structure->get_displayed_number_for_slot($slot),
                'name' => $this->format_question_text($question),
                'qtype' => $question->qtype ?? '',
                'maxmark' => isset($question->maxmark) ? format_float($question->maxmark, 2) : '',
                'page' => $structure->get_page_number_for_slot($slot),
            ];
        }

        return $questions;
    }

    private function get_section_label(\stdClass $course, int $sectionnum): string {
        $modinfo = get_fast_modinfo($course);
        $sectioninfo = $modinfo->get_section_info($sectionnum);

        if ($sectioninfo) {
            return trim(get_section_name($course, $sectioninfo));
        }

        return get_string('section') . ' ' . $sectionnum;
    }

    private function format_datetime_bundle(int $timestamp): array {
        return [
            'timestamp' => $timestamp,
            'label' => $this->format_datetime($timestamp),
        ];
    }

    private function format_datetime(int $timestamp): string {
        if ($timestamp <= 0) {
            return '-';
        }

        return userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig'));
    }

    private function format_duration(int $seconds): string {
        if ($seconds <= 0) {
            return '-';
        }

        return format_time($seconds);
    }

    private function format_whole_number($value): string {
        if ($value === null || $value === '') {
            return '-';
        }

        return (string)(int)round((float)$value);
    }

    private function get_activity_edit_url(int $cmid): string {
        return (new \moodle_url('/course/modedit.php', ['update' => $cmid, 'return' => 1]))->out(false);
    }

    private function format_question_text(\stdClass $question): string {
        $text = '';

        if (!empty($question->questiontext)) {
            $formatted = format_text(
                $question->questiontext,
                $question->questiontextformat ?? FORMAT_HTML,
                ['noclean' => false, 'para' => false, 'context' => null]
            );
            $text = trim(preg_replace('/\s+/', ' ', html_to_text($formatted, 0, false)));
        }

        if ($text !== '') {
            return $text;
        }

        return format_string($question->name ?? '', true);
    }
}
