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
 * Site-wide admin settings for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Template placeholder delimiters (Phase 4.5 follow-up, user feedback,
    // 2026-07-05): sitewide, not per-instance, since organisers across a site
    // benefit from one consistent convention to learn, and changing it after
    // templates have been authored requires updating those templates anyway (see
    // \mod_confcheckin\local\placeholder's docblock) -- a per-instance setting
    // would only multiply that maintenance burden. Defaults to double square
    // brackets (e.g. [[fullname]]) rather than the double-curly-brace convention
    // this feature originally shipped with, since curly braces can visually
    // collide with TinyMCE's own HTML/CSS authoring context.
    $settings->add(new admin_setting_heading(
        'mod_confcheckin/placeholderheading',
        get_string('placeholderheading', 'confcheckin'),
        get_string('placeholderheading_desc', 'confcheckin')
    ));

    $settings->add(new admin_setting_configtext(
        'mod_confcheckin/delimiterstart',
        get_string('delimiterstart', 'confcheckin'),
        get_string('delimiterstart_desc', 'confcheckin'),
        '[[',
        PARAM_RAW,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'mod_confcheckin/delimiterend',
        get_string('delimiterend', 'confcheckin'),
        get_string('delimiterend_desc', 'confcheckin'),
        ']]',
        PARAM_RAW,
        6
    ));
}
