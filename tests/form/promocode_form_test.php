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
 * Tests for \mod_confcheckin\form\promocode_form server-side validation, including the
 * friendly duplicate-code check and the instance-scoped tickettypeid check.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(promocode_form::class)]
final class promocode_form_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance with one ticket type and returns [confcheckinid, tickettypeid].
     *
     * @return array{0: int, 1: int}
     */
    private function create_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'   => $confcheckin->id,
            'name'          => 'Test ticket',
            'price'         => '0.00',
            'currency'      => 'USD',
            'presenteronly' => 0,
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'timecreated'   => time(),
            'timemodified'  => time(),
        ]);

        return [(int) $confcheckin->id, $tickettypeid];
    }

    /**
     * A minimal, otherwise-valid submitted-data array, so only the field under test can
     * fail.
     *
     * @param int $tickettypeid
     * @param array $overrides
     * @return array
     */
    private function base_data(int $tickettypeid, array $overrides = []): array {
        return array_merge([
            'promocodeid'  => 0,
            'code'         => 'ABC123',
            'tickettypeid' => $tickettypeid,
            'maxuses'      => '',
            'timeexpires'  => 0,
        ], $overrides);
    }

    public function test_valid_data_passes(): void {
        $this->resetAfterTest();
        [$confcheckinid, $tickettypeid] = $this->create_fixture();

        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
        ]);

        $errors = $form->validation($this->base_data($tickettypeid), []);
        $this->assertSame([], $errors);
    }

    public function test_duplicate_code_rejected(): void {
        $this->resetAfterTest();
        global $DB;
        [$confcheckinid, $tickettypeid] = $this->create_fixture();

        $DB->insert_record('confcheckin_promocode', (object) [
            'confcheckin'  => $confcheckinid,
            'code'         => 'ABC123',
            'tickettypeid' => $tickettypeid,
            'timesused'    => 0,
            'timecreated'  => time(),
        ]);

        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
        ]);

        $errors = $form->validation($this->base_data($tickettypeid, ['code' => 'ABC123']), []);
        $this->assertArrayHasKey('code', $errors);
    }

    /**
     * Editing a code without changing its own code string is NOT flagged as a duplicate
     * of itself, via the excludeid customdata.
     */
    public function test_editing_same_code_not_flagged_as_duplicate(): void {
        $this->resetAfterTest();
        global $DB;
        [$confcheckinid, $tickettypeid] = $this->create_fixture();

        $existingid = (int) $DB->insert_record('confcheckin_promocode', (object) [
            'confcheckin'  => $confcheckinid,
            'code'         => 'ABC123',
            'tickettypeid' => $tickettypeid,
            'timesused'    => 0,
            'timecreated'  => time(),
        ]);

        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
            'excludeid'         => $existingid,
        ]);

        $errors = $form->validation(
            $this->base_data($tickettypeid, ['promocodeid' => $existingid, 'code' => 'ABC123']),
            []
        );
        $this->assertArrayNotHasKey('code', $errors);
    }

    /**
     * A duplicate code in a DIFFERENT confcheckin instance is not flagged: codes are
     * only unique per instance.
     */
    public function test_duplicate_code_in_different_instance_allowed(): void {
        $this->resetAfterTest();
        global $DB;
        [, $tickettypeid] = $this->create_fixture();
        [$otherconfcheckinid, $othertickettypeid] = $this->create_fixture();

        $DB->insert_record('confcheckin_promocode', (object) [
            'confcheckin'  => $otherconfcheckinid,
            'code'         => 'SHARED',
            'tickettypeid' => $othertickettypeid,
            'timesused'    => 0,
            'timecreated'  => time(),
        ]);

        [$confcheckinid] = $this->create_fixture();
        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
        ]);

        $errors = $form->validation($this->base_data($tickettypeid, ['code' => 'SHARED']), []);
        $this->assertArrayNotHasKey('code', $errors);
    }

    /**
     * A tickettypeid outside the instance-scoped option set the caller provided is
     * rejected -- e.g. a ticket type belonging to a different confcheckin instance.
     */
    public function test_tickettypeid_must_be_in_offered_set(): void {
        $this->resetAfterTest();
        [$confcheckinid, $tickettypeid] = $this->create_fixture();
        [, $foreigntickettypeid] = $this->create_fixture();

        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
        ]);

        $errors = $form->validation($this->base_data($foreigntickettypeid), []);
        $this->assertArrayHasKey('tickettypeid', $errors);
    }

    public function test_invalid_maxuses_rejected(): void {
        $this->resetAfterTest();
        [$confcheckinid, $tickettypeid] = $this->create_fixture();

        $form = new promocode_form(null, [
            'confcheckinid'     => $confcheckinid,
            'tickettypeoptions' => [$tickettypeid => 'Test ticket'],
        ]);

        $errors = $form->validation($this->base_data($tickettypeid, ['maxuses' => '0']), []);
        $this->assertArrayHasKey('maxuses', $errors);

        $errors = $form->validation($this->base_data($tickettypeid, ['maxuses' => '5']), []);
        $this->assertArrayNotHasKey('maxuses', $errors);
    }
}
