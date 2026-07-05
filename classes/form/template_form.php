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

namespace mod_confcheckin\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * TinyMCE template editor form used on templates.php, one instance per template type
 * (badge/ticket/receipt/certificate).
 *
 * Required custom data:
 * - templatetype: string, one of \mod_confcheckin\local\pdf_generator::VALID_TYPES
 * - context: \context_module, this confcheckin instance's own context (required by the
 *   'editor' element even though maxfiles is 0 here -- there is no embedded file
 *   picker/manager to scope, only the rich-text editor itself)
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_form extends \moodleform {
    /**
     * Defines the form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $templatetype = $this->_customdata['templatetype'];
        $context = $this->_customdata['context'];

        // Named 'templatetype', not 'type': templates.php treats the querystring
        // 'type' param as which template is being edited, matching every other page
        // in this plugin's "POST field name must not collide with a GET routing
        // param" convention (see track_form/tickettype_form in the sibling plugins).
        $mform->addElement('hidden', 'templatetype', $templatetype);
        $mform->setType('templatetype', PARAM_ALPHA);

        $editoroptions = [
            'maxfiles'  => 0,
            'noclean'   => true,
            'context'   => $context,
            'subdirs'   => 0,
        ];
        $mform->addElement(
            'editor',
            'content',
            get_string('templatecontent', 'confcheckin'),
            null,
            $editoroptions
        );
        $mform->setType('content', PARAM_RAW);
        $mform->addHelpButton('content', 'templatecontent', 'confcheckin');

        // A "template within a template" (user feedback, 2026-07-05): this
        // template type's own mini format, applied once per accepted submission a
        // ticket holder presents to build the [[presentationinfo]] placeholder
        // above -- see classes/local/placeholder.php::render_presentationinfo().
        // Plain textarea, not TinyMCE: it is meant to hold a short HTML-ish
        // snippet (e.g. "<strong>{title}</strong> ({track})"), not a full document.
        $mform->addElement(
            'textarea',
            'presentationinfoformat',
            get_string('presentationinfoformat', 'confcheckin'),
            ['rows' => 3, 'cols' => 60]
        );
        $mform->setType('presentationinfoformat', PARAM_RAW);
        $mform->addHelpButton('presentationinfoformat', 'presentationinfoformat', 'confcheckin');

        $this->add_action_buttons();
    }
}
