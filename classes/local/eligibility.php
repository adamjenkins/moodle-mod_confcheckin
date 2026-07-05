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
 * Presenter-ticket eligibility: is a given user a speaker on at least one
 * accepted submission in the mod_confprogram instance linked to a confcheckin
 * instance (confcheckin.confprogramcmid)?
 *
 * This is a read across two upstream plugins (mod_confprogram, then
 * mod_confsubmissions via mod_confprogram's own confsubmissionscmid link), per
 * the coordination repo's RELATIONS.md. mod_confprogram is a hard dependency of
 * this plugin ($plugin->dependencies in version.php), so unlike
 * mod_confprogram's own soft (class_exists()-guarded) integration with
 * mod_confscheduler, no existence checks are needed for mod_confprogram/
 * mod_confsubmissions classes themselves -- they are guaranteed installed.
 * What IS guarded defensively here is confcheckin.confprogramcmid itself: it is
 * a nullable, unvalidated-at-read-time soft link (the course module or its
 * target instance could have been deleted after linking), so every step
 * degrades to "not eligible" rather than fataling on a stale link.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class eligibility {
    /**
     * Whether a user is eligible to buy/claim a presenteronly ticket type for a
     * confcheckin instance: they must be a speaker (by userid -- a manually-entered
     * co-presenter with no userid never matches, since there is no Moodle account to
     * check eligibility for) on at least one submission the linked mod_confprogram
     * instance has accept-decided.
     *
     * ** Deliberate design decision, called out here because it is easy to miss: **
     * this check does NOT consult mod_confprogram::get_phase() / the Display-phase
     * embargo that RELATIONS.md says most downstream integrations must respect. A
     * presenter should be able to buy their own presenter ticket the moment their
     * submission is accepted, even before the programme is publicly displayed --
     * this is a deliberate, already-made product decision from this project's
     * history (see the coordination repo's TASKLIST.md Phase 4.3 entry), not an
     * oversight. If a future phase needs to gate this differently, this is the one
     * place to change.
     *
     * @param int $userid The user id to check
     * @param int|null $confprogramcmid The confcheckin instance's confprogramcmid setting (nullable)
     * @return bool
     */
    public static function is_presenter(int $userid, ?int $confprogramcmid): bool {
        return self::find_presenter_submission($userid, $confprogramcmid) !== null;
    }

    /**
     * Returns the first accept-decided submission (in the linked mod_confprogram
     * instance) a user is a speaker on, or null if none -- a thin convenience
     * wrapper around find_presenter_submissions() for callers needing only a
     * single-value answer (is_presenter(), and classes/local/placeholder.php's
     * older single-submission {{submissiontitle}}/{{track}} placeholders).
     *
     * @param int $userid The user id to check
     * @param int|null $confprogramcmid The confcheckin instance's confprogramcmid setting (nullable)
     * @return \stdClass|null The submission record, or null if the user is not an eligible presenter
     */
    public static function find_presenter_submission(int $userid, ?int $confprogramcmid): ?\stdClass {
        return self::find_presenter_submissions($userid, $confprogramcmid)[0] ?? null;
    }

    /**
     * Returns every accept-decided submission (in the linked mod_confprogram
     * instance) a user is a speaker on, in the program's own accepted-submissions
     * order -- used by classes/local/placeholder.php's {{presentationinfo}}
     * placeholder to list ALL of a presenter's presentations, not just one.
     *
     * @param int $userid The user id to check
     * @param int|null $confprogramcmid The confcheckin instance's confprogramcmid setting (nullable)
     * @return \stdClass[] The submission records the user speaks on; empty if none or not eligible
     */
    public static function find_presenter_submissions(int $userid, ?int $confprogramcmid): array {
        if (empty($confprogramcmid)) {
            // No linked mod_confprogram instance: presenteronly ticket types are
            // simply never eligible for anyone, per this plugin's README.md.
            return [];
        }

        $result = [];
        foreach (self::get_accepted_with_speakers($confprogramcmid) as $entry) {
            foreach ($entry->speakers as $speaker) {
                // A manually-entered co-presenter (userid null) never matches: there is
                // no Moodle account to check eligibility for.
                if (!empty($speaker->userid) && (int) $speaker->userid === $userid) {
                    $result[] = $entry->submission;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Resolves a confprogramcmid down to its accepted submissions, each paired with its
     * own already-fetched speakers list -- cached per confprogramcmid for the lifetime of
     * the request (moodle-reviewer finding, Phase 4.6: a bulk badge/ticket ZIP export
     * calls find_presenter_submissions() once per ticket, i.e. once per user, all against
     * the SAME confprogramcmid; without this cache, every single ticket re-resolved the
     * whole confprogram/confsubmissions chain and re-queried every accepted submission's
     * speakers from scratch). A `cache::MODE_REQUEST` store is used (the same ad-hoc,
     * no-db/caches.php-registration pattern \tool_usertours\local\filter\role::filter_matches()
     * already uses for its own per-request role cache) rather than a plain static array,
     * specifically so Moodle's own test-reset machinery clears it between PHPUnit tests --
     * a raw static property would leak stale data across test methods instead.
     *
     * @param int $confprogramcmid The confcheckin instance's confprogramcmid setting
     * @return \stdClass[] Objects with ->submission and ->speakers, in accepted-submissions order
     */
    private static function get_accepted_with_speakers(int $confprogramcmid): array {
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_confcheckin', 'eligibility_accepted');

        $cached = $cache->get($confprogramcmid);
        if ($cached !== false) {
            return $cached;
        }

        global $DB;
        $result = [];

        $confprogramcm = get_coursemodule_from_id('confprogram', $confprogramcmid, 0, false, IGNORE_MISSING);
        $confprogram = $confprogramcm ? $DB->get_record('confprogram', ['id' => $confprogramcm->instance]) : false;
        $confsubmissionscm = $confprogram
            ? get_coursemodule_from_id('confsubmissions', $confprogram->confsubmissionscmid, 0, false, IGNORE_MISSING)
            : false;

        // Stale link (the mod_confprogram/mod_confsubmissions course module was deleted, or
        // the confprogram record itself is gone) degrades to an empty result rather than
        // fatal -- see this class's own docblock for why every step here is defensive.
        if ($confsubmissionscm) {
            $accepted = \mod_confprogram\local\display_list::get_accepted_submissions(
                (int) $confprogram->id,
                (int) $confsubmissionscm->instance
            );

            foreach ($accepted as $submission) {
                $result[] = (object) [
                    'submission' => $submission,
                    'speakers'   => \mod_confsubmissions\api::get_speakers((int) $submission->id),
                ];
            }
        }

        $cache->set($confprogramcmid, $result);

        return $result;
    }
}
