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

namespace mod_confcheckin\external;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the toggle_tickettype_visible AJAX external function.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(toggle_tickettype_visible::class)]
final class toggle_tickettype_visible_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance with one visible ticket type.
     *
     * @return array{0: \stdClass, 1: int, 2: int} [$course, $cmid, $tickettypeid]
     */
    private function create_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('confcheckin', $confcheckin->id);

        $tickettypeid = $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckin->id,
            'name'         => 'Test ticket',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        return [$course, (int) $cm->id, (int) $tickettypeid];
    }

    /**
     * A user with mod/confcheckin:managetickettypes can toggle a ticket type's
     * visible flag off, then back on.
     */
    public function test_editingteacher_can_toggle_visible(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $cmid, $tickettypeid] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            toggle_tickettype_visible::execute_returns(),
            toggle_tickettype_visible::execute($cmid, $tickettypeid, false)
        );
        $this->assertFalse($result['visible']);
        $this->assertSame('0', $DB->get_field('confcheckin_tickettype', 'visible', ['id' => $tickettypeid]));

        $result = \core_external\external_api::clean_returnvalue(
            toggle_tickettype_visible::execute_returns(),
            toggle_tickettype_visible::execute($cmid, $tickettypeid, true)
        );
        $this->assertTrue($result['visible']);
        $this->assertSame('1', $DB->get_field('confcheckin_tickettype', 'visible', ['id' => $tickettypeid]));
    }

    /**
     * A plain student (no mod/confcheckin:managetickettypes) cannot toggle.
     */
    public function test_student_cannot_toggle_visible(): void {
        $this->resetAfterTest();

        [$course, $cmid, $tickettypeid] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        toggle_tickettype_visible::execute($cmid, $tickettypeid, false);
    }

    /**
     * A ticket type belonging to a DIFFERENT confcheckin instance is rejected
     * (chain-of-custody / IDOR guard), even for an organiser who genuinely holds
     * managetickettypes on the instance whose cmid they passed.
     */
    public function test_tickettype_from_another_instance_is_rejected(): void {
        $this->resetAfterTest();

        [$course, $cmid] = $this->create_fixture();
        [, , $othertickettypeid] = $this->create_fixture();

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->expectException(\moodle_exception::class);
        toggle_tickettype_visible::execute($cmid, $othertickettypeid, false);
    }
}
