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
import {get_strings as getStrings} from 'core/str';

/**
 * QR check-in scanner (scan.php, camera-only rework 2026-07-09): submits a
 * scanned token to mod_confcheckin_record_checkin and shows the result
 * inline, keeping a running log for the scanning session.
 *
 * Camera starts automatically on page load -- there is no manual/typed input
 * path any more. Two decode strategies are supported, chosen once per page
 * load (chooseDecodeStrategy()):
 * - The browser's native BarcodeDetector API, where available (Chromium-
 *   based browsers only).
 * - A fallback to the vendored jsQR pure-JS decoder (thirdparty/jsQR/,
 *   loaded as a plain global by scan.php) for every other browser, notably
 *   Safari/iPhone and Firefox, neither of which implement BarcodeDetector.
 *   Without this fallback "always a camera" would only actually work on
 *   Chromium.
 * Both strategies feed the same detectLoop()/submitToken() pipeline, so
 * nothing downstream of a decoded value differs between them.
 *
 * getUserMedia is requested with an "ideal" (not exact) environment-facing
 * constraint first, falling back to no constraint at all on failure -- a
 * bare/exact facingMode constraint can throw OverconstrainedError on a
 * desktop webcam with no facingMode metadata at all, which is believed to be
 * why desktop webcams previously failed to activate. When more than one
 * camera is available, enumerateDevices() populates a device-select control
 * so the operator can switch (e.g. front/back on a phone, or between
 * multiple webcams on a desktop).
 *
 * For attendee privacy, both the result banner and each scan-log entry
 * auto-clear 3 seconds after being shown, so a scanned name never stays on
 * screen indefinitely.
 *
 * @module     mod_confcheckin/scanner
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** How long a decoded value must go unseen before it can trigger another submit. */
const REDETECT_COOLDOWN_MS = 3000;

/** How long the result banner and each log entry stay visible before clearing, for privacy. */
const RESULT_HIDE_MS = 3000;

/** Decode-loop poll interval (~10fps): keeps pure-JS (jsQR) decoding from pegging the CPU. */
const FRAME_INTERVAL_MS = 100;

/** localStorage key the mute checkbox's state is persisted under, across page loads. */
const MUTE_STORAGE_KEY = 'mod_confcheckin_scanner_muted';

/**
 * Calls mod_confcheckin_record_checkin.
 *
 * @param {Number} cmid The confcheckin course-module id
 * @param {String} qrtoken The scanned QR token
 * @return {Promise}
 */
const recordCheckin = (cmid, qrtoken) => Ajax.call([{
    methodname: 'mod_confcheckin_record_checkin',
    args: {cmid, qrtoken},
}])[0];

/**
 * Prepends a log entry (most recent scan first) to the running scan log,
 * removing it again after RESULT_HIDE_MS. Each entry's timeout closes over
 * that entry's own element reference, so a fast burst of scans always
 * removes the right node at the right time regardless of how many more
 * scans happen in between -- no shared timer/registry needed.
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
    window.setTimeout(() => item.remove(), RESULT_HIDE_MS);
};

/**
 * Plays a short synthesised "success" beep via the Web Audio API (user request,
 * 2026-07-08 -- feedback that a scan succeeded, distinct from the visual-only
 * border flash/checkmark, useful when not looking directly at the screen/camera
 * preview). Synthesised rather than an audio file asset: no third-party asset
 * to license, matching this module's own no-third-party-vendoring-unless-
 * necessary posture (jsQR being the one deliberate exception -- see the module
 * docblock above for why that one *is* vendored).
 *
 * Silently does nothing if muted, or if the Web Audio API is unavailable (e.g.
 * some older/embedded web views) -- an inability to beep must never break
 * scanning itself, matching submitToken()'s own "never throw" contract.
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
        // user-gesture-triggered call stack; the first successful scan (itself
        // downstream of the camera-permission gesture) satisfies that, whereas
        // creating it eagerly in init() would not.
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
 * Sets the result banner's content and schedules it to clear after
 * RESULT_HIDE_MS, for attendee privacy. A single shared timeout is correct
 * here (unlike the per-entry log timers): the banner only ever shows the most
 * recent result, so a newer call legitimately supersedes and reschedules any
 * pending hide from a previous one.
 *
 * @param {Object} state The module state object
 * @param {String} text The banner text
 * @param {String} className The full className to set on the banner element
 */
