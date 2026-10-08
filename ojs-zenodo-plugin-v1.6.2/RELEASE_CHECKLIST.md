# Release checklist

- [ ] Update `version.xml` release/date.
- [ ] Update `CHANGELOG.md`.
- [ ] Run PHP syntax checks.
- [ ] Verify XML files parse.
- [ ] Verify locale keys.
- [ ] Confirm no credentials/secrets are present.
- [ ] Test article deposit in Zenodo Sandbox.
- [ ] Test article publish in Sandbox.
- [ ] Test issue publish in Sandbox.
- [ ] Test previous-issue backfill in Sandbox.
- [ ] Build install ZIP with top-level `zenodo/` directory.
- [ ] Create Git tag `vX.Y.Z`.
- [ ] Attach install ZIP to GitHub Release.
