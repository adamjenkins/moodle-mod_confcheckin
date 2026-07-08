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
 * Client-side sortable columns for report.php's check-in report (user request,
 * 2026-07-08). Re-orders the already-rendered <tbody> rows by a column's
 * data-sort-value on header click, toggling ascending/descending on repeated
 * clicks of the same header. A pure DOM re-order, not a page reload/server
 * round-trip -- see report.php's own docblock for why this is plain client-side
 * JS rather than Moodle's table_sql/flexible_table API.
 *
 * data-sort-value (set per cell in report.php), not the cell's own visible text,
 * is what gets compared: the "Checked in" column's Yes/No text would not sort as
 * a clean boolean group in every language, and "Check-in time"'s formatted,
 * locale-specific date text would not sort chronologically as a plain string --
 * both instead carry a normalised value (1/0, and a raw unix timestamp
 * respectively) in data-sort-value for this to compare against.
 *
 * @module     mod_confcheckin/report_table
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    TABLE: '.mod_confcheckin-report-table',
    SORT_BUTTON: '.mod_confcheckin-report-sortbutton',
};

/**
 * Re-orders a table's body rows in place by the data-sort-value of the cell at
 * a given column index.
 *
 * @param {HTMLTableElement} table
 * @param {Number} columnIndex
 * @param {String} direction 'ascending' or 'descending'
 */
const sortRows = (table, columnIndex, direction) => {
    const tbody = table.tBodies[0];
    if (!tbody) {
        return;
    }

    const multiplier = direction === 'ascending' ? 1 : -1;
    const rows = Array.from(tbody.rows);

    rows.sort((rowA, rowB) => {
        const cellA = rowA.cells[columnIndex];
        const cellB = rowB.cells[columnIndex];
        const rawA = cellA ? (cellA.dataset.sortValue ?? cellA.textContent.trim()) : '';
        const rawB = cellB ? (cellB.dataset.sortValue ?? cellB.textContent.trim()) : '';

        // Numeric columns (Checked in's 1/0, Check-in time's raw timestamp) compare
        // as numbers; everything else (name, ticket type) falls through to a
        // locale-aware string compare. parseFloat() on genuinely non-numeric text
        // (a name, "No ticket") reliably yields NaN, so this needs no per-column
        // type configuration.
        const numA = parseFloat(rawA);
        const numB = parseFloat(rawB);
        if (!isNaN(numA) && !isNaN(numB)) {
            return (numA - numB) * multiplier;
        }

        return rawA.localeCompare(rawB) * multiplier;
    });

    rows.forEach((row) => tbody.appendChild(row));
};

/**
 * Initialises sortable-column click handling for every check-in report table on
 * the page (in practice, exactly one).
 */
export const init = () => {
    document.querySelectorAll(SELECTORS.TABLE).forEach((table) => {
        const headerRow = table.tHead ? table.tHead.rows[0] : null;
        if (!headerRow) {
            return;
        }

        table.addEventListener('click', (event) => {
            const button = event.target.closest(SELECTORS.SORT_BUTTON);
            if (!button) {
                return;
            }

            const th = button.closest('th');
            const columnIndex = Array.from(headerRow.cells).indexOf(th);
            if (columnIndex === -1) {
                return;
            }

            const nextDirection = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';

            Array.from(headerRow.cells).forEach((cell) => cell.setAttribute('aria-sort', 'none'));
            th.setAttribute('aria-sort', nextDirection);

            sortRows(table, columnIndex, nextDirection);
        });
    });
};
