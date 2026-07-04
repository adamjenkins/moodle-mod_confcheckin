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
 * Add/edit promo code mini-form used on promocodes.php.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class promocode_form extends \moodleform {
    /** @var int[] Valid tickettypeid option keys, set by definition() from customdata. */
    protected $tickettypeids = [];

    /**
     * Defines the form fields.
     *
     * Required custom data:
     * - confcheckinid: int, the confcheckin instance id this code belongs to (used for
     *   the server-side duplicate-code check in validation()).
     * - tickettypeoptions: array<int, string>, this instance's OWN ticket types
     *   (id => name) -- the same instance-scoping IDOR pattern used throughout this
     *   project: the select is built only from ticket types the caller already scoped
     *   to this instance, and validation() re-checks the submitted id against exactly
     *   that set.
     *
     * Optional custom data:
     * - editing: bool, true when editing an existing code (changes submit button label).
     * - excludeid: int, this code's own confcheckin_promocode id when editing, excluded
     *   from the duplicate-code check (so saving a code without changing its own code
     *   string does not flag itself as a duplicate).
     */
    public function definition() {
        $mform = $this->_form;
        $editing = !empty($this->_customdata['editing']);
        $tickettypeoptions = $this->_customdata['tickettypeoptions'] ?? [];
        $this->tickettypeids = array_map('intval', array_keys($tickettypeoptions));

        // Named 'promocodeid', not 'id' -- see tickettype_form's identical convention note.
        $mform->addElement('hidden', 'promocodeid', 0);
        $mform->setType('promocodeid', PARAM_INT);

        $mform->addElement('text', 'code', get_string('promocode', 'confcheckin'), ['size' => 20, 'maxlength' => 64]);
        $mform->setType('code', PARAM_ALPHANUMEXT);
        $mform->addRule('code', null, 'required', null, 'client');
        $mform->addRule('code', get_string('maximumchars', '', 64), 'maxlength', 64, 'client');

        $mform->addElement(
            'select',
            'tickettypeid',
            get_string('grantsticketype', 'confcheckin'),
            $tickettypeoptions
        );
        $mform->addRule('tickettypeid', null, 'required', null, 'client');

        $mform->addElement('text', 'maxuses', get_string('maxuses', 'confcheckin'), ['size' => 6]);
        $mform->setType('maxuses', PARAM_RAW);
        $mform->addHelpButton('maxuses', 'maxuses', 'confcheckin');

        $mform->addElement(
            'date_selector',
            'timeexpires',
            get_string('timeexpires', 'confcheckin'),
            ['optional' => true]
        );
        $mform->addHelpButton('timeexpires', 'timeexpires', 'confcheckin');

        $this->add_action_buttons(
            false,
            $editing ? get_string('savechanges') : get_string('addpromocode', 'confcheckin')
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
        global $DB;

        $errors = parent::validation($data, $files);

        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $errors['code'] = get_string('required');
        } else {
            $confcheckinid = (int) ($this->_customdata['confcheckinid'] ?? 0);
            $excludeid = (int) ($this->_customdata['excludeid'] ?? 0);

            $conditions = ['confcheckin' => $confcheckinid, 'code' => $code];
            $existing = $DB->get_record('confcheckin_promocode', $conditions);
            if ($existing && (int) $existing->id !== $excludeid) {
                // Friendly duplicate-code error rather than a raw DB unique-constraint
                // violation surfacing from the eventual insert/update -- the db/install.xml
                // confcheckincode index is the authoritative backstop, this is just UX.
                $errors['code'] = get_string('error:promocodenotunique', 'confcheckin');
            }
        }

        if (!in_array((int) ($data['tickettypeid'] ?? 0), $this->tickettypeids, true)) {
            // Reject a submitted tickettypeid outside the instance-scoped option set the
            // UI actually offered -- see this class's docblock on tickettypeoptions.
            $errors['tickettypeid'] = get_string('error:invalidtickettype', 'confcheckin');
        }

        $maxuses = trim((string) ($data['maxuses'] ?? ''));
        if ($maxuses !== '' && (!ctype_digit($maxuses) || (int) $maxuses < 1)) {
            $errors['maxuses'] = get_string('error:invalidmaxuses', 'confcheckin');
        }

        return $errors;
    }
}
