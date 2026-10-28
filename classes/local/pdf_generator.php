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

    /** @var string[] Image MIME types inline_images() lets through to TCPDF, as data: URIs. */
    private const INLINE_IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /** @var int Largest remote template image inline_images() will fetch, in bytes. */
    private const MAX_REMOTE_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * The built-in fallback content for a template type, used until an organiser
     * configures their own via templates.php. Deliberately simple: real visual
     * design is exactly what the TinyMCE editor lets an organiser customise.
     *
     * Built via placeholder::wrap() rather than a fixed string constant, so the
     * fallback content always uses whichever delimiter pair is CURRENTLY
     * configured (mod_confcheckin/delimiterstart/delimiterend) -- otherwise a site
     * that changed the delimiter after this plugin's initial install would get a
     * fallback template full of placeholders in the OLD delimiter, which
     * render()'s regex (built from the current delimiter) would then never match.
     *
     * @param string $templatetype One of self::VALID_TYPES
     * @return string
     */
    public static function default_template(string $templatetype): string {
        $p = static fn (string $name): string => placeholder::wrap($name);

        // Every human-readable fragment comes from a lang string (FABLE.md review,
        // 2026-07-09: they were hard-coded English, so a Japanese site that never
        // customised its templates handed out English certificates -- and
        // templates.php pre-fills the editor with this content too). Rendered in
        // whatever language is current when the fallback is used/pre-filled, same
        // as any other get_string() output.
        $templates = [
            'badge' => '<div style="text-align:center;">'
                . '<h2>' . $p('fullname') . '</h2>'
                . '<p>' . $p('tickettype') . '</p>'
                . '<p>' . $p('confcheckinname') . '</p>'
                . '<div>' . $p('qrcode') . '</div>'
                . '</div>',
            'ticket' => '<h2>' . $p('confcheckinname') . '</h2>'
                . '<p><strong>' . $p('fullname') . '</strong> &mdash; ' . $p('tickettype') . '</p>'
                . '<div>' . $p('qrcode') . '</div>',
            'receipt' => '<h2>' . $p('confcheckinname') . '</h2>'
                . '<p>' . get_string('defaulttemplate:receiptfor', 'confcheckin', $p('fullname')) . '</p>'
                . '<p>' . get_string('defaulttemplate:tickettype', 'confcheckin', $p('tickettype')) . '</p>'
                . '<p>' . get_string('defaulttemplate:cost', 'confcheckin', $p('cost')) . '</p>'
                . '<p>' . get_string('defaulttemplate:origin', 'confcheckin', $p('origin')) . '</p>',
            'certificate' => '<div style="text-align:center;">'
                . '<h1>' . get_string('defaulttemplate:certificatetitle', 'confcheckin') . '</h1>'
                . '<p>' . get_string('defaulttemplate:certifiesthat', 'confcheckin') . '</p>'
                . '<h2>' . $p('fullname') . '</h2>'
                . '<p>' . get_string('defaulttemplate:attended', 'confcheckin', $p('confcheckinname')) . '</p>'
                . '</div>',
        ];

        return $templates[$templatetype] ?? '';
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
        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user, $templatetype);
        $html = self::inline_images(placeholder::render($content, $context));

        // Use pdf_document, not \pdf: it stops TCPDF reading any URL or server file the
        // template HTML names (see its class docblock).
        $pdf = new pdf_document();
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetCreator('Moodle mod_confcheckin');
        $pdf->SetTitle(format_string($confcheckin->name) . ' - ' . get_string($templatetype, 'confcheckin'));
        $pdf->SetMargins(15, 15, 15);

        // Without this, TCPDF falls back to its own default (Latin-only Helvetica),
        // which has no CJK glyph coverage -- every template with Japanese content
        // (or any other non-Latin script Noto Sans JP covers) rendered as mojibake.
        // Moodle core's own CJK CID font support is deliberately unusable here (see
        // lib/pdflib.php's get_font_families(), which filters out 'cid0*' fonts), so
        // this plugin bundles its own font instead, under thirdparty/ (same
        // convention as amd/src/scanner.js's vendored jsQR) -- see
        // thirdparty/notosansjp/README.md and thirdpartylibs.xml.
        //
        // Registered under all four styles, all pointing at the same (Regular-only)
        // font file: only one weight was bundled, so writeHTML() rendering a <strong>/
        // <h2>/etc. (which requests the Bold style) would otherwise fail outright --
        // TCPDF has no fallback when a requested style's font file can't be found, it
        // throws. Reusing the Regular outlines for B/I/BI means bold/italic markup
        // renders as plain weight rather than true bold/italic, which is an accepted
        // tradeoff for a single-weight bundled font (the same one TCPDF's own
        // documentation describes for exactly this situation).
        $fontfile = $CFG->dirroot . '/mod/confcheckin/thirdparty/notosansjp/notosansjp.php';
        $pdf->AddFont('notosansjp', '', $fontfile);
        $pdf->AddFont('notosansjp', 'B', $fontfile);
        $pdf->AddFont('notosansjp', 'I', $fontfile);
        $pdf->AddFont('notosansjp', 'BI', $fontfile);
        $pdf->SetFont('notosansjp', '');

        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf;
    }

    /**
     * Rewrites every <img> in rendered template HTML so TCPDF only ever sees inline
     * image data: a data:image/(png|jpeg|gif);base64 source (the [[qrcode]] image,
     * or one an organiser pasted) is kept; an http(s) source is fetched through
     * Moodle's \curl, which applies curlsecurityblockedhosts/curlsecurityallowedport,
     * and inlined if what comes back is a PNG, JPEG or GIF; every other <img> (local
     * paths, file://, SVG data, TCPDF's '@' streams, a blocked or failed fetch) is
     * dropped. Left to itself TCPDF fetches template image URLs with its own raw
     * curl and reads absolute paths off the server's disk. pdf_document refuses those
     * reads anyway; this keeps legitimate remote logos working and drops what it would
     * refuse, rather than letting an SVG/EPS source make TCPDF throw.
     *
     * @param string $html Rendered template HTML
     * @return string The same HTML with only inline images left
     */
    public static function inline_images(string $html): string {
        $srcpattern = '/\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';

        return preg_replace_callback('/<img\b[^>]*>/i', static function (array $matches) use ($srcpattern): string {
            $tag = $matches[0];
            if (preg_match_all($srcpattern, $tag, $srcs, PREG_SET_ORDER) !== 1) {
                // No src is harmless; two is ambiguous (TCPDF keeps the last), so drop it.
                return $srcs ? '' : $tag;
            }
            // Groups 1-3 are the double-quoted, single-quoted and bare forms; only one matched.
            $raw = ($srcs[0][1] ?? '') . ($srcs[0][2] ?? '') . ($srcs[0][3] ?? '');
            $src = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (preg_match('~^data:image/(png|jpe?g|gif);base64,[A-Za-z0-9+/=\s]*$~i', $src)) {
                return $tag;
            }
            if (!preg_match('~^https?://~i', $src)) {
                return '';
            }
            $datauri = self::fetch_remote_image($src);
            if ($datauri === null) {
                return '';
            }

            return str_replace($srcs[0][0], 'src="' . $datauri . '"', $tag);
        }, $html);
    }

    /**
     * Fetches a remote template image through Moodle's \curl (so the site's curl
     * security settings apply, redirects included) and returns it as a data: URI, or
     * null if it is blocked, fails, is too large or is not a PNG, JPEG or GIF. Cached
     * per request: the bulk badge ZIP (badges.php) renders the same template once per
     * ticket.
     *
     * @param string $url An http(s) URL
     * @return string|null
     */
    private static function fetch_remote_image(string $url): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $cache = \cache::make_from_params(\cache_store::MODE_REQUEST, 'mod_confcheckin', 'inlineimages');
        $cached = $cache->get($url);
        if ($cached !== false) {
            return $cached === '' ? null : $cached;
        }

        $datauri = '';
        $curl = new \curl();
        $content = $curl->get($url, [], [
            'CURLOPT_TIMEOUT'        => 10,
            'CURLOPT_CONNECTTIMEOUT' => 5,
            'CURLOPT_MAXFILESIZE'    => self::MAX_REMOTE_IMAGE_BYTES,
        ]);
        $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
        if (
            !$curl->get_errno() && $httpcode === 200 && is_string($content)
            && $content !== '' && strlen($content) <= self::MAX_REMOTE_IMAGE_BYTES
        ) {
            $info = @getimagesizefromstring($content);
            if ($info && in_array($info['mime'] ?? '', self::INLINE_IMAGE_TYPES, true)) {
                $datauri = 'data:' . $info['mime'] . ';base64,' . base64_encode($content);
            }
        }
        $cache->set($url, $datauri);

        return $datauri === '' ? null : $datauri;
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
