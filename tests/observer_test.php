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

namespace mod_confcheckin;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\observer: real-time auto-grant on group join and
 * enrolment (Phase 4.5 follow-up). These fire via Moodle's real event dispatch
 * (db/events.php), not direct method calls, to prove the registration is wired
 * up correctly, not just that the handler logic works in isolation.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(observer::class)]
final class observer_test extends advanced_testcase {
    /**
     * Inserts a confcheckin_tickettype row and returns its id.
     *
     * @param int $confcheckinid
     * @param array $overrides
     * @return int
     */
    private function create_tickettype(int $confcheckinid, array $overrides = []): int {
        global $DB;

        $record = array_merge([
            'confcheckin'   => $confcheckinid,
            'name'          => 'Test ticket',
            'price'         => '0.00',
            'currency'      => 'USD',
            'capacity'      => null,
            'presenteronly' => 0,
            'validfrom'     => null,
            'validto'       => null,
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ], $overrides);

        return (int) $DB->insert_record('confcheckin_tickettype', (object) $record);
    }

    /**
     * Adding a user to a linked group triggers a real \core\event\group_member_added
     * event that this plugin's observer picks up, issuing a 'grant'-origin ticket.
     */
    public function test_joining_a_linked_group_grants_a_ticket(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id, ['groupid' => $group->id]);

        // The groups_add_member() function (called by the generator below) silently no-ops for a
        // user who is not enrolled in the group's course -- a real course
        // participant always is, so a plain create_user() here would never
        // actually join the group, and the observer would never fire.
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);

        $ticket = $DB->get_record('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $user->id]);
        $this->assertNotFalse($ticket);
        $this->assertSame('grant', $ticket->origin);
    }

    /**
     * Joining a group with NO linked ticket type is a no-op (no ticket created for
     * any ticket type in any confcheckin instance).
     */
    public function test_joining_an_unlinked_group_grants_nothing(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $this->create_tickettype((int) $confcheckin->id);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);

        $this->assertEquals(0, $DB->count_records('confcheckin_ticket', ['userid' => $user->id]));
    }

    /**
     * Enrolling a user via a linked enrolment method instance triggers a real
     * \core\event\user_enrolment_created event that this plugin's observer picks
     * up, issuing a 'grant'-origin ticket -- matched by the specific {enrol}.id
     * instance, not merely the enrol plugin name.
     */
    public function test_enrolling_via_a_linked_method_grants_a_ticket(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id, ['enrolid' => $manualinstance->id]);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');

        $ticket = $DB->get_record('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $user->id]);
        $this->assertNotFalse($ticket);
        $this->assertSame('grant', $ticket->origin);
    }

    /**
     * A capacity-exhausted linked ticket type must not let ticket_service's
     * moodle_exception propagate out of the observer and break the group-join
     * operation that triggered it -- observer.php's docblock says this is caught
     * and logged via debugging() rather than thrown; this proves that by
     * exercising the real event dispatch against an already-full ticket type.
     */
    public function test_joining_a_linked_group_with_no_capacity_grants_nothing_and_does_not_throw(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $tickettypeid = $this->create_tickettype(
            (int) $confcheckin->id,
            ['groupid' => $group->id, 'capacity' => 1, 'soldcount' => 1]
        );

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $user->id]);

        $this->assertFalse(
            $DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $user->id])
        );
        $this->assertDebuggingCalled();
    }
}
