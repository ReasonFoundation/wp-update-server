# packages.reason.com runbook

## What this serves

* Update-checker route (legacy WordPress sites): `/<slug>/?action=get_metadata`
  and `?action=download`. Key via `Authorization: Bearer` or `?key=`.
* Composer routes (vnext sites): `/packages.json`, `/p2/reason-dev/<slug>.json`,
  `/dist/reason-dev/<slug>/<slug>-<version>.zip`. Key via `Authorization: Bearer`
  only. Answer 503 if `SIMPLE_UPDATE_KEY` is unset.

This server only reads the bucket. Everything under `packages/`, `dist/`,
`meta/` and `p2/` is written by `reason-wp-repo-actions`.

## Rules

* **Append-only.** Never overwrite or delete objects under `packages/`, `dist/`,
  `meta/` or `p2/` by hand, and never add an S3 lifecycle rule that expires
  them. Sites' `composer.lock` files download exact URLs forever; deleting a
  version breaks every site that locked it, including their rollbacks.
* **The one exception: `reason-dev/wpup-smoke`.** It is a throwaway test
  package written only by `tests/acceptance/composer-smoke.sh`. Each run
  uploads a new zip under `dist/` (append-only) and replaces
  `p2/reason-dev/wpup-smoke.json` with just that run's version. No site
  ever requires it, and it has no `meta/` sidecar, so the index rebuild
  script leaves it alone.
* **Rebuilding an index:** `scripts/rebuild-composer-index.sh` in
  `reason-wp-repo-actions` regenerates `p2/` from `meta/`. Use it instead of
  editing a p2 file.

## Rotating SIMPLE_UPDATE_KEY

One key serves the whole fleet. Rotation touches, in this order:

1. Every legacy site's `REASON_PACKAGES_UPDATES_KEY` constant. The server
   accepts only one key at a time, so schedule a short window: sites send
   the old key until their constant changes.
2. Every vnext site repo's `COMPOSER_AUTH_JSON` secret
   (`{"bearer":{"packages.reason.com":"<key>"}}`).
3. The org secret `REASON_PACKAGES_UPDATES_KEY` used by plugin CI.
4. SSM `/wp-update-server/prod/SIMPLE_UPDATE_KEY`, then redeploy.

## Test stage

`dev` stage: SSM `/wp-update-server/dev/SIMPLE_UPDATE_KEY`, stack
`wp-update-server-dev`. Smoke test:
`tests/acceptance/composer-smoke.sh <url> <bucket> <key>`.

The smoke test's "query-string key is refused" check sends the key in a
URL (`?key=`) on purpose, to prove it is rejected. Treat that key as
possibly present in API Gateway access logs for that request.
