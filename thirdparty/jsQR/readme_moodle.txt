- jsQR.js -

jsQR

A pure JavaScript QR code reading library that takes in raw images and
will locate, extract and parse any QR code found within.

https://github.com/cozmo/jsQR

Version 1.4.0, vendored 2026-07-09.
License: Apache-2.0.

Used as the QR-decoding fallback in mod_confcheckin/amd/src/scanner.js for
browsers without the native BarcodeDetector API (Safari/iPhone, Firefox,
and any other non-Chromium browser).

Local changes applied:
(verify on each upgrade of the library if they have been applied
upstream, or are still necessary. Keep the local change if so.)

Modified the top-of-file webpack UMD wrapper to unconditionally assign
the decoded factory to the global (`root["jsQR"] = factory();`) instead
of branching on `typeof define === 'function' && define.amd`. This file
is always loaded as a plain script via $PAGE->requires->js(), never via
RequireJS `require()`/`define()`. Moodle's own RequireJS `define` global
is present on every page regardless, so the unmodified wrapper would
silently register jsQR as an anonymous AMD module instead of setting
window.jsQR, leaving window.jsQR undefined with no error. No other part
of the file (the actual QR-decoding logic) was changed.
