import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { execFileSync, spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const source = existsSync(join(root, '.parity/tools/factcheck.mjs'))
  ? join(root, '.parity') : resolve(root, '../prism-parity');

function fixture(t) {
  const dir = mkdtempSync(join(tmpdir(), 'prism-factcheck-'));
  t.after(() => rmSync(dir, { recursive: true, force: true }));
  const repo = join(dir, 'prism');
  const parity = join(dir, 'prism-parity');
  mkdirSync(join(repo, 'src'), { recursive: true });
  mkdirSync(join(parity, 'tools'), { recursive: true });
  cpSync(join(source, 'tools/factcheck.mjs'), join(parity, 'tools/factcheck.mjs'));
  cpSync(join(source, 'docs/decisions'), join(parity, 'docs/decisions'), { recursive: true });
  writeFileSync(join(repo, 'composer.json'), JSON.stringify({
    name: 'particle-academy/prism', autoload: { 'psr-4': { 'Prism\\Prism\\': 'src/' } },
  }));
  writeFileSync(join(repo, 'src/Example.php'), '<?php namespace Prism\\Prism; class Example {}');
  writeFileSync(join(repo, 'README.md'), '```php\nuse Prism\\Prism\\Example;\n```\n');
  const git = (...args) => execFileSync('git', ['-C', repo, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  git('init', '-q');
  git('add', '.');
  git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'fixture');
  git('tag', 'v0.130.0');
  const sha = git('rev-parse', 'HEAD');
  const lock = join(parity, 'tools/factcheck.lock.json');
  const initial = {
    contract: '1.0', reconciledAt: 'original timestamp', note: 'preserve this',
    packages: { 'particle-academy/prism': { published: true }, 'particle-academy/sibling': { published: false, sentinel: 1 } },
    repos: { prism: { version: 'v0.121.0', census: { 'php-class': 1 } }, sibling: { version: 'v9.9.9', census: { 'local-link': 42 } } },
  };
  writeFileSync(lock, JSON.stringify(initial, null, 2) + '\n');
  // Only the test process tree stubs publication lookup; no network is needed.
  const preload = join(dir, 'fetch.mjs');
  writeFileSync(preload, 'globalThis.fetch = async () => ({status: 200});');
  const run = (mode, reviewed = sha) => spawnSync(process.execPath,
    [join(root, '.github/scripts/factcheck.mjs'), mode, parity, ...(mode === 'reconcile' ? [reviewed] : [])],
    { cwd: repo, encoding: 'utf8', env: { ...process.env, NODE_OPTIONS: `--import=${pathToFileURL(preload).href}` } });
  return { repo, parity, sha, lock, initial, run, git, preload };
}

test('stale claims fail with versions, lock path and a manual command; checking never writes', t => {
  const f = fixture(t);
  const before = readFileSync(f.lock, 'utf8');
  const result = f.run('check');
  assert.equal(result.status, 1);
  const output = result.stdout + result.stderr;
  for (const value of ['v0.121.0', 'v0.130.0', 'factcheck.lock.json', 'reconcile', f.sha]) assert.ok(output.includes(value), output);
  assert.equal(readFileSync(f.lock, 'utf8'), before);
});

test('reviewed reconciliation preserves every sibling entry and unrelated lock field', t => {
  const f = fixture(t);
  const result = f.run('reconcile');
  assert.equal(result.status, 0, result.stdout + result.stderr);
  const after = JSON.parse(readFileSync(f.lock, 'utf8'));
  assert.equal(after.repos.prism.version, 'v0.130.0');
  assert.deepEqual(after.repos.prism.census, { 'php-class': 1 });
  assert.equal(after.packages['particle-academy/prism'].published, true);
  after.repos.prism = f.initial.repos.prism;
  after.packages['particle-academy/prism'] = f.initial.packages['particle-academy/prism'];
  assert.deepEqual(after, f.initial);
  const checked = f.run('check');
  assert.equal(checked.status, 0, checked.stdout + checked.stderr);
});

test('a wrong or absent reviewed SHA cannot change the lock', t => {
  const f = fixture(t);
  const before = readFileSync(f.lock, 'utf8');
  for (const reviewed of ['', '0'.repeat(40)]) {
    const result = f.run('reconcile', reviewed);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /reviewed.*SHA/i);
    assert.equal(readFileSync(f.lock, 'utf8'), before);
  }
});

test('uncommitted claim edits cannot be attested by a committed SHA', t => {
  const f = fixture(t);
  const before = readFileSync(f.lock, 'utf8');
  writeFileSync(join(f.repo, 'README.md'), 'Edited after review');
  const result = f.run('reconcile');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /clean|uncommitted/i);
  assert.equal(readFileSync(f.lock, 'utf8'), before);
});

test('a broken documented class refuses reconciliation and preserves the entire lock', t => {
  const f = fixture(t);
  writeFileSync(join(f.repo, 'README.md'), '```php\nuse Prism\\Prism\\Missing;\n```\n');
  f.git('add', 'README.md');
  f.git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'broken claim');
  const before = readFileSync(f.lock, 'utf8');
  const result = f.run('reconcile', f.git('rev-parse', 'HEAD'));
  assert.notEqual(result.status, 0);
  assert.match(result.stdout + result.stderr, /unresolved finding|No such class/);
  assert.equal(readFileSync(f.lock, 'utf8'), before);
});

test('an unavailable publication lookup cannot overwrite verified publication data', t => {
  const f = fixture(t);
  writeFileSync(f.preload, 'globalThis.fetch = async () => { throw new Error("offline"); };');
  const before = readFileSync(f.lock, 'utf8');
  const result = f.run('reconcile');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /publication/i);
  assert.equal(readFileSync(f.lock, 'utf8'), before);
});

test('a concurrent shared-lock edit is preserved instead of overwritten', t => {
  const f = fixture(t);
  const concurrent = JSON.stringify({ ...f.initial, note: 'another review landed' }, null, 2) + '\n';
  writeFileSync(f.preload, `import {writeFileSync} from 'node:fs'; globalThis.fetch = async () => {
    writeFileSync(${JSON.stringify(f.lock)}, ${JSON.stringify(concurrent)}); return {status: 200};
  };`);
  const result = f.run('reconcile');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /changed during reconciliation/);
  assert.equal(readFileSync(f.lock, 'utf8'), concurrent);
});

test('post-reconcile verification uses the preserved sibling publication evidence', t => {
  const f = fixture(t);
  f.initial.packages['particle-academy/sibling'].published = true;
  writeFileSync(f.lock, JSON.stringify(f.initial));
  writeFileSync(join(f.repo, 'README.md'), '```sh\ncomposer require particle-academy/sibling\n```\n');
  f.git('add', 'README.md');
  f.git('-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-qm', 'sibling install');
  const result = f.run('reconcile', f.git('rev-parse', 'HEAD'));
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.doesNotMatch(result.stdout + result.stderr, /Not in the published census/);
});
