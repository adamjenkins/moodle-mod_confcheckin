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
 * Library functions for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the features this module supports.
 *
 * FEATURE_BACKUP_MOODLE2 is deliberately not claimed yet: no backup/restore
 * steps have been written for this plugin's tables (which, once Phases
 * 4.3-4.5 land, will include payment/ticket/check-in records with real
 * personal-data implications for backup/restore). Add the backup/restore
 * steplibs before flipping this to true.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function confcheckin_supports($feature) {
    return match ($feature) {
        FEATURE_MOD_INTRO        => true,
        FEATURE_SHOW_DESCRIPTION => true,
        FEATURE_BACKUP_MOODLE2   => false, // Not yet implemented; set true once backup/restore steps exist.
        FEATURE_GRADE_HAS_GRADE  => false,
        FEATURE_MOD_PURPOSE      => MOD_PURPOSE_OTHER,
        default                  => null,
    };
}

/**
 * Adds a new instance of the confcheckin activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confcheckin_mod_form|null $form The form instance
 * @return int The id of the newly inserted record
 */
function confcheckin_add_instance(stdClass $data, ?mod_confcheckin_mod_form $form = null) {
    global $DB;

    $data->timecreated  = time();
    $data->timemodified = $data->timecreated;

    if (!isset($data->intro)) {
        $data->intro = '';
    }
    if (!isset($data->introformat)) {
        $data->introformat = FORMAT_HTML;
    }

    confcheckin_normalise_soft_links($data);

    return $DB->insert_record('confcheckin', $data);
}

/**
 * Normalises the mod_form.php "0 = none" select sentinel to a real null for the two
 * nullable soft-link columns, matching db/install.xml's "null means no link" column
 * comments (mod_form.php's select elements use 0, not an empty string, as their
 * "unset" option value, since both fields are PARAM_INT).
 *
 * @param stdClass $data Data from the settings form, modified in place
 * @return void
 */
function confcheckin_normalise_soft_links(stdClass $data): void {
    if (isset($data->confprogramcmid) && (int) $data->confprogramcmid === 0) {
        $data->confprogramcmid = null;
    }
    if (isset($data->paymentaccountid) && (int) $data->paymentaccountid === 0) {
        $data->paymentaccountid = null;
    }
}

/**
 * Updates an existing instance of the confcheckin activity.
 *
 * @param stdClass $data Data from the settings form
 * @param mod_confcheckin_mod_form|null $form The form instance
 * @return bool
 */
function confcheckin_update_instance(stdClass $data, ?mod_confcheckin_mod_form $form = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    confcheckin_normalise_soft_links($data);

    return $DB->update_record('confcheckin', $data);
}

/**
 * Deletes an instance of the confcheckin activity and all associated data.
 *
 * Moodle's DDL layer never creates DB-enforced foreign key constraints on any
 * supported driver, so no real database would reject an out-of-order delete
 * here. The order below still matters for a different reason: this instance's
 * confcheckin_ticket ids are collected before confcheckin_ticket itself is
 * deleted, because confcheckin_checkin rows (which hold scannedby personal
 * data) are looked up by ticketid -- deleting the tickets first would leave
 * no way to identify which check-in rows belong to this instance, orphaning
 * them with no cleanup path.
 *
 * @param int $id The instance id
 * @return bool
 */
function confcheckin_delete_instance($id) {
    global $DB;

    if (!$confcheckin = $DB->get_record('confcheckin', ['id' => $id])) {
        return false;
    }

    $ticketids = $DB->get_fieldset_select(
        'confcheckin_ticket',
        'id',
        'confcheckin = :confcheckin',
        ['confcheckin' => $id]
    );
    if ($ticketids) {
        [$insql, $params] = $DB->get_in_or_equal($ticketids);
        $DB->delete_records_select('confcheckin_checkin', "ticketid $insql", $params);
    }

    $DB->delete_records('confcheckin_ticket', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_promocode', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_tickettype', ['confcheckin' => $id]);
    $DB->delete_records('confcheckin_template', ['confcheckin' => $id]);

    $DB->delete_records('confcheckin', ['id' => $id]);

    return true;
}

