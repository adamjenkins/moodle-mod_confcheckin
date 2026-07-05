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
 * Orphaned auto-granted ticket report for mod_confcheckin (Phase 4.5 follow-up).
 *
 * A "grant"-origin ticket is orphaned when its ticket type's linked group no
 * longer counts the holder as a member, or its linked enrolment method instance
 * no longer enrols them. Auto-revoking on group/enrolment removal was explicitly
 * rejected (user feedback, 2026-07-05: leave the ticket alone once granted) in
 * favour of this manual report, which lets an organiser review and revoke each
 * one deliberately -- see classes/local/ticket_service.php's find_orphaned_tickets()/
 * revoke_ticket() docblocks.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\checkin_service;
use mod_confcheckin\local\instance_helper;
use mod_confcheckin\local\ticket_service;

$id = required_param('id', PARAM_INT);
$revokeid = optional_param('revoke', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_manage($id);

$pageurl = new moodle_url('/mod/confcheckin/orphanedtickets.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('orphanedtickets', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($revokeid) {
    require_sesskey();

    $ticket = $DB->get_record('confcheckin_ticket', ['id' => $revokeid, 'confcheckin' => $confcheckin->id]);
    if (!$ticket) {
        throw new \moodle_exception('error:invalidticket', 'confcheckin');
    }

    $ticketuser = \core_user::get_user((int) $ticket->userid);
    $username = $ticketuser ? fullname($ticketuser) : '?';

    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($confcheckin->name), 2);
        echo $OUTPUT->confirm(
            get_string('confirmrevoketicket', 'confcheckin', $username),
            new moodle_url($pageurl, ['revoke' => $revokeid, 'confirm' => 1, 'sesskey' => sesskey()]),
            $pageurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    ticket_service::revoke_ticket($revokeid);
    redirect($pageurl, get_string('ticketrevoked', 'confcheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('orphanedtickets', 'confcheckin'), 3);
echo html_writer::tag('p', get_string('orphanedtickets_help', 'confcheckin'));

$orphaned = ticket_service::find_orphaned_tickets((int) $confcheckin->id);

if (!$orphaned) {
    echo $OUTPUT->notification(get_string('noorphanedtickets', 'confcheckin'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('mytickets', 'confcheckin'),
    get_string('tickettypename', 'confcheckin'),
    get_string('purchased', 'confcheckin'),
    get_string('checkedin', 'confcheckin'),
    get_string('orphanedreason', 'confcheckin'),
    '',
];
$table->attributes['class'] = 'generaltable';

foreach ($orphaned as $entry) {
    $ticket = $entry['ticket'];
    $tickettype = $entry['tickettype'];

    $ticketuser = \core_user::get_user((int) $ticket->userid);
    $ischeckedin = checkin_service::has_checked_in((int) $ticket->id);

    $revokeurl = new moodle_url($pageurl, ['revoke' => $ticket->id, 'sesskey' => sesskey()]);
    $revokelink = html_writer::link($revokeurl, get_string('revoke', 'confcheckin'));

    $table->data[] = [
        $ticketuser ? fullname($ticketuser) : '?',
        format_string($tickettype->name),
        userdate($ticket->timecreated),
        $ischeckedin ? get_string('checkedin', 'confcheckin') : '-',
        get_string('orphanedreason:' . $entry['reason'], 'confcheckin'),
        $revokelink,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
