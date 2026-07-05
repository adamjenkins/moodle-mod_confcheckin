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
 * Moodle app addon registration for mod_confcheckin (Phase 4.5).
 *
 * Registers a single "view" handler under CoreCourseModuleDelegate that opens
 * this instance's own scan.php inside the app via the officially-supported
 * <core-iframe> site-plugins component -- i.e. this reuses the real web scanner
 * page (scan.php/amd/src/scanner.js) inside an embedded browser view, rather than
 * reimplementing scanning as native Ionic/TypeScript app code. This matches the
 * architecture decision recorded in the coordination repo's TASKLIST.md: "Scanner
 * UI ... (web-based, reusable by the mobile web-view addon)".
 *
 * Not independently live-tested against a real Moodle app client in this
 * environment (no mobile app emulator/build tooling available this session) --
 * the same documented limitation as this plugin's paid-PayPal purchase path (see
 * changelog.md). The response shape below matches the documented
 * tool_mobile_get_content contract other core/contrib plugins' mobile_init()
 * handlers return.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_confcheckin' => [
        'handlers' => [
            'scanner' => [
                'displaydata' => [
                    'icon'  => $CFG->wwwroot . '/mod/confcheckin/pix/monologo.svg',
                    'class' => '',
                ],
                'delegate' => 'CoreCourseModuleDelegate',
                'method'   => 'mobile_course_view',
            ],
        ],
        'lang' => [
            ['scancheckin', 'confcheckin'],
            ['scancheckin_help', 'confcheckin'],
        ],
    ],
];
