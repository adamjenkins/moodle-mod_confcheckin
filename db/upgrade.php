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

/**
 * Upgrade steps for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs upgrade steps between versions.
 *
 * No upgrade steps yet: the initial schema is installed directly via
 * db/install.xml. Add xmldb_confcheckin_upgrade() savepoint blocks here
 * (see mod_confsubmissions's/mod_confscheduler's db/upgrade.php for the
 * established pattern) once a schema/capability change ships after this
 * scaffold, per the moodle-bump-version skill workflow.
 *
 * @param int $oldversion Plugin version being upgraded from
 * @return bool
 */
function xmldb_confcheckin_upgrade($oldversion) {
    return true;
}
