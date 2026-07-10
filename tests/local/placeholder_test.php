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
     * delimited text in the rendered output. Uses the default `[[name]]` delimiter
     * pair (mod_confcheckin/delimiterstart/delimiterend admin settings, unset here).
     */
    public function test_render_substitutes_known_and_drops_unknown_placeholders(): void {
        $this->resetAfterTest();

        $context = ['fullname' => 'Ada Lovelace', 'tickettype' => 'Presenter'];

        $result = placeholder::render(
            'Hello [[fullname]], your ticket is [[tickettype]]. Note: [[doesnotexist]].',
            $context
        );

        $this->assertSame('Hello Ada Lovelace, your ticket is Presenter. Note: .', $result);
    }

    /**
     * render() honours the sitewide configured delimiter pair, not a fixed one --
     * changing the admin setting changes what render() recognises.
     */
    public function test_render_honours_configured_delimiters(): void {
        $this->resetAfterTest();

        set_config('delimiterstart', '{{', 'mod_confcheckin');
        set_config('delimiterend', '}}', 'mod_confcheckin');

        $context = ['fullname' => 'Ada Lovelace'];

        // The OLD default ([[ ]]) is no longer recognised once the config changes...
        $this->assertSame('Hello [[fullname]].', placeholder::render('Hello [[fullname]].', $context));
        // ...but the newly-configured one ({{ }}) is.
        $this->assertSame('Hello Ada Lovelace.', placeholder::render('Hello {{fullname}}.', $context));
    }

    /**
     * render() performs a raw string substitution: a value containing HTML (as
     * 'qrcode' deliberately does -- see build_context()) is inserted as markup, not
     * escaped a second time.
     */
    public function test_render_inserts_html_values_unescaped(): void {
        $this->resetAfterTest();

        $context = ['qrcode' => '<img src="data:image/png;base64,AAAA" alt="" />'];

        $result = placeholder::render('<div>[[qrcode]]</div>', $context);

        $this->assertSame('<div><img src="data:image/png;base64,AAAA" alt="" /></div>', $result);
    }

    /**
     * wrap() builds an example delimited placeholder using whatever delimiter pair
     * is currently configured -- used by templates.php's "available placeholders"
     * help text so it always matches reality.
     */
    public function test_wrap_uses_configured_delimiters(): void {
        $this->resetAfterTest();

        $this->assertSame('[[fullname]]', placeholder::wrap('fullname'));

        set_config('delimiterstart', '{{', 'mod_confcheckin');
        set_config('delimiterend', '}}', 'mod_confcheckin');

        $this->assertSame('{{fullname}}', placeholder::wrap('fullname'));
    }

    /**
     * build_context() HTML-escapes user-supplied text values (fullname/email), so a
     * name/email containing HTML-significant characters cannot inject markup into the
     * rendered PDF's source HTML.
     */
    public function test_build_context_escapes_user_supplied_text(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        // A confprogramcmid-less confcheckin (unset here) means build_context() never
        // actually looks up a presenter submission, but it still reads $confcheckin->id
        // (to look up this template type's own presentationinfoformat) and $user->id
        // (as part of that no-op eligibility check), so a hand-built stub needs both.
        $confcheckin = (object) ['id' => 0, 'name' => 'My Conf', 'course' => $course->id];
        $tickettype = (object) ['name' => 'General', 'price' => '0.00', 'currency' => 'USD'];
        $ticket = (object) ['origin' => 'free', 'qrtoken' => 'abc123'];
        $user = (object) ['id' => 0, 'firstname' => 'A & B', 'lastname' => 'Ltd', 'email' => 'a@b.com'];
        // Moodle's fullname() needs firstnamephonetic/etc fields to be at least set
        // (unset ok, treated as empty) -- also alternatename/middlename.
        $user->firstnamephonetic = '';
        $user->lastnamephonetic = '';
        $user->middlename = '';
        $user->alternatename = '';

        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user, 'badge');

        $this->assertStringContainsString('&amp;', $context['fullname']);
        $this->assertStringNotContainsString('A & B', $context['fullname']);
    }

    /**
     * build_context() includes 'coursefullname'/'courseshortname' for this
     * instance's own course.
     */
    public function test_build_context_includes_course_fields(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'My Course', 'shortname' => 'MC101']);
        $confcheckin = (object) ['id' => 0, 'name' => 'My Conf', 'course' => $course->id];
        $tickettype = (object) ['name' => 'General', 'price' => '0.00', 'currency' => 'USD'];
        $ticket = (object) ['origin' => 'free', 'qrtoken' => 'abc123'];
        $user = $this->getDataGenerator()->create_user();

        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user, 'badge');

        $this->assertSame('My Course', $context['coursefullname']);
        $this->assertSame('MC101', $context['courseshortname']);
    }

    /**
     * build_context() includes 'cost', formatted via
     * \core_payment\helper::get_cost_as_string() (same formatter tickettypes.php's
     * Manage page uses), sourced from the ticket type's own price/currency.
     */
    public function test_build_context_includes_cost_field(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = (object) ['id' => 0, 'name' => 'My Conf', 'course' => $course->id];
        $tickettype = (object) ['name' => 'Presenter', 'price' => '25.00', 'currency' => 'USD'];
        $ticket = (object) ['origin' => 'free', 'qrtoken' => 'abc123'];
        $user = $this->getDataGenerator()->create_user();

        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $user, 'badge');

        $this->assertSame(
            \core_payment\helper::get_cost_as_string(25.00, 'USD'),
            $context['cost']
        );
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
        $context = placeholder::build_context($confcheckin, $tickettype, $presenterticket, $presenter, 'badge');
        $this->assertSame('My Great Talk', $context['submissiontitle']);
        $this->assertSame('Security', $context['track']);
        // Default presentationinfo format is just the title (see
        // placeholder::DEFAULT_PRESENTATIONINFO_FORMAT's docblock for why track is
        // deliberately excluded from the default).
        $this->assertSame('My Great Talk', $context['presentationinfo']);

        $bystander = $this->getDataGenerator()->create_user();
        $bystanderticket = ticket_service::issue_free_ticket((int) $confcheckin->id, $tickettypeid, (int) $bystander->id);
        $bystandercontext = placeholder::build_context($confcheckin, $tickettype, $bystanderticket, $bystander, 'badge');
        $this->assertSame('', $bystandercontext['submissiontitle']);
        $this->assertSame('', $bystandercontext['track']);
        $this->assertSame('', $bystandercontext['presentationinfo']);
    }

    /**
     * {{presentationinfo}} lists EVERY accepted submission a presenter speaks on
     * (unlike {{submissiontitle}}/{{track}}, which only ever cover the first),
     * each rendered through this template TYPE's own configured
     * confcheckin_template.presentationinfoformat and joined with a line break.
     */
    public function test_build_context_presentationinfo_lists_every_presentation(): void {
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
        $decider = $this->getDataGenerator()->create_user();

        $firstid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $presenter->id,
            'title'           => 'First Talk',
            'abstract'        => 'An abstract.',
            'trackid'         => $trackid,
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($firstid, [['userid' => $presenter->id]]);
        \mod_confprogram\api::record_decision((int) $confprogram->id, $firstid, 'accept', 1, (int) $decider->id);

        $secondid = (int) $DB->insert_record('confsubmissions_submission', (object) [
            'confsubmissions' => $confsubmissions->id,
            'userid'          => $presenter->id,
            'title'           => 'Second Talk',
            'abstract'        => 'Another abstract.',
            'status'          => 'submitted',
            'timecreated'     => time(),
            'timemodified'    => time(),
        ]);
        \mod_confsubmissions\api::sync_speakers($secondid, [['userid' => $presenter->id]]);
        \mod_confprogram\api::record_decision((int) $confprogram->id, $secondid, 'accept', 1, (int) $decider->id);

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
        $ticket = ticket_service::issue_free_ticket((int) $confcheckin->id, $tickettypeid, (int) $presenter->id);

        // Default format (no confcheckin_template row yet): just the title, joined.
        $context = placeholder::build_context($confcheckin, $tickettype, $ticket, $presenter, 'badge');
        $this->assertSame('First Talk<br>Second Talk', $context['presentationinfo']);

        // A configured per-type format string is applied per submission, and is
        // specific to this one template type ('badge') -- a different type
        // ('ticket') with no row of its own still falls back to the default.
        $DB->insert_record('confcheckin_template', (object) [
            'confcheckin'            => $confcheckin->id,
            'templatetype'           => 'badge',
            'content'                => '',
            'contentformat'          => FORMAT_HTML,
            'presentationinfoformat' => '{title} ({track})',
            'timecreated'            => time(),
            'timemodified'           => time(),
        ]);
        // A direct DB write, standing in for what templates.php's save handler does in
        // real usage -- that handler also calls forget_presentationinfo_format()
        // immediately after saving (see templates.php), specifically so a save-then-render
        // within the same request doesn't see a stale per-request-cached default/older
        // format. Without this, the read below would incorrectly still return the
        // pre-insert default cached by the build_context() call above.
        placeholder::forget_presentationinfo_format((int) $confcheckin->id, 'badge');

        $badgecontext = placeholder::build_context($confcheckin, $tickettype, $ticket, $presenter, 'badge');
        $this->assertSame('First Talk (Security)<br>Second Talk ()', $badgecontext['presentationinfo']);

        $ticketcontext = placeholder::build_context($confcheckin, $tickettype, $ticket, $presenter, 'ticket');
        $this->assertSame('First Talk<br>Second Talk', $ticketcontext['presentationinfo']);
    }
}
