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
 * Tests for \mod_confcheckin\local\placeholder: context building (including QR
 * uniqueness per token) and template substitution (including the "unknown
 * placeholder silently drops" and HTML-escaping behaviours).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(placeholder::class)]
final class placeholder_test extends advanced_testcase {
    /**
     * render() substitutes every recognised placeholder and drops (replaces with '')
     * any placeholder the context has no entry for, rather than leaving the literal
     * `{{name}}` text in the rendered output.
     */
    public function test_render_substitutes_known_and_drops_unknown_placeholders(): void {
        $context = ['fullname' => 'Ada Lovelace', 'tickettype' => 'Presenter'];

        $result = placeholder::render(
            'Hello {{fullname}}, your ticket is {{tickettype}}. Note: {{doesnotexist}}.',
            $context
        );

        $this->assertSame('Hello Ada Lovelace, your ticket is Presenter. Note: .', $result);
    }

    /**
     * render() performs a raw string substitution: a value containing HTML (as
     * 'qrcode' deliberately does -- see build_context()) is inserted as markup, not
     * escaped a second time.
     */
    public function test_render_inserts_html_values_unescaped(): void {
        $context = ['qrcode' => '<img src="data:image/png;base64,AAAA" alt="" />'];

        $result = placeholder::render('<div>{{qrcode}}</div>', $context);

        $this->assertSame('<div><img src="data:image/png;base64,AAAA" alt="" /></div>', $result);
    }

    /**
     * build_context() HTML-escapes user-supplied text values (fullname/email), so a
     * name/email containing HTML-significant characters cannot inject markup into the
     * rendered PDF's source HTML.
     */
    public function test_build_context_escapes_user_supplied_text(): void {
        $this->resetAfterTest();

        $confcheckin = (object) ['name' => 'My Conf'];
        $tickettype = (object) ['name' => 'General'];
        $ticket = (object) ['origin' => 'free', 'qrtoken' => 'abc123'];
        // A confprogramcmid-less confcheckin (unset here) means build_context() never
        // actually looks up a presenter submission, but it does read $user->id as part
        // of that no-op check, so a hand-built stub still needs one.
        $user = (object) ['id' => 0, 'firstname' => 'A & B', 'lastname' => 'Ltd', 'email' => 'a@b.com'];
        // Moodle's fullname() needs firstnamephonetic/etc fields to be at least set
        // (unset ok, treated as empty) -- also alternatename/middlename.
        $user->firstnamephonetic = '';
        $user->lastnamephonetic = '';
        $user->middlename = '';
        $user->alternatename = '';

        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user);

        $this->assertStringContainsString('&amp;', $context['fullname']);
        $this->assertStringNotContainsString('A & B', $context['fullname']);
    }

    /**
     * qr_image_tag() produces a distinct image (base64 payload) for two different
     * tokens -- a sanity check that the QR encodes the token itself, not a constant
     * placeholder image.
     */
    public function test_qr_image_tag_differs_per_token(): void {
        $tag1 = placeholder::qr_image_tag('token-one');
        $tag2 = placeholder::qr_image_tag('token-two');

        $this->assertStringStartsWith('<img src="data:image/png;base64,', $tag1);
        $this->assertNotSame($tag1, $tag2);
    }

    /**
     * build_context() populates {{submissiontitle}}/{{track}} only for a ticket held
     * by an eligible presenter (an accepted-submission speaker on the linked
     * mod_confprogram instance -- see classes/local/eligibility.php), leaving both ''
     * for any other ticket holder, e.g. a plain attendee who bought a non-presenter
     * ticket type.
     */
    public function test_build_context_includes_presenter_fields_only_for_a_presenter(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();

        $confsubmissions = $this->getDataGenerator()->create_module('confsubmissions', ['course' => $course->id]);
        $confsubmissionscm = get_coursemodule_from_instance('confsubmissions', $confsubmissions->id);
        $trackid = \mod_confsubmissions\api::add_track((int) $confsubmissions->id, 'Security');

        $confprogram = $this->getDataGenerator()->create_module('confprogram', [
            'course'              => $course->id,
            'confsubmissionscmid' => $confsubmissionscm->id,
        ]);
        $confprogramcm = get_coursemodule_from_instance('confprogram', $confprogram->id);

        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', [
            'course'          => $course->id,
            'confprogramcmid' => $confprogramcm->id,
        ]);

        $presenter = $this->getDataGenerator()->create_user();
        $submissionid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $presenter->id,
            'title'           => 'My Great Talk',
            'abstract'        => 'An abstract.',
            'trackid'         => $trackid,
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($submissionid, [['userid' => $presenter->id]]);
        $decider = $this->getDataGenerator()->create_user();
        \mod_confprogram\api::record_decision((int) $confprogram->id, $submissionid, 'accept', 1, (int) $decider->id);

        $tickettypeid = $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckin->id,
            'name'         => 'Presenter Pass',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $tickettype = $DB->get_record('confcheckin_tickettype', ['id' => $tickettypeid], '*', MUST_EXIST);

        $presenterticket = ticket_service::issue_free_ticket((int) $confcheckin->id, $tickettypeid, (int) $presenter->id);
        $context = placeholder::build_context($confcheckin, $tickettype, $presenterticket, $presenter);
        $this->assertSame('My Great Talk', $context['submissiontitle']);
        $this->assertSame('Security', $context['track']);

        $bystander = $this->getDataGenerator()->create_user();
        $bystanderticket = ticket_service::issue_free_ticket((int) $confcheckin->id, $tickettypeid, (int) $bystander->id);
        $bystandercontext = placeholder::build_context($confcheckin, $tickettype, $bystanderticket, $bystander);
        $this->assertSame('', $bystandercontext['submissiontitle']);
        $this->assertSame('', $bystandercontext['track']);
    }
}
