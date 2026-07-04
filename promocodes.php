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
 * Promo code management screen for mod_confcheckin.
 *
 * Gated on mod/confcheckin:managetickettypes -- the same capability as
 * tickettypes.php, since both are "organiser configures the ticket economics"
 * actions (see db/access.php's docblock for that capability).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\form\promocode_form;
use mod_confcheckin\local\instance_helper;

$id = required_param('id', PARAM_INT);
$deleteid = optional_param('delete', 0, PARAM_INT);
$editid = optional_param('edit', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// The real login/capability/instance check happens inside require_manage() below
// (mirroring mod_confscheduler's scheduler_context_trait pattern); this bare call
// exists only so a static "is there a login check in this file" scan does not flag a
// false positive.
require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_manage($id);

$pageurl = new moodle_url('/mod/confcheckin/promocodes.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('managepromocodes', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$tickettypes = $DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckin->id], 'sortorder ASC, id ASC');
$tickettypeoptions = [];
foreach ($tickettypes as $tickettype) {
    $tickettypeoptions[$tickettype->id] = format_string($tickettype->name);
}

if (!$tickettypeoptions) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($confcheckin->name), 2);
    echo $OUTPUT->notification(get_string('notickettypesyet', 'confcheckin'), 'info');
    echo $OUTPUT->continue_button(new moodle_url('/mod/confcheckin/tickettypes.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

// Handle deletion, confirmed via sesskey on every state-changing request.
if ($deleteid) {
    require_sesskey();

    $promocode = instance_helper::require_promocode_in_instance($confcheckin->id, $deleteid);

    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($confcheckin->name), 2);
        echo $OUTPUT->confirm(
            get_string('confirmdeletepromocode', 'confcheckin', $promocode->code),
            new moodle_url($pageurl, ['delete' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
            $pageurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    $DB->delete_records('confcheckin_promocode', ['id' => $deleteid, 'confcheckin' => $confcheckin->id]);
    redirect(
        $pageurl,
        get_string('promocodedeleted', 'confcheckin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// When editing, the code must belong to this instance -- the same instance-scoping
// IDOR check already applied to deletion above.
$editpromocode = null;
if ($editid) {
    $editpromocode = instance_helper::require_promocode_in_instance($confcheckin->id, $editid);
}

$formcustomdata = [
    'editing'           => (bool) $editid,
    'tickettypeoptions' => $tickettypeoptions,
    'confcheckinid'     => $confcheckin->id,
    'excludeid'         => $editid,
];
$promocodeform = new promocode_form($pageurl, $formcustomdata);

if ($editpromocode) {
    $promocodeform->set_data((object) [
        'promocodeid'  => $editpromocode->id,
        'code'         => $editpromocode->code,
        'tickettypeid' => $editpromocode->tickettypeid,
        'maxuses'      => $editpromocode->maxuses,
        'timeexpires'  => $editpromocode->timeexpires,
    ]);
}

if ($promocodeform->is_cancelled()) {
    redirect($pageurl);
} else if ($formdata = $promocodeform->get_data()) {
    $promocodeid = (int) ($formdata->promocodeid ?? 0);

    // Re-verify server-side that the submitted tickettypeid belongs to this instance,
    // even though the form's own validation() already checked it against the
    // instance-scoped option set -- never trust a client-supplied id transitively.
    instance_helper::require_tickettype_in_instance($confcheckin->id, (int) $formdata->tickettypeid);

    $maxusesraw = trim((string) $formdata->maxuses);

    $record = (object) [
        'confcheckin'  => $confcheckin->id,
        'code'         => trim($formdata->code),
        'tickettypeid' => (int) $formdata->tickettypeid,
        'maxuses'      => $maxusesraw === '' ? null : (int) $maxusesraw,
        'timeexpires'  => !empty($formdata->timeexpires) ? (int) $formdata->timeexpires : null,
    ];

    if ($promocodeid) {
        instance_helper::require_promocode_in_instance($confcheckin->id, $promocodeid);
        $record->id = $promocodeid;
        $DB->update_record('confcheckin_promocode', $record);
        redirect(
            $pageurl,
            get_string('promocodeupdated', 'confcheckin'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $record->timesused = 0;
        $record->timecreated = time();
        $DB->insert_record('confcheckin_promocode', $record);
        redirect(
            $pageurl,
            get_string('promocodeadded', 'confcheckin'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('managepromocodes', 'confcheckin'), 3);

$promocodes = $DB->get_records('confcheckin_promocode', ['confcheckin' => $confcheckin->id], 'code ASC');

if ($promocodes) {
    $table = new html_table();
    $table->head = [
        get_string('promocode', 'confcheckin'),
        get_string('grantsticketype', 'confcheckin'),
        get_string('uses', 'confcheckin'),
        get_string('timeexpires', 'confcheckin'),
        '',
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($promocodes as $promocode) {
        $usesleft = $promocode->maxuses === null
            ? ((int) $promocode->timesused . ' / ' . get_string('unlimited', 'confcheckin'))
            : ((int) $promocode->timesused . ' / ' . (int) $promocode->maxuses);

        $expires = $promocode->timeexpires ? userdate($promocode->timeexpires) : get_string('never');

        $editurl = new moodle_url($pageurl, ['edit' => $promocode->id]);
        $editlink = $OUTPUT->action_icon(
            $editurl,
            new pix_icon('t/edit', get_string('edit')),
            null,
            ['title' => get_string('edit')]
        );

        $deleteurl = new moodle_url($pageurl, ['delete' => $promocode->id, 'sesskey' => sesskey()]);
        $deletelink = $OUTPUT->action_icon(
            $deleteurl,
            new pix_icon('t/delete', get_string('delete')),
            null,
            ['title' => get_string('delete')]
        );

        $table->data[] = [
            s($promocode->code),
            $tickettypeoptions[$promocode->tickettypeid] ?? get_string('error:invalidtickettype', 'confcheckin'),
            $usesleft,
            $expires,
            $editlink . ' ' . $deletelink,
        ];
    }

    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('nopromocodes', 'confcheckin'), 'info');
}

echo $OUTPUT->heading(
    $editid ? get_string('editpromocode', 'confcheckin') : get_string('addpromocode', 'confcheckin'),
    4
);
$promocodeform->display();

echo $OUTPUT->footer();
