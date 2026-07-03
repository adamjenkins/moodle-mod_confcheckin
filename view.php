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
 * This is a minimal scaffold: it renders the activity intro only, gated by
 * plain course-module visibility (require_login()) since no general "view
 * this activity" capability exists yet in this scaffold phase -- only
 * narrower action-specific capabilities (:purchase, :viewowncertificate,
 * etc.) which do not apply to everyone who should be able to see this page
 * (e.g. an editingteacher checking the activity is set up correctly holds
 * none of them by default). Ticket purchase, badge/certificate download,
 * and the QR scanner are follow-up work (Phases 4.3-4.5) and will replace
 * this placeholder body with capability-gated sections of their own.
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

echo $OUTPUT->notification(get_string('scaffoldnotice', 'mod_confcheckin'), 'info');

echo $OUTPUT->footer();
