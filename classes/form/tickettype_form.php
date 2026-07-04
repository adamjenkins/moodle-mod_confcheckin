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
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

/**
 * Add/edit ticket type mini-form used on tickettypes.php.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tickettype_form extends \moodleform {
    /**
     * Defines the form fields.
     *
     * Optional custom data:
     * - editing: bool, true when this form is editing an existing ticket type (changes
     *   the submit button label). Defaults to false (adding a new one).
     */
    public function definition() {
        $mform = $this->_form;
        $editing = !empty($this->_customdata['editing']);

        // Named 'tickettypeid', not 'id': tickettypes.php treats the querystring 'id'
        // param as the course-module id, matching mod_confsubmissions's track_form
        // convention for the identical reason (POST would otherwise collide with GET).
        $mform->addElement('hidden', 'tickettypeid', 0);
        $mform->setType('tickettypeid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('tickettypename', 'confcheckin'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'price', get_string('price', 'confcheckin'), ['size' => 10]);
        $mform->setType('price', PARAM_RAW);
        $mform->setDefault('price', '0.00');
        $mform->addRule('price', null, 'required', null, 'client');
        $mform->addHelpButton('price', 'price', 'confcheckin');

        $mform->addElement('select', 'currency', get_string('currency', 'confcheckin'), confcheckin_get_currency_options());
        $mform->setType('currency', PARAM_ALPHA);
        $mform->setDefault('currency', 'USD');

        $mform->addElement('text', 'capacity', get_string('capacity', 'confcheckin'), ['size' => 6]);
        $mform->setType('capacity', PARAM_RAW);
        $mform->addHelpButton('capacity', 'capacity', 'confcheckin');

        $mform->addElement('advcheckbox', 'presenteronly', get_string('presenteronly', 'confcheckin'));
        $mform->addHelpButton('presenteronly', 'presenteronly', 'confcheckin');
        $mform->setDefault('presenteronly', 0);

        $mform->addElement(
            'date_selector',
            'validfrom',
            get_string('validfrom', 'confcheckin'),
            ['optional' => true]
        );
        $mform->addHelpButton('validfrom', 'validfrom', 'confcheckin');

        $mform->addElement(
            'date_selector',
            'validto',
            get_string('validto', 'confcheckin'),
            ['optional' => true]
        );
        $mform->addHelpButton('validto', 'validto', 'confcheckin');

        $mform->addElement('text', 'sortorder', get_string('sortorder', 'confcheckin'), ['size' => 4]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $mform->addElement('advcheckbox', 'visible', get_string('visible', 'confcheckin'));
        $mform->addHelpButton('visible', 'visible', 'confcheckin');
        $mform->setDefault('visible', 1);

        $this->add_action_buttons(
            false,
            $editing ? get_string('savechanges') : get_string('addtickettype', 'confcheckin')
        );
    }

    /**
     * Server-side validation.
     *
     * @param array $data Submitted form data
     * @param array $files Uploaded files
     * @return array Errors keyed by field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }

        if (confcheckin_parse_price((string) ($data['price'] ?? '')) === false) {
            $errors['price'] = get_string('error:invalidprice', 'confcheckin');
        }

        if (!confcheckin_is_valid_currency((string) ($data['currency'] ?? ''))) {
            $errors['currency'] = get_string('error:invalidcurrency', 'confcheckin');
        }

        $capacity = trim((string) ($data['capacity'] ?? ''));
        if ($capacity !== '' && (!ctype_digit($capacity) || (int) $capacity < 1)) {
            $errors['capacity'] = get_string('error:invalidcapacity', 'confcheckin');
        }

        if (
            !empty($data['validfrom']) && !empty($data['validto'])
                && (int) $data['validto'] < (int) $data['validfrom']
        ) {
            $errors['validto'] = get_string('error:validtobeforevalidfrom', 'confcheckin');
        }

        return $errors;
    }
}
