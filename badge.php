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
 * Single-ticket badge/ticket/receipt PDF download for mod_confcheckin (Phase 4.4).
 *
 * Certificate download is deliberately NOT one of the types offered here -- it is
 * gated on a recorded check-in (Phase 4.5), which does not exist yet on this page;
 * see certificate.php once that phase lands.
 *
 * A ticket's own holder can always download it; mod/confcheckin:downloadbadges lets
 * an organiser download ANY ticket in this instance (used by the per-attendee links
 * an organiser might follow from elsewhere, and defence-in-depth alongside the bulk
 * ZIP in badges.php). No receipt is generated for a free/promo-origin ticket --
 * matches Phase 4.3's "no receipt generated for free tickets" decision (nothing was
 * ever paid, so there is nothing to receipt).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\pdf_generator;

$id = required_param('id', PARAM_INT);
$ticketid = required_param('ticketid', PARAM_INT);
$type = optional_param('type', 'badge', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'confcheckin');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
$confcheckin = $DB->get_record('confcheckin', ['id' => $cm->instance], '*', MUST_EXIST);

if (!in_array($type, ['badge', 'ticket', 'receipt'], true)) {
    throw new \moodle_exception('error:invalidtemplatetype', 'confcheckin');
}

$ticket = $DB->get_record('confcheckin_ticket', ['id' => $ticketid, 'confcheckin' => $confcheckin->id]);
if (!$ticket) {
    // Same message regardless of whether the ticket simply doesn't exist or belongs to
    // a different instance -- no enumeration oracle, matching every other instance-
    // scoped lookup in this project (see instance_helper.php's docblock).
    throw new \moodle_exception('error:invalidticket', 'confcheckin');
}

$isowner = (int) $ticket->userid === (int) $USER->id;
if (!$isowner) {
    require_capability('mod/confcheckin:downloadbadges', $context);
}

if ($type === 'receipt' && $ticket->origin !== 'purchase') {
    throw new \moodle_exception('error:noreceiptforfree', 'confcheckin');
}

$tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $ticket->tickettypeid], '*', MUST_EXIST);
$ticketuser = \core_user::get_user((int) $ticket->userid, '*', MUST_EXIST);

$pdf = pdf_generator::build($confcheckin, $tickettype, $ticket, $ticketuser, $type);
$pdf->Output(pdf_generator::filename($confcheckin, $type, $ticket), 'D');
