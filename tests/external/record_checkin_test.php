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
use mod_confcheckin\local\ticket_service;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the record_checkin AJAX external function: capability enforcement on
 * top of the already-unit-tested \mod_confcheckin\local\checkin_service logic.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(record_checkin::class)]
final class record_checkin_test extends advanced_testcase {
    /**
     * Creates a confcheckin instance (with an enrolled editingteacher and an issued
     * ticket) and returns everything a test needs.
     *
     * @return array{0: \stdClass, 1: int, 2: \stdClass}[$course, $cmid, $ticket]
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

        $attendee = $this->getDataGenerator()->create_user();
        $ticket = (object) [
            'confcheckin'  => $confcheckin->id,
            'tickettypeid' => $tickettypeid,
            'userid'       => $attendee->id,
            'origin'       => 'free',
            'promocodeid'  => null,
            'qrtoken'      => ticket_service::generate_qrtoken(),
            'timecreated'  => time(),
            'timemodified' => time(),
        ];
        $ticket->id = $DB->insert_record('confcheckin_ticket', $ticket);

        return [$course, (int) $cm->id, $ticket];
    }

    /**
     * A user with mod/confcheckin:scancheckin can record a check-in.
     */
    public function test_editingteacher_can_record_checkin(): void {
        $this->resetAfterTest();

        [$course, $cmid, $ticket] = $this->create_fixture();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = \core_external\external_api::clean_returnvalue(
            record_checkin::execute_returns(),
            record_checkin::execute($cmid, $ticket->qrtoken)
        );

        $this->assertSame((int) $ticket->id, $result['ticketid']);
        $this->assertFalse($result['alreadycheckedin']);
    }

    /**
     * A plain student (no scancheckin capability) cannot call this endpoint, even
     * for a real, valid token.
     */
    public function test_student_cannot_record_checkin(): void {
        $this->resetAfterTest();

        [$course, $cmid, $ticket] = $this->create_fixture();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        record_checkin::execute($cmid, $ticket->qrtoken);
    }
}