/**
 * Returns a currency-code => "CODE - Name" select option list covering every ISO 4217
 * code Moodle core knows about (lang/en/currencies.php, component 'core_currencies'),
 * NOT just the currencies supported by whatever payment gateways happen to be
 * installed/enabled right now (unlike enrol_fee's own get_possible_currencies(), which
 * intentionally restricts to \core_payment\helper::get_supported_currencies()).
 * Ticket types are organiser configuration that can reasonably be entered before a
 * payment gateway account exists at all (e.g. while setting up a free/promo-only
 * instance, or before a PayPal account is configured), so this plugin validates
 * currency against the full ISO 4217 set core ships strings for, via
 * confcheckin_is_valid_currency() below, rather than against the narrower
 * currently-enabled-gateway subset.
 *
 * @return array<string, string> Currency code => display label, sorted by code
 */
function confcheckin_get_currency_options(): array {
    $strings = get_string_manager()->load_component_strings('core_currencies', current_language());

    $options = [];
    foreach ($strings as $code => $name) {
        $options[$code] = "$code - $name";
    }
    ksort($options);

    return $options;
}

/**
 * Whether a string is a real ISO 4217 currency code, i.e. one core has a display name
 * string for in the 'core_currencies' component. See confcheckin_get_currency_options()'s
 * docblock for why this is checked against the full ISO 4217 set rather than the
 * currently-enabled-payment-gateway subset.
 *
 * @param string $code A currency code, e.g. 'USD'
 * @return bool
 */
function confcheckin_is_valid_currency(string $code): bool {
    return get_string_manager()->string_exists($code, 'core_currencies');
}

/**
 * Parses a user-entered price string into a validated decimal amount, applying the
 * same decimal-separator tolerance enrol_fee's edit_instance_validation() applies to
 * its own cost field (str_replace the current language's decimal separator with '.'
 * before is_numeric()), plus a non-negative check (enrol_fee's cost field allows
 * negative values since a negative enrolment cost has no real meaning there either,
 * but does not explicitly reject them; a ticket price has no meaningful negative
 * value, so this plugin rejects them explicitly).
 *
 * @param string $raw The raw, user-entered price string
 * @return float|false The parsed non-negative amount, or false if invalid
 */
function confcheckin_parse_price(string $raw) {
    $normalised = str_replace(get_string('decsep', 'langconfig'), '.', trim($raw));

    if ($normalised === '' || !is_numeric($normalised)) {
        return false;
    }

    $amount = (float) $normalised;
    if ($amount < 0) {
        return false;
    }

    return $amount;
}

/**
 * Returns a groupid => name select option list for a course, plus a leading
 * 0 => "None" entry -- backs the "auto-grant via group" select in
 * classes/form/tickettype_form.php (Phase 4.5 follow-up).
 *
 * @param int $courseid The course id
 * @return array<int, string> Group id => name, 0 first
 */
function confcheckin_group_options(int $courseid): array {
    $options = [0 => get_string('none')];

    foreach (groups_get_all_groups($courseid) as $group) {
        $options[(int) $group->id] = format_string($group->name);
    }

    return $options;
}

/**
 * Returns an {enrol}.id => display-name select option list for a course's own
 * ENABLED enrolment method instances, plus a leading 0 => "None" entry -- backs
 * the "auto-grant via enrolment method" select in classes/form/tickettype_form.php
 * (Phase 4.5 follow-up). Disabled instances are excluded: a disabled method can
 * enrol no one, so linking a ticket type to one could never actually grant
 * anything.
 *
 * @param int $courseid The course id
 * @return array<int, string> Enrol instance id => display name, 0 first
 */
function confcheckin_enrol_options(int $courseid): array {
    $options = [0 => get_string('none')];

    foreach (enrol_get_instances($courseid, true) as $instance) {
        $plugin = enrol_get_plugin($instance->enrol);
        if (!$plugin) {
            continue;
        }
        $options[(int) $instance->id] = $plugin->get_instance_name($instance);
    }

    return $options;
}
