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

namespace mod_confcheckin\form;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for \mod_confcheckin\form\tickettype_form server-side validation.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(tickettype_form::class)]
final class tickettype_form_test extends advanced_testcase {
    /**
     * Customdata required by definition() (Phase 4.5 follow-up: auto-grant selects).
     * groupid 5 / enrolid 7 are included as valid options since several tests below
     * submit those ids to exercise the mutual-exclusivity check specifically, not
     * the separate "must be one of the offered options" check.
     */
    private const FORM_CUSTOMDATA = [
        'editing'      => false,
        'groupoptions' => [0 => 'None', 5 => 'Test group'],
        'enroloptions' => [0 => 'None', 7 => 'Test enrolment method'],
    ];

    /**
     * A minimal, otherwise-valid submitted-data array, so only the field under test
     * can fail.
     *
     * @param array $overrides
     * @return array
     */
    private function base_data(array $overrides = []): array {
        return array_merge([
            'tickettypeid'  => 0,
            'name'          => 'Test ticket',
            'price'         => '10.00',
            'currency'      => 'USD',
            'capacity'      => '',
            'presenteronly' => 0,
            'validfrom'     => 0,
            'validto'       => 0,
            'sortorder'     => 0,
            'visible'       => 1,
            'groupid'             => 0,
            'enrolid'             => 0,
            'eligibilitygroupid'  => 0,
            'eligibilityenrolid'  => 0,
        ], $overrides);
    }

    public function test_valid_data_passes(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);
        $errors = $form->validation($this->base_data(), []);

        $this->assertSame([], $errors);
    }

    public function test_missing_name_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);
        $errors = $form->validation($this->base_data(['name' => '  ']), []);

        $this->assertArrayHasKey('name', $errors);
    }

    /**
     * A negative price is rejected; zero and a normal positive amount pass.
     */
    public function test_price_validation(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['price' => '-5.00']), []);
        $this->assertArrayHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => 'not-a-number']), []);
        $this->assertArrayHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '0.00']), []);
        $this->assertArrayNotHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '49.99']), []);
        $this->assertArrayNotHasKey('price', $errors);
    }

    /**
     * A zero-decimal currency (JPY -- this plugin's default) rejects fractional
     * prices: gateways refuse them at checkout time, so the form must catch
     * "¥500.50" here instead of every buyer failing opaquely (FABLE.md review,
     * 2026-07-09). Whole amounts, and fractional amounts in decimal currencies,
     * still pass.
     */
    public function test_zero_decimal_currency_rejects_fractional_price(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['price' => '500.50', 'currency' => 'JPY']), []);
        $this->assertArrayHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '500', 'currency' => 'JPY']), []);
        $this->assertArrayNotHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '500.50', 'currency' => 'USD']), []);
        $this->assertArrayNotHasKey('price', $errors);
    }

    /**
     * maxperuser accepts blank (unlimited) and positive whole numbers, rejecting
     * zero, negatives and non-numeric input (FABLE.md review, 2026-07-09 -- the
     * field shipped without a validation test).
     */
    public function test_maxperuser_validation(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        foreach (['' => false, '1' => false, '4' => false, '0' => true, '-2' => true, 'lots' => true] as $value => $iserror) {
            $errors = $form->validation($this->base_data(['maxperuser' => (string) $value]), []);
            if ($iserror) {
                $this->assertArrayHasKey('maxperuser', $errors, "'$value' should be rejected");
            } else {
                $this->assertArrayNotHasKey('maxperuser', $errors, "'$value' should be accepted");
            }
        }
    }

    public function test_invalid_currency_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);
        $errors = $form->validation($this->base_data(['currency' => 'ZZZ']), []);

        $this->assertArrayHasKey('currency', $errors);
    }

    public function test_capacity_validation(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['capacity' => '']), []);
        $this->assertArrayNotHasKey('capacity', $errors);

        $errors = $form->validation($this->base_data(['capacity' => '10']), []);
        $this->assertArrayNotHasKey('capacity', $errors);

        $errors = $form->validation($this->base_data(['capacity' => '0']), []);
        $this->assertArrayHasKey('capacity', $errors);

        $errors = $form->validation($this->base_data(['capacity' => '-1']), []);
        $this->assertArrayHasKey('capacity', $errors);

        $errors = $form->validation($this->base_data(['capacity' => 'abc']), []);
        $this->assertArrayHasKey('capacity', $errors);
    }

    public function test_validto_before_validfrom_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);
        $now = time();

        $errors = $form->validation($this->base_data(['validfrom' => $now, 'validto' => $now - DAYSECS]), []);
        $this->assertArrayHasKey('validto', $errors);

        $errors = $form->validation($this->base_data(['validfrom' => $now, 'validto' => $now + DAYSECS]), []);
        $this->assertArrayNotHasKey('validto', $errors);
    }

    /**
     * Auto-grant via a group and via an enrolment method are mutually exclusive;
     * either alone, or neither, is fine.
     */
    public function test_autogrant_group_and_enrol_are_mutually_exclusive(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['groupid' => 5, 'enrolid' => 7]), []);
        $this->assertArrayHasKey('enrolid', $errors);

        $errors = $form->validation($this->base_data(['groupid' => 5, 'enrolid' => 0]), []);
        $this->assertArrayNotHasKey('enrolid', $errors);

        $errors = $form->validation($this->base_data(['groupid' => 0, 'enrolid' => 7]), []);
        $this->assertArrayNotHasKey('enrolid', $errors);

        $errors = $form->validation($this->base_data(['groupid' => 0, 'enrolid' => 0]), []);
        $this->assertSame([], $errors);
    }

    /**
     * A groupid/enrolid not among the options this course's select was actually
     * rendered with (e.g. one belonging to a different course) is rejected, even
     * though it's individually well-formed -- a crafted POST must not be able to
     * link a ticket type to another course's group or enrolment method.
     */
    public function test_autogrant_id_not_in_offered_options_is_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['groupid' => 999, 'enrolid' => 0]), []);
        $this->assertArrayHasKey('groupid', $errors);

        $errors = $form->validation($this->base_data(['groupid' => 0, 'enrolid' => 999]), []);
        $this->assertArrayHasKey('enrolid', $errors);
    }

    /**
     * The eligibility requirement's group and enrolment method are mutually
     * exclusive (user request, 2026-07-06), same rule as auto-grant's groupid/enrolid
     * above but a wholly separate pair of fields.
     */
    public function test_eligibility_group_and_enrol_are_mutually_exclusive(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 5, 'eligibilityenrolid' => 7]), []);
        $this->assertArrayHasKey('eligibilityenrolid', $errors);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 5, 'eligibilityenrolid' => 0]), []);
        $this->assertArrayNotHasKey('eligibilityenrolid', $errors);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 0, 'eligibilityenrolid' => 7]), []);
        $this->assertArrayNotHasKey('eligibilityenrolid', $errors);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 0, 'eligibilityenrolid' => 0]), []);
        $this->assertSame([], $errors);
    }

    /**
     * An eligibilitygroupid/eligibilityenrolid not among the options this course's
     * select was actually rendered with is rejected, same IDOR-prevention rule as
     * auto-grant's groupid/enrolid above.
     */
    public function test_eligibility_id_not_in_offered_options_is_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, self::FORM_CUSTOMDATA);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 999, 'eligibilityenrolid' => 0]), []);
        $this->assertArrayHasKey('eligibilitygroupid', $errors);

        $errors = $form->validation($this->base_data(['eligibilitygroupid' => 0, 'eligibilityenrolid' => 999]), []);
        $this->assertArrayHasKey('eligibilityenrolid', $errors);
    }
}
