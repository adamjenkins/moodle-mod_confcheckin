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
$string['addpromocode'] = 'Add promo code';
$string['addtickettype'] = 'Add ticket type';
$string['availabletickettypes'] = 'Available ticket types';
$string['badge'] = 'Badge';
$string['buyticket'] = 'Buy ticket';
$string['capacity'] = 'Capacity';
$string['capacity_help'] = 'The maximum number of tickets of this type that can ever be issued. Leave blank for no limit.';
$string['certificate'] = 'Certificate';
$string['confcheckin:addinstance'] = 'Add a new Conference Check-in activity';
$string['confcheckin:downloadbadges'] = 'Bulk-download all attendees\' badge/ticket PDFs';
$string['confcheckin:managetemplates'] = 'Edit badge/ticket/receipt/certificate templates';
$string['confcheckin:managetickettypes'] = 'Manage ticket types and promo codes';
$string['confcheckin:purchase'] = 'Buy or claim a ticket';
$string['confcheckin:scancheckin'] = 'Use the QR scanner to record a check-in';
$string['confcheckin:viewowncertificate'] = 'Download your own attendance certificate';
$string['confirmdeletepromocode'] = 'Delete the promo code "{$a}"? Tickets already issued with it are not affected.';
$string['confirmdeletetickettype'] = 'Delete the ticket type "{$a}"? Tickets already issued for it are not affected.';
$string['confprogramcmid'] = 'Conference Program activity';
$string['confprogramcmid_help'] = 'The Conference Program activity to check presenter-ticket eligibility against: a user is eligible for a "presenter only" ticket type if they are a listed speaker (by their Moodle account, not a manually-entered co-presenter) on at least one submission that activity has accepted. Optional -- if left unset, "presenter only" ticket types can never be bought or claimed by anyone.';
$string['currency'] = 'Currency';
$string['downloadall'] = 'Download all {$a}s';
$string['editpromocode'] = 'Edit promo code';
$string['edittickettype'] = 'Edit ticket type';
$string['error:invalidcapacity'] = 'Capacity must be a whole number of 1 or more, or left blank for unlimited.';
$string['error:invalidconfprogramcmid'] = 'That is not a Conference Program activity in this course.';
$string['error:invalidcurrency'] = 'Choose a valid currency.';
$string['error:invalidmaxuses'] = 'Maximum uses must be a whole number of 1 or more, or left blank for unlimited.';
$string['error:invalidprice'] = 'Enter a valid, non-negative price, e.g. 0.00 or 49.99.';
$string['error:invalidpromocode'] = 'That promo code is not valid.';
$string['error:invalidtemplatetype'] = 'That is not a recognised template type.';
$string['error:invalidticket'] = 'That ticket could not be found.';
$string['error:invalidtickettype'] = 'That ticket type could not be found.';
$string['error:nopaymentaccount'] = 'This activity has no payment account configured yet, so paid ticket types cannot currently be purchased. Contact the course organiser.';
$string['error:noreceiptforfree'] = 'No receipt is generated for a free or promo-code ticket, since nothing was paid.';
$string['error:notpresenteronly'] = 'That ticket type is only available to eligible presenters.';
$string['error:promocodeexhausted'] = 'That promo code has already been used the maximum number of times.';
$string['error:promocodeexpired'] = 'That promo code has expired.';
$string['error:promocodenotunique'] = 'That code is already in use for this activity. Choose a different code.';
$string['error:tickettypenotfree'] = 'That ticket type is not free.';
$string['error:tickettypesoldout'] = 'That ticket type has no tickets remaining.';
$string['error:validtobeforevalidfrom'] = '"Valid to" cannot be before "Valid from".';
$string['free'] = 'Free';
$string['getfreeticket'] = 'Get free ticket';
$string['grantsticketype'] = 'Grants ticket type';
$string['managepromocodes'] = 'Manage promo codes';
$string['managetemplates'] = 'Manage badge/ticket/receipt/certificate templates';
$string['managetickettypes'] = 'Manage ticket types';
$string['maxuses'] = 'Maximum uses';
$string['maxuses_help'] = 'The maximum number of times this code can be redeemed in total. Leave blank for no limit.';
$string['modulename'] = 'Conference Check-in';
$string['modulename_help'] = 'The Conference Check-in activity sells or issues tickets for a conference, generates QR-coded badges, and records attendance via a QR scanner. Ticket types can be restricted to accepted-submission presenters (via the Conference Program activity), sold with promo codes, and configured with organiser-edited badge/ticket/receipt/certificate templates. Attendees can download an attendance certificate once checked in.';
$string['modulenameplural'] = 'Conference Check-ins';
$string['mytickets'] = 'Your tickets';
$string['noinstances'] = 'There are no Conference Check-in activities in this course yet.';
$string['nopromocodes'] = 'No promo codes have been added yet.';
$string['notickets'] = 'No tickets have been issued yet.';
$string['notickettypes'] = 'No ticket types have been added yet.';
$string['notickettypesyet'] = 'No ticket types have been added yet. Add one first.';
$string['origin'] = 'How obtained';
$string['origin:free'] = 'Free';
$string['origin:promo'] = 'Promo code';
$string['origin:purchase'] = 'Purchased';
$string['paymentaccountid'] = 'Payment account';
$string['paymentaccountid_help'] = 'The payment account paid ticket types in this instance are payable to. Only needed if you plan to sell a ticket type with a nonzero price; free and promo-code ticket types never use this.';
$string['pluginadministration'] = 'Conference Check-in administration';
$string['pluginname'] = 'Conference Check-in';
$string['presenteronly'] = 'Presenter-only';
$string['presenteronly_help'] = 'Restrict this ticket type to users who are a listed speaker (by their Moodle account) on at least one submission accepted by the linked Conference Program activity.';
$string['price'] = 'Price';
$string['price_help'] = 'The ticket price as a decimal amount, e.g. 49.99. Use 0.00 for a free ticket type, which is issued directly without going through the payment system.';
$string['privacy:metadata'] = 'The Conference Check-in plugin stores personal data about issued tickets and recorded check-ins in its own tables. Ticket type, template and promo code configuration hold no personal data. Payment amount/status are not stored by this plugin at all; they live in core_payment\'s own tables.';
$string['privacy:metadata:confcheckin_checkin'] = 'A recorded check-in event for an issued ticket.';
$string['privacy:metadata:confcheckin_checkin:scannedby'] = 'The ID of the user who performed the QR scan that recorded this check-in.';
$string['privacy:metadata:confcheckin_checkin:timecreated'] = 'The time the check-in was recorded.';
$string['privacy:metadata:confcheckin_ticket'] = 'An issued ticket, one row per attendee per Conference Check-in instance.';
$string['privacy:metadata:confcheckin_ticket:origin'] = 'How the ticket was obtained: purchase, free, or promo.';
$string['privacy:metadata:confcheckin_ticket:qrtoken'] = 'The unique token identifying this ticket\'s QR code.';
$string['privacy:metadata:confcheckin_ticket:timecreated'] = 'The time the ticket was issued.';
$string['privacy:metadata:confcheckin_ticket:timemodified'] = 'The time the ticket was last modified.';
$string['privacy:metadata:confcheckin_ticket:userid'] = 'The ID of the user the ticket was issued to.';
$string['promocode'] = 'Promo code';
$string['promocodeadded'] = 'Promo code added.';
$string['promocodedeleted'] = 'Promo code deleted.';
$string['promocodeupdated'] = 'Promo code updated.';
$string['purchased'] = 'Date';
$string['purchasedescription'] = 'Ticket: {$a}';
$string['purchaseticket'] = 'Buy or claim a ticket';
$string['receipt'] = 'Receipt';
$string['redeem'] = 'Redeem';
$string['redeempromocode'] = 'Have a promo code?';
$string['scaffoldnotice'] = 'Ticket purchase is not yet available: no capability lets you view any part of this activity yet.';
$string['scaffoldnoticecheckin'] = 'The QR check-in scanner and attendance certificates are still under construction.';
$string['soldout'] = 'Sold out';
$string['sortorder'] = 'Display order';
$string['templatecontent'] = 'Template content';
$string['templatecontent_help'] = 'Available placeholders: {{fullname}}, {{email}}, {{tickettype}}, {{confcheckinname}}, {{origin}}, {{qrcode}} (the attendee\'s unique QR code), and, for an eligible presenter only, {{submissiontitle}} and {{track}} (blank for a non-presenter). Any placeholder not recognised is simply removed when the PDF is generated.';
$string['templateplaceholders'] = 'Available placeholders: {{fullname}}, {{email}}, {{tickettype}}, {{confcheckinname}}, {{origin}}, {{qrcode}}, and, for an eligible presenter only, {{submissiontitle}} and {{track}}.';
$string['templatesaved'] = 'Template saved.';
$string['ticket'] = 'Ticket';
$string['ticketissued'] = 'Your ticket has been issued.';
$string['tickettypeadded'] = 'Ticket type added.';
$string['tickettypedeleted'] = 'Ticket type deleted.';
$string['tickettypename'] = 'Ticket type name';
$string['tickettypeupdated'] = 'Ticket type updated.';
$string['timeexpires'] = 'Expires';
$string['timeexpires_help'] = 'The date after which this code can no longer be redeemed. Leave blank for a code that never expires.';
$string['unlimited'] = 'Unlimited';
$string['uses'] = 'Uses';
$string['validfrom'] = 'Valid from';
$string['validfrom_help'] = 'The date this ticket type admits entry from. Informational only in this phase; not enforced at check-in yet.';
$string['validfromdate'] = 'Valid from {$a}.';
$string['validto'] = 'Valid to';
$string['validto_help'] = 'The date this ticket type admits entry until. Informational only in this phase; not enforced at check-in yet.';
$string['validtodate'] = 'Valid until {$a}.';
$string['visible'] = 'Visible';
$string['visible_help'] = 'Whether this ticket type is offered on the purchase page. Hiding a ticket type does not delete it or affect tickets already issued.';
