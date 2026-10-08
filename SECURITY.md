# Security Policy

## Supported version

Security fixes should be applied to the latest released version.

## Secrets

Never include Zenodo personal access tokens, OJS database credentials, OJS `config.inc.php`, database dumps, authenticated HAR files, or private server logs in GitHub issues or commits.

If a token is exposed, revoke/rotate it immediately in Zenodo and update the token in OJS plugin settings.

## Reporting a vulnerability

Please report security-sensitive issues privately to the repository maintainer rather than opening a public issue that contains credentials or exploitable details.

When reporting, include:

- plugin version;
- OJS version;
- PHP version;
- affected workflow;
- sanitized error message;
- steps to reproduce without secrets.
