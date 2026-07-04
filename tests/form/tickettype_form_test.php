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
        ], $overrides);
    }

    public function test_valid_data_passes(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, ['editing' => false]);
        $errors = $form->validation($this->base_data(), []);

        $this->assertSame([], $errors);
    }

    public function test_missing_name_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, ['editing' => false]);
        $errors = $form->validation($this->base_data(['name' => '  ']), []);

        $this->assertArrayHasKey('name', $errors);
    }

    /**
     * A negative price is rejected; zero and a normal positive amount pass.
     */
    public function test_price_validation(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, ['editing' => false]);

        $errors = $form->validation($this->base_data(['price' => '-5.00']), []);
        $this->assertArrayHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => 'not-a-number']), []);
        $this->assertArrayHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '0.00']), []);
        $this->assertArrayNotHasKey('price', $errors);

        $errors = $form->validation($this->base_data(['price' => '49.99']), []);
        $this->assertArrayNotHasKey('price', $errors);
    }

    public function test_invalid_currency_rejected(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, ['editing' => false]);
        $errors = $form->validation($this->base_data(['currency' => 'ZZZ']), []);

        $this->assertArrayHasKey('currency', $errors);
    }

    public function test_capacity_validation(): void {
        $this->resetAfterTest();

        $form = new tickettype_form(null, ['editing' => false]);

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

        $form = new tickettype_form(null, ['editing' => false]);
        $now = time();

        $errors = $form->validation($this->base_data(['validfrom' => $now, 'validto' => $now - DAYSECS]), []);
        $this->assertArrayHasKey('validto', $errors);

        $errors = $form->validation($this->base_data(['validfrom' => $now, 'validto' => $now + DAYSECS]), []);
        $this->assertArrayNotHasKey('validto', $errors);
    }
}
