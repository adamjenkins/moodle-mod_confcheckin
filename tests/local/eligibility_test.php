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

declare(strict_types=1);

namespace mod_confcheckin\local;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\local\eligibility.
 *
 * Fixture-building mirrors mod_confscheduler\api_test's create_full_fixture()/
 * create_accepted_submission() pattern (a confsubmissions + confprogram + confcheckin
 * chain, with a decision recorded via \mod_confprogram\api::record_decision()), since
 * this is the same cross-plugin chain that plugin's chain-of-custody tests build.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(eligibility::class)]
final class eligibility_test extends advanced_testcase {
    /**
     * Builds a confsubmissions + confprogram fixture and returns their instance records
     * and the confprogram course-module id (what confcheckin.confprogramcmid points at).
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: int} [$confsubmissions, $confprogram, $confprogramcmid]
     */
    private function create_program_fixture(): array {
        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        return [$confsubmissions, $confprogram, (int) $confprogramcm->id];
    }

    /**
     * Creates a submission with the given speaker rows, and optionally records an
     * 'accept' decision for it within the given confprogram instance.
     *
     * @param \stdClass $confsubmissions The confsubmissions instance record
     * @param array $speakers Speaker rows, as \mod_confsubmissions\api::sync_speakers() expects
     * @param \stdClass|null $confprogram If given, an 'accept' decision is recorded for this submission
     * @return int The confsubmissions_submission id
     */
    private function create_submission_with_speakers(
        \stdClass $confsubmissions,
        array $speakers,
        ?\stdClass $confprogram = null
    ): int {
        global $DB;

        $submitter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $submitter->id,
            'title'           => 'Test Talk',
            'abstract'        => 'An abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);

        \mod_confsubmissions\api::sync_speakers($submissionid, $speakers);

        if ($confprogram !== null) {
            $decider = $this->getDataGenerator()->create_user();
            \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);
        }

