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
 * Placeholder substitution for organiser-authored badge/ticket/receipt/certificate
 * templates (Phase 4.4).
 *
 * A template's stored HTML (confcheckin_template.content) may contain any of the
 * placeholders build_context() populates, wrapped in the sitewide configured
 * delimiter pair (admin setting, Phase 4.5 follow-up: mod_confcheckin/delimiterstart
 * / mod_confcheckin/delimiterend, default `[[`/`]]`, e.g. `[[fullname]]`) --
 * render() replaces each with its value, and silently drops (replaces with '') any
 * placeholder the context does not recognise, rather than leaving the literal
 * delimited text in the rendered PDF -- e.g. a template authored before a given
 * ticket's origin/tickettype context existed, or a simple organiser typo.
 *
 * The delimiter pair is sitewide, not per-instance: see settings.php's own
 * docblock for why. Changing it after templates have already been authored means
 * those templates' existing delimited text (in the OLD delimiter) will no longer
 * be recognised and must be updated to the new delimiter manually -- this is an
 * inherent, accepted tradeoff of a configurable delimiter, not a bug.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholder {
    /** @var string Fallback opening delimiter, used only if the admin setting is unset/blank. */
    private const DEFAULT_DELIMITER_START = '[[';

    /** @var string Fallback closing delimiter, used only if the admin setting is unset/blank. */
    private const DEFAULT_DELIMITER_END = ']]';

    /**
     * @var string Fallback per-presentation format for {{presentationinfo}}, used
     * when a template type's confcheckin_template.presentationinfoformat is unset
     * or blank. Deliberately just the title -- a default that also includes
     * {track} would render an ugly trailing "()" for the common case of a
     * submission with no track, and there is no per-mini-placeholder "omit if
     * blank" logic (see render_presentationinfo()'s docblock).
     */
    private const DEFAULT_PRESENTATIONINFO_FORMAT = '{title}';

    /**
     * The sitewide configured opening delimiter (e.g. `[[`), falling back to
     * DEFAULT_DELIMITER_START if the admin setting is unset or blank.
     *
     * @return string
     */
    public static function delimiter_start(): string {
        $configured = get_config('mod_confcheckin', 'delimiterstart');

        return $configured !== false && trim((string) $configured) !== ''
            ? (string) $configured
            : self::DEFAULT_DELIMITER_START;
    }

    /**
     * The sitewide configured closing delimiter (e.g. `]]`), falling back to
     * DEFAULT_DELIMITER_END if the admin setting is unset or blank.
     *
     * @return string
     */
    public static function delimiter_end(): string {
        $configured = get_config('mod_confcheckin', 'delimiterend');

        return $configured !== false && trim((string) $configured) !== ''
            ? (string) $configured
            : self::DEFAULT_DELIMITER_END;
    }

    /**
     * Wraps a placeholder name in the currently-configured delimiter pair, e.g.
     * `fullname` -> `[[fullname]]` -- used to build example text in
     * templates.php's "available placeholders" help, so that text always matches
     * whatever delimiter is actually configured right now.
     *
     * @param string $name The placeholder name, e.g. 'fullname'
     * @return string
     */
    public static function wrap(string $name): string {
        return self::delimiter_start() . $name . self::delimiter_end();
    }

    /**
     * Builds the placeholder => replacement-HTML context for one ticket.
     *
     * Every value except 'qrcode' is plain text passed through s()/format_string()
     * (i.e. already HTML-escaped) before being placed here, since render() performs a
     * raw string substitution into the template's own HTML with no escaping of its
     * own -- 'qrcode' is the one deliberate exception, since its value is itself a
     * literal `<img>` tag meant to be inserted as markup, not escaped text.
     *
     * 'submissiontitle' and 'track' are only populated when the ticket holder is an
     * eligible presenter per classes/local/eligibility.php (an accepted-submission
     * speaker on the linked mod_confprogram instance); otherwise both are '', so a
     * template using them for a non-presenter simply renders those spots blank rather
     * than showing a stale/wrong presenter's details.
     *
     * 'coursefullname'/'courseshortname' (Phase 4.5 follow-up) are this instance's
     * own course's fullname/shortname -- get_course() is cached per-request by
     * Moodle's own request cache, so calling this per-ticket (e.g. once per
     * attendee in a bulk badges.php ZIP) does not mean one real DB query each.
     *
     * 'presentationinfo' lists EVERY accepted submission the ticket holder
     * presents (unlike 'submissiontitle'/'track' above, which only ever cover
     * the first) -- see render_presentationinfo()'s docblock for its own mini
     * placeholder syntax and per-template-type configurability.
     *
     * @param \stdClass $confcheckin The confcheckin instance record
     * @param \stdClass $tickettype The confcheckin_tickettype record
     * @param \stdClass $ticket The confcheckin_ticket record
     * @param \stdClass $user The ticket holder's user record
     * @param string $templatetype One of \mod_confcheckin\local\pdf_generator::VALID_TYPES,
     *        used only to look up this template type's own presentationinfoformat
     * @return array Placeholder name => replacement HTML
     */
    public static function build_context(
        \stdClass $confcheckin,
        \stdClass $tickettype,
        \stdClass $ticket,
        \stdClass $user,
        string $templatetype
    ): array {
        $course = get_course((int) $confcheckin->course);

        $context = [
            'fullname'        => s(fullname($user)),
            'email'           => s($user->email),
            'tickettype'      => format_string($tickettype->name),
            'confcheckinname' => format_string($confcheckin->name),
            'coursefullname'  => format_string($course->fullname),
            'courseshortname' => format_string($course->shortname),
            'origin'          => get_string('origin:' . $ticket->origin, 'confcheckin'),
            'qrtoken'         => s($ticket->qrtoken),
            'qrcode'          => self::qr_image_tag($ticket->qrtoken),
        ];

        $confprogramcmid = isset($confcheckin->confprogramcmid) ? (int) $confcheckin->confprogramcmid : null;
        $submissions = eligibility::find_presenter_submissions((int) $user->id, $confprogramcmid);

        $context['submissiontitle'] = $submissions ? format_string($submissions[0]->title) : '';
        $context['track'] = $submissions ? self::track_name($submissions[0]) : '';
        // Short-circuits before touching $confcheckin->id/looking up
        // presentationinfoformat at all for the common non-presenter ticket, not
        // just before rendering -- avoids an unnecessary confcheckin_template
        // query for every plain attendee.
        $context['presentationinfo'] = $submissions
            ? self::render_presentationinfo($submissions, (int) $confcheckin->id, $templatetype)
            : '';

        return $context;
    }

    /**
     * Renders the {{presentationinfo}} placeholder: every submission in
     * $submissions run through a per-template-type mini format string (a
     * "template within a template" -- configured per templatetype on
     * templates.php, stored in confcheckin_template.presentationinfoformat),
     * joined with a line break.
     *
     * The mini format string has its OWN small, fixed placeholder syntax --
     * {title}/{track} -- deliberately distinct from the sitewide [[ ]] (or
     * whatever it's configured to) delimiter that wraps {{presentationinfo}}
     * itself, so the two nesting levels are never visually confusable and never
     * collide (this method's substitution runs and fully resolves before the
     * result is ever placed into the outer context array that render() consumes).
     * Substitution is a plain strtr() (not render()'s regex, and not gated on the
     * sitewide delimiter): a missing {track} on a submission with no track
     * becomes '' inline, same "drop what's not recognised/blank" philosophy as
     * everywhere else in this class, but there is no way to also drop
     * surrounding punctuation an organiser wrote around it -- documented, not
     * fixed, in RECOMMENDATIONS.md.
     *
     * @param \stdClass[] $submissions From eligibility::find_presenter_submissions(); the
     *        caller (build_context()) never calls this with an empty array
     * @param int $confcheckinid The confcheckin instance id
     * @param string $templatetype One of pdf_generator::VALID_TYPES
     * @return string The joined, rendered HTML
     */
    private static function render_presentationinfo(array $submissions, int $confcheckinid, string $templatetype): string {
        $format = self::get_presentationinfo_format($confcheckinid, $templatetype);

        $rendered = array_map(
            static fn (\stdClass $submission): string => strtr((string) $format, [
                '{title}' => format_string($submission->title),
                '{track}' => self::track_name($submission),
            ]),
            $submissions
        );

        return implode('<br>', $rendered);
    }

    /**
     * The configured presentationinfoformat for a (confcheckin, templatetype) pair, or
     * DEFAULT_PRESENTATIONINFO_FORMAT if unset/blank -- cached per request (moodle-reviewer
     * finding, Phase 4.6: a bulk badge/ticket ZIP export renders the SAME template type once
     * per ticket, which without this cache re-ran an identical confcheckin_template query for
     * every single ticket). Same `cache::MODE_REQUEST`, ad-hoc, no-db/caches.php-registration
     * pattern as \mod_confcheckin\local\eligibility::get_accepted_with_speakers() -- see that
     * method's docblock for why this is a real cache store, not a plain static array.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param string $templatetype One of pdf_generator::VALID_TYPES
     * @return string
     */
    private static function get_presentationinfo_format(int $confcheckinid, string $templatetype): string {
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_confcheckin', 'presentationinfoformat');
        $cachekey = $confcheckinid . ':' . $templatetype;

        $cached = $cache->get($cachekey);
        if ($cached !== false) {
            return $cached;
        }

        global $DB;
        $format = $DB->get_field('confcheckin_template', 'presentationinfoformat', [
            'confcheckin'  => $confcheckinid,
            'templatetype' => $templatetype,
        ]);
        if ($format === false || trim((string) $format) === '') {
            $format = self::DEFAULT_PRESENTATIONINFO_FORMAT;
        }

        $cache->set($cachekey, $format);

        return $format;
    }

    /**
     * Invalidates get_presentationinfo_format()'s per-request cache entry for one
     * (confcheckin, templatetype) pair -- must be called by templates.php immediately
     * after saving a confcheckin_template row, so that a save-then-render within the SAME
     * request (e.g. this is exercised directly by
     * tests/local/placeholder_test.php::test_build_context_presentationinfo_lists_every_presentation,
     * which asserts a freshly-saved format is picked up without a full new request) sees
     * the just-saved value rather than a stale cached default/older format.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param string $templatetype One of pdf_generator::VALID_TYPES
     */
    public static function forget_presentationinfo_format(int $confcheckinid, string $templatetype): void {
        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_confcheckin', 'presentationinfoformat');
        $cache->delete($confcheckinid . ':' . $templatetype);
    }

    /**
     * Looks up a submission's track name, if it has one, via a course-module lookup
     * on its own confsubmissions instance id -- \mod_confsubmissions\api has no
     * single "track by id" accessor, only get_tracks(int $cmid) (all of an instance's
     * tracks, keyed by id), so this resolves the cmid first the same defensive way
     * classes/local/eligibility.php resolves its own course-module links.
     *
     * @param \stdClass $submission A confsubmissions_submission record
     * @return string The track name, or '' if the submission has no track or the
     *         track/course-module lookup fails (e.g. a stale/deleted link)
     */
    private static function track_name(\stdClass $submission): string {
        if (empty($submission->trackid)) {
            return '';
        }

        $cm = get_coursemodule_from_instance('confsubmissions', $submission->confsubmissions, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return '';
        }

        $tracks = \mod_confsubmissions\api::get_tracks($cm->id);

        return isset($tracks[$submission->trackid]) ? format_string($tracks[$submission->trackid]->name) : '';
    }

    /**
     * Renders a unique QR code for a ticket's qrtoken as an inline base64 `<img>` tag,
     * suitable for direct embedding in HTML that will be passed to TCPDF's writeHTML()
     * (which supports `data:image/...;base64,` image sources directly -- no temp file
     * needed).
     *
     * @param string $qrtoken The confcheckin_ticket.qrtoken value to encode
     * @return string An `<img>` tag with the QR code as an inline base64 PNG
     */
    public static function qr_image_tag(string $qrtoken): string {
        global $CFG;
        require_once($CFG->libdir . '/classes/qrcode.php');

        $qrcode = new \core_qrcode($qrtoken);
        $png = $qrcode->getBarcodePngData(4, 4);

        return '<img src="data:image/png;base64,' . base64_encode($png)
            . '" alt="" style="width:120px;height:120px;" />';
    }

    /**
     * Substitutes every delimited placeholder (e.g. `[[fullname]]`, using the
     * currently-configured sitewide delimiter pair -- see delimiter_start()/
     * delimiter_end()) in $content with its value in $context, or '' if $context
     * has no entry for that name.
     *
     * @param string $content The template's raw HTML content
     * @param array $context Placeholder name => replacement HTML, from build_context()
     * @return string The rendered HTML, ready for TCPDF's writeHTML()
     */
    public static function render(string $content, array $context): string {
        $pattern = '/' . preg_quote(self::delimiter_start(), '/') . '(\w+)' . preg_quote(self::delimiter_end(), '/') . '/';

        return preg_replace_callback(
            $pattern,
            static fn (array $matches): string => $context[$matches[1]] ?? '',
            $content
        );
    }
}
