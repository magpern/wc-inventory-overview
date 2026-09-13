# WC Inventory Overview 1.43.2 — release notes

## Changed

- Automatic updates now come from a private update server via the bundled
  [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) v5
  library (`lib/plugin-update-checker/`). The bespoke GitHub-release updater
  (`includes/class-github-updater.php`) has been removed.
- The update check runs only when the `PRIVATE_UPDATE_SERVER` constant is defined
  in `wp-config.php` (admin/cron only).
- Added a CI workflow that uploads the release ZIP to the update server on each
  `v*` tag.

## Install

Deploy `wc-inventory-overview` **1.43.2** / tag **`v1.43.2`**.

Rollback: **1.43.1** / `v1.43.1`.
