# Review notes — composer routes (2026-10-06)

Downstream review of `feature/composer-routes` at `5929908`, from the
consumer side: `wp-site-template` (`docs/plugin-distribution.md`) and
`reason-edge-delivery` (PLAN §0.4) now treat this branch as the canonical
distribution mechanism, and their docs were aligned to it on this date —
`reason-dev/*` vendor, Bearer-only `COMPOSER_AUTH_JSON`, append-only store.

Both test suites pass locally at this commit. The router design, fail-closed
auth, the no-S3-calls-on-bad-input guarantees, and the append-only doctrine
all hold up under review. Recommendations, in priority order:

## 1. Write down the p2 publish contract before plan 2 implements it

Today the only specification of what a `p2/reason-dev/<slug>.json` version
entry contains is the smoke test's heredoc. Downstream correctness depends on
fields this server never inspects:

- `name` = `reason-dev/<slug>` (slug = the plugin's directory/text domain);
- `type: wordpress-plugin` and `extra.installer-name` (so composer/installers
  lands the plugin in the right directory on sites);
- the package's **`require` list, derived from the plugin's own
  `composer.json`** — reason-edge-delivery hard-requires `aws/aws-sdk-php`
  and its plan assumes the requirement propagates so the *site's* composer
  resolves and dedupes the SDK;
- the **dist zip shape**: plugin source + `composer.json`, **no `vendor/`**.
  The bundled-vendor zip belongs only under `packages/` for the
  update-checker route — a vendored zip served through `dist/` would install
  a second SDK copy into every consuming site.

Recommendation: a short "publish contract" section in `docs/runbook.md` (or a
spec file plan 2 must implement against), so the pipeline work can't quietly
invent a different shape than the one the smoke test and the consumers assume.

## 2. The runbook points at a script that doesn't exist yet

`scripts/rebuild-composer-index.sh` in `reason-wp-repo-actions` is named as
the recovery tool for `p2/`, but that repo's `main` currently has no
`scripts/` directory and no composer-side writers at all (only the legacy
`packages/` upload). Worth a "(lands with plan 2)" marker so an operator
mid-incident doesn't hunt for a tool that isn't there.

## 3. Dual-key rotation: promote from "possible future work" to a follow-up

Accepting `SIMPLE_UPDATE_KEY` plus a `SIMPLE_UPDATE_KEY_PREVIOUS` during
rotation removes the fleet-wide breakage window the runbook currently
documents as accepted. It is a few lines in `isAuthorized()` (and the
update-checker equivalent once the key is enforced there), and the rotation
procedure gets simpler rather than more complex.

## 4. Open the PR

This branch's own plan ends with a PR into `master`; it has not been opened.
Downstream work is now aligned to this branch (reason-edge-delivery PR #1 is
renaming to the `reason-dev` vendor; wp-site-template documents the
bearer-shaped secret), so the remaining risk is drift the longer it sits
unmerged.
