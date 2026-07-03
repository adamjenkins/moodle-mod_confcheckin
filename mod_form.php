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
 * Activity settings form for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Settings form for the Conference Check-in activity.
 *
 * This is a minimal scaffold: name/intro only. Ticket type management, the
 * TinyMCE template editor, and payment/gateway settings are follow-up work
 * (Phases 4.3-4.4); they get their own dedicated screens rather than living
 * in this form, the same way mod_confsubmissions's track management and
 * mod_confscheduler's room management do not live in their settings forms
 * either.
 */
class mod_confcheckin_mod_form extends moodleform_mod {
    /**
     * Defines the form fields.
     */
    public function definition() {
        $mform = $this->_form;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Standard module elements (visibility, groups, etc.).
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }
}
