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
 * `{{name}}` placeholders build_context() populates; render() replaces each with its
 * value, and silently drops (replaces with '') any `{{name}}` the context does not
 * recognise, rather than leaving the literal placeholder text in the rendered PDF --
 * e.g. a template authored before a given ticket's origin/tickettype context existed,
 * or a simple organiser typo.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholder {
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
     * @param \stdClass $confcheckin The confcheckin instance record
     * @param \stdClass $tickettype The confcheckin_tickettype record
     * @param \stdClass $ticket The confcheckin_ticket record
     * @param \stdClass $user The ticket holder's user record
     * @return array Placeholder name => replacement HTML
     */
    public static function build_context(
        \stdClass $confcheckin,
        \stdClass $tickettype,
        \stdClass $ticket,
        \stdClass $user
    ): array {
        $context = [
            'fullname'        => s(fullname($user)),
            'email'           => s($user->email),
            'tickettype'      => format_string($tickettype->name),
            'confcheckinname' => format_string($confcheckin->name),
            'origin'          => get_string('origin:' . $ticket->origin, 'confcheckin'),
            'qrtoken'         => s($ticket->qrtoken),
            'qrcode'          => self::qr_image_tag($ticket->qrtoken),
        ];

        $confprogramcmid = isset($confcheckin->confprogramcmid) ? (int) $confcheckin->confprogramcmid : null;
        $submission = eligibility::find_presenter_submission((int) $user->id, $confprogramcmid);

        $context['submissiontitle'] = $submission ? format_string($submission->title) : '';
        $context['track'] = $submission ? self::track_name($submission) : '';

        return $context;
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
     * Substitutes every `{{name}}` placeholder in $content with its value in
     * $context, or '' if $context has no entry for that name.
     *
     * @param string $content The template's raw HTML content
     * @param array $context Placeholder name => replacement HTML, from build_context()
     * @return string The rendered HTML, ready for TCPDF's writeHTML()
     */
    public static function render(string $content, array $context): string {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static fn (array $matches): string => $context[$matches[1]] ?? '',
            $content
        );
    }
}
