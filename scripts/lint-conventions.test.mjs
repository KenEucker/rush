import { test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { lintCommit, lintPullRequest } from './lint-conventions.mjs';

const message = `ci(foundation): verify clean checkout and session boundaries

Run real sessions through Caddy to catch integration regressions.

Refs: RUSH-007
Requirements: R-01–R-38 (cross-cutting); Technical §13
Tests: npm run conventions:test (passed)
`;
const body = `## Task and requirements
RUSH-007; R-01–R-38, cross-cutting Technical §13.
## Behavior
Verify sign-in and authorization.
## Decisions
Use real sessions with a disposable PostgreSQL fixture.
## Database and sync
No schema or sync changes.
## Tests
npm run conventions:test (passed).
## Screenshots
Not applicable: no UI change.
## Limitations and migration
Human review is required; no migration.
`;

test('accepts the documented commit and complete PR formats, including CRLF', () => {
  assert.deepEqual(lintCommit(message), []);
  assert.deepEqual(lintCommit(message.replaceAll('\n', '\r\n')), []);
  assert.deepEqual(lintPullRequest(message.split('\n')[0], body), []);
});
for (const field of ['Refs:', 'Requirements:', 'Tests:']) {
  test(`rejects a commit missing ${field}`, () => {
    assert.notEqual(lintCommit(message.split('\n').filter((line) => !line.startsWith(field)).join('\n')).length, 0);
  });
}
test('rejects unknown types, unscoped summaries, missing rationale and unreported tests', () => {
  for (const invalid of [message.replace('ci(foundation)', 'build(foundation)'), message.replace('ci(foundation)', 'ci'), message.replace('Run real sessions through Caddy to catch integration regressions.', ''), message.replace('(passed)', '(not run)'), message.replace('RUSH-007', 'RUSH-099'), message.replace('R-01–R-38', 'R-99')]) {
    assert.notEqual(lintCommit(invalid).length, 0);
  }
});
test('requires a breaking change explanation only when marked breaking', () => {
  const breaking = message.replace('ci(foundation):', 'ci(foundation)!:');
  assert.notEqual(lintCommit(breaking).length, 0);
  assert.deepEqual(lintCommit(breaking + '\nBREAKING CHANGE: Existing callers must migrate.\n'), []);
});
test('rejects absent, empty or comment-only PR sections and missing references/results', () => {
  for (const invalid of ['', body.replace('## Decisions', '## Other'), body.replace('Use real sessions with a disposable PostgreSQL fixture.', '<!-- explain -->'), body.replace('RUSH-007', 'RUSH-###'), body.replace('R-01–R-38', 'R-##'), body.replace('(passed)', '(not run)')]) {
    assert.notEqual(lintPullRequest(message.split('\n')[0], invalid).length, 0);
  }
  assert.notEqual(lintPullRequest('Update things', body).length, 0);
});
test('CLI checks only the selected commit range and safely parses PR event text', () => {
  const directory = mkdtempSync(join(tmpdir(), 'rush-conventions-'));
  const script = resolve('scripts/lint-conventions.mjs');
  const git = (...args) => execFileSync('git', args, { cwd: directory, encoding: 'utf8', stdio: 'pipe' }).trim();
  const run = (...args) => execFileSync(process.execPath, [script, ...args], { cwd: directory, encoding: 'utf8', stdio: 'pipe' });
  try {
    git('init'); git('config', 'user.name', 'RUSH Test'); git('config', 'user.email', 'test@example.test');
    git('-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'legacy base');
    const base = git('rev-parse', 'HEAD');
    git('-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', message);
    const head = git('rev-parse', 'HEAD');
    assert.match(run('--range', base, head), /passed/);
    const event = join(directory, 'event.json');
    writeFileSync(event, JSON.stringify({ pull_request: { title: message.split('\n')[0], body: body + '\nLiteral $(not-a-command) `not-a-command`', base: { sha: base }, head: { sha: head } } }));
    assert.match(run('--event', event), /passed/);
    git('-c', 'commit.gpgsign=false', 'commit', '--allow-empty', '-m', 'invalid change');
    assert.throws(() => run('--range', base, 'HEAD'));
    assert.throws(() => run('--range', '--help', 'HEAD'));
  } finally {
    // Only the absolute temporary directory just created by this test is removed.
    rmSync(directory, { recursive: true, force: true });
  }
});
