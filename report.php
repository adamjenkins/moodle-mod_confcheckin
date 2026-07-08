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
 * Two enhancements added the same day, both user-requested:
 * - Sortable columns: purely client-side (amd/src/report_table.js re-orders
 *   the already-rendered rows by each cell's data-sort-value on header click),
 *   not Moodle's table_sql/flexible_table API -- this report's data comes from
 *   combining get_enrolled_users() with a separate ticket/check-in query in
 *   PHP, not one flat SQL result set a table_sql subclass could sort/paginate
 *   at the DB level, and course-enrolment-sized lists don't need server-side
 *   pagination in the first place.
 * - A manual check-in/remove-check-in toggle, gated separately on
 *   mod/confcheckin:scancheckin (the same capability the QR scanner itself
 *   requires -- viewing the report and recording a check-in are different
 *   actions, even though today's archetype defaults happen to grant both to
 *   the same roles). Implemented as a plain GET link + sesskey + redirect,
 *   the exact pattern orphanedtickets.php's own revoke-ticket action already
 *   uses, rather than introducing a new AJAX external function/web service
 *   for a single button. Only ever acts on a ticket a report row already
 *   shows (re-scoped to this instance below before use, same as every other
 *   caller-supplied id in this project -- see RELATIONS.md), never issues a
 *   new ticket: a user with no ticket at all has nothing here for the toggle
 *   to act on, and the button does not appear for them. A user holding more
 *   than one ticket (a rare edge case -- see get_tickets_by_user()'s own
 *   docblock) has the toggle act on their earliest-issued ticket only; the
 *   report's own aggregated Checked in/Check-in time columns still correctly
 *   reflect ALL of their tickets either way.
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
$toggleticketid = optional_param('togglecheckin', 0, PARAM_INT);
$targetstate = optional_param('checkedin', 0, PARAM_BOOL);

require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_viewreport($id);

$pageurl = new moodle_url('/mod/confcheckin/report.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('checkinreport', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$cantogglecheckin = has_capability('mod/confcheckin:scancheckin', $context);

if ($toggleticketid) {
    require_capability('mod/confcheckin:scancheckin', $context);
    require_sesskey();

    // Re-scope the caller-supplied ticket id to THIS instance before acting on it
    // -- see this file's own docblock/RELATIONS.md for why every id-taking entry
    // point in this project does this.
    $ticket = $DB->get_record('confcheckin_ticket', ['id' => $toggleticketid, 'confcheckin' => $confcheckin->id]);
    if (!$ticket) {
        throw new \moodle_exception('error:invalidticket', 'confcheckin');
    }

    checkin_service::set_checkin((int) $ticket->id, $targetstate, (int) $USER->id);
    redirect($pageurl);
}

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

$PAGE->requires->js_call_amd('mod_confcheckin/report_table', 'init');

// Column key => header label. Keys become both the header's data-column (so
// amd/src/report_table.js knows which <td> data-column to read per row when
// re-ordering) and are reused per row below when building each cell.
$columns = [
    'fullname'    => get_string('fullname'),
    'tickettype'  => get_string('tickettypename', 'confcheckin'),
    'checkedin'   => get_string('checkedin', 'confcheckin'),
    'checkintime' => get_string('checkintime', 'confcheckin'),
];

$table = new html_table();
$head = [];
foreach ($columns as $key => $label) {
    $sortbutton = html_writer::tag('button', $label . ' '
        . html_writer::tag('span', '', ['class' => 'mod_confcheckin-report-sortarrow', 'aria-hidden' => 'true']), [
        'type'  => 'button',
        'class' => 'mod_confcheckin-report-sortbutton',
    ]);
    $headercell = new html_table_cell($sortbutton);
    $headercell->attributes['data-column'] = $key;
    $headercell->attributes['aria-sort'] = 'none';
    $head[] = $headercell;
}
if ($cantogglecheckin) {
    $head[] = '';
}
$table->head = $head;
$table->attributes['class'] = 'generaltable mod_confcheckin-report-table';

foreach ($enrolledusers as $user) {
    $tickets = $ticketsbyuser[(int) $user->id] ?? [];

    $profileurl = new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $course->id]);
    $namecell = new html_table_cell(html_writer::link($profileurl, fullname($user)));
    $namecell->attributes['data-column'] = 'fullname';
    $namecell->attributes['data-sort-value'] = fullname($user);

    if ($tickets) {
        $tickettypenames = array_unique(array_map(
            fn($ticket) => format_string($ticket->tickettypename),
            $tickets
        ));
        $tickettypetext = implode(', ', $tickettypenames);

        $checkedintickets = array_filter($tickets, fn($ticket) => $ticket->checkedintime !== null);
        $ischeckedin = (bool) $checkedintickets;
        if ($checkedintickets) {
            $checkedintext = get_string('yes');
            $latestcheckintime = max(array_map(fn($ticket) => (int) $ticket->checkedintime, $checkedintickets));
            $checkintimetext = implode(', ', array_map(
                fn($ticket) => userdate((int) $ticket->checkedintime),
                $checkedintickets
            ));
        } else {
            $checkedintext = get_string('no');
            $latestcheckintime = 0;
            $checkintimetext = '-';
        }
    } else {
        $tickettypetext = get_string('noticketheld', 'confcheckin');
        $ischeckedin = false;
        $checkedintext = get_string('no');
        $latestcheckintime = 0;
        $checkintimetext = '-';
    }

    $tickettypecell = new html_table_cell($tickettypetext);
    $tickettypecell->attributes['data-column'] = 'tickettype';
    $tickettypecell->attributes['data-sort-value'] = $tickettypetext;

    $checkedincell = new html_table_cell($checkedintext);
    $checkedincell->attributes['data-column'] = 'checkedin';
    // Sorts as a boolean group regardless of the translated Yes/No text (a
    // string sort would not reliably group them together in every language).
    $checkedincell->attributes['data-sort-value'] = $ischeckedin ? '1' : '0';

    $checkintimecell = new html_table_cell($checkintimetext);
    $checkintimecell->attributes['data-column'] = 'checkintime';
    // Sorts by the raw timestamp, not the locale-formatted display text (which
    // would not sort chronologically as a plain string).
    $checkintimecell->attributes['data-sort-value'] = (string) $latestcheckintime;

    $row = [$namecell, $tickettypecell, $checkedincell, $checkintimecell];

    if ($cantogglecheckin) {
        if ($tickets) {
            // Earliest-issued ticket first (get_tickets_by_user() orders by userid
            // then ticket type, not ticket id -- re-sort here so "the ticket the
            // toggle acts on" is well-defined and stable) -- see this file's own
            // docblock for why a multi-ticket holder's toggle targets only this one.
            $ticketsbyid = $tickets;
            usort($ticketsbyid, fn($a, $b) => $a->id <=> $b->id);
            $primaryticket = $ticketsbyid[0];
            $primarycheckedin = $primaryticket->checkedintime !== null;

            $togglelabel = $primarycheckedin
                ? get_string('removecheckin', 'confcheckin')
                : get_string('checkin', 'confcheckin');
            $toggleurl = new moodle_url($pageurl, [
                'togglecheckin' => $primaryticket->id,
                'checkedin'     => $primarycheckedin ? 0 : 1,
                'sesskey'       => sesskey(),
            ]);
            $toggleclass = 'btn btn-sm ' . ($primarycheckedin ? 'btn-outline-danger' : 'btn-outline-success');
            $row[] = html_writer::link($toggleurl, $togglelabel, ['class' => $toggleclass]);
        } else {
            $row[] = '';
        }
    }

    $table->data[] = $row;
}

echo html_writer::table($table);

echo $OUTPUT->footer();
