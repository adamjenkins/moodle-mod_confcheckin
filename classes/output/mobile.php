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

namespace mod_confcheckin\output;

/**
 * Mobile app output class for mod_confcheckin (Phase 4.5).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /**
     * Returns the app view for a confcheckin activity: an embedded web view
     * (`<core-iframe>`, the Moodle app's own officially-supported component for
     * exactly this "reuse an existing web page" case) pointed at this instance's
     * own scan.php -- for a user with mod/confcheckin:scancheckin only; anyone
     * else instead sees a plain link to the same activity's normal web view (the
     * app's default "not supported here" fallback would otherwise apply).
     *
     * @param array $args Arguments from the tool_mobile_get_content WS call; only 'cmid' is used
     * @return array{templates: array, javascript: string, otherdata: array}
     */
    public static function mobile_course_view($args) {
        global $CFG;

        $args = (object) $args;
        $cmid = (int) $args->cmid;

        $cm = get_coursemodule_from_id('confcheckin', $cmid, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $context = \context_module::instance($cm->id);

        // Core's tool_mobile external get_content docblock states that mobile output
        // callbacks are responsible for their own security checks. Merely checking a
        // capability does not enforce course visibility, start-date, or
        // enrolment-suspension the way logging in properly does, unlike every other
        // entry point in this plugin (see view.php/badge.php's identical call below).
        require_login($course, true, $cm);

        $canscan = has_capability('mod/confcheckin:scancheckin', $context);
        $url = new \moodle_url('/mod/confcheckin/' . ($canscan ? 'scan.php' : 'view.php'), ['id' => $cmid]);

        // A double-quoted attribute value inside this hand-built HTML fragment, so
        // the URL is escaped for that context (s(), not format_string() -- this is
        // a plain system-generated URL, not user-authored rich text).
        $html = \html_writer::tag('core-iframe', '', ['src' => $url->out(false)]);

        return [
            'templates' => [
                ['id' => 'main', 'html' => $html],
            ],
            'javascript' => '',
            'otherdata' => [],
        ];
    }
}
