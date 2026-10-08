# Changelog

All notable changes to this plugin are documented here.

## 1.6.2 - 2026-10-08

### Fixed

- Issue-level Zenodo publication now satisfies Zenodo's requirement that every published record contain at least one file.
- Issue records now upload a generated `issue-<id>-manifest.json` before publication instead of attempting a metadata-only record.
- Existing issue drafts are updated with files enabled and reuse the same saved Zenodo record ID/DOI on retry.

### Added

- GitHub-ready project documentation and release instructions.
- GitHub Actions PHP lint workflow.
- Security and contribution guidance.

## 1.6.1 - 2026-10-06

- Display saved per-issue backfill errors in the Backfill Previous Issues modal.

## 1.6.0

- Add previous-issue backfill: one issue or all published issues not yet completed.
- Add issue-level Zenodo records and issue DOI workflow.

## 1.5.4

- Add creator family-name fallback for legacy/migrated OJS author records.

## 1.5.3

- Improve Zenodo field-level validation error parsing.
- Validate ORCID checksums before sending creator identifiers.

## 1.5.2

- Improve file readiness checks before publication.

## 1.5.1

- Fix OJS 3.5 queue job `$timeout` property typing.

## 1.5.0

- Add automatic article and issue processing.
- Add issue DOI model and article DOI relationships.

## 1.4.0

- Add one-click background queue workflow.

## 1.3.0

- Move to Zenodo/InvenioRDM Records API.
- Add rich metadata, DOI reservation, workflow dates, and verified PDF upload.
