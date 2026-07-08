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

/** localStorage key the mute checkbox's state is persisted under, across page loads. */
const MUTE_STORAGE_KEY = 'mod_confcheckin_scanner_muted';

/**
 * Plays a short synthesised "success" beep via the Web Audio API (user request,
 * 2026-07-08 -- feedback that a scan succeeded, distinct from the visual-only
 * border flash/checkmark, useful when not looking directly at the screen/camera
 * preview). Synthesised rather than an audio file asset: matches this module's own
 * existing preference (see its docblock) for zero-dependency, no-third-party-asset
 * behaviour, and avoids needing a licensed sound file in the plugin.
 *
 * Silently does nothing if muted, or if the Web Audio API is unavailable (e.g. some
 * older/embedded web views) -- an inability to beep must never break scanning
 * itself, matching submitToken()'s own "never throw" contract.
 *
 * @param {Object} state The module state object
 */
const playSuccessBeep = (state) => {
    if (state.muted) {
        return;
    }

    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return;
        }
        // Created lazily, on first actual use, and reused after that -- browsers
        // increasingly require an AudioContext to be created/resumed from within a
        // user-gesture-triggered call stack; the click that starts camera scanning
        // (or a form submit) satisfies that, whereas creating it eagerly in init()
        // would not.
        if (!state.audioContext) {
            state.audioContext = new AudioContextClass();
        }
        const ctx = state.audioContext;

        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.value = 880;
        // Ramp rather than a hard on/off: avoids an audible click at the start/end
        // of the tone. Exponential ramps can't target exactly 0, hence 0.0001.
        gain.gain.setValueAtTime(0.0001, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18);
        oscillator.connect(gain);
        gain.connect(ctx.destination);
        oscillator.start();
        oscillator.stop(ctx.currentTime + 0.2);
    } catch (exception) {
        window.console.warn(exception);
    }
};

/**
 * Triggers the camera preview's success flash: a green border pulse plus a
 * checkmark pop, both pure-CSS animations (see styles.css) restarted here by
 * removing and re-adding the class -- necessary so two successes in quick
 * succession (e.g. two attendees scanned back to back) each get their own full
 * animation instead of the second being a no-op because the class was already
 * present.
 *
 * @param {Object} state The module state object
 */
const flashCameraSuccess = (state) => {
    state.videoWrapEl.classList.remove('mod_confcheckin-scanner-flash');
    // Forces a reflow so the class removal above is actually applied before it's
    // added back, or the browser would coalesce the two into a no-op change.
    void state.videoWrapEl.offsetWidth;
    state.videoWrapEl.classList.add('mod_confcheckin-scanner-flash');
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

        // Success feedback (user request, 2026-07-08): only for a genuinely NEW
        // check-in, not a re-scan of an already-checked-in ticket -- an
        // "alreadycheckedin" result already gets its own distinct warning styling
        // above, and re-beeping/re-flashing green on every accidental re-scan of a
        // still-visible QR code would be noisy and would muddy "this just worked"
        // as a signal. The beep plays for any successful input path (typed, a USB/
        // Bluetooth hardware scanner acting as a keyboard, or camera); the border
        // flash/checkmark are camera-only, since there is no video preview to
        // overlay them on otherwise.
        if (!result.alreadycheckedin) {
            playSuccessBeep(state);
            if (state.cameraActive) {
                flashCameraSuccess(state);
            }
        }

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
    state.videoWrapEl.hidden = false;
    await state.videoEl.play();

    let detector;
    try {
        detector = new window.BarcodeDetector({formats: ['qr_code']});
    } catch (exception) {
        // Defence in depth: init() below already checks getSupportedFormats() before
        // ever showing the camera toggle, specifically because a browser can expose
        // `BarcodeDetector` (so the 'BarcodeDetector' in window check alone passes)
        // while not actually supporting the 'qr_code' format -- observed live as an
        // intermittent Android bug report ("camera activated, but no QR code was
        // read"): this constructor throws synchronously in that case, and since it
        // used to run AFTER the camera stream was already live with no try/catch
        // around it, the whole detect loop silently never started, leaving a dead
        // camera preview with no error shown. Caught here too in case support changes
        // between that earlier check and this call, or getSupportedFormats() itself
        // is unreliable on some implementation.
        stopCameraScanning(state);
        Notification.alert(state.strings.scanwithcamera, state.strings.cameraerror);
        return;
    }

    state.cameraActive = true;
    state.lastDetected = null;
    state.lastDetectedTime = 0;

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
    state.videoWrapEl.hidden = true;
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
        videoWrapEl: root.querySelector('.mod_confcheckin-scanner-videowrap'),
        cameraToggleEl: root.querySelector('.mod_confcheckin-scanner-cameratoggle'),
        muteToggleEl: root.querySelector('.mod_confcheckin-scanner-mute'),
        inputEl: root.querySelector('.mod_confcheckin-scanner-input'),
        cameraActive: false,
        mediaStream: null,
        lastDetected: null,
        lastDetectedTime: 0,
        // Persisted across page loads (user request, 2026-07-08): re-checking this
        // desk's device into a fresh scan.php load, e.g. after a browser refresh
        // mid-event, shouldn't un-mute a volume choice already made for a noisy
        // check-in desk.
        muted: window.localStorage.getItem(MUTE_STORAGE_KEY) === '1',
        audioContext: null,
        strings: {scanning, checkedin, alreadycheckedin, scanwithcamera, cameraerror},
    };

    state.muteToggleEl.checked = state.muted;
    state.muteToggleEl.addEventListener('change', () => {
        state.muted = state.muteToggleEl.checked;
        window.localStorage.setItem(MUTE_STORAGE_KEY, state.muted ? '1' : '0');
    });

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
    //
    // 'BarcodeDetector' in window alone is not enough (bug report, 2026-07-07:
    // "QR scanner when tried on Android didn't recognise/process QR codes. Camera
    // activated, but no QR code was read"): some Android browsers expose the
    // BarcodeDetector constructor without actually supporting the 'qr_code' format
    // (the on-device barcode-scanning module some vendors ship it against can be
    // missing/unsupported), in which case `new BarcodeDetector({formats:
    // ['qr_code']})` throws synchronously -- see startCameraScanning()'s own
    // try/catch for the second layer of defence against that. Checking
    // getSupportedFormats() up front means the button is never shown at all on a
    // device where it would only fail after already turning the camera on, matching
    // this feature's own progressive-enhancement philosophy: degrade to the
    // always-usable text field rather than show a control that looks like it works
    // but silently doesn't.
    let qrcodeSupported = false;
    if ('BarcodeDetector' in window) {
        try {
            const formats = await window.BarcodeDetector.getSupportedFormats();
            qrcodeSupported = formats.includes('qr_code');
        } catch (exception) {
            qrcodeSupported = false;
        }
    }

    if (qrcodeSupported) {
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
