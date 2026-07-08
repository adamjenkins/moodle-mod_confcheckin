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
$string['addtogroup'] = 'Add ticket holders to group';
$string['addtogroup_help'] = 'Every user issued a ticket of this type is added to the chosen course group.';
$string['addtogroupheader'] = 'Add to group';
$string['addtogroupheader_help'] = 'Automatically add a user to a course group the moment they are issued a ticket of this type (purchase, free, promo redemption, or auto-grant) -- the opposite direction from "Auto-grant" above, which grants a ticket FROM group membership. If they already belong to the group, nothing changes. Leaving a group afterwards does not revoke the ticket, and losing the ticket does not remove them from the group.';
$string['alreadycheckedin'] = 'Already checked in';
$string['autogrant'] = 'Auto-grant';
$string['autogrant_help'] = 'Automatically issue a free ticket of this type to a user the moment they join a specific course group, or are enrolled via a specific enrolment method -- useful for e.g. comping a ticket to a "Volunteers" group or a specific self-enrolment key. Choose at most one of the two below. Saving this also immediately grants a ticket to every CURRENT member/enrolled user, not just future ones. If someone later leaves the group or is unenrolled, their ticket is left alone -- see "Orphaned tickets" for a report of tickets whose granting condition no longer holds, with a manual revoke option.';
$string['autograntenrol'] = 'Auto-grant via enrolment method';
$string['autograntenrolvalue'] = 'Enrolment: {$a}';
$string['autograntgroup'] = 'Auto-grant via group';
$string['autograntgroupvalue'] = 'Group: {$a}';
$string['availabletickettypes'] = 'Available ticket types';
$string['badge'] = 'Badge';
$string['buyticket'] = 'Buy ticket';
$string['cameraerror'] = 'Could not access the camera. You can still check attendees in using the text field.';
$string['capacity'] = 'Capacity';
$string['capacity_help'] = 'The maximum number of tickets of this type that can ever be issued. Leave blank for no limit.';
$string['certificate'] = 'Certificate';
$string['checkedin'] = 'Checked in';
$string['checkin'] = 'Check in';
$string['checkinreport'] = 'Check-in report';
$string['checkinreport_help'] = 'Every enrolled participant, with their ticket-holding and check-in status for this instance -- including anyone who has not yet checked in, and anyone with no ticket at all.';
$string['checkintime'] = 'Check-in time';
$string['confcheckin:addinstance'] = 'Add a new Conference Check-in activity';
$string['confcheckin:downloadbadges'] = 'Bulk-download all attendees\' badge/ticket PDFs';
$string['confcheckin:managetemplates'] = 'Edit badge/ticket/receipt/certificate templates';
$string['confcheckin:managetickettypes'] = 'Manage ticket types and promo codes';
$string['confcheckin:purchase'] = 'Buy or claim a ticket';
$string['confcheckin:scancheckin'] = 'Use the QR scanner to record a check-in';
$string['confcheckin:viewowncertificate'] = 'Download your own attendance certificate';
$string['confcheckin:viewreport'] = 'View the check-in report';
$string['confirmdeletepromocode'] = 'Delete the promo code "{$a}"? Tickets already issued with it are not affected.';
$string['confirmdeletetickettype'] = 'Delete the ticket type "{$a}"? Tickets already issued for it are not affected.';
$string['confirmrevoketicket'] = 'Revoke {$a}\'s ticket? This permanently deletes it (and any recorded check-in); their QR code will stop working.';
$string['confprogramcmid'] = 'Conference Program activity';
$string['confprogramcmid_help'] = 'The Conference Program activity to check presenter-ticket eligibility against: a user is eligible for a "presenter only" ticket type if they are a listed speaker (by their Moodle account, not a manually-entered co-presenter) on at least one submission that activity has accepted. Optional -- if left unset, "presenter only" ticket types can never be bought or claimed by anyone.';
$string['currency'] = 'Currency';
$string['delimiterend'] = 'Closing delimiter';
$string['delimiterend_desc'] = 'The closing delimiter for a template placeholder, e.g. the `]]` in `[[fullname]]`. Changing this after templates have already been authored requires updating those templates to use the new delimiter -- their existing placeholders (in the old delimiter) will otherwise simply stop being recognised.';
$string['delimiterstart'] = 'Opening delimiter';
$string['delimiterstart_desc'] = 'The opening delimiter for a template placeholder, e.g. the `[[` in `[[fullname]]`.';
$string['downloadall'] = 'Download all {$a}s';
$string['editpromocode'] = 'Edit promo code';
$string['edittickettype'] = 'Edit ticket type';
$string['eligibilityenrol'] = 'Require enrolment method';
$string['eligibilityenrol_help'] = 'Restrict this ticket type so only a user enrolled in this course via the chosen enrolment method may purchase or claim it. Choose at most one of this or "Require group membership" below.';
$string['eligibilityenrolvalue'] = 'Enrolment: {$a}';
$string['eligibilitygroup'] = 'Require group membership';
$string['eligibilitygroup_help'] = 'Restrict this ticket type so only a member of the chosen course group may purchase or claim it. Choose at most one of this or "Require enrolment method" below.';
$string['eligibilitygroupvalue'] = 'Group: {$a}';
$string['eligibilityheader'] = 'Eligibility';
$string['eligibilityheader_help'] = 'Optionally restrict who may purchase or claim this ticket type to members of a specific course group, or users enrolled via a specific enrolment method -- distinct from "Auto-grant" above, which issues a ticket automatically rather than gating self-service purchase. A promo code redemption bypasses this restriction entirely, same as "Presenter-only" above.';
$string['error:autograntexclusive'] = 'Choose auto-grant via a group OR an enrolment method, not both.';
$string['error:certificatenotready'] = 'This ticket has not been checked in yet, so no certificate is available.';
$string['error:eligibilityexclusive'] = 'Choose an eligibility requirement via a group OR an enrolment method, not both.';
$string['error:invalidautogrant'] = 'That group or enrolment method does not belong to this course.';
$string['error:invalidcapacity'] = 'Capacity must be a whole number of 1 or more, or left blank for unlimited.';
$string['error:invalidconfprogramcmid'] = 'That is not a Conference Program activity in this course.';
$string['error:invalidcurrency'] = 'Choose a valid currency.';
$string['error:invalidmaxperuser'] = 'Max tickets per user must be a whole number of 1 or more, or left blank for unlimited.';
$string['error:invalidmaxuses'] = 'Maximum uses must be a whole number of 1 or more, or left blank for unlimited.';
$string['error:invalidprice'] = 'Enter a valid, non-negative price, e.g. 0.00 or 49.99.';
$string['error:invalidpromocode'] = 'That promo code is not valid.';
$string['error:invalidqrtoken'] = 'That QR code (ticket token) was not recognised.';
$string['error:invalidtemplatetype'] = 'That is not a recognised template type.';
$string['error:invalidticket'] = 'That ticket could not be found.';
$string['error:invalidtickettype'] = 'That ticket type could not be found.';
$string['error:maxperuserexceeded'] = 'You already hold the maximum number of tickets of this type.';
$string['error:nopaymentaccount'] = 'This activity has no payment account configured yet, so paid ticket types cannot currently be purchased. Contact the course organiser.';
$string['error:noreceiptforfree'] = 'No receipt is generated for a free or promo-code ticket, since nothing was paid.';
$string['error:noteligible'] = 'You are not eligible to purchase or claim this ticket type.';
$string['error:promocodeexhausted'] = 'That promo code has already been used the maximum number of times.';
$string['error:promocodeexpired'] = 'That promo code has expired.';
$string['error:promocodenotunique'] = 'That code is already in use for this activity. Choose a different code.';
$string['error:qrtokenwrongevent'] = 'That ticket is not for this event.';
$string['error:tickettypenotfree'] = 'That ticket type is not free.';
$string['error:tickettypesoldout'] = 'That ticket type has no tickets remaining.';
$string['error:validtobeforevalidfrom'] = '"Valid to" cannot be before "Valid from".';
$string['free'] = 'Free';
$string['getfreeticket'] = 'Get free ticket';
$string['grantsticketype'] = 'Grants ticket type';
$string['managepromocodes'] = 'Manage promo codes';
$string['managetemplates'] = 'Manage badge/ticket/receipt/certificate templates';
$string['managetickettypes'] = 'Manage ticket types';
$string['maxperuser'] = 'Max tickets per user';
$string['maxperuser_help'] = 'The maximum number of tickets of this type a single user may hold at once. Leave blank for no limit.';
$string['maxperuserreached'] = 'Limit reached';
$string['maxuses'] = 'Maximum uses';
$string['maxuses_help'] = 'The maximum number of times this code can be redeemed in total. Leave blank for no limit.';
$string['modulename'] = 'Conference Check-in';
$string['modulename_help'] = 'The Conference Check-in activity sells or issues tickets for a conference, generates QR-coded badges, and records attendance via a QR scanner. Ticket types can be restricted to accepted-submission presenters (via the Conference Program activity), sold with promo codes, and configured with organiser-edited badge/ticket/receipt/certificate templates. Attendees can download an attendance certificate once checked in.';
$string['modulenameplural'] = 'Conference Check-ins';
$string['mutescansound'] = 'Mute sound';
$string['mytickets'] = 'Your tickets';
$string['noenrolledusers'] = 'No enrolled users found.';
$string['noinstances'] = 'There are no Conference Check-in activities in this course yet.';
$string['noorphanedtickets'] = 'No orphaned tickets found.';
$string['nopromocodes'] = 'No promo codes have been added yet.';
$string['noticketheld'] = 'No ticket';
$string['notickets'] = 'No tickets have been issued yet.';
$string['notickettypes'] = 'No ticket types have been added yet.';
$string['notickettypesyet'] = 'No ticket types have been added yet. Add one first.';
$string['origin'] = 'How obtained';
$string['origin:free'] = 'Free';
$string['origin:grant'] = 'Auto-granted';
$string['origin:promo'] = 'Promo code';
$string['origin:purchase'] = 'Purchased';
$string['orphanedreason'] = 'Why orphaned';
$string['orphanedreason:enrol'] = 'No longer enrolled via the linked enrolment method';
$string['orphanedreason:group'] = 'No longer a member of the linked group';
$string['orphanedtickets'] = 'Orphaned tickets';
$string['orphanedtickets_help'] = 'Tickets that were auto-granted via a linked group or enrolment method (see "Auto-grant" when adding/editing a ticket type), but whose holder is no longer a member of that group or enrolled via that method. The ticket is NOT automatically revoked when this happens -- review each one here and revoke it manually if appropriate.';
$string['paymentaccountid'] = 'Payment account';
$string['paymentaccountid_help'] = 'The payment account paid ticket types in this instance are payable to. Only needed if you plan to sell a ticket type with a nonzero price; free and promo-code ticket types never use this.';
$string['placeholderheading'] = 'Template placeholders';
$string['placeholderheading_desc'] = 'Controls the delimiter organisers use to mark a placeholder field (e.g. attendee name, QR code) inside a badge/ticket/receipt/certificate template.';
$string['pluginadministration'] = 'Conference Check-in administration';
$string['pluginname'] = 'Conference Check-in';
$string['presentationinfoformat'] = 'Presentation info format';
$string['presentationinfoformat_help'] = 'Controls what [[presentationinfo]] shows for this template type, and how: this format is applied once per accepted submission an eligible presenter is presenting, then joined with a line break. Use {title} and/or {track} (note the single braces -- different from the [[ ]] placeholder delimiter above) inside it, e.g. "<strong>{title}</strong> ({track})". Leave blank to just show the title. A submission with no track leaves {track} blank, so a fixed "(...)" you type around it will still show as empty parentheses.';
$string['presenteronly'] = 'Presenter-only';
$string['presenteronly_help'] = 'Restrict this ticket type to users who are a listed speaker (by their Moodle account) on at least one submission accepted by the linked Conference Program activity.';
$string['price'] = 'Price';
$string['price_help'] = 'The ticket price as a decimal amount, e.g. 49.99. Use 0.00 for a free ticket type, which is issued directly without going through the payment system.';
$string['privacy:metadata'] = 'The Conference Check-in plugin stores personal data about issued tickets and recorded check-ins in its own tables. Ticket type, template and promo code configuration hold no personal data. Payment amount/status are not stored by this plugin at all; they live in core_payment\'s own tables.';
$string['privacy:metadata:confcheckin_checkin'] = 'A recorded check-in event for an issued ticket.';
$string['privacy:metadata:confcheckin_checkin:scannedby'] = 'The ID of the user who performed the QR scan that recorded this check-in. Retained as an operational audit record of who performed the check-in, and not removed or anonymised even if that scanning staff member later requests deletion of their own personal data.';
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
$string['removecheckin'] = 'Remove check-in';
$string['removetickets'] = 'Delete all issued tickets and check-ins';
$string['revoke'] = 'Revoke';
$string['scaffoldnotice'] = 'Ticket purchase is not yet available: no capability lets you view any part of this activity yet.';
$string['scancheckin'] = 'Scan check-in';
$string['scancheckin_help'] = 'Type or paste a ticket\'s QR token to record its check-in, or use a USB/Bluetooth barcode scanner (which types the scanned value directly into the field below, as if from a keyboard). If your browser supports it, a "Scan with camera" option also appears.';
$string['scanning'] = 'Checking...';
$string['scanqrtoken'] = 'QR code / ticket token';
$string['scanqrtokensubmit'] = 'Check in';
$string['scanwithcamera'] = 'Scan with camera';
$string['soldout'] = 'Sold out';
$string['sortorder'] = 'Display order';
$string['templatecontent'] = 'Template content';
$string['templatecontent_help'] = 'See the list of available placeholders shown above this form. Any placeholder not recognised is simply removed when the PDF is generated.';
$string['templateplaceholders'] = 'Available placeholders: {$a->placeholders}, and, for an eligible presenter only, {$a->presenterplaceholders}.';
$string['templatesaved'] = 'Template saved.';
$string['ticket'] = 'Ticket';
$string['ticketissued'] = 'Your ticket has been issued.';
$string['ticketrevoked'] = 'Ticket revoked.';
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
