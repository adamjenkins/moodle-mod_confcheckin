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

/**
 * Payment subsystem callback implementation for mod_confcheckin.
 *
 * Modeled directly on enrol_fee's classes/payment/service_provider.php. Auto-discovered
 * by \core_payment\helper::get_service_provider_classname(), which looks for exactly
 * "$component\payment\service_provider" implementing
 * \core_payment\local\callback\service_provider -- i.e. this class's fully-qualified
 * name and interface are load-bearing, not just convention.
 *
 * paymentarea/itemid convention (Phase 4.3 decision, see db/install.xml's
 * confcheckin_ticket table comment): paymentarea is always 'tickettype', and itemid is
 * a confcheckin_tickettype.id -- the ticket type being bought, NOT a pre-created
 * pending ticket row. Nothing is inserted into confcheckin_ticket until
 * deliver_order() runs after a successful payment, exactly matching enrol_fee's own
 * pattern of only calling enrol_user() inside deliver_order() rather than
 * pre-creating a pending enrolment row.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_confcheckin\payment;

use mod_confcheckin\local\ticket_service;

/**
 * Payment subsystem callback implementation for mod_confcheckin.
 *
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class service_provider implements \core_payment\local\callback\service_provider {
    /**
     * Looks up the confcheckin_tickettype and its owning confcheckin instance for a
     * given itemid, validating the paymentarea convention along the way.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The confcheckin_tickettype id
     * @return array{0: \stdClass, 1: \stdClass} [$tickettype, $confcheckin]
     * @throws \coding_exception if $paymentarea is not 'tickettype'
     */
    private static function get_tickettype_and_instance(string $paymentarea, int $itemid): array {
        global $DB;

        if ($paymentarea !== 'tickettype') {
            throw new \coding_exception('Unknown payment area for mod_confcheckin: ' . $paymentarea);
        }

        $tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $itemid], '*', MUST_EXIST);
        $confcheckin = $DB->get_record('confcheckin', ['id' => $tickettype->confcheckin], '*', MUST_EXIST);

        return [$tickettype, $confcheckin];
    }

    /**
     * Callback function that returns the ticket type's price and the confcheckin
     * instance's payment account id.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The confcheckin_tickettype id
     * @return \core_payment\local\entities\payable
     */
    public static function get_payable(string $paymentarea, int $itemid): \core_payment\local\entities\payable {
        [$tickettype, $confcheckin] = self::get_tickettype_and_instance($paymentarea, $itemid);

        // A ticket type with a nonzero price but no configured payment account is a
        // misconfiguration purchase.php already refuses to offer a pay button for; if
        // core_payment ever reaches this regardless (e.g. a stale/crafted request), fail
        // loudly rather than passing accountid = 0 through to core_payment, which would
        // resolve to "no account" and produce a confusing downstream error anyway.
        if (empty($confcheckin->paymentaccountid)) {
            throw new \moodle_exception('error:nopaymentaccount', 'confcheckin');
        }

        // The paying user must be able to reach the purchase page at all: itemids are
        // guessable sequential ints and core_payment's gateway AJAX is otherwise open to
        // any logged-in user, so without this any site user who learned an itemid could
        // start (and complete) a checkout for a course they cannot even access
        // (FABLE.md review, 2026-07-09). get_payable() runs as the paying user in every
        // gateway flow, so this is the right chokepoint to stop the checkout before any
        // money moves; issue_purchased_ticket()'s own visibility/eligibility re-checks
        // below remain the defence-in-depth for delivery time.
        $cm = get_coursemodule_from_instance('confcheckin', $confcheckin->id, $confcheckin->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        require_capability('mod/confcheckin:purchase', $context);

        return new \core_payment\local\entities\payable(
            (float) $tickettype->price,
            $tickettype->currency,
            (int) $confcheckin->paymentaccountid
        );
    }

    /**
     * Callback function that returns the URL of the page the user should be redirected
     * to after a successful payment: this ticket type's own confcheckin instance's
     * purchase page.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The confcheckin_tickettype id
     * @return \moodle_url
     */
    public static function get_success_url(string $paymentarea, int $itemid): \moodle_url {
        [$tickettype, $confcheckin] = self::get_tickettype_and_instance($paymentarea, $itemid);

        $cm = get_coursemodule_from_instance('confcheckin', $confcheckin->id, $confcheckin->course, false, MUST_EXIST);

        return new \moodle_url('/mod/confcheckin/purchase.php', ['id' => $cm->id]);
    }

    /**
     * Callback function that delivers what the user paid for: a confcheckin_ticket row
     * (origin = 'purchase') with a freshly-generated qrtoken.
     *
     * Capacity is still enforced here via ticket_service::issue_purchased_ticket() even
     * though purchase.php only ever offers a pay button for a ticket type it believed
     * had remaining capacity at page-render time -- a real gateway checkout can take
     * long enough for capacity to fill up from other purchases in the meantime, and
     * overselling is the one correctness property this plugin must never trade away
     * (see classes/local/ticket_service.php's docblock). If capacity has been
     * exhausted by the time this runs, this returns false: core_payment's own payment
     * record still exists (the gateway has already captured the money), but no ticket
     * is created. There is no automated refund path in this phase -- resolving that
     * rare race is a manual/support action, out of scope here, and considered
     * preferable to either silently overselling the ticket type or throwing a fatal
     * error mid-payment-callback.
     *
     * @param string $paymentarea Payment area
     * @param int $itemid The confcheckin_tickettype id
     * @param int $paymentid payment id as inserted into the 'payments' table, if needed for reference
     * @param int $userid The userid the order is going to deliver to
     * @return bool Whether successful or not
     */
    public static function deliver_order(string $paymentarea, int $itemid, int $paymentid, int $userid): bool {
        [$tickettype, $confcheckin] = self::get_tickettype_and_instance($paymentarea, $itemid);

        try {
            ticket_service::issue_purchased_ticket((int) $confcheckin->id, (int) $tickettype->id, $userid);
            return true;
        } catch (\moodle_exception $e) {
            return false;
        }
    }
}
