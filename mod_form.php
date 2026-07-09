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
 * Ticket type/promo code management and the TinyMCE template editor get
 * their own dedicated screens (tickettypes.php, promocodes.php), the same
 * way mod_confsubmissions's track management and mod_confscheduler's room
 * management do not live in their settings forms either. This form does
 * carry the two soft-link settings needed before those screens are useful:
 * which mod_confprogram instance to check presenter-ticket eligibility
 * against (confprogramcmid, optional -- see classes/local/eligibility.php),
 * and which core_payment account paid ticket types are payable to
 * (paymentaccountid, optional -- an instance selling only free/promo ticket
 * types never needs one).
 */
class mod_confcheckin_mod_form extends moodleform_mod {
    /** @var int[] Valid confprogramcmid option keys (course_module ids in this course), set by definition(). */
    protected $confprogramcmids = [];

    /**
     * Defines the form fields.
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $courseid = $this->current->course ?? $this->course->id;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Which mod_confprogram instance (if any) grants presenter-ticket eligibility.
        // Deliberately optional (unlike mod_confscheduler's required confprogramcmid):
        // a confcheckin instance with no link simply has no usable presenteronly ticket
        // types -- see classes/local/eligibility.php's docblock.
        $options = [0 => get_string('none')];
        // Note: get_coursemodules_in_course() is an alternative, but its exact return
        // shape is unconfirmed against this Moodle version; querying course_modules
        // joined to modules directly here is unambiguous (same approach as
        // mod_confscheduler's mod_form.php).
        $confprogramcms = $DB->get_records_sql(
            "SELECT cm.id, cp.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'confprogram'
               JOIN {confprogram} cp ON cp.id = cm.instance
              WHERE cm.course = :courseid
           ORDER BY cp.name ASC",
            ['courseid' => $courseid]
        );
        foreach ($confprogramcms as $cm) {
            $options[$cm->id] = format_string($cm->name);
        }
        $this->confprogramcmids = array_keys($options);

        $mform->addElement(
            'select',
            'confprogramcmid',
            get_string('confprogramcmid', 'confcheckin'),
            $options
        );
        $mform->setDefault('confprogramcmid', 0);
        $mform->addHelpButton('confprogramcmid', 'confprogramcmid', 'confcheckin');

        // Which core_payment account paid ticket types are payable to. Optional, same
        // "no accounts configured yet" static-text fallback enrol_fee's edit_instance_form()
        // uses.
        $accounts = \core_payment\helper::get_payment_accounts_menu($this->get_context());
        if ($accounts) {
            $accounts = [0 => get_string('none')] + $accounts;
            $mform->addElement('select', 'paymentaccountid', get_string('paymentaccountid', 'confcheckin'), $accounts);
            $mform->setDefault('paymentaccountid', 0);
        } else {
            $mform->addElement(
                'static',
                'paymentaccountid_text',
                get_string('paymentaccountid', 'confcheckin'),
                get_string('noaccountsavilable', 'payment')
            );
            $mform->addElement('hidden', 'paymentaccountid', 0);
            $mform->setType('paymentaccountid', PARAM_INT);
        }
        // The help icon must attach to whichever element is actually VISIBLE in this
        // branch -- attached to the hidden 'paymentaccountid' it silently never
        // rendered in the no-accounts case (FABLE.md review, 2026-07-09).
        if ($accounts) {
            $mform->addHelpButton('paymentaccountid', 'paymentaccountid', 'confcheckin');
        } else {
            $mform->addHelpButton('paymentaccountid_text', 'paymentaccountid', 'confcheckin');
        }

        // Standard module elements (visibility, groups, etc.).
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
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

        if (
            !empty($data['confprogramcmid'])
                && !in_array((int) $data['confprogramcmid'], $this->confprogramcmids, true)
        ) {
            // Reject a submitted value outside the course-scoped option set the UI actually
            // offered (e.g. a confprogram activity in an unrelated course), since
            // classes/local/eligibility.php trusts confcheckin.confprogramcmid implicitly.
            $errors['confprogramcmid'] = get_string('error:invalidconfprogramcmid', 'confcheckin');
        }

        return $errors;
    }
}
