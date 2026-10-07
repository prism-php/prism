import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';

export function requireFactcheck(runs, sha) {
  // PR workflows can execute a synthetic merge commit instead of head_sha.
  const found = runs.some(run => run.head_sha === sha && run.status === 'completed'
    && run.conclusion === 'success' && ['push', 'workflow_dispatch', 'schedule'].includes(run.event));
  if (!found) throw new Error(`No successful strict Factcheck run for ${sha}. Refusing to release.`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const { GITHUB_REPOSITORY: repo, GITHUB_SHA: sha } = process.env;
    if (!repo || !/^[a-f0-9]{40}$/.test(sha ?? '')) throw new Error('GITHUB_REPOSITORY and full GITHUB_SHA are required.');
    const pages = JSON.parse(execFileSync('gh', ['api', '--paginate', '--slurp',
      `repos/${repo}/actions/workflows/factcheck.yml/runs?head_sha=${sha}&per_page=100`,
    ], { encoding: 'utf8' }));
    requireFactcheck(pages.flatMap(page => page.workflow_runs), sha);
    console.log(`Strict Factcheck succeeded for ${sha}.`);
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
