# Bundled font: Noto Sans JP

`notosansjp.php`/`.z`/`.ctg.z` are a TCPDF-embedded-font conversion of Noto
Sans JP Regular, used by `classes/local/pdf_generator.php` for all four PDF
template types (badge/ticket/receipt/certificate) so Japanese template
content renders correctly instead of as mojibake -- Moodle's `pdf` wrapper
(`lib/pdflib.php`) never called `SetFont()` before this fix, so every PDF
fell back to TCPDF's Latin-only default (Helvetica), which has no CJK glyph
coverage at all.

**License**: SIL Open Font License 1.1 (see `OFL.txt` in this directory) --
explicitly permissive for embedding and redistribution, including in GPL
software. Noto Sans CJK/JP is a Google/Adobe collaboration (Adobe's Source
Han Sans design); the OFL.txt copyright notice referencing Adobe is
expected, not a licensing error.

**Source**: [Noto Sans JP](https://github.com/google/fonts/tree/main/ofl/notosansjp)
(Google Fonts), the variable-weight `NotoSansJP[wght].ttf`. Google Fonts
does not publish a static Regular-weight TTF for this family, so the
Regular (wght=400) static instance was extracted with `fonttools`:

```
fonttools varLib.instancer --update-name-table \
    -o NotoSansJP-Regular.ttf 'NotoSansJP[wght].ttf' wght=400
```

(`--update-name-table` is required -- without it the font's name table keeps
referencing the variable font's default named instance, which for this
family is Thin, not Regular, even though the outlines themselves are
correctly pinned to wght=400.)

**Conversion to TCPDF format**: via TCPDF's own `TCPDF_FONTS::addTTFfont()`
(`lib/tcpdf/include/tcpdf_fonts.php`), run once against the static Regular
TTF above:

```php
require_once($CFG->libdir . '/pdflib.php');
\TCPDF_FONTS::addTTFfont('/path/to/NotoSansJP-Regular.ttf', 'TrueTypeUnicode', '', 32, '/path/to/output/');
```

This produced `notosansjp.php` (font metrics/definition), `notosansjp.z`
(compressed embedded font program), and `notosansjp.ctg.z` (compressed
character-to-glyph map) -- the standard three-file TCPDF custom font format,
loaded via `$pdf->AddFont('notosansjp', '', $CFG->dirroot . '/mod/confcheckin/thirdparty/notosansjp/notosansjp.php')`
in `pdf_generator::build()`.
