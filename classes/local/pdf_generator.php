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
 * Renders a confcheckin_template (or a built-in fallback, if none has been
 * configured yet) to a PDF, via Moodle's own `pdf` wrapper around TCPDF
 * (lib/pdflib.php) -- the same library `mod_assign`/`mod_certificate`-style
 * plugins use, rather than a third-party dependency.
 *
 * build() returns the constructed \pdf object itself (not yet Output()), so a caller
 * can choose how to deliver it: 'D' (direct browser download, badge.php), 'S' (raw
 * string, for bundling several into one ZIP, badges.php), or 'I' (inline display).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_generator {
    /** @var string[] Template types this class knows how to render. */
    public const VALID_TYPES = ['badge', 'ticket', 'receipt', 'certificate'];

    /**
     * Built-in fallback content for each template type, used until an organiser
     * configures their own via templates.php. Deliberately simple: real visual design
     * is exactly what the TinyMCE editor lets an organiser customise.
     */
    private const DEFAULT_TEMPLATES = [
        'badge' => '<div style="text-align:center;">'
            . '<h2>{{fullname}}</h2>'
            . '<p>{{tickettype}}</p>'
            . '<p>{{confcheckinname}}</p>'
            . '<div>{{qrcode}}</div>'
            . '</div>',
        'ticket' => '<h2>{{confcheckinname}}</h2>'
            . '<p><strong>{{fullname}}</strong> &mdash; {{tickettype}}</p>'
            . '<div>{{qrcode}}</div>',
        'receipt' => '<h2>{{confcheckinname}}</h2>'
            . '<p>Receipt for: {{fullname}}</p>'
            . '<p>Ticket type: {{tickettype}}</p>'
            . '<p>How obtained: {{origin}}</p>',
        'certificate' => '<div style="text-align:center;">'
            . '<h1>Certificate of Attendance</h1>'
            . '<p>This certifies that</p>'
            . '<h2>{{fullname}}</h2>'
            . '<p>attended {{confcheckinname}}</p>'
            . '</div>',
    ];

    /**
     * The built-in fallback content for a template type.
     *
     * @param string $templatetype One of self::VALID_TYPES
     * @return string
     */
    public static function default_template(string $templatetype): string {
        return self::DEFAULT_TEMPLATES[$templatetype] ?? '';
    }

    /**
     * The content to render for a template type: the organiser's own configured
     * confcheckin_template row if one exists and is non-blank, else the built-in
     * fallback -- so an instance that has not yet visited templates.php for a given
     * type still produces a usable PDF rather than a blank/broken one.
     *
     * @param int $confcheckinid The confcheckin instance id
     * @param string $templatetype One of self::VALID_TYPES
     * @return string
     */
    public static function get_template_content(int $confcheckinid, string $templatetype): string {
        global $DB;

        $template = $DB->get_record('confcheckin_template', [
            'confcheckin'  => $confcheckinid,
            'templatetype' => $templatetype,
        ]);

        // Decode entities and strip U+00A0 before checking blankness: TinyMCE
        // routinely leaves a lone '&nbsp;' inside an otherwise-empty paragraph to stop
        // the editor collapsing it. strip_tags() does not decode entities, and trim()
        // does not strip a
        // (decoded) non-breaking space either -- trim(strip_tags('<p>&nbsp;</p>')) is
        // the non-empty literal string '&nbsp;', not '', so a template an organiser
        // never actually filled in would have been treated as "configured" and
        // rendered verbatim instead of falling back to the built-in default (caught by
        // test_get_template_content_blank_row_falls_back_to_default()).
        $decoded = html_entity_decode(strip_tags((string) ($template->content ?? '')), ENT_QUOTES, 'UTF-8');
        $stripped = trim(str_replace("\xc2\xa0", ' ', $decoded));
        if ($template && $stripped !== '') {
            return $template->content;
        }

        return self::default_template($templatetype);
    }

    /**
     * Builds a rendered PDF for one ticket, ready for the caller to Output() however
     * it needs (direct download, ZIP bundling, or inline display).
     *
     * @param \stdClass $confcheckin The confcheckin instance record
     * @param \stdClass $tickettype The confcheckin_tickettype record (must belong to $confcheckin)
     * @param \stdClass $ticket The confcheckin_ticket record (must belong to $confcheckin)
     * @param \stdClass $user The ticket holder's user record
     * @param string $templatetype One of self::VALID_TYPES
     * @return \pdf
     * @throws \coding_exception if $templatetype is not one of self::VALID_TYPES
     */
    public static function build(
        \stdClass $confcheckin,
        \stdClass $tickettype,
        \stdClass $ticket,
        \stdClass $user,
        string $templatetype
    ): \pdf {
        global $CFG;

        if (!in_array($templatetype, self::VALID_TYPES, true)) {
            throw new \coding_exception('Unknown confcheckin template type: ' . $templatetype);
        }

        require_once($CFG->libdir . '/pdflib.php');

        $content = self::get_template_content((int) $confcheckin->id, $templatetype);
        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user);
        $html = placeholder::render($content, $context);

        $pdf = new \pdf();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetCreator('Moodle mod_confcheckin');
        $pdf->SetTitle(format_string($confcheckin->name) . ' - ' . get_string($templatetype, 'confcheckin'));
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf;
    }

    /**
     * A safe, descriptive filename for a rendered PDF, e.g. "my-conf-badge-42.pdf".
     *
     * @param \stdClass $confcheckin The confcheckin instance record
     * @param string $templatetype One of self::VALID_TYPES
     * @param \stdClass $ticket The confcheckin_ticket record (only its id is used)
     * @return string
     */
    public static function filename(\stdClass $confcheckin, string $templatetype, \stdClass $ticket): string {
        return clean_filename(format_string($confcheckin->name) . '-' . $templatetype . '-' . $ticket->id . '.pdf');
    }
}
