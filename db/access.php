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
 * Capability definitions for mod_confcheckin.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Required for teachers/managers to be able to add this activity to a course at all
    // (course_allowed_module() checks mod/<name>:addinstance). Same pattern as
    // mod_confsubmissions, mod_confprogram and mod_confscheduler.
    'mod/confcheckin:addinstance' => [
        'riskbitmask'  => RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],

    // Buy/claim a ticket for this conference instance. A normal participant action, not
    // config/XSS/personal risk in itself.
    'mod/confcheckin:purchase' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'student' => CAP_ALLOW,
        ],
    ],

    // Create/edit/delete ticket types and promo codes for this instance (pricing,
    // capacity, presenter-only flag, validity window).
    'mod/confcheckin:managetickettypes' => [
        'riskbitmask'  => RISK_CONFIG,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Use the QR scanner to record a check-in. A write action, but not config/XSS/personal
    // risk in itself -- matches mod_confprogram:favourite's and
    // mod_confscheduler:favourite's no-riskbitmask write-capability pattern for a plain
    // "perform an action" capability.
    'mod/confcheckin:scancheckin' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Bulk-download all attendees' badge/ticket PDFs. Flagged RISK_PERSONAL: unlike a
    // single-user download, this exposes other users' personal data in bulk.
    'mod/confcheckin:downloadbadges' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],

    // Download your own attendance certificate. A "view/download your own thing"
    // capability, no elevated risk.
    'mod/confcheckin:viewowncertificate' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'student' => CAP_ALLOW,
        ],
    ],

    // Edit the TinyMCE badge/ticket/receipt/certificate templates. Flagged RISK_XSS: this
    // is stored rich-text HTML rendered back out later (to PDF, but the same
    // stored-HTML-for-later-display risk class as any other TinyMCE-authored content
    // field, e.g. an intro or abstract).
    'mod/confcheckin:managetemplates' => [
        'riskbitmask'  => RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
