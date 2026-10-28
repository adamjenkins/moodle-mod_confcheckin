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

namespace mod_confcheckin\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/pdflib.php');

/**
 * Moodle's \pdf with TCPDF's own file access shut off, for rendering
 * organiser-authored template HTML.
 *
 * TCPDF's writeHTML() fetches whatever an <img src>, <link href> (stylesheet),
 * list-style "img|" bullet or SVG <image href> names: http(s) URLs through its own
 * raw file_get_contents()/curl (lib/tcpdf/include/tcpdf_static.php fileGetContents()
 * and url_exists()), which ignores Moodle's curlsecurityblockedhosts/allowedports,
 * and absolute local paths straight off the server's disk. Every one of those reads
 * goes through the two protected helpers overridden here (fileExists() gates
 * Image() before its getimagesize(); getCachedFileContents() is what Image(),
 * ImageSVG(), ImageEps() and the stylesheet loader read through), so refusing
 * everything except this document's own temp files closes them all at the sink,
 * whatever the HTML looked like. Images an organiser wants in a template reach
 * TCPDF as data: URIs instead -- pdf_generator::inline_images() turns remote ones
 * into those through Moodle's own \curl before writeHTML() is called.
 *
 * @package    mod_confcheckin
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_document extends \pdf {
    /**
     * Whether $file is one of the temp files TCPDF itself wrote for this document
     * (TCPDF_STATIC::getObjFilename(): K_PATH_CACHE . '__tcpdf_<file_id>_...'), e.g.
     * a decoded data: image or the alpha/plain halves of a transparent PNG. Scoped to
     * this document's own random file_id, so a template cannot name another request's
     * temp image (such as someone else's QR code) either.
     *
     * @param mixed $file The path TCPDF wants to read
     * @return bool
     */
    protected function is_own_temp_file($file): bool {
        if (!is_string($file) || $file === '' || strpos($file, '://') !== false) {
            return false;
        }
        if (strpos(basename($file), '__tcpdf_' . $this->file_id . '_') !== 0) {
            return false;
        }
        $dir = realpath(dirname($file));
        $cachedir = realpath(K_PATH_CACHE);

        return $dir !== false && $cachedir !== false && $dir === $cachedir;
    }

    /**
     * Refuses every file but this document's own temp files (see the class docblock).
     *
     * @param string $file The path or URL TCPDF wants to test
     * @return bool
     */
    protected function fileExists($file) { // phpcs:ignore moodle.NamingConventions.ValidFunctionName -- TCPDF's name.
        if (!$this->is_own_temp_file($file)) {
            return false;
        }

        return parent::fileExists($file);
    }

    /**
     * Refuses to read anything but this document's own temp files (see the class docblock).
     *
     * @param string $file The path or URL TCPDF wants to read
     * @return string|false
     */
    protected function getCachedFileContents($file) { // phpcs:ignore moodle.NamingConventions.ValidFunctionName -- TCPDF's name.
        if (!$this->is_own_temp_file($file)) {
            return false;
        }

        return parent::getCachedFileContents($file);
    }
}
