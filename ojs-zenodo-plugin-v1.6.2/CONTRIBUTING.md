# Contributing

Contributions are welcome.

## Development guidelines

- Target OJS 3.5.x compatibility unless a change explicitly targets a newer major version.
- Keep Zenodo tokens and production data out of fixtures and commits.
- Preserve idempotency: retrying a deposit should reuse a saved draft whenever safe.
- Do not create side effects from simple GET requests.
- Use OJS background jobs for long-running network operations.
- Surface Zenodo field-level validation errors instead of hiding them.

## Before opening a pull request

Run:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Also verify:

- `version.xml` is valid XML;
- locale keys used by templates/code exist;
- no credentials are present;
- Sandbox deposit works before testing Production.
