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
 * Check-in report for mod_confcheckin (user request, 2026-07-08), gated on
 * mod/confcheckin:viewreport (editingteacher/manager only, per
 * db/access.php).
 *
 * Lists every user ENROLLED IN THE COURSE, not just ticket holders -- this is
 * deliberate: a plain "ticket holders and their check-in status" report could
 * only ever show people who already have a ticket, but the request was
 * specifically to also surface people who have NOT checked in, which includes
 * both "has a ticket but hasn't shown up yet" and "no ticket at all". Starting
 * from the full enrolled-user list is what makes both of those visible in one
 * place, alongside who's already checked in and when.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\checkin_service;
use mod_confcheckin\local\instance_helper;

$id = required_param('id', PARAM_INT);

require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_viewreport($id);

$pageurl = new moodle_url('/mod/confcheckin/report.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('checkinreport', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('checkinreport', 'confcheckin'), 3);
echo html_writer::tag('p', get_string('checkinreport_help', 'confcheckin'));

$coursecontext = context_course::instance($course->id);
// onlyactive => true: a suspended enrolment shouldn't appear as "hasn't checked
// in yet" alongside people who are actually expected to attend.
$enrolledusers = get_enrolled_users($coursecontext, '', 0, 'u.*', 'u.lastname, u.firstname', 0, 0, true);

$ticketsbyuser = checkin_service::get_tickets_by_user((int) $confcheckin->id);

if (!$enrolledusers) {
    echo $OUTPUT->notification(get_string('noenrolledusers', 'confcheckin'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('fullname'),
    get_string('tickettypename', 'confcheckin'),
    get_string('checkedin', 'confcheckin'),
    get_string('checkintime', 'confcheckin'),
];
$table->attributes['class'] = 'generaltable';

foreach ($enrolledusers as $user) {
    $tickets = $ticketsbyuser[(int) $user->id] ?? [];

    $profileurl = new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $course->id]);
    $namelink = html_writer::link($profileurl, fullname($user));

    if ($tickets) {
        $tickettypenames = array_unique(array_map(
            fn($ticket) => format_string($ticket->tickettypename),
            $tickets
        ));
        $tickettypecell = implode(', ', $tickettypenames);

        $checkedintickets = array_filter($tickets, fn($ticket) => $ticket->checkedintime !== null);
        if ($checkedintickets) {
            $ischeckedin = get_string('yes');
            $checkintimecell = implode(', ', array_map(
                fn($ticket) => userdate((int) $ticket->checkedintime),
                $checkedintickets
            ));
        } else {
            $ischeckedin = get_string('no');
            $checkintimecell = '-';
        }
    } else {
        $tickettypecell = get_string('noticketheld', 'confcheckin');
        $ischeckedin = get_string('no');
        $checkintimecell = '-';
    }

    $table->data[] = [$namelink, $tickettypecell, $ischeckedin, $checkintimecell];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
