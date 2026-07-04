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
 * Tests for \mod_confcheckin\local\instance_helper's instance-scoping lookups: the same
 * "does this id belong to this instance, with an identical error either way" pattern
 * used throughout this project (see RELATIONS.md).
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(instance_helper::class)]
final class instance_helper_test extends advanced_testcase {
    public function test_require_tickettype_in_instance_no_enumeration_oracle(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckina = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $confcheckinb = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckinb->id,
            'name'         => 'Belongs to B',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);

        // A ticket type belonging to instance B, looked up scoped to instance A.
        $wronginstancemessage = null;
        try {
            instance_helper::require_tickettype_in_instance((int) $confcheckina->id, $tickettypeid);
        } catch (\moodle_exception $e) {
            $wronginstancemessage = $e->getMessage();
        }

        // A tickettypeid that does not exist at all.
        $nonexistentmessage = null;
        try {
            instance_helper::require_tickettype_in_instance((int) $confcheckina->id, $tickettypeid + 999999);
        } catch (\moodle_exception $e) {
            $nonexistentmessage = $e->getMessage();
        }

        $this->assertNotNull($wronginstancemessage);
        $this->assertSame($wronginstancemessage, $nonexistentmessage);

        // Scoped to its real instance, the lookup succeeds.
        $record = instance_helper::require_tickettype_in_instance((int) $confcheckinb->id, $tickettypeid);
        $this->assertSame($tickettypeid, (int) $record->id);
    }

    public function test_require_promocode_in_instance_no_enumeration_oracle(): void {
        $this->resetAfterTest();
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $confcheckina = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);
        $confcheckinb = $this->getDataGenerator()->create_module('confcheckin', ['course' => $course->id]);

        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckinb->id,
            'name'         => 'Belongs to B',
            'price'        => '0.00',
            'currency'     => 'USD',
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $promocodeid = (int) $DB->insert_record('confcheckin_promocode', (object) [
            'confcheckin'  => $confcheckinb->id,
            'code'         => 'BCODE',
            'tickettypeid' => $tickettypeid,
            'timesused'    => 0,
            'timecreated'  => time(),
        ]);

        $wronginstancemessage = null;
        try {
            instance_helper::require_promocode_in_instance((int) $confcheckina->id, $promocodeid);
        } catch (\moodle_exception $e) {
            $wronginstancemessage = $e->getMessage();
        }

        $nonexistentmessage = null;
        try {
            instance_helper::require_promocode_in_instance((int) $confcheckina->id, $promocodeid + 999999);
        } catch (\moodle_exception $e) {
            $nonexistentmessage = $e->getMessage();
        }

        $this->assertNotNull($wronginstancemessage);
        $this->assertSame($wronginstancemessage, $nonexistentmessage);
    }
}
