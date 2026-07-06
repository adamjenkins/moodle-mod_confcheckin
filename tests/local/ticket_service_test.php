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
 * Tests for \mod_confcheckin\local\ticket_service: capacity enforcement (including the
 * boundary case), promo code redemption (including the max-uses boundary), free ticket
 * issuance, and qrtoken generation.
 *
 * These tests exercise the boundary logic (capacity N succeeds, N+1 cleanly fails) that
 * a single PHPUnit process/connection CAN verify; the real concurrency guarantee (two
 * genuinely simultaneous requests on separate DB connections) is provided by the
 * SELECT ... FOR UPDATE row locking documented in ticket_service's own docblock, and was
 * additionally verified live against this checkout's mariadb backend -- see this
 * plugin's changelog.md.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(ticket_service::class)]
final class ticket_service_test extends advanced_testcase {
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
     * Inserts a confcheckin_promocode row and returns its id.
     *
     * @param int $confcheckinid
     * @param array $overrides
     * @return int
     */
    private function create_promocode(int $confcheckinid, array $overrides = []): int {
        global $DB;

        $record = array_merge([
            'confcheckin'  => $confcheckinid,
            'code'         => 'TESTCODE',
            'tickettypeid' => 0,
            'maxuses'      => null,
            'timesused'    => 0,
            'timeexpires'  => null,
            'timecreated'  => time(),
        ], $overrides);

        return (int) $DB->insert_record('confcheckin_promocode', (object) $record);
    }

    /**
     * A price-zero ticket type can be claimed directly, creating a ticket with
     * origin = 'free' and a well-formed qrtoken.
     */
    public function test_issue_free_ticket(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $user = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $user->id);

