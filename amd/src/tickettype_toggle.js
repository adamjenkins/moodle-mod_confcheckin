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

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Wires the "visible" quick-toggle switch on the Manage ticket types page
 * (tickettypes.php) to the mod_confcheckin_toggle_tickettype_visible AJAX
 * external function (user request, 2026-07-10) -- lets an organiser
 * enable/disable a ticket type without navigating to the edit form.
 *
 * @module     mod_confcheckin/tickettype_toggle
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Handles a single switch's change event: disables it while the request is
 * in flight, and reverts its checked state on failure (the server is always
 * the source of truth for what's actually saved).
 *
 * @param {Event} event The change event from a .mod_confcheckin-tickettype-visible-toggle checkbox
 * @return {void}
 */
const onToggle = (event) => {
    const checkbox = event.target;
    const cmid = Number(checkbox.dataset.cmid);
    const tickettypeid = Number(checkbox.dataset.tickettypeid);
    const desired = checkbox.checked;

    checkbox.disabled = true;
    Ajax.call([{
        methodname: 'mod_confcheckin_toggle_tickettype_visible',
        args: {cmid, tickettypeid, visible: desired},
    }])[0].then((result) => {
        checkbox.checked = result.visible;
        checkbox.disabled = false;
        return result;
    }).catch((error) => {
        checkbox.checked = !desired;
        checkbox.disabled = false;
        Notification.exception(error);
    });
};

/**
 * Wires every visible-toggle switch currently on the page.
 *
 * @return {void}
 */
export const init = () => {
    document.querySelectorAll('.mod_confcheckin-tickettype-visible-toggle').forEach((checkbox) => {
        checkbox.addEventListener('change', onToggle);
    });
};
