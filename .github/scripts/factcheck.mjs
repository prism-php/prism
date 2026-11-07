import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';

const [mode, parityArg, reviewedSha] = process.argv.slice(2);
const repo = process.cwd();
const parity = resolve(parityArg ?? '.parity');
const lockPath = join(parity, 'tools/factcheck.lock.json');
const git = (...args) => execFileSync('git', ['-C', repo, ...args], { encoding: 'utf8' }).trim();
const read = path => JSON.parse(readFileSync(path, 'utf8'));
const run = (root, ...args) => {
  const result = spawnSync(process.execPath, [join(root, 'tools/factcheck.mjs'), '--repo', repo, ...args], { stdio: 'inherit' });
  if (result.error) throw result.error;
  return result.status ?? 1;
};

/**
 * Manual only: the shared --reconcile is not scope-safe, even with --repo.
 * Run it in a temporary copy, then merge only repos.prism and
 * packages['particle-academy/prism']; never replace any sibling's evidence.
 * A reviewed SHA is an explicit operator assertion, not proof of a review.
 */
function reconcile() {
  if (!reviewedSha || !/^[a-f0-9]{40}$/.test(reviewedSha) || reviewedSha !== git('rev-parse', 'HEAD')) {
    throw new Error('Supply the full reviewed SHA, equal to HEAD, after re-reading every claim.');
  }
  if (git('status', '--porcelain', '--untracked-files=normal')) {
    throw new Error('Reconciliation requires a clean Prism checkout; commit the reviewed changes first.');
  }
  const before = readFileSync(lockPath, 'utf8');
  const original = JSON.parse(before);
  if (original.contract !== '1.0' || !original.repos?.prism || !original.packages?.['particle-academy/prism']) {
    throw new Error('Expected the existing contract 1.0 Prism entries; refusing to replace an unknown lock.');
  }
  const temp = mkdtempSync(join(tmpdir(), 'prism-reconcile-'));
  try {
    mkdirSync(join(temp, 'tools'));
    cpSync(join(parity, 'tools/factcheck.mjs'), join(temp, 'tools/factcheck.mjs'));
    cpSync(join(parity, 'docs/decisions'), join(temp, 'docs/decisions'), { recursive: true });
    writeFileSync(join(temp, 'tools/factcheck.lock.json'), before);
    if (run(temp, '--reconcile') !== 0) throw new Error('Reconciliation refused; the shared lock is unchanged.');
    const updated = read(join(temp, 'tools/factcheck.lock.json'));
    if (updated.packages?.['particle-academy/prism']?.published !== true) {
      throw new Error('Could not confirm Prism publication; the shared lock is unchanged. Retry with network access.');
    }
    original.repos.prism = updated.repos.prism;
    original.packages['particle-academy/prism'] = updated.packages['particle-academy/prism'];
    // Validate the actual merged result, including preserved sibling publication
    // evidence used by Prism's companion-package install documentation.
    writeFileSync(join(temp, 'tools/factcheck.lock.json'), JSON.stringify(original, null, 2) + '\n');
    if (run(temp, '--strict') !== 0) throw new Error('Reconciled claims still fail; the shared lock is unchanged.');
    if (readFileSync(lockPath, 'utf8') !== before || git('rev-parse', 'HEAD') !== reviewedSha || git('status', '--porcelain', '--untracked-files=normal')) {
      throw new Error('The lock or reviewed checkout changed during reconciliation. Re-read and retry.');
    }
    writeFileSync(lockPath, JSON.stringify(original, null, 2) + '\n');
    console.log(`Reconciled only Prism at reviewed SHA ${reviewedSha} into ${lockPath}.`);
  } finally {
    rmSync(temp, { recursive: true, force: true });
  }
}

try {
  if (basename(repo) !== 'prism' || read(join(repo, 'composer.json')).name !== 'particle-academy/prism') {
    throw new Error('Run this command from the prism repository root.');
  }
  if (mode === 'reconcile') {
    reconcile();
  } else if (mode === 'check') {
    const status = run(parity, '--strict');
    if (status !== 0) {
      console.error(`\nVerification lock: ${lockPath}`);
      console.error(`Recorded Prism version: ${read(lockPath).repos?.prism?.version ?? 'unrecorded'}`);
      console.error(`Current Prism version: ${git('describe', '--tags', '--abbrev=0')}`);
      console.error('Fix findings and re-read the claims against current code BEFORE reconciling. From a clean Prism checkout with prism-parity beside it, run:');
      console.error(`node .github/scripts/factcheck.mjs reconcile ../prism-parity ${git('rev-parse', 'HEAD')}`);
      console.error('Review and commit only the Prism lock entry in prism-parity, push it, then rerun Factcheck. See .github/RELEASING.md. CI never reconciles.');
    }
    process.exitCode = status;
  } else {
    throw new Error('Usage: node .github/scripts/factcheck.mjs check <parity-path> | reconcile <parity-path> <reviewed-sha>');
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
