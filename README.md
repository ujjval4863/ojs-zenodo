# OJS Zenodo Deposit Plugin

A generic plugin for **Open Journal Systems (OJS) 3.5.x** that deposits published journal articles and issues to **Zenodo**, uploads article PDFs, reserves/publishes DOIs, transfers rich metadata, and stores Zenodo DOIs back in OJS when appropriate.

Current release: **1.6.2**

## Highlights

- One-click/manual or automatic background deposit of published articles.
- Automatic Zenodo deposit when an article is published in OJS.
- Automatic Zenodo issue record when an issue is published.
- Backfill support for issues published before the plugin was installed.
- Zenodo DOI reservation and publication.
- Article PDF is uploaded as a file of the article record, so the article and its PDF use the same Zenodo record DOI.
- Issue records get their own DOI and link to article DOIs using `HasPart` relations.
- Issue records upload a generated JSON manifest instead of duplicating all article PDFs.
- Author affiliations and ORCID identifiers when available.
- Journal metadata, publisher, ISSN, version, language, references, keywords, and OJS workflow dates.
- Native OJS background jobs with duplicate/overlap protection.
- Sandbox and Production environments.
- Detailed Zenodo validation errors surfaced in OJS.

## Why issue records contain a manifest file

Zenodo currently requires a published record to have at least one file. Therefore issue-level records cannot be metadata-only on the public Zenodo service.

This plugin uploads a small generated file named similar to:

```text
issue-12-manifest.json
```

The manifest contains journal/issue metadata and the DOI list of the articles in that issue. Article PDFs remain only in their article records and are **not duplicated** at issue level.

Zenodo reference: <https://support.zenodo.org/help/en-gb/1-upload-deposit/36-do-you-support-metadata-only-records>

## Requirements

- OJS **3.5.x**
- PHP **8.2+** recommended for the tested deployment
- cURL PHP extension
- A Zenodo or Zenodo Sandbox account
- A Zenodo personal access token with permissions required to create/update and publish deposits
- OJS background job runner enabled for automatic/background processing

## Installation

Download the release ZIP and extract it so the final directory is exactly:

```text
plugins/generic/zenodo
```

From the OJS root directory run:

```bash
php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml
rm -f cache/*.php
rm -rf cache/t_compile/*
rm -rf cache/t_cache/*
rm -rf cache/opcache/*
```

Then open:

```text
Settings → Website → Plugins → Zenodo Deposit
```

Enable the plugin and open **Settings**.

> Keep backup plugin directories outside `plugins/generic/`. OJS scans directories in that location as plugins.

## Upgrade

Back up the existing plugin outside `plugins/generic`, extract the new release, register the new version, and clear caches.

Example:

```bash
cd /path/to/ojs
mv plugins/generic/zenodo ./zenodo-previous-backup
unzip ojs-zenodo-3.5-v1.6.2.zip -d plugins/generic/
php lib/pkp/tools/installPluginVersion.php plugins/generic/zenodo/version.xml
rm -f cache/*.php
rm -rf cache/t_compile/* cache/t_cache/* cache/opcache/*
```

Do **not** put a backup directory such as `zenodo-old` inside `plugins/generic/`.

## Configuration

Configure the plugin under **Settings → Website → Plugins → Zenodo Deposit → Settings**.

Typical settings include:

- Environment: Sandbox or Production
- Zenodo personal access token
- Publisher
- Journal ISSN
- Resource version
- Language code
- Store Zenodo DOI in OJS after successful Production publication
- Automatic article publication
- Automatic issue publication

### Recommended Tark Tansaku defaults

```text
Publisher: Tejo Prabha Foundation
ISSN: 3139-7700
Version: 1.0
Language: eng
```

## Article workflow

When an article is processed, the plugin:

1. Creates or reuses one Zenodo draft.
2. Saves the Zenodo record ID immediately to prevent duplicate drafts.
3. Synchronizes article metadata.
4. Reserves a DOI when needed.
5. Uploads the local PDF galley.
6. Verifies the uploaded PDF.
7. Publishes the same Zenodo record.
8. Verifies the public record.
9. In Production, optionally stores a newly allocated Zenodo DOI back in an empty OJS publication DOI field.

