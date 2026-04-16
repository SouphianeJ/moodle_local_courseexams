<?php
namespace local_courseexams\local\export;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/export/xls/grade_export_xls.php');

class single_exam_grade_export extends \grade_export_xls {
    /** @var string */
    private $downloadfilenamebase;

    public function __construct(\stdClass $course, int $groupid, \stdClass $formdata, string $downloadfilenamebase) {
        parent::__construct($course, $groupid, $formdata);
        $this->downloadfilenamebase = trim($downloadfilenamebase);
    }

    public function print_grades() {
        global $CFG;

        require_once($CFG->dirroot . '/lib/excellib.class.php');

        $export_tracking = $this->track_exports();
        $strgrades = get_string('grades');

        \core_form\util::form_download_complete();

        $downloadfilename = clean_filename(($this->downloadfilenamebase !== '' ? $this->downloadfilenamebase : $strgrades) . '.xls');

        $workbook = new \MoodleExcelWorkbook('-');
        $workbook->send($downloadfilename);
        $myxls = $workbook->add_worksheet($strgrades);

        $profilefields = \grade_helper::get_user_profile_fields($this->course->id, $this->usercustomfields);
        foreach ($profilefields as $id => $field) {
            $myxls->write_string(0, $id, $field->fullname);
        }
        $pos = count($profilefields);
        if (!$this->onlyactive) {
            $myxls->write_string(0, $pos++, get_string("suspended"));
        }
        foreach ($this->columns as $grade_item) {
            foreach ($this->displaytype as $gradedisplayname => $gradedisplayconst) {
                $myxls->write_string(0, $pos++, $this->format_column_name($grade_item, false, $gradedisplayname));
            }
            if ($this->export_feedback) {
                $myxls->write_string(0, $pos++, $this->format_column_name($grade_item, true));
            }
        }
        $myxls->write_string(0, $pos++, get_string('timeexported', 'gradeexport_xls'));

        $i = 0;
        $geub = new \grade_export_update_buffer();
        $gui = new \graded_users_iterator($this->course, $this->columns, $this->groupid);
        $gui->require_active_enrolment($this->onlyactive);
        $gui->allow_user_custom_fields($this->usercustomfields);
        $gui->init();
        while ($userdata = $gui->next_user()) {
            $i++;
            $user = $userdata->user;

            foreach ($profilefields as $id => $field) {
                $fieldvalue = \grade_helper::get_user_field_value($user, $field);
                $myxls->write_string($i, $id, $fieldvalue);
            }
            $j = count($profilefields);
            if (!$this->onlyactive) {
                $issuspended = ($user->suspendedenrolment) ? get_string('yes') : '';
                $myxls->write_string($i, $j++, $issuspended);
            }
            foreach ($userdata->grades as $itemid => $grade) {
                if ($export_tracking) {
                    $geub->track($grade);
                }
                foreach ($this->displaytype as $gradedisplayconst) {
                    $gradestr = $this->format_grade($grade, $gradedisplayconst);
                    if (is_numeric($gradestr)) {
                        $myxls->write_number($i, $j++, $gradestr);
                    } else {
                        $myxls->write_string($i, $j++, $gradestr);
                    }
                }
                if ($this->export_feedback) {
                    $myxls->write_string($i, $j++, $this->format_feedback($userdata->feedbacks[$itemid], $grade));
                }
            }
            $myxls->write_string($i, $j++, time());
        }
        $gui->close();
        $geub->close();

        $workbook->close();

        exit;
    }
}
