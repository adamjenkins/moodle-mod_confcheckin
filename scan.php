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
 * Camera-only (2026-07-09 rework): there is no manual/typed input path and no
 * USB/Bluetooth barcode-scanner-gun support any more -- the camera starts
 * automatically on page load and is the only way to record a check-in here.
 * amd/src/scanner.js prefers the browser's native BarcodeDetector API where
 * available (Chromium-based browsers), and falls back to the vendored jsQR
 * pure-JS decoder (thirdparty/jsQR/, loaded below) everywhere else -- notably
 * Safari/iPhone and Firefox, neither of which implement BarcodeDetector. When
 * more than one camera is available, a device-select control lets the operator
 * switch between them. See amd/src/scanner.js's own docblock for the full
 * acquisition/decode/error-handling design.
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

// Plain script tag, not an AMD module: jsQR is a vendored third-party global,
// not something written for/shimmed into Moodle's RequireJS -- see
// thirdparty/jsQR/readme_moodle.txt for why its UMD wrapper needed a small,
// documented local change to make this work.
$PAGE->requires->js(new moodle_url('/mod/confcheckin/thirdparty/jsQR/jsQR.js'), true);
$PAGE->requires->js_call_amd('mod_confcheckin/scanner', 'init', [$cm->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name), 2);
echo $OUTPUT->heading(get_string('scancheckin', 'confcheckin'), 3);

echo html_writer::tag('p', get_string('scancheckin_help', 'confcheckin'));

echo html_writer::tag(
    'noscript',
    html_writer::div(get_string('scannoscript', 'confcheckin'), 'alert alert-warning')
);

echo html_writer::start_div('mod_confcheckin-scanner', ['id' => 'mod_confcheckin-scanner-root']);

// Mutes the success beep only (user request, 2026-07-08); the visual flash/checkmark
// below are silent regardless, so nothing else needs to respect this.
echo html_writer::start_tag('label', ['class' => 'mod_confcheckin-scanner-mutelabel']);
echo html_writer::empty_tag('input', [
    'type'  => 'checkbox',
    'class' => 'mod_confcheckin-scanner-mute',
]);
echo ' ' . get_string('mutescansound', 'confcheckin');
echo html_writer::end_tag('label');

// Only revealed by JS (amd/src/scanner.js) once enumerateDevices() finds more
// than one camera -- kept out of the way entirely on the common single-camera
// phone case.
echo html_writer::start_tag('label', ['class' => 'mod_confcheckin-scanner-cameraselectlabel', 'hidden' => 'hidden']);
echo get_string('selectcamera', 'confcheckin');
echo html_writer::start_tag('select', ['class' => 'form-control mod_confcheckin-scanner-cameraselect']);
echo html_writer::end_tag('select');
echo html_writer::end_tag('label');

// A page-level blocking error state (permission denied, no camera, insecure
// context, unsupported browser, or no decoder available at all) -- there is no
// fallback UI left to degrade to, so this needs to be obvious rather than
// treated like a routine scan-outcome message (contrast with the polite-live
// result banner below).
echo html_writer::div('', 'mod_confcheckin-scanner-error alert alert-danger', ['hidden' => 'hidden', 'role' => 'alert']);

// A wrapper around <video> (user request, 2026-07-08): gives the border-flash-green
// success cue somewhere to paint (a border directly on <video> would be clipped/replaced
// oddly across browsers when the element's own aspect ratio changes), and somewhere to
// absolutely-position the checkmark overlay centred over the live camera image. Hidden
// alongside the video itself (see amd/src/scanner.js's start/stopCameraScanning()) so
// nothing -- not even an empty bordered box -- shows before the camera is actually on.
echo html_writer::start_div('mod_confcheckin-scanner-videowrap', ['hidden' => 'hidden']);
// playsinline/muted/autoplay as literal attributes (not just JS properties set
// later): iOS Safari is known to require playsinline present in the initial
// HTML for a custom camera UI to render inline instead of going fullscreen/
// failing outright.
echo html_writer::empty_tag('video', [
    'class'       => 'mod_confcheckin-scanner-video',
    'hidden'      => 'hidden',
    'playsinline' => 'playsinline',
    'muted'       => 'muted',
    'autoplay'    => 'autoplay',
    'aria-label'  => get_string('scanwithcamera', 'confcheckin'),
]);
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