const showResult = (state, text, className) => {
    window.clearTimeout(state.resultHideTimeout);
    state.resultEl.className = className;
    state.resultEl.textContent = text;
    state.resultHideTimeout = window.setTimeout(() => {
        state.resultEl.textContent = '';
        state.resultEl.className = 'mod_confcheckin-scanner-result';
    }, RESULT_HIDE_MS);
};

/**
 * Submits one decoded token: calls the AJAX endpoint, shows the result
 * (success/already checked in/error) in the result banner, and logs it. Never
 * throws -- an AJAX exception (invalid token, wrong-event token, capability
 * loss mid-session) is caught and shown as an error result like any other
 * outcome, so one bad scan never breaks the scanning session.
 *
 * @param {Object} state The module state object
 * @param {String} qrtoken The scanned QR token
 * @return {Promise}
 */
const submitToken = async(state, qrtoken) => {
    const trimmed = qrtoken.trim();
    if (!trimmed) {
        return null;
    }

    showResult(state, state.strings.scanning, 'mod_confcheckin-scanner-result');

    try {
        const result = await recordCheckin(state.cmid, trimmed);
        const label = result.alreadycheckedin ? state.strings.alreadycheckedin : state.strings.checkedin;
        const message = `${label}: ${result.fullname} (${result.tickettype})`;

        showResult(
            state,
            message,
            'mod_confcheckin-scanner-result '
                + (result.alreadycheckedin ? 'mod_confcheckin-scanner-result-warning' : 'mod_confcheckin-scanner-result-success')
        );
        prependLogEntry(state.logEl, message, false);

        // Success feedback (user request, 2026-07-08): only for a genuinely NEW
        // check-in, not a re-scan of an already-checked-in ticket -- an
        // "alreadycheckedin" result already gets its own distinct warning styling
        // above, and re-beeping/re-flashing green on every accidental re-scan of a
        // still-visible QR code would be noisy and would muddy "this just worked"
        // as a signal. Both the beep and the border flash/checkmark always apply
        // now (camera is the only input path left).
        if (!result.alreadycheckedin) {
            playSuccessBeep(state);
            flashCameraSuccess(state);
        }

        return result;
    } catch (exception) {
        const message = exception.message || String(exception);
        showResult(state, message, 'mod_confcheckin-scanner-result mod_confcheckin-scanner-result-error');
        prependLogEntry(state.logEl, message, true);

        return null;
    }
};

/**
 * Maps a getUserMedia failure to one of this module's specific cameraerror_*
 * lang strings, so the operator sees an actionable message rather than one
 * generic catch-all -- there is no fallback UI left to point them at instead.
 *
 * @param {DOMException|Error} exception The rejected/thrown getUserMedia error
 * @return {String} A key into state.strings
 */
const cameraErrorKeyFor = (exception) => {
    const name = exception && exception.name;
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return 'cameraerror_denied';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || name === 'OverconstrainedError') {
        return 'cameraerror_notfound';
    }
    return 'cameraerror';
};

/**
 * Shows the page-level camera-error banner. Distinct from showResult() above:
 * this is a blocking "the page can't work at all" state, not a routine scan
 * outcome, so it's a separate element (role="alert") that isn't subject to the
 * 3-second auto-hide.
 *
 * @param {Object} state The module state object
 * @param {String} key A key into state.strings (typically from cameraErrorKeyFor())
 */
const showCameraError = (state, key) => {
    state.errorEl.textContent = state.strings[key] || state.strings.cameraerror;
    state.errorEl.hidden = false;
};

/**
 * Hides the page-level camera-error banner, e.g. once a retry (switching
 * camera) succeeds after an earlier failure.
 *
 * @param {Object} state The module state object
 */
const hideCameraError = (state) => {
    state.errorEl.hidden = true;
    state.errorEl.textContent = '';
};

