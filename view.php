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
 * Main view page for mod_confcheckin.
 *
 * Renders the activity intro plus capability-gated links into the screens Phase 4.3
 * added (ticket type/promo code management, ticket purchase). Badge/certificate
 * download and the QR scanner are still follow-up work (Phases 4.4-4.5). No general
 * "view this activity" capability exists yet -- plain course-module visibility
 * (require_login()) gates the page itself, and each link below is only shown to a
 * user who actually holds the capability the target page requires.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

$id = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'confcheckin');
$confcheckin = $DB->get_record('confcheckin', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);

$context = context_module::instance($cm->id);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$pageurl = new moodle_url('/mod/confcheckin/view.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($confcheckin->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($confcheckin->name));

if ($confcheckin->intro) {
    echo $OUTPUT->box(
        format_module_intro('confcheckin', $confcheckin, $cm->id),
        'generalbox mod_introbox',
        'confcheckinintro'
    );
}

$links = [];
if (has_capability('mod/confcheckin:purchase', $context)) {
    $links[] = html_writer::link(
        new moodle_url('/mod/confcheckin/purchase.php', ['id' => $cm->id]),
        get_string('purchaseticket', 'confcheckin')
    );
}
if (has_capability('mod/confcheckin:managetickettypes', $context)) {
    $links[] = html_writer::link(
        new moodle_url('/mod/confcheckin/tickettypes.php', ['id' => $cm->id]),
        get_string('managetickettypes', 'confcheckin')
    );
    $links[] = html_writer::link(
        new moodle_url('/mod/confcheckin/promocodes.php', ['id' => $cm->id]),
        get_string('managepromocodes', 'confcheckin')
    );
}

if ($links) {
    echo html_writer::alist($links);
} else {
    echo $OUTPUT->notification(get_string('scaffoldnotice', 'confcheckin'), 'info');
}

echo $OUTPUT->notification(get_string('scaffoldnoticebadges', 'confcheckin'), 'info');

echo $OUTPUT->footer();
