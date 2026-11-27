import { test } from 'node:test';
import assert from 'node:assert/strict';
import { requireFactcheck } from './release-factcheck.mjs';

const sha = 'a'.repeat(40);
const success = { head_sha: sha, status: 'completed', conclusion: 'success', event: 'push' };

test('only completed successful checks on the exact commit qualify', () => {
  assert.doesNotThrow(() => requireFactcheck([success], sha));
  for (const runs of [[], [{ ...success, head_sha: 'b'.repeat(40) }], [{ ...success, status: 'in_progress' }], [{ ...success, conclusion: 'failure' }], [{ ...success, conclusion: 'skipped' }], [{ ...success, event: 'pull_request' }]]) {
    assert.throws(() => requireFactcheck(runs, sha), /No successful strict Factcheck/);
  }
});

test('manual and nightly runs can prove the exact candidate, regardless of unrelated runs', () => {
  for (const event of ['workflow_dispatch', 'schedule']) {
    assert.doesNotThrow(() => requireFactcheck([{ ...success, head_sha: 'b'.repeat(40) }, { ...success, event }], sha));
  }
});
