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

namespace mod_confcheckin\privacy;

use advanced_testcase;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use mod_confcheckin\local\checkin_service;
use mod_confcheckin\local\ticket_service;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\privacy\provider, focused on the Phase 4.5 additions:
 * a confcheckin_checkin row involves both a ticket holder (attendee) and a
 * scannedby (staff member), and the two must be treated differently by
 * export/delete -- see that class's own docblock for the full rationale.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance, one ticket type, and one checked-in ticket.
     *
     * @return array{0: \context_module, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     *     [$context, $confcheckin, $attendee, $staff]
     */
    private function create_checked_in_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('confcheckin', $confcheckin->id);
        $context = \context_module::instance($cm->id);

        $tickettypeid = $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckin->id,
            'name'         => 'Test ticket',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $attendee = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();

        $ticket = (object) [
            'confcheckin'  => $confcheckin->id,
            'tickettypeid' => $tickettypeid,
            'userid'       => $attendee->id,
            'origin'       => 'free',
            'promocodeid'  => null,
            'qrtoken'      => ticket_service::generate_qrtoken(),
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $ticket->id = $DB->insert_record('confcheckin_ticket', $ticket);

        checkin_service::record_checkin((int) $confcheckin->id, $ticket->qrtoken, (int) $staff->id);

        return [$context, $confcheckin, $attendee, $staff];
    }

    /**
     * get_contexts_for_userid() includes a context for the ATTENDEE (ticket holder)
     * and, separately, for the STAFF member who performed the scan -- two different
     * people, two different reasons to be included.
     */
    public function test_get_contexts_for_userid_includes_attendee_and_scanner(): void {
        $this->resetAfterTest();

        [$context, , $attendee, $staff] = $this->create_checked_in_fixture();

        $attendeecontexts = provider::get_contexts_for_userid((int) $attendee->id);
        $this->assertContains((int) $context->id, array_map('intval', $attendeecontexts->get_contextids()));

        $staffcontexts = provider::get_contexts_for_userid((int) $staff->id);
        $this->assertContains((int) $context->id, array_map('intval', $staffcontexts->get_contextids()));
    }

    /**
     * export_user_data() for the ATTENDEE includes their ticket with its check-in
     * timestamp; for the STAFF member, it includes a separate "check-ins performed"
     * list, not the ticket itself (they don't hold one).
     */
    public function test_export_user_data_distinguishes_attendee_and_scanner(): void {
        $this->resetAfterTest();

        [$context, , $attendee, $staff] = $this->create_checked_in_fixture();

        $attendeeapproved = new approved_contextlist($attendee, 'mod_confcheckin', [$context->id]);
        provider::export_user_data($attendeeapproved);
        $ticketkey = get_string('privacy:metadata:confcheckin_ticket', 'confcheckin');
        $attendeedata = writer::with_context($context)->get_data([$ticketkey]);
        $this->assertNotEmpty($attendeedata->tickets);
        $this->assertNotNull($attendeedata->tickets[0]->checkedin);

        writer::reset();

        $staffapproved = new approved_contextlist($staff, 'mod_confcheckin', [$context->id]);
        provider::export_user_data($staffapproved);
        $staffdata = writer::with_context($context)->get_data([get_string('privacy:metadata:confcheckin_checkin', 'confcheckin')]);
        $this->assertNotEmpty($staffdata->checkinsperformed);
    }

    /**
     * delete_data_for_user() for the ATTENDEE removes their own ticket and its
     * check-in row entirely.
     */
    public function test_delete_data_for_user_removes_attendees_own_ticket_and_checkin(): void {
        $this->resetAfterTest();
        global $DB;

        [$context, , $attendee] = $this->create_checked_in_fixture();

        $ticketid = (int) $DB->get_field('confcheckin_ticket', 'id', ['userid' => $attendee->id]);
        $this->assertTrue($DB->record_exists('confcheckin_checkin', ['ticketid' => $ticketid]));

        $approved = new approved_contextlist($attendee, 'mod_confcheckin', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertFalse($DB->record_exists('confcheckin_ticket', ['id' => $ticketid]));
        $this->assertFalse($DB->record_exists('confcheckin_checkin', ['ticketid' => $ticketid]));
    }

    /**
     * delete_data_for_user() for the STAFF member (who only performed a scan on
     * SOMEONE ELSE's ticket) does NOT remove that check-in row -- a deliberate,
     * documented limitation (see provider::class's own docblock): the check-in
     * event belongs to the attendee/ticket, and confcheckin_checkin.scannedby has
     * no NULL/anonymisation path in this schema.
     */
    public function test_delete_data_for_user_does_not_remove_checkin_the_user_only_scanned(): void {
        $this->resetAfterTest();
        global $DB;

        [$context, , $attendee, $staff] = $this->create_checked_in_fixture();

        $ticketid = (int) $DB->get_field('confcheckin_ticket', 'id', ['userid' => $attendee->id]);

        $approved = new approved_contextlist($staff, 'mod_confcheckin', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertTrue($DB->record_exists('confcheckin_ticket', ['id' => $ticketid]));
        $this->assertTrue($DB->record_exists('confcheckin_checkin', ['ticketid' => $ticketid]));
    }

    /**
     * delete_data_for_all_users_in_context() removes every confcheckin_checkin row
     * for the instance BEFORE removing the confcheckin_ticket rows they reference
     * (mirrors lib.php::confcheckin_delete_instance()'s own ordering rationale).
     */
    public function test_delete_data_for_all_users_in_context_removes_checkins_and_tickets(): void {
        $this->resetAfterTest();
        global $DB;

        [$context, $confcheckin] = $this->create_checked_in_fixture();

        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(0, $DB->count_records('confcheckin_ticket', ['confcheckin' => $confcheckin->id]));
        $this->assertSame(0, $DB->count_records('confcheckin_checkin'));
    }
}
