# Moodle Plugin Release Workflow

The actual Moodle plugin source is `plugin/webirr`.

## Required Release Shape

Moodle plugin releases must be packaged as a ZIP whose top-level folder is named:

```text
webirr
```

That means the contents of `plugin/webirr` should appear inside `webirr/` in the
ZIP, not inside `plugin/webirr/`.

## Release Automation Tasks

- [ ] Create `tools/package-plugin.sh` to build a clean `webirr.zip` from
  `plugin/webirr`.
- [ ] Exclude repository-only files, standalone demo files, Moodle example site
  files, local SQLite data, and development vendor folders from the plugin ZIP.
- [ ] Add a GitHub Actions release workflow that validates the plugin and
  attaches the generated `webirr.zip` to a GitHub release.
- [ ] Decide whether the Moodle Plugins directory upload will be manual or
  automated after GitHub release creation.
- [ ] Document the final Moodle Plugins directory submission steps once they are
  confirmed.

## Current Manual Packaging Target

Until automation exists, a release package should be assembled so it has this
shape:

```text
webirr/
  amd/
  classes/
  db/
  lang/
  pix/
  pay.php
  settings.php
  styles.css
  success.php
  version.php
  ...
```

The package should be tested by installing it into Moodle as
`payment/gateway/webirr`, then completing a TestEnv checkout validation.