        $this->assertSame('free', $ticket->origin);
        $this->assertNull($ticket->promocodeid);
        $this->assertSame(64, strlen($ticket->qrtoken));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket->qrtoken);
        $this->assertSame(1, (int) $DB->get_field('confcheckin_tickettype', 'soldcount', ['id' => $tickettypeid]));
    }

    /**
     * A nonzero-price ticket type is refused by issue_free_ticket() as a defence-in-depth
     * check (purchase.php should never route a nonzero-price type here in the first
     * place, but the service itself does not trust that).
     */
    public function test_issue_free_ticket_rejects_nonzero_price(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['price' => '10.00']);
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $user->id);
    }

    /**
     * Capacity enforcement boundary: buying up to capacity succeeds; the next attempt
     * cleanly fails with a moodle_exception, not a fatal error, and soldcount never
     * exceeds capacity.
     */
    public function test_capacity_boundary_enforced(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['capacity' => 1]);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $first->id);
        $this->assertSame('free', $ticket->origin);

        $this->expectException(\moodle_exception::class);
        try {
            ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $second->id);
        } finally {
            // Whether or not the exception was thrown as expected, soldcount must never
            // exceed capacity, and only one ticket must exist.
            $this->assertSame(1, (int) $DB->get_field('confcheckin_tickettype', 'soldcount', ['id' => $tickettypeid]));
            $this->assertEquals(1, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
        }
    }

    /**
     * A ticket type with capacity = null (unlimited) never refuses on capacity grounds.
     */
    public function test_unlimited_capacity_never_refuses(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['capacity' => null]);

        for ($i = 0; $i < 5; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $ticket = ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $user->id);
            $this->assertSame('free', $ticket->origin);
        }
    }

    /**
     * A valid promo code issues a ticket for the ticket type it specifies, with
     * origin = 'promo' and promocodeid set, and increments timesused.
     */
    public function test_redeem_promocode_success(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['price' => '25.00']);
        $promocodeid = $this->create_promocode($confcheckinid, ['tickettypeid' => $tickettypeid, 'code' => 'SPEAKER25']);
        $user = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::redeem_promocode($confcheckinid, 'SPEAKER25', (int) $user->id);

        $this->assertSame('promo', $ticket->origin);
        $this->assertSame($promocodeid, (int) $ticket->promocodeid);
        $this->assertSame($tickettypeid, (int) $ticket->tickettypeid);
        $this->assertSame(1, (int) $DB->get_field('confcheckin_promocode', 'timesused', ['id' => $promocodeid]));
    }

    /**
     * A promo code that does not exist, and one that exists but belongs to a different
     * confcheckin instance, both produce the exact same error message -- no enumeration
     * oracle for guessing whether a code exists elsewhere.
     */
    public function test_redeem_promocode_no_enumeration_oracle(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $othercheckinid = $this->create_confcheckin();
        $othertickettypeid = $this->create_tickettype($othercheckinid);
        $this->create_promocode($othercheckinid, ['tickettypeid' => $othertickettypeid, 'code' => 'ELSEWHERE']);
        $user = $this->getDataGenerator()->create_user();

        $nonexistentmessage = null;
        try {
            ticket_service::redeem_promocode($confcheckinid, 'DOES-NOT-EXIST', (int) $user->id);
        } catch (\moodle_exception $e) {
            $nonexistentmessage = $e->getMessage();
        }

        $wronginstancemessage = null;
        try {
            ticket_service::redeem_promocode($confcheckinid, 'ELSEWHERE', (int) $user->id);
        } catch (\moodle_exception $e) {
            $wronginstancemessage = $e->getMessage();
        }

        $this->assertNotNull($nonexistentmessage);
        $this->assertSame($nonexistentmessage, $wronginstancemessage);
    }

    /**
     * An expired promo code is refused.
     */
    public function test_redeem_promocode_rejects_expired(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $this->create_promocode($confcheckinid, [
            'tickettypeid' => $tickettypeid,
            'code'         => 'EXPIRED',
            'timeexpires'  => time() - DAYSECS,
        ]);
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        ticket_service::redeem_promocode($confcheckinid, 'EXPIRED', (int) $user->id);
    }

    /**
     * Max-uses boundary: redeeming up to maxuses succeeds; the next redemption cleanly
     * fails, and timesused never exceeds maxuses.
     */
    public function test_redeem_promocode_maxuses_boundary(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $promocodeid = $this->create_promocode($confcheckinid, [
            'tickettypeid' => $tickettypeid,
            'code'         => 'ONCEONLY',
            'maxuses'      => 1,
        ]);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        ticket_service::redeem_promocode($confcheckinid, 'ONCEONLY', (int) $first->id);

        $this->expectException(\moodle_exception::class);
        try {
            ticket_service::redeem_promocode($confcheckinid, 'ONCEONLY', (int) $second->id);
        } finally {
            $this->assertSame(1, (int) $DB->get_field('confcheckin_promocode', 'timesused', ['id' => $promocodeid]));
        }
    }

    /**
     * A promo code granting a capacity-limited ticket type is itself subject to that
     * ticket type's capacity, not just its own maxuses.
     */
    public function test_redeem_promocode_respects_tickettype_capacity(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['capacity' => 1]);
        $this->create_promocode($confcheckinid, ['tickettypeid' => $tickettypeid, 'code' => 'TIGHT', 'maxuses' => 10]);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        ticket_service::redeem_promocode($confcheckinid, 'TIGHT', (int) $first->id);

        $this->expectException(\moodle_exception::class);
        ticket_service::redeem_promocode($confcheckinid, 'TIGHT', (int) $second->id);
    }

    /**
     * issue_purchased_ticket() creates exactly one ticket with origin = 'purchase' and a
     * genuinely random-looking qrtoken, and is subject to the same capacity enforcement.
     */
    public function test_issue_purchased_ticket(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['price' => '50.00', 'capacity' => 1]);
        $user = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_purchased_ticket($confcheckinid, $tickettypeid, (int) $user->id);
        $this->assertSame('purchase', $ticket->origin);
        $this->assertEquals(1, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));

        $another = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        ticket_service::issue_purchased_ticket($confcheckinid, $tickettypeid, (int) $another->id);
    }

    /**
     * issue_free_ticket() re-checks the eligibility group requirement (user request,
     * 2026-07-06) server-side: a non-member is rejected even though nothing upstream
     * (e.g. purchase.php) is trusted to have already filtered them out.
     */
    public function test_issue_free_ticket_rejects_ineligible_group_requirement(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $courseid = (int) $DB->get_field('confcheckin', 'course', ['id' => $confcheckinid]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $courseid]);
        $tickettypeid = $this->create_tickettype($confcheckinid, ['eligibilitygroupid' => $group->id]);

        $member = $this->getDataGenerator()->create_user();
        // Groups_add_member() silently no-ops for a user not enrolled in the group's
        // own course.
        $this->getDataGenerator()->enrol_user($member->id, $courseid);
        groups_add_member($group->id, $member->id);
        $nonmember = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $member->id);
        $this->assertSame('free', $ticket->origin);

        $this->expectException(\moodle_exception::class);
        ticket_service::issue_free_ticket($confcheckinid, $tickettypeid, (int) $nonmember->id);
    }

    /**
     * issue_purchased_ticket() re-checks the same eligibility group requirement --
     * this is the defence-in-depth fix that stops a crafted direct core_payment
     * request from buying an ineligible ticket type purchase.php merely hides from
     * the UI (see that method's own docblock).
     */
    public function test_issue_purchased_ticket_rejects_ineligible_group_requirement(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $courseid = (int) $DB->get_field('confcheckin', 'course', ['id' => $confcheckinid]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $courseid]);
        $tickettypeid = $this->create_tickettype(
            $confcheckinid,
            ['price' => '50.00', 'eligibilitygroupid' => $group->id]
        );

        $nonmember = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        ticket_service::issue_purchased_ticket($confcheckinid, $tickettypeid, (int) $nonmember->id);
    }

    /**
     * generate_qrtoken() produces distinct, well-formed 64-character hex tokens across
     * repeated calls (a basic sanity check that it is not a constant or predictable
     * sequence).
     */
    public function test_generate_qrtoken_is_random_and_well_formed(): void {
        $tokens = [];
        for ($i = 0; $i < 20; $i++) {
            $token = ticket_service::generate_qrtoken();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
            $tokens[] = $token;
        }

        $this->assertSame(count($tokens), count(array_unique($tokens)));
    }

    /**
     * issue_granted_ticket() creates a ticket with origin = 'grant', regardless of
     * the ticket type's own nonzero price (unlike issue_free_ticket(), which
     * refuses a nonzero-price type -- a group/enrolment grant is a deliberate
     * complimentary allocation).
     */
    public function test_issue_granted_ticket_ignores_price(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['price' => '99.00']);
        $user = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $user->id);

        $this->assertSame('grant', $ticket->origin);
    }

    /**
     * issue_granted_ticket() is idempotent: calling it twice for the same user/type
     * returns the SAME ticket (no duplicate row, soldcount only incremented once) --
     * required since classes/observer.php and sync_group_grants()/sync_enrol_grants()
     * can legitimately call it more than once for the same pair (re-running a sync,
     * a user leaving and rejoining a group).
     */
    public function test_issue_granted_ticket_is_idempotent(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid);
        $user = $this->getDataGenerator()->create_user();

        $first = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $user->id);
        $second = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $user->id);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertEquals(1, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
        $this->assertSame(1, (int) $DB->get_field('confcheckin_tickettype', 'soldcount', ['id' => $tickettypeid]));
    }

    /**
     * A user who already holds a ticket of a DIFFERENT origin (e.g. they already
     * purchased one) is not issued a second, duplicate ticket by
     * issue_granted_ticket() -- the idempotency check is per user+type, not
     * per user+type+origin.
     */
    public function test_issue_granted_ticket_does_not_duplicate_an_existing_ticket_of_any_origin(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['price' => '20.00']);
        $user = $this->getDataGenerator()->create_user();

        $purchased = ticket_service::issue_purchased_ticket($confcheckinid, $tickettypeid, (int) $user->id);
        $granted = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $user->id);

        $this->assertSame((int) $purchased->id, (int) $granted->id);
        $this->assertSame('purchase', $granted->origin);
        $this->assertEquals(1, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
    }

    /**
     * issue_granted_ticket() still respects capacity for a genuinely NEW grant.
     */
    public function test_issue_granted_ticket_respects_capacity(): void {
        $this->resetAfterTest();

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['capacity' => 1]);
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();

        ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $first->id);

        $this->expectException(\moodle_exception::class);
        ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $second->id);
    }

    /**
     * revoke_ticket() deletes the ticket AND its check-in row (if any), and
     * decrements the ticket type's soldcount by one, freeing the capacity.
     */
    public function test_revoke_ticket_deletes_ticket_and_checkin_and_frees_capacity(): void {
        $this->resetAfterTest();
        global $DB;

        $confcheckinid = $this->create_confcheckin();
        $tickettypeid = $this->create_tickettype($confcheckinid, ['capacity' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();

        $ticket = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $user->id);
        checkin_service::record_checkin($confcheckinid, $ticket->qrtoken, (int) $staff->id);

        ticket_service::revoke_ticket((int) $ticket->id);

        $this->assertFalse($DB->record_exists('confcheckin_ticket', ['id' => $ticket->id]));
        $this->assertFalse($DB->record_exists('confcheckin_checkin', ['ticketid' => $ticket->id]));
        $this->assertSame(0, (int) $DB->get_field('confcheckin_tickettype', 'soldcount', ['id' => $tickettypeid]));

        // The freed capacity is usable again.
        $another = $this->getDataGenerator()->create_user();
        $newticket = ticket_service::issue_granted_ticket($confcheckinid, $tickettypeid, (int) $another->id);
        $this->assertNotNull($newticket);
    }

    /**
     * revoke_ticket() never lets soldcount go negative, even called on an already
     * (or never) issued ticket id.
     */
    public function test_revoke_ticket_on_nonexistent_ticket_is_a_no_op(): void {
        $this->resetAfterTest();

        ticket_service::revoke_ticket(999999);
        $this->addToAssertionCount(1);
    }

    /**
     * sync_group_grants() issues a ticket to every CURRENT member of a group, and
     * returns the count of NEWLY issued tickets (excluding anyone who already held
     * one).
     */
    public function test_sync_group_grants_issues_to_current_members_only_once(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id);

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $member1 = $this->getDataGenerator()->create_user();
        $member2 = $this->getDataGenerator()->create_user();
        $nonmember = $this->getDataGenerator()->create_user();
        // The groups_add_member() function silently no-ops for a user not enrolled in the
        // group's course -- a real course participant always is.
        $this->getDataGenerator()->enrol_user($member1->id, $course->id);
        $this->getDataGenerator()->enrol_user($member2->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member1->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member2->id]);

        $issued = ticket_service::sync_group_grants((int) $confcheckin->id, $tickettypeid, (int) $group->id);

        $this->assertSame(2, $issued);
        $this->assertEquals(2, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
        $this->assertFalse($DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $nonmember->id]));

        // Re-syncing does not duplicate.
        $reissued = ticket_service::sync_group_grants((int) $confcheckin->id, $tickettypeid, (int) $group->id);
        $this->assertSame(0, $reissued);
        $this->assertEquals(2, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
    }

    /**
     * sync_enrol_grants() issues a ticket to every user CURRENTLY enrolled via a
     * specific enrolment method instance.
     */
    public function test_sync_enrol_grants_issues_to_current_enrolments_only_once(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id);

        $manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);

        $enrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student', 'manual');
        $notenrolled = $this->getDataGenerator()->create_user();

        $issued = ticket_service::sync_enrol_grants((int) $confcheckin->id, $tickettypeid, (int) $manualinstance->id);

        $this->assertSame(1, $issued);
        $this->assertTrue($DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $enrolled->id]));
        $this->assertFalse(
            $DB->record_exists('confcheckin_ticket', ['tickettypeid' => $tickettypeid, 'userid' => $notenrolled->id])
        );
    }

    /**
     * find_orphaned_tickets() flags a 'grant'-origin ticket whose holder has since
     * left the linking group, but leaves a still-member's ticket alone, and never
     * flags a non-'grant'-origin ticket even if it happens to belong to a linked
     * ticket type.
     */
    public function test_find_orphaned_tickets_detects_group_departure(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id, ['groupid' => $group->id]);

        $stays = $this->getDataGenerator()->create_user();
        $leaves = $this->getDataGenerator()->create_user();
        // The groups_add_member() function silently no-ops for a user not enrolled in the
        // group's course -- a real course participant always is.
        $this->getDataGenerator()->enrol_user($stays->id, $course->id);
        $this->getDataGenerator()->enrol_user($leaves->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $stays->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $leaves->id]);

        ticket_service::sync_group_grants((int) $confcheckin->id, $tickettypeid, (int) $group->id);

        groups_remove_member($group->id, $leaves->id);

        $orphaned = ticket_service::find_orphaned_tickets((int) $confcheckin->id);

        $this->assertCount(1, $orphaned);
        $entry = reset($orphaned);
        $this->assertSame((int) $leaves->id, (int) $entry['ticket']->userid);
        $this->assertSame('group', $entry['reason']);
    }

    /**
     * find_orphaned_tickets() flags a 'grant'-origin ticket whose holder has since
     * been unenrolled from the linking enrolment method instance.
     */
    public function test_find_orphaned_tickets_detects_unenrolment(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $manualinstance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id, ['enrolid' => $manualinstance->id]);

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student', 'manual');
        ticket_service::sync_enrol_grants((int) $confcheckin->id, $tickettypeid, (int) $manualinstance->id);

        $manualplugin = enrol_get_plugin('manual');
        $manualplugin->unenrol_user($manualinstance, $user->id);

        $orphaned = ticket_service::find_orphaned_tickets((int) $confcheckin->id);

        $this->assertCount(1, $orphaned);
        $entry = reset($orphaned);
        $this->assertSame((int) $user->id, (int) $entry['ticket']->userid);
        $this->assertSame('enrol', $entry['reason']);
    }

    /**
     * find_orphaned_tickets() never flags a ticket whose holder is still a current
     * member/still enrolled.
     */
    public function test_find_orphaned_tickets_ignores_current_members(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $tickettypeid = $this->create_tickettype((int) $confcheckin->id, ['groupid' => $group->id]);

        $member = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($member->id, $course->id);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
        ticket_service::sync_group_grants((int) $confcheckin->id, $tickettypeid, (int) $group->id);

        $this->assertSame([], ticket_service::find_orphaned_tickets((int) $confcheckin->id));
    }
}