/**
 * Stops every track on the current media stream, if any. Must happen before
 * requesting a new stream (e.g. switching camera) rather than after -- some
 * browsers/OSes refuse a second concurrent open of a device that's still
 * held, and holding two camera handles open is a resource leak regardless.
 *
 * @param {Object} state The module state object
 */
const stopMediaTracks = (state) => {
    if (state.mediaStream) {
        state.mediaStream.getTracks().forEach((track) => track.stop());
        state.mediaStream = null;
    }
};

/**
 * Attaches a newly acquired media stream to the <video> element and starts
 * playback, revealing the video/videowrap (both start hidden in scan.php's
 * markup so nothing shows before a stream is actually live).
 *
 * @param {Object} state The module state object
 * @param {MediaStream} stream The acquired camera stream
 * @return {Promise}
 */
const attachStream = async(state, stream) => {
    state.mediaStream = stream;
    state.videoEl.srcObject = stream;
    state.videoEl.hidden = false;
    state.videoWrapEl.hidden = false;
    // Belt-and-suspenders alongside the playsinline/muted/autoplay attributes
    // already in scan.php's markup -- iOS Safari is known to be picky about
    // whether these are present in the initial HTML vs. only set later via JS.
    state.videoEl.muted = true;
    state.videoEl.playsInline = true;
    await state.videoEl.play();
};

/**
 * Acquires the initial camera stream. Requests an "ideal" (not exact)
 * environment-facing camera first -- a bare/exact facingMode constraint can
 * throw OverconstrainedError on a webcam with no facingMode metadata at all,
 * which is believed to be why desktop webcams previously never activated --
 * and falls back to no constraint whatsoever (any camera at all) if that
 * first attempt fails for any reason.
 *
 * @return {Promise<MediaStream>}
 */
const acquireInitialStream = async() => {
    try {
        return await navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}});
    } catch (exception) {
        return await navigator.mediaDevices.getUserMedia({video: true});
    }
};

/**
 * Enumerates available cameras and populates the device-select control, only
 * revealing it when there's more than one (avoids UI clutter on the common
 * single-camera-phone case). Device labels are blank until permission has
 * been granted at least once, which by this point it has -- but a device can
 * still report a blank label in some browsers, hence the numbered fallback.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const populateCameraSelect = async(state) => {
    let devices;
    try {
        devices = await navigator.mediaDevices.enumerateDevices();
    } catch (exception) {
        return;
    }

    const videoInputs = devices.filter((device) => device.kind === 'videoinput');
    if (videoInputs.length < 2) {
        return;
    }

    state.cameraSelectEl.innerHTML = '';
    videoInputs.forEach((device, index) => {
        const option = document.createElement('option');
        option.value = device.deviceId;
        option.textContent = device.label || `${state.strings.scanwithcamera} ${index + 1}`;
        state.cameraSelectEl.appendChild(option);
    });

    const activeTrack = state.mediaStream && state.mediaStream.getVideoTracks()[0];
    const activeDeviceId = activeTrack && activeTrack.getSettings ? activeTrack.getSettings().deviceId : null;
    if (activeDeviceId) {
        state.cameraSelectEl.value = activeDeviceId;
    }

    state.cameraSelectLabelEl.hidden = false;
};

/**
 * Switches to the camera chosen in the device-select control. Keeps the
 * existing detect loop running (it reads state.videoEl on every tick, whose
 * srcObject this swaps) rather than tearing it down and restarting it, to
 * avoid a flash/gap in scanning.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const switchCamera = async(state) => {
    const deviceId = state.cameraSelectEl.value;
    if (!deviceId) {
        return;
    }

    stopMediaTracks(state);
    try {
        const stream = await navigator.mediaDevices.getUserMedia({video: {deviceId: {exact: deviceId}}});
        await attachStream(state, stream);
        hideCameraError(state);
    } catch (exception) {
        showCameraError(state, cameraErrorKeyFor(exception));
    }
};

/**
 * Decodes one frame via the native BarcodeDetector API.
 *
 * @param {Object} state The module state object
 * @return {Promise<?String>} The decoded value, or null if nothing was found
 */
const detectWithBarcodeDetector = async(state) => {
    const barcodes = await state.detector.detect(state.videoEl);
    return barcodes.length ? barcodes[0].rawValue : null;
};

