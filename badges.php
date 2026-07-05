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
 * Bulk badge/ticket/receipt/certificate PDF download (ZIP of every issued ticket
 * in this instance) for mod_confcheckin (Phase 4.4; certificate added Phase 4.5),
 * gated on mod/confcheckin:downloadbadges (RISK_PERSONAL -- see db/access.php).
 *
 * A deleted user's ticket is silently skipped (there is no profile data left to
 * render), rather than failing the whole download. A certificate bulk download
 * only ever includes tickets with a recorded check-in -- see
 * classes/local/checkin_service.php's docblock for why a certificate has no
 * meaning before then.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/mod/confcheckin/lib.php');

use mod_confcheckin\local\checkin_service;
use mod_confcheckin\local\instance_helper;
use mod_confcheckin\local\pdf_generator;

$id = required_param('id', PARAM_INT);
$type = optional_param('type', 'badge', PARAM_ALPHA);

require_login();

[$course, $cm, $context, $confcheckin] = instance_helper::require_downloadbadges($id);

if (!in_array($type, ['badge', 'ticket', 'receipt', 'certificate'], true)) {
    throw new \moodle_exception('error:invalidtemplatetype', 'confcheckin');
}

$viewurl = new moodle_url('/mod/confcheckin/view.php', ['id' => $cm->id]);

$tickets = $DB->get_records('confcheckin_ticket', ['confcheckin' => $confcheckin->id], 'id ASC');
if ($type === 'receipt') {
    // No receipt for free/promo tickets -- Phase 4.3 decision, see badge.php.
    $tickets = array_filter($tickets, static fn (\stdClass $ticket): bool => $ticket->origin === 'purchase');
} else if ($type === 'certificate') {
    $tickets = array_filter(
        $tickets,
        static fn (\stdClass $ticket): bool => checkin_service::has_checked_in((int) $ticket->id)
    );
}
if (!$tickets) {
    redirect($viewurl, get_string('notickets', 'confcheckin'), null, \core\output\notification::NOTIFY_INFO);
}

$tickettypes = $DB->get_records('confcheckin_tickettype', ['confcheckin' => $confcheckin->id]);

$tmpdir = make_request_directory();
$zippath = $tmpdir . '/badges.zip';

$zip = new ZipArchive();
$zip->open($zippath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$added = 0;
foreach ($tickets as $ticket) {
    $tickettype = $tickettypes[$ticket->tickettypeid] ?? null;
    if (!$tickettype) {
        // The ticket type was deleted after this ticket was issued -- there is no
        // longer a name/price to render; skip rather than fail the whole download.
        continue;
    }

    $ticketuser = \core_user::get_user((int) $ticket->userid);
    if (!$ticketuser || $ticketuser->deleted) {
        continue;
    }

    $pdf = pdf_generator::build($confcheckin, $tickettype, $ticket, $ticketuser, $type);
    $zip->addFromString(pdf_generator::filename($confcheckin, $type, $ticket), $pdf->Output('', 'S'));
    $added++;
}

$zip->close();

if ($added === 0) {
    redirect($viewurl, get_string('notickets', 'confcheckin'), null, \core\output\notification::NOTIFY_INFO);
}

$zipfilename = clean_filename(format_string($confcheckin->name) . '-' . $type . 's.zip');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipfilename . '"');
header('Content-Length: ' . filesize($zippath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');

readfile($zippath);
