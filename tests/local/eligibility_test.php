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
}
