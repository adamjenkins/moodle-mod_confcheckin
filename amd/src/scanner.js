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
import {get_strings as getStrings} from 'core/str';

/**
 * QR check-in scanner (scan.php, Phase 4.5): submits a scanned/typed token to
 * mod_confcheckin_record_checkin and shows the result inline, keeping a running
 * log for the scanning session.
 *
 * Two independent input paths feed the same submitToken() call: the always-
 * present text field (typed manually, or "typed" instantly by a USB/Bluetooth
 * barcode scanner acting as a keyboard, which is the reliable, zero-dependency
 * baseline this page relies on -- see scan.php's own docblock for why no
 * third-party camera-QR-decoding JS library is vendored), and an optional
 * camera path progressively enhanced via the browser's native BarcodeDetector
 * API where it exists.
 *
 * @module     mod_confcheckin/scanner
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Calls mod_confcheckin_record_checkin.
 *
 * @param {Number} cmid The confcheckin course-module id
 * @param {String} qrtoken The scanned/typed QR token
 * @return {Promise}
 */
const recordCheckin = (cmid, qrtoken) => Ajax.call([{
    methodname: 'mod_confcheckin_record_checkin',
    args: {cmid, qrtoken},
}])[0];

/**
 * Prepends a log entry (most recent scan first) to the running scan log.
 *
 * @param {HTMLElement} logEl The <ul> log element
 * @param {String} text The log line text
 * @param {Boolean} isError Whether to style this entry as an error
 */
const prependLogEntry = (logEl, text, isError) => {
    const item = document.createElement('li');
    item.className = isError ? 'text-danger' : 'text-success';
    item.textContent = text;
    logEl.insertBefore(item, logEl.firstChild);
};

/**
 * Submits one token: calls the AJAX endpoint, shows the result (success/already
 * checked in/error) in the result banner, and logs it. Never throws -- an AJAX
 * exception (invalid token, wrong-event token, capability loss mid-session) is
 * caught and shown as an error result like any other outcome, so one bad scan
 * never breaks the scanning session.
 *
 * @param {Object} state The module state object
 * @param {String} qrtoken The scanned/typed QR token
 * @return {Promise}
 */
const submitToken = async(state, qrtoken) => {
    const trimmed = qrtoken.trim();
    if (!trimmed) {
        return null;
    }

    state.resultEl.className = 'mod_confcheckin-scanner-result';
    state.resultEl.textContent = state.strings.scanning;

    try {
        const result = await recordCheckin(state.cmid, trimmed);
        const label = result.alreadycheckedin ? state.strings.alreadycheckedin : state.strings.checkedin;
        const message = `${label}: ${result.fullname} (${result.tickettype})`;

        state.resultEl.className = 'mod_confcheckin-scanner-result '
            + (result.alreadycheckedin ? 'mod_confcheckin-scanner-result-warning' : 'mod_confcheckin-scanner-result-success');
        state.resultEl.textContent = message;
        prependLogEntry(state.logEl, message, false);

        return result;
    } catch (exception) {
        const message = exception.message || String(exception);
        state.resultEl.className = 'mod_confcheckin-scanner-result mod_confcheckin-scanner-result-error';
        state.resultEl.textContent = message;
        prependLogEntry(state.logEl, message, true);

        return null;
    }
};

/**
 * Starts the optional camera-based scanning enhancement: requests camera access,
 * streams to the <video> element, and repeatedly runs the native BarcodeDetector
 * across frames via requestAnimationFrame. A detected value is fed into
 * submitToken() exactly like a manually-typed one, with a short cooldown so the
 * same still-visible QR code is not resubmitted on every subsequent frame.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const startCameraScanning = async(state) => {
    if (state.cameraActive) {
        return;
    }

    try {
        state.mediaStream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
    } catch (exception) {
        Notification.alert(state.strings.scanwithcamera, state.strings.cameraerror);
        return;
    }

    state.videoEl.srcObject = state.mediaStream;
    state.videoEl.hidden = false;
    await state.videoEl.play();

    state.cameraActive = true;
    state.lastDetected = null;
    state.lastDetectedTime = 0;

    const detector = new window.BarcodeDetector({formats: ['qr_code']});

    const detectLoop = async() => {
        if (!state.cameraActive) {
            return;
        }

        try {
            const barcodes = await detector.detect(state.videoEl);
            if (barcodes.length) {
                const value = barcodes[0].rawValue;
                const now = Date.now();
                // Cooldown: a still-visible code would otherwise be detected (and
                // resubmitted) on every single animation frame.
                if (value !== state.lastDetected || (now - state.lastDetectedTime) > 3000) {
                    state.lastDetected = value;
                    state.lastDetectedTime = now;
                    await submitToken(state, value);
                }
            }
        } catch (exception) {
            // A transient decode error on one frame is not worth surfacing; the
            // loop simply tries again on the next frame.
            window.console.warn(exception);
        }

        window.requestAnimationFrame(detectLoop);
    };

    window.requestAnimationFrame(detectLoop);
};

/**
 * Stops the camera-based scanning enhancement, releasing the media stream.
 *
 * @param {Object} state The module state object
 */
const stopCameraScanning = (state) => {
    state.cameraActive = false;
    if (state.mediaStream) {
        state.mediaStream.getTracks().forEach((track) => track.stop());
        state.mediaStream = null;
    }
    state.videoEl.hidden = true;
};

/**
 * Initialises the scanner page.
 *
 * @param {Number} cmid The confcheckin course-module id
 * @return {Promise}
 */
export const init = async(cmid) => {
    const root = document.getElementById('mod_confcheckin-scanner-root');
    if (!root) {
        return;
    }

    const [scanning, checkedin, alreadycheckedin, scanwithcamera, cameraerror] = await getStrings([
        {key: 'scanning', component: 'mod_confcheckin'},
        {key: 'checkedin', component: 'mod_confcheckin'},
        {key: 'alreadycheckedin', component: 'mod_confcheckin'},
        {key: 'scanwithcamera', component: 'mod_confcheckin'},
        {key: 'cameraerror', component: 'mod_confcheckin'},
    ]);

    const state = {
        cmid,
        resultEl: root.querySelector('.mod_confcheckin-scanner-result'),
        logEl: root.querySelector('.mod_confcheckin-scanner-log'),
        videoEl: root.querySelector('.mod_confcheckin-scanner-video'),
        cameraToggleEl: root.querySelector('.mod_confcheckin-scanner-cameratoggle'),
        inputEl: root.querySelector('.mod_confcheckin-scanner-input'),
        cameraActive: false,
        mediaStream: null,
        lastDetected: null,
        lastDetectedTime: 0,
        strings: {scanning, checkedin, alreadycheckedin, scanwithcamera, cameraerror},
    };

    const form = root.querySelector('.mod_confcheckin-scanner-form');
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const value = state.inputEl.value;
        state.inputEl.value = '';
        submitToken(state, value).finally(() => state.inputEl.focus());
    });

    // Progressive enhancement only: most browsers/web views (notably Safari/
    // WebKit as of this writing) do not implement BarcodeDetector at all, and the
    // page is already fully usable without it via the text field above.
    if ('BarcodeDetector' in window) {
        state.cameraToggleEl.hidden = false;
        state.cameraToggleEl.addEventListener('click', () => {
            if (state.cameraActive) {
                stopCameraScanning(state);
            } else {
                startCameraScanning(state);
            }
        });
    }

    window.addEventListener('beforeunload', () => stopCameraScanning(state));
};