/**
 * Decodes one frame via the vendored jsQR fallback: draws the current video
 * frame to an offscreen canvas (created once, resized only when the video's
 * own dimensions change) and feeds its pixel data to jsQR().
 *
 * @param {Object} state The module state object
 * @return {Promise<?String>} The decoded value, or null if nothing was found
 */
const decodeWithJsQR = async(state) => {
    const {videoEl, jsQRCanvas: canvas, jsQRCtx: ctx} = state;
    if (!videoEl.videoWidth || !videoEl.videoHeight) {
        return null;
    }

    if (canvas.width !== videoEl.videoWidth || canvas.height !== videoEl.videoHeight) {
        canvas.width = videoEl.videoWidth;
        canvas.height = videoEl.videoHeight;
    }

    ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const result = window.jsQR(imageData.data, imageData.width, imageData.height);
    return result ? result.data : null;
};

/**
 * Chooses which decode strategy to use for this page load: the native
 * BarcodeDetector API where it genuinely supports the 'qr_code' format, else
 * the vendored jsQR fallback, else a page-level error (effectively
 * unreachable once jsQR is vendored, but a defined state rather than an
 * undefined one).
 *
 * 'BarcodeDetector' in window alone is not enough (bug report, 2026-07-07:
 * "QR scanner when tried on Android didn't recognise/process QR codes. Camera
 * activated, but no QR code was read"): some Android browsers expose the
 * BarcodeDetector constructor without actually supporting the 'qr_code'
 * format, in which case getSupportedFormats() (or, defensively, the
 * constructor itself) can fail to confirm support -- checked here up front
 * so this falls through to jsQR instead of silently never detecting anything.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const chooseDecodeStrategy = async(state) => {
    if ('BarcodeDetector' in window) {
        try {
            const formats = await window.BarcodeDetector.getSupportedFormats();
            if (formats.includes('qr_code')) {
                state.detector = new window.BarcodeDetector({formats: ['qr_code']});
                state.decodeFrame = detectWithBarcodeDetector;
                return;
            }
        } catch (exception) {
            window.console.warn(exception);
        }
    }

    if (typeof window.jsQR === 'function') {
        state.jsQRCanvas = document.createElement('canvas');
        state.jsQRCtx = state.jsQRCanvas.getContext('2d', {willReadFrequently: true});
        state.decodeFrame = decodeWithJsQR;
        return;
    }

    showCameraError(state, 'noqrdecodersupport');
};

/**
 * Runs the decode loop via requestAnimationFrame, throttled to roughly
 * FRAME_INTERVAL_MS between actual decode attempts (scheduling every frame
 * regardless would peg the CPU unnecessarily, especially for the pure-JS
 * jsQR path). A detected value is fed into submitToken(), with a cooldown so
 * the same still-visible QR code is not resubmitted on every poll.
 *
 * @param {Object} state The module state object
 */
