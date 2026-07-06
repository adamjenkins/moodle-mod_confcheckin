<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_confcheckin\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Cross-plugin backup/restore integration tests spanning all four Conference Tools
 * plugins in a single course (user request, 2026-07-06: "Also make sure
 * backup/restore/reset all works fine with all plugins").
 *
 * Each plugin already has its own backup/restore test exercising its own cross-activity
 * references against a fresh course of just the activities it directly needs. This file
 * additionally verifies:
 *  1. All four activities together, in one course, still resolve every cross-activity
 *     reference correctly when restored as a whole (confsubmissionscmid,
 *     confprogramcmid on both mod_confscheduler and mod_confcheckin, every
 *     submissionid, and a presenter ticket type's own eligibility check against the
 *     restored chain).
 *  2. A course backup that only includes a SUBSET of these activities degrades
 *     gracefully on restore -- a cross-activity reference into an activity that wasn't
 *     included ends up null/0 (a visibly incomplete but non-corrupt state), rather than
 *     erroring or silently pointing at an unrelated activity.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class restore_full_chain_test extends \restore_date_testcase {
    /**
     * Creates a course containing all four Conference Tools activities, correctly
     * linked, with one accepted, scheduled, presenter-ticket-eligible submission
     * threaded through all of them.
     *
     * @return array{0: \stdClass, 1: int, 2: int} [$course, $submissionid, $tickettypeid]
     */
    private function create_full_chain_course(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['startdate' => $this->startdate]);

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confscheduler = $this->getDataGenerator()->create_module('confscheduler', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);

        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);

        $speaker = $this->getDataGenerator()->create_user();
        $decider = $this->getDataGenerator()->create_user();
        $now = time();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $speaker->id,
            'title'           => 'A Test Talk',
            'abstract'        => 'Abstract text',
            'status'          => 'submitted',
            'timecreated'     => $now,
            'timemodified'    => $now,
        ]);
        \mod_confsubmissions\api::sync_speakers($submissionid, [['userid' => $speaker->id]]);
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $roomid = \mod_confscheduler\api::add_room((int) $confscheduler->id, 'Main Hall');
        \mod_confscheduler\api::add_slot(
            (int) $confscheduler->id,
            [$roomid],
            $this->startdate + (9 * HOURSECS),
            $this->startdate + (9 * HOURSECS) + (30 * MINSECS),
            $submissionid
        );

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'   => $confcheckin->id,
            'name'          => 'Presenter',
            'price'         => '0.00',
            'currency'      => 'USD',
            'presenteronly' => 1,
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);

        return [$course, $submissionid, $tickettypeid];
    }

    /**
     * All four activities, restored together in one course, correctly resolve every
     * cross-activity reference against each other's restored copies -- including a
     * presenter's ticket type eligibility check, which itself depends on
     * mod_confcheckin -> mod_confprogram -> mod_confsubmissions all resolving
     * correctly.
     */
    public function test_full_course_with_all_four_activities_restores_correctly(): void {
        global $DB;

        [$course, $submissionid, $tickettypeid] = $this->create_full_chain_course();
        $speakerid = (int) $DB->get_field('confsubmissions_submission', 'userid', ['id' => $submissionid]);

        $newcourseid = $this->backup_and_restore($course);

        $newconfsubmissions = $DB->get_record('confsubmissions', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfsubmissionscm = get_coursemodule_from_instance('confsubmissions', $newconfsubmissions->id);
        $newconfprogram = $DB->get_record('confprogram', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfprogramcm = get_coursemodule_from_instance('confprogram', $newconfprogram->id);
        $newconfscheduler = $DB->get_record('confscheduler', ['course' => $newcourseid], '*', MUST_EXIST);
        $newconfcheckin = $DB->get_record('confcheckin', ['course' => $newcourseid], '*', MUST_EXIST);

        $this->assertSame((int) $newconfsubmissionscm->id, (int) $newconfprogram->confsubmissionscmid);
        $this->assertSame((int) $newconfprogramcm->id, (int) $newconfscheduler->confprogramcmid);
        $this->assertSame((int) $newconfprogramcm->id, (int) $newconfcheckin->confprogramcmid);

        $newsubmission = $DB->get_record(
            'confsubmissions_submission',
            ['confsubmissions' => $newconfsubmissions->id],
            '*',
            MUST_EXIST
        );
        $newslot = $DB->get_record('confscheduler_slot', ['confscheduler' => $newconfscheduler->id], '*', MUST_EXIST);
        $this->assertSame((int) $newsubmission->id, (int) $newslot->submissionid);

        $newdecision = $DB->get_record('confprogram_decision', ['confprogram' => $newconfprogram->id], '*', MUST_EXIST);
        $this->assertSame((int) $newsubmission->id, (int) $newdecision->submissionid);
        $this->assertSame('accept', $newdecision->decision);

        // The end-to-end proof: the restored presenter's ticket-type eligibility check
        // (which itself walks mod_confcheckin -> mod_confprogram -> mod_confsubmissions)
        // still recognises the speaker as an eligible presenter in the RESTORED course.
        $newtickettype = $DB->get_record(
            'confcheckin_tickettype',
            ['confcheckin' => $newconfcheckin->id],
            '*',
            MUST_EXIST
        );
        $this->assertTrue(
            \mod_confcheckin\local\eligibility::is_eligible_for_tickettype(
                $speakerid,
                $newtickettype,
                (int) $newconfprogramcm->id
            )
        );
    }

    /**
     * A course backup that only includes mod_confsubmissions and mod_confcheckin
     * (deliberately excluding mod_confprogram, the activity confcheckin's own
     * confprogramcmid points at) restores without error, with the unresolvable
     * cross-activity reference left null rather than pointing at an unrelated activity
     * or crashing the restore.
     */
    public function test_partial_activity_selection_degrades_gracefully(): void {
        global $USER, $CFG, $DB;

        [$course, , $tickettypeid] = $this->create_full_chain_course();

        $confprogramcm = $DB->get_record('course_modules', [
            'course' => $course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'confprogram']),
        ], '*', MUST_EXIST);

        $CFG->backup_file_logger_level = \backup::LOG_NONE;
        set_config('backup_general_users', 1, 'backup');
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        // Deliberately excludes mod_confprogram from this backup.
        $bc->get_plan()->get_setting('confprogram_' . $confprogramcm->id . '_included')->set_value(false);
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $fp = get_file_packer('application/vnd.moodle.backup');
        $filepath = $CFG->dataroot . '/temp/backup/test-restore-partial';
        $file->extract_to_pathname($fp, $filepath);
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname,
            $course->shortname . '_partial',
            $course->category
        );
        $rc = new \restore_controller(
            'test-restore-partial',
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        $this->assertFalse($DB->record_exists('confprogram', ['course' => $newcourseid]));

        $newconfcheckin = $DB->get_record('confcheckin', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNull($newconfcheckin->confprogramcmid);

        // The presenteronly ticket type still restores intact; it simply has no usable
        // presenter check without a linked mod_confprogram (a real, already-supported
        // state -- see classes/local/eligibility.php's docblock).
        $newtickettype = $DB->get_record(
            'confcheckin_tickettype',
            ['confcheckin' => $newconfcheckin->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(1, (int) $newtickettype->presenteronly);
    }
}
