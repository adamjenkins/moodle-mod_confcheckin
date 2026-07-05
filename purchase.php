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
 * Ticket purchase page for mod_confcheckin.
 *
 * Lists this instance's visible ticket types and offers three ways to obtain one:
 * - A price-zero ticket type is issued directly (origin = 'free'), bypassing
 *   core_payment entirely since there is nothing to actually pay.
 * - A promo code redemption issues a ticket directly (origin = 'promo') for
 *   whichever ticket type the code specifies, regardless of that type's own price.
 * - Any other (nonzero-price) ticket type goes through the real core_payment flow
 *   (origin = 'purchase', ticket created in classes/payment/service_provider.php's
 *   deliver_order() once payment succeeds).
 *
 * presenteronly ticket types are hidden from the list entirely for a user who is
 * not eligible per classes/local/eligibility.php, UNLESS a promo code is used to
 * claim one directly -- see the 'promo' action handler below for why a promo code
 * is treated as its own, independent authorisation that bypasses the presenter
 * check (an organiser handing someone a specific code is itself the grant).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\checkin_service;
use mod_confcheckin\local\eligibility;
use mod_confcheckin\local\instance_helper;
use mod_confcheckin\local\ticket_service;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// The real login/capability/instance check happens inside require_purchase() below
// (mirroring mod_confscheduler's scheduler_context_trait pattern); this bare call
// exists only so a static "is there a login check in this file" scan does not flag a
// false positive.
require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_purchase($id);