const detectLoop = (state) => {
    const tick = async(timestamp) => {
        if (!state.cameraActive) {
            return;
        }

        if (!state.lastFrameTime || (timestamp - state.lastFrameTime) >= FRAME_INTERVAL_MS) {
            state.lastFrameTime = timestamp;
            try {
                const value = await state.decodeFrame(state);
                if (value) {
                    const now = Date.now();
                    if (value !== state.lastDetected || (now - state.lastDetectedTime) > REDETECT_COOLDOWN_MS) {
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
        }

        window.requestAnimationFrame(tick);
    };

    window.requestAnimationFrame(tick);
};

/**
 * Stops scanning entirely (page unload only -- there is no manual "stop"
 * control any more, camera runs for the page's whole lifetime).
 *
 * @param {Object} state The module state object
 */
const stopCameraScanning = (state) => {
    state.cameraActive = false;
    stopMediaTracks(state);
};

/**
 * Starts the camera and, once a stream is live, the decode loop. Runs the
 * pre-flight guards first (secure context, getUserMedia existing at all)
 * since there is no fallback UI left to degrade to -- each failure mode gets
 * its own specific on-page message instead.
 *
 * @param {Object} state The module state object
 * @return {Promise}
 */
const startScanning = async(state) => {
    if (!window.isSecureContext) {
        showCameraError(state, 'cameraerror_insecurecontext');
        return;
    }
    if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
        showCameraError(state, 'cameraerror_notsupported');
        return;
    }

    let stream;
    try {
        stream = await acquireInitialStream();
    } catch (exception) {
        showCameraError(state, cameraErrorKeyFor(exception));
        return;
    }

    await attachStream(state, stream);
    hideCameraError(state);
    state.cameraActive = true;

    await populateCameraSelect(state);
    await chooseDecodeStrategy(state);

    if (state.decodeFrame) {
        detectLoop(state);
    }
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

    const [
        scanning, checkedin, alreadycheckedin, scanwithcamera, cameraerror,
        cameraerrorDenied, cameraerrorNotfound, cameraerrorInsecurecontext,
        cameraerrorNotsupported, noqrdecodersupport,
    ] = await getStrings([
        {key: 'scanning', component: 'mod_confcheckin'},
        {key: 'checkedin', component: 'mod_confcheckin'},
        {key: 'alreadycheckedin', component: 'mod_confcheckin'},
        {key: 'scanwithcamera', component: 'mod_confcheckin'},
        {key: 'cameraerror', component: 'mod_confcheckin'},
        {key: 'cameraerror_denied', component: 'mod_confcheckin'},
        {key: 'cameraerror_notfound', component: 'mod_confcheckin'},
        {key: 'cameraerror_insecurecontext', component: 'mod_confcheckin'},
        {key: 'cameraerror_notsupported', component: 'mod_confcheckin'},
        {key: 'noqrdecodersupport', component: 'mod_confcheckin'},
    ]);

    const strings = {scanning, checkedin, alreadycheckedin, scanwithcamera, cameraerror, noqrdecodersupport};
    // Bracket-assigned rather than declared as object-literal keys: these
    // deliberately match their underscored lang-string keys 1:1 (see
    // cameraErrorKeyFor()'s return values), not camelCase identifiers.
    strings['cameraerror_denied'] = cameraerrorDenied;
    strings['cameraerror_notfound'] = cameraerrorNotfound;
    strings['cameraerror_insecurecontext'] = cameraerrorInsecurecontext;
    strings['cameraerror_notsupported'] = cameraerrorNotsupported;

    const state = {
        cmid,
        resultEl: root.querySelector('.mod_confcheckin-scanner-result'),
        errorEl: root.querySelector('.mod_confcheckin-scanner-error'),
        logEl: root.querySelector('.mod_confcheckin-scanner-log'),
        videoEl: root.querySelector('.mod_confcheckin-scanner-video'),
        videoWrapEl: root.querySelector('.mod_confcheckin-scanner-videowrap'),
        cameraSelectEl: root.querySelector('.mod_confcheckin-scanner-cameraselect'),
        cameraSelectLabelEl: root.querySelector('.mod_confcheckin-scanner-cameraselectlabel'),
        muteToggleEl: root.querySelector('.mod_confcheckin-scanner-mute'),
        cameraActive: false,
        mediaStream: null,
        detector: null,
        decodeFrame: null,
        jsQRCanvas: null,
        jsQRCtx: null,
        lastDetected: null,
        lastDetectedTime: 0,
        lastFrameTime: 0,
        resultHideTimeout: null,
        // Persisted across page loads (user request, 2026-07-08): re-checking this
        // desk's device into a fresh scan.php load, e.g. after a browser refresh
        // mid-event, shouldn't un-mute a volume choice already made for a noisy
        // check-in desk.
        muted: window.localStorage.getItem(MUTE_STORAGE_KEY) === '1',
        audioContext: null,
        strings,
    };

    state.muteToggleEl.checked = state.muted;
    state.muteToggleEl.addEventListener('change', () => {
        state.muted = state.muteToggleEl.checked;
        window.localStorage.setItem(MUTE_STORAGE_KEY, state.muted ? '1' : '0');
    });

    state.cameraSelectEl.addEventListener('change', () => switchCamera(state));

    window.addEventListener('beforeunload', () => stopCameraScanning(state));

    await startScanning(state);
};
