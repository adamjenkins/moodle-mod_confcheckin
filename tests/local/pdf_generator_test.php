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

        $course = $this->getDataGenerator()->create_course();
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
}