The PDF is a file inside the article record. The plugin does **not** mint a second DOI specifically for the PDF.

## Metadata transferred for articles

Where available in OJS, the plugin sends:

- Title
- Abstract/description
- Publication date
- Authors/creators
- Affiliations
- ROR identifiers when available
- ORCID identifiers when valid
- Publisher
- Version
- Language
- References/citations
- Keywords/subjects
- Journal title
- ISSN
- Volume
- Issue
- Page range/article number
- Submission date
- Review-completed date
- Accepted/copyediting date
- Production date
- Published date
- Local PDF galley

## DOI policy

The plugin avoids deliberately minting a second DOI for an OJS object that already has one.

- **No existing OJS DOI:** Zenodo can reserve/publish a DOI, which may then be stored back in OJS in Production.
- **Existing OJS DOI:** the plugin uses it as an external DOI rather than requesting a second DOI for the same object.

If you want **one DOI for an article and its PDF**, do not independently configure OJS to mint a separate DOI for the article galley/representation.

## Issue workflow

When an issue is processed, the plugin:

1. Waits for OJS to finish publishing the issue's articles.
2. Ensures each article has a published Zenodo record/DOI.
3. Creates or reuses one Zenodo issue draft.
4. Reserves or reuses an issue DOI.
5. Adds journal/issue metadata and `HasPart` relations to article DOIs.
6. Generates and uploads `issue-<id>-manifest.json` because Zenodo requires a file on published records.
7. Publishes the issue record.
8. Optionally stores the issue DOI back in OJS in Production.

The manifest includes the journal title, ISSN, publisher, issue identification, publication date, issue DOI, and article titles/DOIs.

## Backfilling previous issues

Use:

```text
Zenodo Deposit → Backfill Issues
```

You can choose:

- **Backfill Selected Issue**
- **Backfill All Previous Issues**

Backfill jobs reuse saved Zenodo IDs and skip already completed issue records, so retries are designed to be idempotent.

## Background processing

The plugin uses OJS's native queue infrastructure and job uniqueness/overlap protection. Normal automatic operation is:

```text
Publish in OJS
    ↓
Queue job
    ↓
Return control to editor
    ↓
Process Zenodo deposit in background
```

The manual **Deposit & Publish** action remains available for testing and recovery.

## Troubleshooting

### Zenodo HTTP 400 validation error

The plugin extracts Zenodo field-level validation errors when available. Correct the reported OJS metadata and retry; the saved draft is normally reused.

### Creator family name cannot be blank

OJS can contain migrated/legacy author records without a family name. The plugin derives a safe family-name fallback from the display name, including support for single-name authors.

### Issue says `files.enabled: Missing uploaded files`

Use version **1.6.2 or later**. Zenodo requires at least one file for a published record, so issue records now upload a generated issue manifest before publication.

### Draft created but not published

Check the background job status and confirm the Zenodo token can perform publication actions in the selected environment.

### Plugin list/page fails to load

PHP warnings printed before OJS JSON responses can break the OJS plugin grid even when the HTTP status is 200. Check browser DevTools Network responses for warning text before JSON.

## Security

- Never commit Zenodo API tokens to GitHub.
- Tokens are stored in OJS plugin settings and are not included in release archives.
- Use separate Sandbox and Production tokens.
- Rotate a token if it has ever been exposed publicly.
- Keep OJS debug output disabled on production after troubleshooting.

See [SECURITY.md](SECURITY.md).

## Development

PHP syntax check:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

The repository includes a GitHub Actions workflow that performs PHP syntax checks on pushes and pull requests.

## Release packaging

For an OJS-installable ZIP, the archive must contain the top-level folder `zenodo/`:

```text
zenodo/
├── index.php
├── version.xml
├── ZenodoPlugin.php
└── ...
```

See [GITHUB_PUBLISHING.md](GITHUB_PUBLISHING.md) for repository and release instructions.

## License

GNU General Public License v3.0 or later. See [LICENSE](LICENSE).

## Disclaimer

Test new versions against Zenodo Sandbox and a staging OJS installation before enabling Production publication. Repository APIs and OJS internals may change across versions.
