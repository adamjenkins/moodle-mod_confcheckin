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
 * Tests for \mod_confcheckin\local\checkin_service: recording a check-in by
 * scanned QR token (success, idempotent re-scan, invalid token, wrong-instance
 * token), and has_checked_in()'s certificate-gating check.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(checkin_service::class)]
final class checkin_service_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance and returns its id.
     *
     * @return int
     */
    private function create_confcheckin(): int {
        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        return (int) $confcheckin->id;
    }

    /**
     * Inserts a confcheckin_tickettype row and returns its id.
     *
     * @param int $confcheckinid
     * @return int
     */
    private function create_tickettype(int $confcheckinid): int {
        global $DB;

        return (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckinid,
            'name'         => 'Test ticket',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Inserts a confcheckin_ticket row with a real, unique qrtoken and returns it.
     *
     * @param int $confcheckinid
     * @param int $tickettypeid
     * @param int $userid
     * @return \stdClass
     */
    private function create_ticket(int $confcheckinid, int $tickettypeid, int $userid): \stdClass {
        global $DB;

        $record = (object) [
            'confcheckin'  => $confcheckinid,
            'tickettypeid' => $tickettypeid,
            'userid'       => $userid,
            'origin'       => 'free',
            'promocodeid'  => null,
            'qrtoken'      => ticket_service::generate_qrtoken(),
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $record->id = $DB->insert_record('confcheckin_ticket', $record);

        return $record;
    }

    /**
     * A fresh scan of a valid ticket in the correct instance records a check-in
     * and returns the ticket holder's details, alreadycheckedin = false.
     */
    public function test_record_checkin_success(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $attendee = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $ticket = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $staff = $this->getDataGenerator()->create_user();

        $result = checkin_service::record_checkin($confcheckinid, $ticket->qrtoken, (int) $staff->id);

        $this->assertSame((int) $ticket->id, $result['ticketid']);
        $this->assertSame('Ada Lovelace', $result['fullname']);
        $this->assertSame('Test ticket', $result['tickettype']);
        $this->assertFalse($result['alreadycheckedin']);

        $checkin = $DB->get_record('confcheckin_checkin', ['ticketid' => $ticket->id]);
        $this->assertNotFalse($checkin);
        $this->assertSame((int) $staff->id, (int) $checkin->scannedby);
    }

    /**
     * Re-scanning an already-checked-in ticket is graceful: it does not insert a
     * second confcheckin_checkin row (the unique index on ticketid would reject
     * that anyway) or throw, and reports alreadycheckedin = true.
     */
    public function test_record_checkin_is_idempotent_on_rescan(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $attendee = $this->getDataGenerator()->create_user();
        $ticket = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $staff = $this->getDataGenerator()->create_user();

        $first = checkin_service::record_checkin($confcheckinid, $ticket->qrtoken, (int) $staff->id);
        $this->assertFalse($first['alreadycheckedin']);

        $second = checkin_service::record_checkin($confcheckinid, $ticket->qrtoken, (int) $staff->id);
        $this->assertTrue($second['alreadycheckedin']);

        $this->assertEquals(1, $DB->count_records('confcheckin_checkin', ['ticketid' => $ticket->id]));
    }

    /**
     * A token that does not match any issued ticket is rejected.
     */
    public function test_record_checkin_rejects_unknown_token(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $staff = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        checkin_service::record_checkin($confcheckinid, 'not-a-real-token-at-all', (int) $staff->id);
    }

    /**
     * A ticket that is real, but issued by a DIFFERENT confcheckin instance, is
     * rejected with a distinct message rather than silently accepted -- otherwise a
     * badge from one event could be checked in at a completely different event.
     */
    public function test_record_checkin_rejects_ticket_from_a_different_instance(): void {
        $this->resetAfterTest();

        $ownconfcheckinid = $this->create_confcheckin();
        $otherconfcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($otherconfcheckinid);
        $attendee = $this->getDataGenerator()->create_user();
        $ticket = $this->create_ticket($otherconfcheckinid, $tickettypeid, (int) $attendee->id);
        $staff = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        checkin_service::record_checkin($ownconfcheckinid, $ticket->qrtoken, (int) $staff->id);
    }

    /**
     * has_checked_in() correctly reflects whether a check-in has been recorded yet
     * -- the check certificate downloads gate on (badge.php/badges.php).
     */
    public function test_has_checked_in(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $attendee = $this->getDataGenerator()->create_user();
        $ticket = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $staff = $this->getDataGenerator()->create_user();

        $this->assertFalse(checkin_service::has_checked_in((int) $ticket->id));

        checkin_service::record_checkin($confcheckinid, $ticket->qrtoken, (int) $staff->id);

        $this->assertTrue(checkin_service::has_checked_in((int) $ticket->id));
    }

    /**
     * set_checkin() (the manual report toggle, 2026-07-08 -- shipped untested,
     * FABLE.md review 2026-07-09) is idempotent in BOTH directions: setting an
     * already-checked-in ticket checked-in creates no second row, clearing an
     * already-clear ticket is a no-op, and a full round trip lands back where
     * it started.
     */
    public function test_set_checkin_idempotent_both_directions(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $attendee = $this->getDataGenerator()->create_user();
        $ticket = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $staff = $this->getDataGenerator()->create_user();

        // Clearing while already clear: no-op.
        checkin_service::set_checkin((int) $ticket->id, false, (int) $staff->id);
        $this->assertSame(0, $DB->count_records('confcheckin_checkin', ['ticketid' => $ticket->id]));

        checkin_service::set_checkin((int) $ticket->id, true, (int) $staff->id);
        $this->assertTrue(checkin_service::has_checked_in((int) $ticket->id));
        $this->assertSame(1, $DB->count_records('confcheckin_checkin', ['ticketid' => $ticket->id]));

        // Setting while already set: still exactly one row.
        checkin_service::set_checkin((int) $ticket->id, true, (int) $staff->id);
        $this->assertSame(1, $DB->count_records('confcheckin_checkin', ['ticketid' => $ticket->id]));

        checkin_service::set_checkin((int) $ticket->id, false, (int) $staff->id);
        $this->assertFalse(checkin_service::has_checked_in((int) $ticket->id));
        $this->assertSame(0, $DB->count_records('confcheckin_checkin', ['ticketid' => $ticket->id]));
    }

    /**
     * get_tickets_by_user() (the check-in report's dataset, 2026-07-08 --
     * shipped untested) groups an instance's tickets by userid, decorating each
     * with its type name and check-in time, and excludes other instances.
     */
    public function test_get_tickets_by_user_groups_and_decorates(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $otherinstanceid = $this->create_confcheckin();
        $othertypeid = $this->create_tickettype($otherinstanceid);

        $attendee = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();
        $ticket1 = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $ticket2 = $this->create_ticket($confcheckinid, $tickettypeid, (int) $attendee->id);
        $this->create_ticket($otherinstanceid, $othertypeid, (int) $attendee->id);

        checkin_service::record_checkin($confcheckinid, $ticket1->qrtoken, (int) $staff->id);

        $byuser = checkin_service::get_tickets_by_user($confcheckinid);

        $this->assertArrayHasKey((int) $attendee->id, $byuser);
        $tickets = $byuser[(int) $attendee->id];
        // Both of THIS instance's tickets, and only those.
        $this->assertCount(2, $tickets);
        $byid = [];
        foreach ($tickets as $ticket) {
            $byid[(int) $ticket->id] = $ticket;
            $this->assertSame('Test ticket', $ticket->tickettypename);
        }
        $this->assertNotNull($byid[(int) $ticket1->id]->checkedintime);
        $this->assertNull($byid[(int) $ticket2->id]->checkedintime);
    }
}
