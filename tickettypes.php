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
 * Ticket type management screen for mod_confcheckin.
 *
 * Ticket types are managed on their own screen, separate from the activity settings
 * form, the same way mod_confsubmissions's tracks.php and mod_confscheduler's room
 * management are.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\form\tickettype_form;
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

$pageurl = new moodle_url('/mod/confcheckin/tickettypes.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('managetickettypes', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Handle deletion, confirmed via sesskey on every state-changing request.
if ($deleteid) {
    require_sesskey();

    $tickettype = instance_helper::require_tickettype_in_instance($confcheckin->id, $deleteid);

    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading(format_string($confcheckin->name), 2);
        echo $OUTPUT->confirm(
            get_string('confirmdeletetickettype', 'confcheckin', format_string($tickettype->name)),
            new moodle_url($pageurl, ['delete' => $deleteid, 'confirm' => 1, 'sesskey' => sesskey()]),
            $pageurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    $DB->delete_records('confcheckin_tickettype', ['id' => $deleteid, 'confcheckin' => $confcheckin->id]);
    redirect(
        $pageurl,
        get_string('tickettypedeleted', 'confcheckin'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// When editing, the ticket type must belong to this instance -- the same
// instance-scoping IDOR check already applied to deletion above.
$edittickettype = null;
if ($editid) {
    $edittickettype = instance_helper::require_tickettype_in_instance($confcheckin->id, $editid);
}

$tickettypeform = new tickettype_form($pageurl, ['editing' => (bool) $editid]);

if ($edittickettype) {
    $tickettypeform->set_data((object) [
        'tickettypeid'  => $edittickettype->id,
        'name'          => $edittickettype->name,
        'price'         => $edittickettype->price,
        'currency'      => $edittickettype->currency,
        'capacity'      => $edittickettype->capacity,
        'presenteronly' => $edittickettype->presenteronly,
        'validfrom'     => $edittickettype->validfrom,
        'validto'       => $edittickettype->validto,
        'sortorder'     => $edittickettype->sortorder,
        'visible'       => $edittickettype->visible,
    ]);
}

if ($tickettypeform->is_cancelled()) {
    redirect($pageurl);
} else if ($formdata = $tickettypeform->get_data()) {
    $tickettypeid = (int) ($formdata->tickettypeid ?? 0);

    $price = confcheckin_parse_price((string) $formdata->price);
    $capacityraw = trim((string) $formdata->capacity);

    $record = (object) [
        'confcheckin'   => $confcheckin->id,
        'name'          => $formdata->name,
        'price'         => number_format($price, 2, '.', ''),
        'currency'      => $formdata->currency,
        'capacity'      => $capacityraw === '' ? null : (int) $capacityraw,
        'presenteronly' => (int) $formdata->presenteronly,
        'validfrom'     => !empty($formdata->validfrom) ? (int) $formdata->validfrom : null,
        'validto'       => !empty($formdata->validto) ? (int) $formdata->validto : null,
        'sortorder'     => (int) $formdata->sortorder,
        'visible'       => (int) $formdata->visible,
        'timemodified'  => time(),
    ];

    if ($tickettypeid) {
        // Re-verify server-side that the submitted id still belongs to this instance,
        // even though the hidden field was originally populated from a checked lookup:
        // it is client-supplied and must never be trusted on its own.
        instance_helper::require_tickettype_in_instance($confcheckin->id, $tickettypeid);
        $record->id = $tickettypeid;
        $DB->update_record('confcheckin_tickettype', $record);
        redirect(
            $pageurl,
            get_string('tickettypeupdated', 'confcheckin'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        $record->timecreated = time();
        $record->soldcount = 0;
        $DB->insert_record('confcheckin_tickettype', $record);
        redirect(
            $pageurl,
            get_string('tickettypeadded', 'confcheckin'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('managetickettypes', 'confcheckin'), 3);

$tickettypes = $DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckin->id], 'sortorder ASC, id ASC');

if ($tickettypes) {
    $table = new html_table();
    $table->head = [
        get_string('tickettypename', 'confcheckin'),
        get_string('price', 'confcheckin'),
        get_string('capacity', 'confcheckin'),
        get_string('presenteronly', 'confcheckin'),
        get_string('visible', 'confcheckin'),
        '',
    ];
    $table->attributes['class'] = 'generaltable';

    foreach ($tickettypes as $tickettype) {
        $capacitylabel = $tickettype->capacity === null
            ? get_string('unlimited', 'confcheckin')
            : ((int) $tickettype->soldcount . ' / ' . (int) $tickettype->capacity);

        $editurl = new moodle_url($pageurl, ['edit' => $tickettype->id]);
        $editlink = $OUTPUT->action_icon(
            $editurl,
            new pix_icon('t/edit', get_string('edit')),
            null,
            ['title' => get_string('edit')]
        );

        $deleteurl = new moodle_url($pageurl, ['delete' => $tickettype->id, 'sesskey' => sesskey()]);
        $deletelink = $OUTPUT->action_icon(
            $deleteurl,
            new pix_icon('t/delete', get_string('delete')),
            null,
            ['title' => get_string('delete')]
        );

        $table->data[] = [
            format_string($tickettype->name),
            \core_payment\helper::get_cost_as_string((float) $tickettype->price, $tickettype->currency),
            $capacitylabel,
            $tickettype->presenteronly ? get_string('yes') : get_string('no'),
            $tickettype->visible ? get_string('yes') : get_string('no'),
            $editlink . ' ' . $deletelink,
        ];
    }

    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('notickettypes', 'confcheckin'), 'info');
}

echo $OUTPUT->heading(
    $editid ? get_string('edittickettype', 'confcheckin') : get_string('addtickettype', 'confcheckin'),
    4
);
$tickettypeform->display();

echo $OUTPUT->footer();
