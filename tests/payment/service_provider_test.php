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

namespace mod_confcheckin\payment;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\payment\service_provider, modeled on enrol_fee's own
 * tests/payment/service_provider_test.php.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(service_provider::class)]
final class service_provider_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance with a payment account and one paid ticket type.
     *
     * @return array{0: \stdClass, 1: int, 2: \core_payment\account} [$confcheckin, $tickettypeid, $account]
     */
    private function create_fixture(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $account = $generator->get_plugin_generator('core_payment')->create_payment_account(['gateways' => 'paypal']);
        $course = $generator->create_course();

        $confcheckinrecord = $generator->create_module('confcheckin', [
            'course'           => $course->id,
            'paymentaccountid' => $account->get('id'),
        ]);
        $confcheckin = $DB->get_record('confcheckin', ['id' => $confcheckinrecord->id], '*', MUST_EXIST);

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'   => $confcheckin->id,
            'name'          => 'Full conference pass',
            'price'         => '99.50',
            'currency'      => 'USD',
            'presenteronly' => 0,
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        return [$confcheckin, $tickettypeid, $account];
    }

    /**
     * get_payable() returns the ticket type's own price/currency and the instance's
     * configured payment account id.
     */
    public function test_get_payable(): void {
        $this->resetAfterTest();

        [, $tickettypeid, $account] = $this->create_fixture();

        $payable = service_provider::get_payable('tickettype', $tickettypeid);

        $this->assertEqualsWithDelta(99.50, $payable->get_amount(), 0.001);
        $this->assertSame('USD', $payable->get_currency());
        $this->assertSame($account->get('id'), $payable->get_account_id());
    }

    /**
     * get_payable() refuses (rather than silently using accountid 0) when the
     * confcheckin instance has no payment account configured.
     */
    public function test_get_payable_without_account_throws(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckin->id,
            'name'         => 'Ticket',
            'price'        => '10.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        $this->expectException(\moodle_exception::class);
        service_provider::get_payable('tickettype', $tickettypeid);
    }

    /**
     * get_success_url() points at this ticket type's confcheckin instance's purchase page.
     */
    public function test_get_success_url(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        [$confcheckin, $tickettypeid] = $this->create_fixture();
        $cm = get_coursemodule_from_instance('confcheckin', $confcheckin->id);

        $url = service_provider::get_success_url('tickettype', $tickettypeid);

        $this->assertSame(
            $CFG->wwwroot . '/mod/confcheckin/purchase.php?id=' . $cm->id,
            $url->out(false)
        );
    }

    /**
     * deliver_order() creates exactly one confcheckin_ticket row with origin = 'purchase'
     * and a genuinely random-looking qrtoken.
     */
    public function test_deliver_order_creates_one_ticket(): void {
        $this->resetAfterTest();
        global $DB;

        [$confcheckin, $tickettypeid, $account] = $this->create_fixture();
        $user = $this->getDataGenerator()->create_user();

        $paymentid = $this->getDataGenerator()->get_plugin_generator('core_payment')->create_payment([
            'accountid' => $account->get('id'),
            'amount'    => 99.50,
            'userid'    => $user->id,
        ]);

        $result = service_provider::deliver_order('tickettype', $tickettypeid, $paymentid, (int) $user->id);
        $this->assertTrue($result);

        $tickets = $DB->get_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]);
        $this->assertCount(1, $tickets);

        $ticket = reset($tickets);
        $this->assertSame('purchase', $ticket->origin);
        $this->assertSame((int) $user->id, (int) $ticket->userid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket->qrtoken);
    }

    /**
     * deliver_order() returns false (rather than throwing or overselling) when the
     * ticket type's capacity has already been exhausted by the time it runs -- see this
     * class's own docblock for why a rare payment/capacity race degrades this way.
     */
    public function test_deliver_order_returns_false_when_sold_out(): void {
        $this->resetAfterTest();
        global $DB;

        [$confcheckin, $tickettypeid, $account] = $this->create_fixture();
        $DB->set_field('confcheckin_tickettype', 'capacity', 1, ['id' => $tickettypeid]);
        $DB->set_field('confcheckin_tickettype', 'soldcount', 1, ['id' => $tickettypeid]);

        $user = $this->getDataGenerator()->create_user();
        $paymentid = $this->getDataGenerator()->get_plugin_generator('core_payment')->create_payment([
            'accountid' => $account->get('id'),
            'amount'    => 99.50,
            'userid'    => $user->id,
        ]);

        $result = service_provider::deliver_order('tickettype', $tickettypeid, $paymentid, (int) $user->id);
        $this->assertFalse($result);
        $this->assertEquals(0, $DB->count_records('confcheckin_ticket', ['tickettypeid' => $tickettypeid]));
    }
}