        return $submissionid;
    }

    /**
     * A user who is a real (userid-backed) speaker on an accepted submission is eligible.
     */
    public function test_accepted_speaker_is_eligible(): void {
        $this->resetAfterTest();

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();

        $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenter->id]],
            $confprogram
        );

        $this->assertTrue(eligibility::is_presenter((int) $presenter->id, $confprogramcmid));
    }

    /**
     * A speaker on a submission that was NOT accepted (no decision recorded at all) is
     * not eligible.
     */
    public function test_speaker_on_undecided_submission_is_not_eligible(): void {
        $this->resetAfterTest();

        [$confsubmissions, , $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();

        $this->create_submission_with_speakers($confsubmissions, [['userid' => $presenter->id]], null);

        $this->assertFalse(eligibility::is_presenter((int) $presenter->id, $confprogramcmid));
    }

    /**
     * A speaker on a REJECTED submission is not eligible.
     */
    public function test_speaker_on_rejected_submission_is_not_eligible(): void {
        $this->resetAfterTest();

        global $DB;
        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();

        $submissionid = $this->create_submission_with_speakers($confsubmissions, [['userid' => $presenter->id]], null);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'reject', 1, (int) $decider->id);

        $this->assertFalse(eligibility::is_presenter((int) $presenter->id, $confprogramcmid));
    }

    /**
     * A user who is not a speaker on any submission at all is not eligible.
     */
    public function test_non_speaker_is_not_eligible(): void {
        $this->resetAfterTest();

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();

        $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenter->id]],
            $confprogram
        );

        $this->assertFalse(eligibility::is_presenter((int) $bystander->id, $confprogramcmid));
    }

    /**
     * A manually-entered co-presenter (name/email, no userid) on an accepted submission
     * never matches ANY real user, even one sharing that submission as a real speaker
     * row -- only userid-backed speaker rows can make someone eligible.
     */
    public function test_manual_entry_speaker_never_matches(): void {
        $this->resetAfterTest();

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $primary = $this->getDataGenerator()->create_user();

        $this->create_submission_with_speakers(
            $confsubmissions,
            [
                ['userid' => $primary->id],
                ['name' => 'Manual Co-Presenter', 'email' => 'manual@example.com'],
            ],
            $confprogram
        );

        // The manual co-presenter has no Moodle account at all, so there is no userid to
        // even check -- what matters is that a bystander user is never accidentally
        // matched against the manual row, and that the primary (real) speaker IS matched.
        $bystander = $this->getDataGenerator()->create_user();
        $this->assertFalse(eligibility::is_presenter((int) $bystander->id, $confprogramcmid));
        $this->assertTrue(eligibility::is_presenter((int) $primary->id, $confprogramcmid));
    }

    /**
     * A null/empty confprogramcmid (no linked mod_confprogram instance) always returns
     * false, without querying anything.
     */
    public function test_no_linked_confprogram_is_never_eligible(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(eligibility::is_presenter((int) $user->id, null));
        $this->assertFalse(eligibility::is_presenter((int) $user->id, 0));
    }

    /**
     * A stale confprogramcmid (course module no longer exists) degrades to false rather
     * than throwing.
     */
    public function test_stale_confprogramcmid_degrades_gracefully(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(eligibility::is_presenter((int) $user->id, 999999));
    }

    /**
     * find_presenter_submission() returns the actual accepted submission record (not
     * just true/false) for an eligible presenter -- consumed by
     * classes/local/placeholder.php's {{submissiontitle}}/{{track}} template
     * placeholders -- and null for anyone is_presenter() would also say false for.
     */
    public function test_find_presenter_submission_returns_the_submission(): void {
        $this->resetAfterTest();

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();

        $submissionid = $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenter->id]],
            $confprogram
        );

        $found = eligibility::find_presenter_submission((int) $presenter->id, $confprogramcmid);
        $this->assertNotNull($found);
        $this->assertSame($submissionid, (int) $found->id);
        $this->assertSame('Test Talk', $found->title);

        $this->assertNull(eligibility::find_presenter_submission((int) $bystander->id, $confprogramcmid));
    }

    /**
     * find_presenter_submissions() returns EVERY accepted submission a user speaks
     * on, not just the first -- consumed by classes/local/placeholder.php's
     * {{presentationinfo}} placeholder to list all of a presenter's presentations.
     * find_presenter_submission() (singular) still only ever returns the first of
     * these, unaffected by there being more than one.
     */
    public function test_find_presenter_submissions_returns_every_accepted_submission(): void {
        $this->resetAfterTest();
        global $DB;

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $presenter = $this->getDataGenerator()->create_user();

        $firstid = $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenter->id]],
            $confprogram
        );

        $secondid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $this->getDataGenerator()->create_user()->id,
            'title'           => 'Second Talk',
            'abstract'        => 'Another abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($secondid, [['userid' => $presenter->id]]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $secondid, 'accept', 1, (int) $decider->id);

        $found = eligibility::find_presenter_submissions((int) $presenter->id, $confprogramcmid);
        $this->assertCount(2, $found);
        $this->assertSame([$firstid, $secondid], array_map(static fn ($s) => (int) $s->id, $found));

        // The singular lookup still only ever returns the first.
        $single = eligibility::find_presenter_submission((int) $presenter->id, $confprogramcmid);
        $this->assertSame($firstid, (int) $single->id);
    }

    /**
     * meets_group_or_enrol_requirement() (user request, 2026-07-06): a ticket type
     * with neither eligibilitygroupid nor eligibilityenrolid set has no restriction
     * at all -- every user meets it.
     */
    public function test_no_group_or_enrol_requirement_means_everyone_is_eligible(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $tickettype = (object) ['eligibilitygroupid' => null, 'eligibilityenrolid' => null];

        $this->assertTrue(eligibility::meets_group_or_enrol_requirement((int) $user->id, $tickettype));
    }

    /**
     * meets_group_or_enrol_requirement() with eligibilitygroupid set: only a member
     * of that specific group meets the requirement.
     */
    public function test_group_requirement_checks_group_membership(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $member = $this->getDataGenerator()->create_user();
        $nonmember = $this->getDataGenerator()->create_user();
        // Groups_add_member() silently no-ops for a user not enrolled in the group's
        // own course -- see \mod_confcheckin\local\ticket_service_test's own note on
        // this same gotcha.
        $this->getDataGenerator()->enrol_user($member->id, $course->id);
        groups_add_member($group->id, $member->id);

        $tickettype = (object) ['eligibilitygroupid' => $group->id, 'eligibilityenrolid' => null];

        $this->assertTrue(eligibility::meets_group_or_enrol_requirement((int) $member->id, $tickettype));
        $this->assertFalse(eligibility::meets_group_or_enrol_requirement((int) $nonmember->id, $tickettype));
    }

    /**
     * meets_group_or_enrol_requirement() with eligibilityenrolid set: only a user
     * enrolled via that specific enrolment method instance meets the requirement.
     */
    public function test_enrol_requirement_checks_enrolment_method(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $enrolled = $this->getDataGenerator()->create_user();
        $unenrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student', 'manual');

        $enrolinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $tickettype = (object) ['eligibilitygroupid' => null, 'eligibilityenrolid' => $enrolinstance->id];

        $this->assertTrue(eligibility::meets_group_or_enrol_requirement((int) $enrolled->id, $tickettype));
        $this->assertFalse(eligibility::meets_group_or_enrol_requirement((int) $unenrolled->id, $tickettype));
    }

    /**
     * is_eligible_for_tickettype() ANDs presenteronly with the group/enrolment
     * requirement: a user must satisfy BOTH if both are configured on the same
     * ticket type, not either alone.
     */
    public function test_is_eligible_for_tickettype_ands_presenteronly_and_group_requirement(): void {
        $this->resetAfterTest();

        [$confsubmissions, $confprogram, $confprogramcmid] = $this->create_program_fixture();
        $group = $this->getDataGenerator()->create_group(['courseid' => $confprogram->course]);

        $presenterinbothgroups = $this->getDataGenerator()->create_user();
        $presenteronly = $this->getDataGenerator()->create_user();
        $groupmemberonly = $this->getDataGenerator()->create_user();

        // Groups_add_member() silently no-ops for a user not enrolled in the group's
        // own course.
        $this->getDataGenerator()->enrol_user($presenterinbothgroups->id, $confprogram->course);
        $this->getDataGenerator()->enrol_user($groupmemberonly->id, $confprogram->course);
        groups_add_member($group->id, $presenterinbothgroups->id);
        groups_add_member($group->id, $groupmemberonly->id);

        $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenterinbothgroups->id]],
            $confprogram
        );
        $this->create_submission_with_speakers(
            $confsubmissions,
            [['userid' => $presenteronly->id]],
            $confprogram
        );

        $tickettype = (object) [
            'presenteronly'      => 1,
            'eligibilitygroupid' => $group->id,
            'eligibilityenrolid' => null,
        ];

        $this->assertTrue(eligibility::is_eligible_for_tickettype(
            (int) $presenterinbothgroups->id,
            $tickettype,
            $confprogramcmid
        ));
        $this->assertFalse(eligibility::is_eligible_for_tickettype(
            (int) $presenteronly->id,
            $tickettype,
            $confprogramcmid
        ));
        $this->assertFalse(eligibility::is_eligible_for_tickettype(
            (int) $groupmemberonly->id,
            $tickettype,
            $confprogramcmid
        ));
    }
}
