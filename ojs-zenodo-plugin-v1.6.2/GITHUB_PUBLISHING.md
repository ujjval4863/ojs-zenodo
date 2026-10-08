# Publishing this plugin on GitHub

This repository is prepared so the plugin source can live at the repository root.

## 1. Create the repository

On GitHub, create a new repository, for example:

```text
ojs-zenodo-plugin
```

Recommended options:

- Public repository
- Do not add another README, license, or `.gitignore` if you are uploading this prepared package

## 2. Push the source with Git

Extract the GitHub source ZIP locally, open a terminal in the extracted directory, then run:

```bash
git init
git add .
git commit -m "Initial release: Zenodo Deposit 1.6.2"
git branch -M main
git remote add origin https://github.com/YOUR-USERNAME/ojs-zenodo-plugin.git
git push -u origin main
```

Replace `YOUR-USERNAME` with your GitHub username or organization.

## 3. Create the first release

On GitHub:

1. Open the repository.
2. Go to **Releases**.
3. Click **Draft a new release**.
4. Create tag:

```text
v1.6.2
```

5. Release title:

```text
Zenodo Deposit for OJS 3.5 - v1.6.2
```

6. Attach the installable asset:

```text
ojs-zenodo-3.5-v1.6.2.zip
```

7. Copy the relevant section of `CHANGELOG.md` into the release notes.
8. Publish the release.

## 4. Keep secrets out of GitHub

Never commit:

- Zenodo access tokens
- OJS `config.inc.php`
- database dumps
- user uploads
- HAR files containing authenticated browser traffic
- server logs containing secrets

The supplied `.gitignore` helps prevent common accidental additions, but always review `git status` before committing.

## 5. Updating a release

For a future version:

1. Update `version.xml`.
2. Update `CHANGELOG.md`.
3. Run PHP lint.
4. Commit changes.
5. Tag the release.
6. Build an install ZIP whose top-level directory is exactly `zenodo/`.
7. Attach that install ZIP to the GitHub release.

Example:

```bash
git add .
git commit -m "Release v1.6.3"
git tag -a v1.6.3 -m "Zenodo Deposit v1.6.3"
git push origin main --tags
```

## 6. GitHub Actions

The included workflow at `.github/workflows/php-lint.yml` runs PHP syntax checks automatically for pushes and pull requests.
