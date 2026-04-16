<?php
namespace local_courseexams\local\service;

defined('MOODLE_INTERNAL') || die();

use context_course;
use local_courseexams\local\export\single_exam_grade_export;
use moodle_exception;

class exam_catalog {
    public function get_course_overview(int $courseid, int $userid, bool $includearchived = false): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');

        [$course, $context] = $this->validate_course_access($courseid, $userid);

        $modinfo = get_fast_modinfo($course);
        $upcomingexams = [];
        $archivedexams = [];
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

            if (!empty($exam['isarchived'])) {
                if ($includearchived) {
                    $archivedexams[] = $exam;
                }
            } else {
                $upcomingexams[] = $exam;
            }
        }

        $sortexams = function(array $left, array $right): int {
            if ($left['section']['number'] === $right['section']['number']) {
                return [$left['sortdate'], $left['cmid']] <=> [$right['sortdate'], $right['cmid']];
            }

            return $left['section']['number'] <=> $right['section']['number'];
        };

        usort($upcomingexams, $sortexams);
        usort($archivedexams, $sortexams);

        return [
            'generated' => $this->format_datetime_bundle(time()),
            'course' => [
                'id' => (int)$course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context]),
                'shortname' => $course->shortname,
                'categoryid' => (int)$course->category,
                'canexportgrades' => has_capability('moodle/grade:export', $context, $userid) &&
                    has_capability('gradeexport/xls:view', $context, $userid),
                'exportgradesurl' => (new \moodle_url('/grade/export/xls/index.php', ['id' => $course->id]))->out(false),
            ],
            'summary' => $summary,
            'upcomingexams' => $upcomingexams,
            'archivedexams' => $archivedexams,
        ];
    }

    public function search_courses(string $query, int $userid): array {
        global $DB;

        $query = trim($query);
        if (\core_text::strlen($query) < 3) {
            return [];
        }

        $like = '%' . $DB->sql_like_escape($query) . '%';
        $params = [
            'contextcourse' => CONTEXT_COURSE,
            'userid' => $userid,
            'shortname' => $like,
            'fullname' => $like,
        ];
        $wherematch = $DB->sql_like('c.shortname', ':shortname', false, false) .
            ' OR ' . $DB->sql_like('c.fullname', ':fullname', false, false);

        if (preg_match('/^\d+$/', $query)) {
            $params['courseid'] = (int)$query;
            $wherematch = '(c.id = :courseid OR ' . $wherematch . ')';
        } else {
            $wherematch = '(' . $wherematch . ')';
        }

        $sql = "SELECT DISTINCT c.id, c.fullname
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextcourse
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = :userid
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE c.id <> :sitecourseid
                   AND r.shortname IN ('teacher', 'editingteacher')
                   AND {$wherematch}
              ORDER BY c.fullname ASC";
        $params['sitecourseid'] = SITEID;

        $records = $DB->get_records_sql($sql, $params, 0, 10);

        return array_map(function(\stdClass $record): array {
            return [
                'id' => (int)$record->id,
                'fullname' => format_string($record->fullname, true),
            ];
        }, array_values($records));
    }

    public function toggle_exam_visibility(int $courseid, int $cmid, int $userid): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');

        [$course] = $this->validate_course_access($courseid, $userid);
        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);

        if (!in_array($cm->modname, ['assign', 'quiz'], true)) {
            throw new moodle_exception('invalidactivitytype', 'local_courseexams');
        }

        $newvisibility = empty($cm->visible) ? 1 : 0;
        set_coursemodule_visible($cm->id, $newvisibility, $newvisibility);
        rebuild_course_cache($course->id, false, true);

        return [
            'cmid' => (int)$cm->id,
            'visible' => $newvisibility,
            'visiblelabel' => $newvisibility ? get_string('visiblelabel', 'local_courseexams') : get_string('hiddenlabel', 'local_courseexams'),
        ];
    }

    public function update_exam_datetime(int $courseid, int $cmid, int $userid, string $field, int $timestamp): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        [$course] = $this->validate_course_access($courseid, $userid);
        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);

        if ($timestamp < 0) {
            throw new moodle_exception('invaliddatetimevalue', 'local_courseexams');
        }

        if ($cm->modname === 'assign') {
            $allowedfields = ['allowsubmissionsfromdate', 'duedate', 'cutoffdate'];
            if (!in_array($field, $allowedfields, true)) {
                throw new moodle_exception('invaliddatetimefield', 'local_courseexams');
            }

            $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
            $assign->instance = (int)$assign->id;
            $assign->coursemodule = (int)$cm->id;
            $assign->{$field} = $timestamp;
            assign_update_instance($assign, null);
        } else if ($cm->modname === 'quiz') {
            $allowedfields = ['timeopen', 'timeclose'];
            if (!in_array($field, $allowedfields, true)) {
                throw new moodle_exception('invaliddatetimefield', 'local_courseexams');
            }

            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            $this->update_quiz_record($quiz, (int)$cm->id, [
                $field => $timestamp,
            ]);
        } else {
            throw new moodle_exception('invalidactivitytype', 'local_courseexams');
        }

        rebuild_course_cache($course->id, false, true);

        return [
            'cmid' => (int)$cm->id,
            'field' => $field,
            'timestamp' => $timestamp,
            'label' => $this->format_datetime($timestamp),
        ];
    }

    public function update_exam_value(int $courseid, int $cmid, int $userid, string $field, float $value): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');

        [$course] = $this->validate_course_access($courseid, $userid);
        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);

        if ($cm->modname === 'assign') {
            if ($field !== 'grade') {
                throw new moodle_exception('invalidvaluefield', 'local_courseexams');
            }

            if ($value < 0) {
                throw new moodle_exception('invalidnumericvalue', 'local_courseexams');
            }

            $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
            $assign->instance = (int)$assign->id;
            $assign->coursemodule = (int)$cm->id;
            $assign->grade = $value;
            assign_update_instance($assign, null);

            return [
                'cmid' => (int)$cm->id,
                'field' => $field,
                'value' => $value,
                'label' => $this->format_whole_number($value),
            ];
        }

        if ($cm->modname !== 'quiz') {
            throw new moodle_exception('invalidactivitytype', 'local_courseexams');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $updates = [];

        if ($field === 'timelimit') {
            if ($value < 0) {
                throw new moodle_exception('invalidnumericvalue', 'local_courseexams');
            }
            $updates['timelimit'] = (int)round($value * 60);
            $label = $this->format_duration((int)$updates['timelimit']);
            $storedvalue = (int)$updates['timelimit'];
        } else if ($field === 'attempts') {
            if ($value < 0) {
                throw new moodle_exception('invalidnumericvalue', 'local_courseexams');
            }
            $updates['attempts'] = (int)round($value);
            $label = empty($updates['attempts']) ? get_string('unlimited', 'local_courseexams') : (string)$updates['attempts'];
            $storedvalue = (int)$updates['attempts'];
        } else if ($field === 'grade') {
            if ($value < 0) {
                throw new moodle_exception('invalidnumericvalue', 'local_courseexams');
            }
            $updates['grade'] = $value;
            $label = $this->format_whole_number($value);
            $storedvalue = $value;
        } else {
            throw new moodle_exception('invalidvaluefield', 'local_courseexams');
        }

        $this->update_quiz_record($quiz, (int)$cm->id, $updates);

        rebuild_course_cache($course->id, false, true);

        return [
            'cmid' => (int)$cm->id,
            'field' => $field,
            'value' => $storedvalue,
            'label' => $label,
        ];
    }

    public function build_single_exam_grade_export(int $courseid, int $cmid, int $userid): \grade_export_xls {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/grade/export/lib.php');
        require_once($CFG->dirroot . '/grade/export/xls/grade_export_xls.php');

        [$course, $context] = $this->validate_course_access($courseid, $userid);
        require_capability('moodle/grade:export', $context, $userid);
        require_capability('gradeexport/xls:view', $context, $userid);

        $cm = get_coursemodule_from_id('', $cmid, $course->id, false, MUST_EXIST);
        if (!in_array($cm->modname, ['assign', 'quiz'], true)) {
            throw new moodle_exception('invalidactivitytype', 'local_courseexams');
        }

        $downloadfilenamebase = format_string($cm->name, true, ['context' => $cm->context]) . ' - notes';

        $gradeitemid = $DB->get_field('grade_items', 'id', [
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
        ]);

        if (!$gradeitemid) {
            throw new moodle_exception('gradeitemnotfound', 'local_courseexams');
        }

        $formdata = \grade_export::export_bulk_export_data(
            $course->id,
            (string)$gradeitemid,
            0,
            1,
            (string)$CFG->grade_export_displaytype,
            (int)$CFG->grade_export_decimalpoints
        );

        return new single_exam_grade_export($course, 0, $formdata, $downloadfilenamebase);
    }

    private function build_assign_exam(\stdClass $course, \cm_info $cm): array {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $sectionlabel = $this->get_section_label($course, (int)$cm->sectionnum);
        $overrides = $this->get_assign_overrides((int)$assign->id);
        $canexportgrades = has_capability('moodle/grade:export', $cm->context) && has_capability('gradeexport/xls:view', $cm->context);

        return [
            'type' => 'assign',
            'type_label' => get_string('assignmentlabel', 'local_courseexams'),
            'name' => format_string($cm->name, true, ['context' => $cm->context]),
            'activityurl' => $cm->url ? $cm->url->out(false) : '',
            'editurl' => $this->get_activity_edit_url((int)$cm->id),
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$assign->id,
            'exportgradesurl' => $canexportgrades ? $this->get_single_exam_export_url((int)$course->id, (int)$cm->id) : '',
            'visible' => (int)$cm->visible,
            'visible_label' => $cm->visible ? get_string('visiblelabel', 'local_courseexams') : get_string('hiddenlabel', 'local_courseexams'),
            'section' => [
                'number' => (int)$cm->sectionnum,
                'label' => $sectionlabel,
            ],
            'sortdate' => max((int)$assign->allowsubmissionsfromdate, (int)$assign->duedate, 0),
            'endtimestamp' => $this->resolve_assign_endtimestamp($assign),
            'isarchived' => !$cm->visible || $this->is_exam_finished($this->resolve_assign_endtimestamp($assign)),
            'meta' => [
                ['label' => get_string('allowsubmissionsfromdate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->allowsubmissionsfromdate), 'datetimefield' => 'allowsubmissionsfromdate', 'datetimetimestamp' => (int)$assign->allowsubmissionsfromdate],
                ['label' => get_string('duedate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->duedate), 'datetimefield' => 'duedate', 'datetimetimestamp' => (int)$assign->duedate],
                ['label' => get_string('cutoffdate', 'local_courseexams'), 'value' => $this->format_datetime((int)$assign->cutoffdate), 'datetimefield' => 'cutoffdate', 'datetimetimestamp' => (int)$assign->cutoffdate],
                ['label' => get_string('grade', 'local_courseexams'), 'value' => $this->format_whole_number($assign->grade ?? null), 'valuefield' => 'grade', 'valueinputtype' => 'number', 'valueunit' => '', 'valuevalue' => (float)($assign->grade ?? 0), 'valuestep' => '1', 'valuemin' => '0'],
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
        $canexportgrades = has_capability('moodle/grade:export', $cm->context) && has_capability('gradeexport/xls:view', $cm->context);

        return [
            'type' => 'quiz',
            'type_label' => get_string('quizlabel', 'local_courseexams'),
            'name' => format_string($cm->name, true, ['context' => $cm->context]),
            'activityurl' => $cm->url ? $cm->url->out(false) : '',
            'editurl' => $this->get_activity_edit_url((int)$cm->id),
            'cmid' => (int)$cm->id,
            'instanceid' => (int)$quiz->id,
            'exportgradesurl' => $canexportgrades ? $this->get_single_exam_export_url((int)$course->id, (int)$cm->id) : '',
            'visible' => (int)$cm->visible,
            'visible_label' => $cm->visible ? get_string('visiblelabel', 'local_courseexams') : get_string('hiddenlabel', 'local_courseexams'),
            'section' => [
                'number' => (int)$cm->sectionnum,
                'label' => $sectionlabel,
            ],
            'sortdate' => max((int)$quiz->timeopen, (int)$quiz->timeclose, 0),
            'endtimestamp' => max((int)$quiz->timeclose, 0),
            'isarchived' => !$cm->visible || $this->is_exam_finished(max((int)$quiz->timeclose, 0)),
            'meta' => [
                ['label' => get_string('timeopen', 'local_courseexams'), 'value' => $this->format_datetime((int)$quiz->timeopen), 'datetimefield' => 'timeopen', 'datetimetimestamp' => (int)$quiz->timeopen],
                ['label' => get_string('timeclose', 'local_courseexams'), 'value' => $this->format_datetime((int)$quiz->timeclose), 'datetimefield' => 'timeclose', 'datetimetimestamp' => (int)$quiz->timeclose],
                ['label' => get_string('timelimit', 'local_courseexams'), 'value' => $this->format_duration((int)$quiz->timelimit), 'valuefield' => 'timelimit', 'valueinputtype' => 'number', 'valueunit' => 'minutes', 'valuevalue' => (float)(((int)$quiz->timelimit) / 60), 'valuestep' => '1', 'valuemin' => '0'],
                ['label' => get_string('attempts', 'local_courseexams'), 'value' => empty($quiz->attempts) ? get_string('unlimited', 'local_courseexams') : (string)$quiz->attempts, 'valuefield' => 'attempts', 'valueinputtype' => 'number', 'valueunit' => '', 'valuevalue' => (int)$quiz->attempts, 'valuestep' => '1', 'valuemin' => '0'],
                ['label' => get_string('grademax', 'local_courseexams'), 'value' => $this->format_whole_number($quiz->grade ?? null), 'valuefield' => 'grade', 'valueinputtype' => 'number', 'valueunit' => '', 'valuevalue' => (float)($quiz->grade ?? 0), 'valuestep' => '1', 'valuemin' => '0'],
                ['label' => get_string('questionsperpage', 'local_courseexams'), 'value' => (string)($quiz->questionsperpage ?? '')],
                ['label' => get_string('seeallquestions', 'local_courseexams'), 'value' => get_string('linklabel', 'local_courseexams'), 'linkurl' => $this->get_quiz_questions_edit_url((int)$cm->id)],
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

    private function is_exam_finished(int $endtimestamp): bool {
        return $endtimestamp > 0 && $endtimestamp <= time();
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

    private function validate_course_access(int $courseid, int $userid): array {
        global $DB;

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

        require_capability('local/courseexams:view', $context, $userid);

        return [$course, $context];
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

    private function get_quiz_questions_edit_url(int $cmid): string {
        return (new \moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]))->out(false);
    }

    private function update_quiz_record(\stdClass $quiz, int $cmid, array $updates): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $oldquiz = clone $quiz;

        foreach ($updates as $field => $value) {
            $quiz->{$field} = $value;
        }

        $quiz->timemodified = time();
        $DB->update_record('quiz', $quiz);

        $quiz->coursemodule = $cmid;
        quiz_update_events($quiz);
        \core_completion\api::update_completion_date_event($cmid, 'quiz', $quiz->id, null);
        quiz_grade_item_update($quiz);

        if ((float)$oldquiz->grade !== (float)$quiz->grade) {
            quiz_update_all_final_grades($quiz);
            quiz_update_grades($quiz);
        }

        $dateschanged = (int)$oldquiz->timelimit !== (int)$quiz->timelimit ||
            (int)$oldquiz->timeclose !== (int)$quiz->timeclose ||
            (int)$oldquiz->graceperiod !== (int)$quiz->graceperiod;

        if ($dateschanged) {
            quiz_update_open_attempts(['quizid' => $quiz->id]);
        }
    }

    private function get_single_exam_export_url(int $courseid, int $cmid): string {
        return (new \moodle_url('/local/courseexams/export.php', [
            'courseid' => $courseid,
            'cmid' => $cmid,
        ]))->out(false);
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
