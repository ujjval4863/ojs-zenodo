# Zenodo Deposit v1.6.2

## Fix: issue and previous-issue publication

Zenodo's public service requires at least one file on a published record. Earlier issue-level records attempted to publish without article files and could fail with:

```text
files.enabled: Missing uploaded files. To disable files for this record please mark it as metadata-only.
```

Zenodo currently does not support metadata-only publication on the public service, so v1.6.2 changes issue records to upload a small generated JSON manifest before publication.

The manifest contains:

- journal title and ISSN;
- publisher;
- issue ID/identification/title;
- volume and issue number;
- publication date;
- reserved/published issue DOI; and
- article titles and article DOIs.

Article PDFs are not duplicated into the issue record.

## GitHub-ready project files

This release also adds:

- expanded `README.md`;
- `CHANGELOG.md`;
- `GITHUB_PUBLISHING.md`;
- `SECURITY.md`;
- `CONTRIBUTING.md`;
- `RELEASE_CHECKLIST.md`;
- GPL-3.0 license; and
- GitHub Actions PHP lint workflow.
