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

declare(strict_types=1);

namespace mod_confcheckin\local;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\local\pdf_generator: falling back to built-in template
 * content until an organiser configures their own, and producing a real PDF byte
 * stream for every template type.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(pdf_generator::class)]
final class pdf_generator_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance and a ticket type/ticket for it, returning
     * [$confcheckin, $tickettype, $ticket, $user].
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass}
     */
    private function create_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        $tickettypeid = $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'   => $confcheckin->id,
            'name'          => 'General',
            'price'         => '0.00',
            'currency'      => 'USD',
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);
        $tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $tickettypeid], '*', MUST_EXIST);

        $user = $this->getDataGenerator()->create_user();
        $ticket = ticket_service::issue_free_ticket((int) $confcheckin->id, $tickettypeid, (int) $user->id);

        return [$confcheckin, $tickettype, $ticket, $user];
    }

    /**
     * get_template_content() returns the built-in fallback when no confcheckin_template
     * row exists yet for a type, and the organiser's own content once one is saved.
     */
    public function test_get_template_content_falls_back_to_default(): void {
        $this->resetAfterTest();
        global $DB;

        [$confcheckin] = $this->create_fixture();

        $fallback = pdf_generator::get_template_content((int) $confcheckin->id, 'badge');
        $this->assertSame(pdf_generator::default_template('badge'), $fallback);
        $this->assertNotSame('', trim($fallback));

        $DB->insert_record('confcheckin_template', (object) [
            'confcheckin'   => $confcheckin->id,
            'templatetype'  => 'badge',
            'content'       => '<p>Custom {{fullname}}</p>',
            'contentformat' => FORMAT_HTML,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $custom = pdf_generator::get_template_content((int) $confcheckin->id, 'badge');
        $this->assertSame('<p>Custom {{fullname}}</p>', $custom);
    }

    /**
     * A confcheckin_template row whose content is blank (e.g. only whitespace/empty
     * tags after strip_tags()) is treated the same as no row at all -- the built-in
     * fallback is used, not an empty PDF.
     */
    public function test_get_template_content_blank_row_falls_back_to_default(): void {
        $this->resetAfterTest();
        global $DB;

        [$confcheckin] = $this->create_fixture();

        $DB->insert_record('confcheckin_template', (object) [
            'confcheckin'   => $confcheckin->id,
            'templatetype'  => 'ticket',
            'content'       => '<p>&nbsp;</p>',
            'contentformat' => FORMAT_HTML,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        $content = pdf_generator::get_template_content((int) $confcheckin->id, 'ticket');
        $this->assertSame(pdf_generator::default_template('ticket'), $content);
    }

    /**
     * build() produces a real PDF (starting with the %PDF magic bytes) for every
     * valid template type, and rejects an unrecognised one.
     */
    public function test_build_produces_a_real_pdf_for_every_valid_type(): void {
        $this->resetAfterTest();

        [$confcheckin, $tickettype, $ticket, $user] = $this->create_fixture();

        foreach (pdf_generator::VALID_TYPES as $type) {
            $pdf = pdf_generator::build($confcheckin, $tickettype, $ticket, $user, $type);
            $bytes = $pdf->Output('', 'S');
            $this->assertStringStartsWith('%PDF', $bytes, "Template type '$type' did not produce a PDF");
        }

        $this->expectException(\coding_exception::class);
        pdf_generator::build($confcheckin, $tickettype, $ticket, $user, 'not-a-real-type');
    }

    /**
     * filename() produces a distinct, filesystem-safe name per ticket, so two
     * attendees' badges never collide inside the same ZIP (badges.php).
     */
    public function test_filename_is_distinct_per_ticket(): void {
        $this->resetAfterTest();

        [$confcheckin, , $ticket] = $this->create_fixture();
        $otherticket = (object) ['id' => $ticket->id + 1];

        $name1 = pdf_generator::filename($confcheckin, 'badge', $ticket);
        $name2 = pdf_generator::filename($confcheckin, 'badge', $otherticket);

        $this->assertStringEndsWith('.pdf', $name1);
        $this->assertNotSame($name1, $name2);
    }

    /**
     * inline_images() keeps inline PNG/JPEG/GIF data, inlines a fetched remote image,
     * and drops every <img> TCPDF would otherwise fetch or read off the server's disk:
     * local paths, file://, '@' streams, SVG data (which can carry its own <image href>)
     * and a URL Moodle's curl security settings block.
     */
    public function test_inline_images_keeps_only_inline_image_data(): void {
        $this->resetAfterTest();

        // A valid 1x1 PNG.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $keep = '<img src="data:image/png;base64,' . $png . '" alt="" />';
        $this->assertSame('<p>' . $keep . '</p>', pdf_generator::inline_images('<p>' . $keep . '</p>'));

        $dropped = [
            '<img src="/etc/hostname.png">',
            '<IMG SRC=\'file:///etc/hostname.png\'>',
            '<img src="@' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>') . '">',
            '<img src="data:image/svg+xml;base64,' . base64_encode('<svg/>') . '">',
            '<img src="relative/logo.png">',
            '<img src="data:image/png;base64,' . $png . '" src="/etc/hostname.png">',
        ];
        foreach ($dropped as $tag) {
            $this->assertSame('<p>ok</p>', pdf_generator::inline_images('<p>' . $tag . 'ok</p>'), $tag);
        }

        // Blocked before any connection is made, so no network is needed.
        set_config('curlsecurityblockedhosts', "127.0.0.1\nlocalhost");
        $this->assertSame('<p></p>', pdf_generator::inline_images('<p><img src="http://127.0.0.1:8080/logo.png"></p>'));
        // The core url_blocked event reports the block through debugging().
        $this->assertDebuggingCalled();

        // A remote image that comes back as a real PNG is inlined as a data: URI.
        \curl::mock_response(base64_decode($png));
        $inlined = pdf_generator::inline_images('<p><img src="https://example.com/logo.png" alt="logo"></p>');
        $this->assertSame('<p><img src="data:image/png;base64,' . $png . '" alt="logo"></p>', $inlined);

        // A remote response that is not an image is dropped.
        \curl::mock_response('{"secret": "metadata"}');
        $this->assertSame('<p></p>', pdf_generator::inline_images('<p><img src="https://example.com/meta.png"></p>'));
    }

    /**
     * build() renders through pdf_document, whose file helpers refuse every URL and
     * every server file except the document's own TCPDF temp files, so template HTML
     * that slips an <img> past inline_images() (or a <link> stylesheet, an "img|" list
     * bullet or an SVG <image>) still cannot make the server fetch or read anything.
     */
    public function test_build_uses_a_pdf_document_that_refuses_external_files(): void {
        $this->resetAfterTest();

        [$confcheckin, $tickettype, $ticket, $user] = $this->create_fixture();
        $pdf = pdf_generator::build($confcheckin, $tickettype, $ticket, $user, 'badge');
        $this->assertInstanceOf(pdf_document::class, $pdf);

        // The overrides only work while TCPDF still routes its reads through these names.
        $this->assertTrue(method_exists(\TCPDF::class, 'fileExists'));
        $this->assertTrue(method_exists(\TCPDF::class, 'getCachedFileContents'));

        $fileexists = new \ReflectionMethod($pdf, 'fileExists');
        $getcontents = new \ReflectionMethod($pdf, 'getCachedFileContents');
        foreach (['http://127.0.0.1:8080/x.png', 'https://example.com/x.css', __FILE__, 'file://' . __FILE__] as $file) {
            $this->assertFalse($fileexists->invoke($pdf, $file), $file);
            $this->assertFalse($getcontents->invoke($pdf, $file), $file);
        }

        // A temp file named like another document's (different file_id) is refused too.
        make_writable_directory(K_PATH_CACHE);
        $other = K_PATH_CACHE . '__tcpdf_' . md5('someone-else') . '_img_x';
        file_put_contents($other, 'x');
        $this->assertFalse($fileexists->invoke($pdf, $other));
        $this->assertFalse($getcontents->invoke($pdf, $other));
        unlink($other);
    }
}