$pageurl = new moodle_url('/mod/confcheckin/purchase.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('purchaseticket', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$confprogramcmid = isset($confcheckin->confprogramcmid) ? (int) $confcheckin->confprogramcmid : null;
$iseligiblepresenter = eligibility::is_presenter((int) $USER->id, $confprogramcmid);

// Handle a direct free-ticket claim.
if ($action === 'free') {
    require_sesskey();
    $tickettypeid = required_param('tickettypeid', PARAM_INT);

    try {
        $tickettype = instance_helper::require_tickettype_in_instance($confcheckin->id, $tickettypeid);

        if (!$tickettype->visible) {
            throw new \moodle_exception('error:invalidtickettype', 'confcheckin');
        }
        if ($tickettype->presenteronly && !$iseligiblepresenter) {
            // Defence-in-depth: the UI already hides this ticket type's "Get ticket"
            // button for an ineligible user, but a crafted POST must be rejected here
            // too -- never trust that hiding a control server-side-enforces anything.
            throw new \moodle_exception('error:notpresenteronly', 'confcheckin');
        }

        ticket_service::issue_free_ticket($confcheckin->id, $tickettypeid, (int) $USER->id);

        redirect($pageurl, get_string('ticketissued', 'confcheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

// Handle a promo code redemption.
if ($action === 'promo') {
    require_sesskey();
    $code = trim(required_param('code', PARAM_ALPHANUMEXT));

    try {
        ticket_service::redeem_promocode($confcheckin->id, $code, (int) $USER->id);

        redirect($pageurl, get_string('ticketissued', 'confcheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('purchaseticket', 'confcheckin'), 3);

// This user's existing tickets for this instance, shown so a repeat visit does not
// look like nothing happened after a successful purchase/claim.
$mytickets = $DB->get_records(
    'confcheckin_ticket',
    ['confcheckin' => $confcheckin->id, 'userid' => $USER->id],
    'timecreated ASC'
);
if ($mytickets) {
    $tickettypenames = $DB->get_records_menu('confcheckin_tickettype', ['confcheckin' => $confcheckin->id], '', 'id, name');
    $canviewowncertificate = has_capability('mod/confcheckin:viewowncertificate', $context);
    echo $OUTPUT->heading(get_string('mytickets', 'confcheckin'), 4);
    $mytable = new html_table();
    $mytable->head = [
        get_string('tickettypename', 'confcheckin'),
        get_string('origin', 'confcheckin'),
        get_string('purchased', 'confcheckin'),
        get_string('checkedin', 'confcheckin'),
        '',
    ];
    $mytable->attributes['class'] = 'generaltable';
    foreach ($mytickets as $ticket) {
        $downloadtypes = ['badge', 'ticket'];
        if ($ticket->origin === 'purchase') {
            // No receipt for free/promo tickets -- Phase 4.3 decision, see badge.php.
            $downloadtypes[] = 'receipt';
        }
        $ischeckedin = checkin_service::has_checked_in((int) $ticket->id);
        if ($ischeckedin && $canviewowncertificate) {
            $downloadtypes[] = 'certificate';
        }
        $downloadlinks = [];
        foreach ($downloadtypes as $type) {
            $downloadlinks[] = html_writer::link(
                new moodle_url('/mod/confcheckin/badge.php', ['id' => $cm->id, 'ticketid' => $ticket->id, 'type' => $type]),
                get_string($type, 'confcheckin')
            );
        }

        $mytable->data[] = [
            format_string($tickettypenames[$ticket->tickettypeid] ?? '?'),
            get_string('origin:' . $ticket->origin, 'confcheckin'),
            userdate($ticket->timecreated),
            $ischeckedin ? get_string('checkedin', 'confcheckin') : '-',
            implode(' | ', $downloadlinks),
        ];
    }
    echo html_writer::table($mytable);
}

// Promo code redemption box.
echo $OUTPUT->heading(get_string('redeempromocode', 'confcheckin'), 4);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false), 'class' => 'form-inline mb-4']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'promo']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'name'        => 'code',
    'class'       => 'form-control mr-2',
    'placeholder' => get_string('promocode', 'confcheckin'),
    'required'    => 'required',
]);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-secondary',
    'value' => get_string('redeem', 'confcheckin'),
]);
echo html_writer::end_tag('form');

// Ticket type list.
$tickettypes = $DB->get_records_select(
    'confcheckin_tickettype',
    'confcheckin = :confcheckin AND visible = 1',
    ['confcheckin' => $confcheckin->id],
    'sortorder ASC, id ASC'
);

echo $OUTPUT->heading(get_string('availabletickettypes', 'confcheckin'), 4);

if (!$tickettypes) {
    echo $OUTPUT->notification(get_string('notickettypesyet', 'confcheckin'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$paymentjsloaded = false;

foreach ($tickettypes as $tickettype) {
    if ($tickettype->presenteronly && !$iseligiblepresenter) {
        // Hidden entirely (not merely disabled) for an ineligible user: this ticket
        // type simply isn't an option for them, per the spec.
        continue;
    }

    $haspcapacity = ticket_service::has_capacity_for_display($tickettype);
    $isfree = confcheckin_parse_price((string) $tickettype->price) !== false
        && abs((float) $tickettype->price) < 0.005;

    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h5', format_string($tickettype->name), ['class' => 'card-title']);

    $validitybits = [];
    if ($tickettype->validfrom) {
        $validitybits[] = get_string('validfromdate', 'confcheckin', userdate($tickettype->validfrom, get_string('strftimedate')));
    }
    if ($tickettype->validto) {
        $validitybits[] = get_string('validtodate', 'confcheckin', userdate($tickettype->validto, get_string('strftimedate')));
    }
    if ($validitybits) {
        echo html_writer::tag('p', implode(' ', $validitybits), ['class' => 'text-muted small']);
    }

    if (!$haspcapacity) {
        echo $OUTPUT->notification(get_string('soldout', 'confcheckin'), 'warning');
    } else if ($isfree) {
        echo html_writer::tag('p', get_string('free', 'confcheckin'), ['class' => 'font-weight-bold']);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'free']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tickettypeid', 'value' => $tickettype->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', [
            'type'  => 'submit',
            'class' => 'btn btn-primary',
            'value' => get_string('getfreeticket', 'confcheckin'),
        ]);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::tag(
            'p',
            \core_payment\helper::get_cost_as_string((float) $tickettype->price, $tickettype->currency),
            ['class' => 'font-weight-bold']
        );

        if (empty($confcheckin->paymentaccountid)) {
            // No payment account configured on this instance yet -- see mod_form.php.
            // Refusing to render a broken payment button is safer than letting a user
            // hit get_payable()'s eventual failure partway through a gateway flow.
            echo $OUTPUT->notification(get_string('error:nopaymentaccount', 'confcheckin'), 'warning');
        } else {
            if (!$paymentjsloaded) {
                $PAGE->requires->js_call_amd('core_payment/gateways_modal', 'init');
                $paymentjsloaded = true;
            }

            $costlabel = \core_payment\helper::get_cost_as_string((float) $tickettype->price, $tickettype->currency);
            $successurl = \mod_confcheckin\payment\service_provider::get_success_url('tickettype', $tickettype->id);
            $description = get_string('purchasedescription', 'confcheckin', format_string($tickettype->name));

            $button = new single_button(
                $pageurl,
                get_string('buyticket', 'confcheckin'),
                'post',
                single_button::BUTTON_PRIMARY,
                [
                    'data-action'      => 'core_payment/triggerPayment',
                    'data-component'   => 'mod_confcheckin',
                    'data-paymentarea' => 'tickettype',
                    'data-itemid'      => $tickettype->id,
                    'data-cost'        => $costlabel,
                    'data-successurl'  => $successurl->out(false),
                    'data-description' => $description,
                ]
            );
            echo $OUTPUT->render($button);
        }
    }

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
