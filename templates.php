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
 * Badge/ticket/receipt/certificate template management screen for mod_confcheckin
 * (Phase 4.4).
 *
 * One TinyMCE template per type per instance (confcheckin_template, unique on
 * confcheckin+templatetype -- see db/install.xml). Visiting this page for a type
 * that has no row yet pre-fills the editor with pdf_generator's built-in fallback
 * content, so an organiser edits from a real starting point rather than a blank box;
 * saving with the fallback content untouched still creates a real row (harmless --
 * get_template_content() would have produced the identical rendered PDF either way).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\form\template_form;
use mod_confcheckin\local\instance_helper;
use mod_confcheckin\local\pdf_generator;
use mod_confcheckin\local\placeholder;

$id = required_param('id', PARAM_INT);
$templatetype = optional_param('type', 'badge', PARAM_ALPHA);

// The real login/capability/instance check happens inside require_managetemplates()
// below (mirroring purchase.php's own pattern); this bare call exists only so a
// static "is there a login check in this file" scan does not flag a false positive.
require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_managetemplates($id);

if (!in_array($templatetype, pdf_generator::VALID_TYPES, true)) {
    throw new \moodle_exception('error:invalidtemplatetype', 'confcheckin');
}

$pageurl = new moodle_url('/mod/confcheckin/templates.php', ['id' => $cm->id, 'type' => $templatetype]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('managetemplates', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$existing = $DB->get_record('confcheckin_template', [
    'confcheckin'  => $confcheckin->id,
    'templatetype' => $templatetype,
]);

$form = new template_form($pageurl, ['templatetype' => $templatetype, 'context' => $context]);

$form->set_data((object) [
    'templatetype' => $templatetype,
    'content'      => [
        'text'   => $existing->content ?? pdf_generator::default_template($templatetype),
        'format' => $existing->contentformat ?? FORMAT_HTML,
    ],
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/mod/confcheckin/view.php', ['id' => $cm->id]));
} else if ($data = $form->get_data()) {
    $now = time();
    $record = (object) [
        'confcheckin'   => $confcheckin->id,
        'templatetype'  => $templatetype,
        'content'       => $data->content['text'],
        'contentformat' => $data->content['format'],
        'timemodified'  => $now,
    ];

    if ($existing) {
        $record->id = $existing->id;
        $DB->update_record('confcheckin_template', $record);
    } else {
        $record->timecreated = $now;
        $DB->insert_record('confcheckin_template', $record);
    }

    redirect($pageurl, get_string('templatesaved', 'confcheckin'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('managetemplates', 'confcheckin'), 3);

$tablinks = [];
foreach (pdf_generator::VALID_TYPES as $type) {
    $label = get_string($type, 'confcheckin');
    if ($type === $templatetype) {
        $tablinks[] = html_writer::tag('strong', $label);
    } else {
        $tablinks[] = html_writer::link(
            new moodle_url('/mod/confcheckin/templates.php', ['id' => $cm->id, 'type' => $type]),
            $label
        );
    }
}
echo html_writer::tag('p', implode(' | ', $tablinks));

// Built from placeholder::wrap() rather than a fixed lang string, so this always
// shows whichever delimiter pair is CURRENTLY configured (see settings.php).
$placeholdernames = ['fullname', 'email', 'tickettype', 'confcheckinname', 'coursefullname', 'courseshortname', 'origin', 'qrcode'];
$placeholderlist = implode(', ', array_map([placeholder::class, 'wrap'], $placeholdernames));
$presenterplaceholderlist = implode(', ', array_map([placeholder::class, 'wrap'], ['submissiontitle', 'track']));
echo $OUTPUT->notification(
    get_string('templateplaceholders', 'confcheckin', (object) [
        'placeholders' => $placeholderlist,
        'presenterplaceholders' => $presenterplaceholderlist,
    ]),
    'info'
);

$form->display();

echo $OUTPUT->footer();
