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

namespace mod_confcheckin;

use advanced_testcase;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Smoke tests for mod_confcheckin: confirms the plugin installs cleanly and
 * that a course-module instance can be created via the standard data
 * generator.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class confcheckin_test extends advanced_testcase {
    /**
     * An activity instance can be added to a course via the data generator,
     * and the resulting row exists in the confcheckin table.
     */
    public function test_instance_can_be_added_via_generator(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $confcheckin = $this->getDataGenerator()->create_module('confcheckin', [
            'course' => $course->id,
            'name'   => 'Test conference check-in',
        ]);

        $this->assertNotEmpty($confcheckin->id);

        global $DB;
        $record = $DB->get_record('confcheckin', ['id' => $confcheckin->id]);
        $this->assertNotFalse($record);
        $this->assertSame('Test conference check-in', $record->name);
    }

    /**
     * The privacy provider class exists and implements the expected interfaces.
     */
    public function test_privacy_provider_exists(): void {
        $this->assertTrue(class_exists(\mod_confcheckin\privacy\provider::class));
        $this->assertInstanceOf(
            \core_privacy\local\metadata\provider::class,
            new \mod_confcheckin\privacy\provider()
        );
        $this->assertInstanceOf(
            \core_privacy\local\request\plugin\provider::class,
            new \mod_confcheckin\privacy\provider()
        );
        $this->assertInstanceOf(
            \core_privacy\local\request\core_userlist_provider::class,
            new \mod_confcheckin\privacy\provider()
        );
    }

    /**
     * The privacy provider's get_metadata() fully describes the personal data
     * this plugin's tables hold, even though the request-side methods are
     * still Phase 4.6 stubs.
     */
    public function test_privacy_metadata_is_populated(): void {
        $collection = new \core_privacy\local\metadata\collection('mod_confcheckin');
        $collection = \mod_confcheckin\privacy\provider::get_metadata($collection);

        $tablenames = array_map(
            fn ($item) => $item->get_name(),
            $collection->get_collection()
        );

        $this->assertContains('confcheckin_ticket', $tablenames);
        $this->assertContains('confcheckin_checkin', $tablenames);
    }

    /**
     * The request-side privacy methods return honest empty/no-op results rather than
     * throwing, since no code path yet writes to confcheckin_ticket/confcheckin_checkin
     * -- a regression guard until Phase 4.6 gives them real bodies (see
     * classes/privacy/provider.php's docblock for the full rationale).
     */
    public function test_privacy_request_methods_are_safe_noops(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        $contextlist = \mod_confcheckin\privacy\provider::get_contexts_for_userid((int) $user->id);
        $this->assertCount(0, $contextlist);

        $userlist = new \core_privacy\local\request\userlist(\context_system::instance(), 'mod_confcheckin');
        \mod_confcheckin\privacy\provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist);

        $approvedcontextlist = new \core_privacy\local\request\approved_contextlist(
            $user,
            'mod_confcheckin',
            [\context_system::instance()->id]
        );
        \mod_confcheckin\privacy\provider::export_user_data($approvedcontextlist);
        \mod_confcheckin\privacy\provider::delete_data_for_user($approvedcontextlist);
        \mod_confcheckin\privacy\provider::delete_data_for_all_users_in_context(\context_system::instance());

        $approveduserlist = new \core_privacy\local\request\approved_userlist(
            \context_system::instance(),
            'mod_confcheckin',
            [$user->id]
        );
        \mod_confcheckin\privacy\provider::delete_data_for_users($approveduserlist);

        // Reaching this line without a thrown exception is the assertion.
        $this->assertTrue(true);
    }

    /**
     * confcheckin_supports() answers sensibly for the core feature constants used.
     */
    public function test_supports_returns_expected_values(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

        $this->assertTrue(confcheckin_supports(FEATURE_MOD_INTRO));
        $this->assertFalse(confcheckin_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertNull(confcheckin_supports('some_unknown_feature'));
    }
}
