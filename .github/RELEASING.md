# Prism release preflight

Complete this checklist on the release candidate **before pushing a tag**.
Packagist publishes from the tag push. The Publish workflow can withhold the
GitHub release and flag a bad tag; it cannot prevent or undo that publication.

- [ ] Fetch current Prism and prism-parity main branches and Prism tags. Record
  the full candidate SHA. Do not overwrite another contributor's working files.
- [ ] Re-read every Prism claim against the candidate code, including unreleased
  fixes. Use the shared checker's `--map` output as an inventory, and check nearby
  behavior and numeric statements too: existence checks do not verify prose.
  Fix drift and record what changed before reconciling. Commit the reviewed
  Prism changes so the candidate checkout is clean.
- [ ] If the Prism entry is stale, run the manual command below with the full
  SHA that was reviewed. Inspect and commit only the Prism changes in the shared
  lock, describe the reviewed SHA in that commit, and push prism-parity.
- [ ] Run all four local gates: Pest, PHPStan, Pint plus Rector, and
  composer-require-checker. Run the factcheck guard tests and the strict checker.
- [ ] Confirm successful Tests, PHPStan, Formatting, Require Checker and
  **strict Factcheck** CI runs on the exact candidate SHA. A successful run on a
  previous commit or a PR merge commit is insufficient. After reconciling the
  shared lock, rerun Factcheck on the candidate using workflow_dispatch.
- [ ] Complete the prerelease security audit and obtain release approval.
  Only the approved candidate may be tagged. Any candidate change repeats the
  review and CI steps.

From the Prism repository root, with prism-parity checked out beside it:

```sh
node ../prism-parity/tools/factcheck.mjs --repo . --map
node .github/scripts/factcheck.mjs reconcile ../prism-parity FULL_REVIEWED_SHA
node --test .github/scripts/factcheck.test.mjs .github/scripts/release-factcheck.test.mjs
node .github/scripts/factcheck.mjs check ../prism-parity
```

The manual helper requires the supplied SHA to equal HEAD and a clean Prism
checkout. The shared checker's raw `--reconcile` replaces the whole lock even
with `--repo`; do not use it directly for a Prism-only review. The helper runs
that operation in a temporary copy and merges only the Prism repo and package
entries back into `prism-parity/tools/factcheck.lock.json`. Sibling evidence and
the global reconciliation timestamp remain unchanged. Review the diff and stage
that file explicitly, never unrelated work in prism-parity. CI never reconciles.

The lock uses the nearest reachable tag, not a content digest. A new tag makes
the lock stale even when the code is identical: the first push after each
release will fail strict until its claims are reviewed and reconciled. The
failure prints the lock path, recorded and current versions, and the manual
command. Changes on the same tag are not detected by this currency heuristic;
the pre-tag review remains necessary. A shared content-based attestation is
separate work, not implemented by these guards.
