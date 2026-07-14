# Changelog

## [0.4.0] - 2026-07-14

Features:
 - Added cost overview to the statistics page.
 - More accessible prompt settings, moved to the "Einstellungen" page.

Changes:
 - Updated shipped presets after some local testing (to be formalized).
 - Removed the "check style" config option for now since it didn't have any effect.
 - Hide docheader button when user lacks module access.

Fixes:
 - Preserve character entities on bodytext apply (e.g. the RTE's &quot; was silently rewritten to " when applying unrelated changes).

## [0.3.0] - 2026-07-07

Features:
 - Added ability to **automatically apply** fixes from the report UI.
 - Added a **statistics** view to see extension usage.
 - Display report state in the header icon.
 - Added test suite.

Changes:
 - Mutating actions now require **content-edit permission** on the page.
 - Better UI for page-wide findings.

Fixes:
 - Fixed prompt building for gender-inclusive language.
 - Some UI fixes.
 - Fixed stale task detection for long-running tasks (heartbeat).
 - Fixed a race condition where a double click on "Report erstellen" could enqueue (and
   bill) the same page twice.

## [0.2.0] - 2026-06-29

- Added **model picker** with presets.

## [0.1.0] - 2026-06-25

Initial release. \o/

This is an **alpha** version, use with care. See [README](README.md) for the most important limitations.

