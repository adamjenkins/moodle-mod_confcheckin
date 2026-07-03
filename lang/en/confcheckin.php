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
 * Language strings for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['confcheckin:addinstance'] = 'Add a new Conference Check-in activity';
$string['confcheckin:downloadbadges'] = 'Bulk-download all attendees\' badge/ticket PDFs';
$string['confcheckin:managetemplates'] = 'Edit badge/ticket/receipt/certificate templates';
$string['confcheckin:managetickettypes'] = 'Manage ticket types and promo codes';
$string['confcheckin:purchase'] = 'Buy or claim a ticket';
$string['confcheckin:scancheckin'] = 'Use the QR scanner to record a check-in';
$string['confcheckin:viewowncertificate'] = 'Download your own attendance certificate';
$string['modulename'] = 'Conference Check-in';
$string['modulename_help'] = 'The Conference Check-in activity sells or issues tickets for a conference, generates QR-coded badges, and records attendance via a QR scanner. Ticket types can be restricted to accepted-submission presenters (via the Conference Program activity), sold with promo codes, and configured with organiser-edited badge/ticket/receipt/certificate templates. Attendees can download an attendance certificate once checked in.';
$string['modulenameplural'] = 'Conference Check-ins';
$string['noinstances'] = 'There are no Conference Check-in activities in this course yet.';
$string['pluginadministration'] = 'Conference Check-in administration';
$string['pluginname'] = 'Conference Check-in';
$string['privacy:metadata'] = 'The Conference Check-in plugin stores personal data about issued tickets and recorded check-ins in its own tables. Ticket type, template and promo code configuration hold no personal data. Payment amount/status are not stored by this plugin at all; they live in core_payment\'s own tables once the payment integration is built.';
$string['privacy:metadata:confcheckin_checkin'] = 'A recorded check-in event for an issued ticket.';
$string['privacy:metadata:confcheckin_checkin:scannedby'] = 'The ID of the user who performed the QR scan that recorded this check-in.';
$string['privacy:metadata:confcheckin_checkin:timecreated'] = 'The time the check-in was recorded.';
$string['privacy:metadata:confcheckin_ticket'] = 'An issued ticket, one row per attendee per Conference Check-in instance.';
$string['privacy:metadata:confcheckin_ticket:origin'] = 'How the ticket was obtained: purchase, free, or promo.';
$string['privacy:metadata:confcheckin_ticket:qrtoken'] = 'The unique token identifying this ticket\'s QR code.';
$string['privacy:metadata:confcheckin_ticket:timecreated'] = 'The time the ticket was issued.';
$string['privacy:metadata:confcheckin_ticket:timemodified'] = 'The time the ticket was last modified.';
$string['privacy:metadata:confcheckin_ticket:userid'] = 'The ID of the user the ticket was issued to.';
$string['scaffoldnotice'] = 'This activity is still under construction. Ticket purchase, badge/certificate downloads, and the QR scanner are not yet available.';
