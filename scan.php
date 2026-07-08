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
 * QR check-in scanner for mod_confcheckin (Phase 4.5), gated on
 * mod/confcheckin:scancheckin.
 *
 * Two input paths, both feeding the same amd/src/scanner.js submit handler:
 * - A plain, always-available text field, auto-focused and auto-submitting on
 *   Enter -- this is the reliable baseline: a USB/Bluetooth barcode scanner (the
 *   common piece of hardware at a real conference check-in desk) behaves exactly
 *   like a keyboard typing the decoded text followed by Enter, so this needs no
 *   camera API or any third-party JS library at all, and works identically inside
 *   the Moodle app's embedded web view (see db/mobile.php) as in a desktop
 *   browser.
 * - An optional camera-based scanner, progressively enhanced via the browser's
 *   native BarcodeDetector API (feature-detected; not vendoring a third-party JS
 *   QR-decoding library, since none ships in Moodle core and this project has no
 *   way to security/license-review one in this environment). Support varies by
 *   browser/web view (notably: no Safari/WebKit support as of this writing), so
 *   this is a convenience on top of the always-working text-field path, never a
 *   requirement.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\instance_helper;

$id = required_param('id', PARAM_INT);

require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_scancheckin($id);

$pageurl = new moodle_url('/mod/confcheckin/scan.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name) . ': ' . get_string('scancheckin', 'confcheckin'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$PAGE->requires->js_call_amd('mod_confcheckin/scanner', 'init', [$cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('scancheckin', 'confcheckin'), 3);

echo html_writer::tag('p', get_string('scancheckin_help', 'confcheckin'));

echo html_writer::start_div('mod_confcheckin-scanner', ['id' => 'mod_confcheckin-scanner-root']);

echo html_writer::start_tag('form', ['class' => 'mod_confcheckin-scanner-form']);
echo html_writer::label(get_string('scanqrtoken', 'confcheckin'), 'mod_confcheckin-scanner-input');
echo html_writer::empty_tag('input', [
    'type'        => 'text',
    'id'          => 'mod_confcheckin-scanner-input',
    'name'        => 'qrtoken',
    'class'       => 'form-control mod_confcheckin-scanner-input',
    'autocomplete' => 'off',
    'autofocus'   => 'autofocus',
    'placeholder' => get_string('scanqrtoken', 'confcheckin'),
]);
echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'class' => 'btn btn-primary mt-2',
    'value' => get_string('scanqrtokensubmit', 'confcheckin'),
]);
echo html_writer::end_tag('form');

echo html_writer::tag(
    'button',
    get_string('scanwithcamera', 'confcheckin'),
    ['type' => 'button', 'class' => 'btn btn-secondary mt-2 mod_confcheckin-scanner-cameratoggle', 'hidden' => 'hidden']
);

// Mutes the success beep only (user request, 2026-07-08); the visual flash/checkmark
// below are silent regardless, so nothing else needs to respect this. Not gated behind
// the camera-support check above: the beep also plays for the always-available text-
// field/hardware-barcode-scanner path, so this toggle is relevant even where the
// camera button itself never appears.
echo html_writer::start_tag('label', ['class' => 'mod_confcheckin-scanner-mutelabel mt-2']);
echo html_writer::empty_tag('input', [
    'type'  => 'checkbox',
    'class' => 'mod_confcheckin-scanner-mute',
]);
echo ' ' . get_string('mutescansound', 'confcheckin');
echo html_writer::end_tag('label');

// A wrapper around <video> (user request, 2026-07-08): gives the border-flash-green
// success cue somewhere to paint (a border directly on <video> would be clipped/replaced
// oddly across browsers when the element's own aspect ratio changes), and somewhere to
// absolutely-position the checkmark overlay centred over the live camera image. Hidden
// alongside the video itself (see amd/src/scanner.js's start/stopCameraScanning()) so
// nothing -- not even an empty bordered box -- shows before the camera is actually on.
echo html_writer::start_div('mod_confcheckin-scanner-videowrap', ['hidden' => 'hidden']);
echo html_writer::empty_tag('video', ['class' => 'mod_confcheckin-scanner-video', 'hidden' => 'hidden']);
echo html_writer::tag('i', '', [
    'class'       => 'mod_confcheckin-scanner-checkmark fa fa-check-circle',
    'aria-hidden' => 'true',
]);
echo html_writer::end_div();

echo html_writer::div('', 'mod_confcheckin-scanner-result', ['aria-live' => 'polite']);
echo html_writer::start_tag('ul', ['class' => 'mod_confcheckin-scanner-log']);
echo html_writer::end_tag('ul');

echo html_writer::end_div();

echo $OUTPUT->footer();
