# Changelog

## [Unreleased]

- **Review-and-fix queue.** Localized findings in *Aktueller Report* can now be
  acted on directly: **Übernehmen** writes the suggestion back into the content
  element (via DataHandler — with undo/history), **Manuell erledigt** marks a
  finding the editor fixed by hand, **Verwerfen** dismisses a finding, and each
  finding deep-links to its content element's edit form. Progress
  ("x von y erledigt") is tracked per report run.
- bodytext write-back anchors the quote within a single RTE text node and only
  offers *Übernehmen* when it matches uniquely; otherwise it falls back to the
  deep-link. header/subheader apply by exact match.
- Page-wide and free-text hints stay advisory under a separate
  *"Erfordert Ihre Einschätzung"* section (they can't be auto-applied).
- The fix actions work without JavaScript (full-page reload); a small progressive
  enhancement updates findings in place when available.

## [0.2.0] - 2026-06-29

- Added **model picker** with presets.

## [0.1.0] - 2026-06-25

Initial release. \o/

This is an **alpha** version, use with care. See [README](README.md) for the most important limitations.
