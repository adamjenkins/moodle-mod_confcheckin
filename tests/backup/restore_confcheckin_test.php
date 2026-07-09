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

namespace mod_confcheckin\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');
require_once($CFG->dirroot . '/mod/confcheckin/backup/moodle2/restore_confcheckin_stepslib.php');

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Backup/restore tests for mod_confcheckin (user request, 2026-07-06: "Also make sure
 * backup/restore/reset all works fine with all plugins").
 *
 * The critical things this exercises: same-plugin foreign keys (tickettypeid,
 * promocodeid) correctly remapped; a ticket's qrtoken is regenerated (never carried
 * over, since it carries a sitewide UNIQUE constraint and reusing the same secret would
 * let an original printed badge check in against the restored copy too); and
 * soldcount is recomputed from the actually-restored tickets rather than carried over
 * verbatim.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\restore_confcheckin_activity_structure_step::class)]
final class restore_confcheckin_test extends \restore_date_testcase {
    /**
     * A full backup/restore round-trip correctly reconstructs a confcheckin instance's
     * ticket types, promo codes, templates, tickets and check-ins, with same-plugin
     * foreign keys remapped, a fresh qrtoken issued per restored ticket, and soldcount
     * recomputed from the restored tickets.
     */
    public function test_backup_and_restore_remaps_and_regenerates(): void {
        global $DB;

        [$course, $confcheckin] = $this->create_course_and_module('confcheckin');

        $now = time();
        $tickettypeid = (int) $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'  => $confcheckin->id,
            'name'         => 'Standard',
            'price'        => '0.00',
            'currency'     => 'USD',
            'capacity'     => null,
            'maxperuser'   => 4,
            'sortorder'    => 0,
            'visible'      => 1,
            'soldcount'    => 999, // Deliberately wrong -- must be recomputed, not carried over.
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $promocodeid = (int) $DB->insert_record('confcheckin_promocode', (object) [
            'confcheckin'  => $confcheckin->id,
            'code'         => 'FREEBIE',
            'tickettypeid' => $tickettypeid,
            'maxuses'      => 10,
            'timesused'    => 1,
            'timecreated'  => $now,
        ]);
        $DB->insert_record('confcheckin_template', (object) [
            'confcheckin'  => $confcheckin->id,
            'templatetype' => 'badge',
            'content'      => '<p>[[fullname]]</p>',
            'contentformat' => FORMAT_HTML,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);

        $attendee = $this->getDataGenerator()->create_user();
        $staff = $this->getDataGenerator()->create_user();

        $ticket = \mod_confcheckin\local\ticket_service::redeem_promocode(
            (int) $confcheckin->id,
            'FREEBIE',
            (int) $attendee->id
        );
        $oldqrtoken = $ticket->qrtoken;

        \mod_confcheckin\local\checkin_service::record_checkin((int) $confcheckin->id, $oldqrtoken, (int) $staff->id);

        $newcourseid = $this->backup_and_restore($course);

        $newconfcheckin = $DB->get_record('confcheckin', ['course' => $newcourseid], '*', MUST_EXIST);

        $newtickettype = $DB->get_record(
            'confcheckin_tickettype',
            ['confcheckin' => $newconfcheckin->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('Standard', $newtickettype->name);
        // maxperuser must round-trip: it was missing from the backup field list, so
        // every restore silently converted custom caps (and "unlimited") to the
        // column default of 1 (FABLE.md review, 2026-07-09).
        $this->assertSame(4, (int) $newtickettype->maxperuser);
        // The critical soldcount check: recomputed from the restored ticket, not the
        // deliberately-wrong 999 carried over from the backup.
        $this->assertSame(1, (int) $newtickettype->soldcount);

        $newpromocode = $DB->get_record('confcheckin_promocode', ['confcheckin' => $newconfcheckin->id], '*', MUST_EXIST);
        $this->assertSame((int) $newtickettype->id, (int) $newpromocode->tickettypeid);
        $this->assertNotSame($promocodeid, (int) $newpromocode->id);

        $newticket = $DB->get_record('confcheckin_ticket', ['confcheckin' => $newconfcheckin->id], '*', MUST_EXIST);
        $this->assertSame((int) $newtickettype->id, (int) $newticket->tickettypeid);
        $this->assertSame((int) $newpromocode->id, (int) $newticket->promocodeid);

        // The critical qrtoken check: regenerated, never the original value.
        $this->assertNotSame($oldqrtoken, $newticket->qrtoken);
        $this->assertSame(64, strlen($newticket->qrtoken));

        $newcheckin = $DB->get_record('confcheckin_checkin', ['ticketid' => $newticket->id], '*', MUST_EXIST);
        $this->assertSame((int) $staff->id, (int) $newcheckin->scannedby);

        $newtemplate = $DB->get_record('confcheckin_template', ['confcheckin' => $newconfcheckin->id], '*', MUST_EXIST);
        $this->assertSame('<p>[[fullname]]</p>', $newtemplate->content);
    }

    /**
     * addtogroupid (user request, 2026-07-07) is a course-level group reference, same
     * remapping class as groupid/eligibilitygroupid -- must be resolved to the
     * RESTORED copy of the group in after_restore(), never left pointing at the
     * original course's group id.
     */
    public function test_addtogroupid_is_remapped(): void {
        global $DB;

        [$course, $confcheckin] = $this->create_course_and_module('confcheckin');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Volunteers']);

        $now = time();
        $DB->insert_record('confcheckin_tickettype', (object) [
            'confcheckin'   => $confcheckin->id,
            'name'          => 'Standard',
            'price'         => '0.00',
            'currency'      => 'USD',
            'sortorder'     => 0,
            'visible'       => 1,
            'soldcount'     => 0,
            'addtogroupid'  => $group->id,
            'timecreated'   => $now,
            'timemodified'  => $now,
        ]);

        $newcourseid = $this->backup_and_restore($course);

        $newconfcheckin = $DB->get_record('confcheckin', ['course' => $newcourseid], '*', MUST_EXIST);
        $newtickettype = $DB->get_record(
            'confcheckin_tickettype',
            ['confcheckin' => $newconfcheckin->id],
            '*',
            MUST_EXIST
        );
        $newgroup = $DB->get_record('groups', ['courseid' => $newcourseid, 'name' => 'Volunteers'], '*', MUST_EXIST);

        $this->assertSame((int) $newgroup->id, (int) $newtickettype->addtogroupid);
        $this->assertNotSame((int) $group->id, (int) $newtickettype->addtogroupid);
    }
}
