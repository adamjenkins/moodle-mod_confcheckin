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

namespace mod_confcheckin\local;

/**
 * Shared context/capability/instance-scoping helpers for this plugin's entry-point
 * pages (tickettypes.php, promocodes.php, purchase.php, view.php).
 *
 * This is the plain-page equivalent of mod_confscheduler's
 * classes/external/scheduler_context_trait.php (that one is written as a trait for
 * classes extending \core_external\external_api; this plugin has no AJAX external
 * functions yet, so a plain static-method class is used instead). The load-bearing
 * property is the same: every write/read entry point in this project re-derives the
 * cm/context/instance from a cmid and requires the matching capability BEFORE trusting
 * any other caller-supplied id, and every "does this id belong to this instance"
 * lookup below throws the SAME exception/message regardless of whether the id simply
 * doesn't exist or exists but belongs to a different confcheckin instance -- this
 * avoids an enumeration oracle, matching the pattern documented in the coordination
 * repo's RELATIONS.md.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance_helper {
    /**
     * Validates login/context/capability for a confcheckin cmid and requires
     * mod/confcheckin:managetickettypes.
     *
     * @param int $cmid The confcheckin course-module id
     * @return array{0: \stdClass, 1: \stdClass, 2: \context_module, 3: \stdClass} [$course, $cm, $context, $confcheckin]
     */
    public static function require_manage(int $cmid): array {
        return self::require_capability_chain($cmid, 'mod/confcheckin:managetickettypes');
    }

    /**
     * Validates login/context/capability for a confcheckin cmid and requires
     * mod/confcheckin:purchase.
     *
     * @param int $cmid The confcheckin course-module id
     * @return array{0: \stdClass, 1: \stdClass, 2: \context_module, 3: \stdClass} [$course, $cm, $context, $confcheckin]
     */
    public static function require_purchase(int $cmid): array {
        return self::require_capability_chain($cmid, 'mod/confcheckin:purchase');
    }

    /**
     * Common course/cm/context/capability/instance lookup shared by the require_*()
     * helpers above.
     *
     * @param int $cmid The confcheckin course-module id
     * @param string $capability The capability to require
     * @return array{0: \stdClass, 1: \stdClass, 2: \context_module, 3: \stdClass} [$course, $cm, $context, $confcheckin]
     */
    private static function require_capability_chain(int $cmid, string $capability): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'confcheckin');
        require_login($course, true, $cm);

        $context = \context_module::instance($cm->id);
        require_capability($capability, $context);

        $confcheckin = $DB->get_record('confcheckin', ['id' => $cm->instance], '*', MUST_EXIST);

        return [$course, $cm, $context, $confcheckin];
    }

    /**
     * Scopes a ticket type id to a confcheckin instance. Throws (with the same message
     * regardless of whether the ticket type simply doesn't exist, or exists but belongs
     * to a different instance) if it does not belong.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $tickettypeid The confcheckin_tickettype id
     * @return \stdClass The ticket type record
     * @throws \moodle_exception if the ticket type does not belong to this instance
     */
    public static function require_tickettype_in_instance(int $confcheckinid, int $tickettypeid): \stdClass {
        global $DB;

        $tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $tickettypeid, 'confcheckin' => $confcheckinid]);
        if (!$tickettype) {
            throw new \moodle_exception('error:invalidtickettype', 'confcheckin');
        }

        return $tickettype;
    }

    /**
     * Scopes a promo code id to a confcheckin instance. Throws (with the same message
     * regardless of whether the code simply doesn't exist, or exists but belongs to a
     * different instance) if it does not belong.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param int $promocodeid The confcheckin_promocode id
     * @return \stdClass The promo code record
     * @throws \moodle_exception if the promo code does not belong to this instance
     */
    public static function require_promocode_in_instance(int $confcheckinid, int $promocodeid): \stdClass {
        global $DB;

        $promocode = $DB->get_record('confcheckin_promocode', ['id' => $promocodeid, 'confcheckin' => $confcheckinid]);
        if (!$promocode) {
            throw new \moodle_exception('error:invalidpromocode', 'confcheckin');
        }

        return $promocode;
    }
}
