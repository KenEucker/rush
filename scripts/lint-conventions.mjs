import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const subject = /^(feat|fix|refactor|test|docs|chore|ci|perf)\([a-z][a-z0-9-]*\)(!)?: [A-Za-z].+$/;
const task = /\bRUSH-0(?:0[1-9]|[1-4][0-9])\b/;
const requirement = /\bR-(?:0[1-9]|[12][0-9]|3[0-8])\b/;
const result = /\b(pass(?:ed)?|fail(?:ed)?|skip(?:ped)?)\b/i;
const sections = ['Task and requirements', 'Behavior', 'Decisions', 'Database and sync', 'Tests', 'Screenshots', 'Limitations and migration'];

export function lintCommit(message) {
  const [title, ...lines] = message.trim().split(/\r?\n/);
  const errors = [];
  if (!subject.test(title)) errors.push('Use <type>(<scope>): <imperative summary> with a supported type.');
  if (lines[0] !== '') errors.push('Separate the summary and body with a blank line.');
  if (!lines.some((line) => /^Refs: /.test(line) && task.test(line))) errors.push('Include Refs: RUSH-001 through RUSH-049.');
  if (!lines.some((line) => /^Requirements: /.test(line) && requirement.test(line))) errors.push('Include applicable Requirements: R-01 through R-38 (ranges may describe cross-cutting support).');
  if (!lines.some((line) => /^Tests: \S/.test(line) && result.test(line))) errors.push('Include Tests: with commands and actual pass/fail/skip results.');
  if (!lines.some((line) => line.trim() && !/^(Refs|Requirements|Tests|BREAKING CHANGE):/.test(line))) errors.push('Explain why the change is necessary and its key decisions.');
  if (subject.exec(title)?.[2] && !lines.some((line) => /^BREAKING CHANGE: \S/.test(line))) errors.push('A breaking change requires a BREAKING CHANGE: footer.');
  return errors;
}

export function lintPullRequest(title, body = '') {
  const errors = [];
  if (!subject.test(title)) errors.push('PR title must use Conventional Commit format for the squash commit.');
  if (!task.test(body)) errors.push('PR body must reference its RUSH task.');
  if (!requirement.test(body)) errors.push('PR body must reference applicable requirements.');
  const content = new Map();
  for (const match of body.matchAll(/^## ([^\r\n]+)\r?\n([\s\S]*?)(?=^## |$(?![\s\S]))/gm)) {
    content.set(match[1].trim(), match[2].replace(/<!--[\s\S]*?-->/g, '').trim());
  }
  for (const heading of sections) {
    if (!content.get(heading)) errors.push(`Fill the PR section: ${heading}.`);
  }
  if (!result.test(content.get('Tests') ?? '')) errors.push('Report actual pass/fail/skip test results.');
  return errors;
}

function git(...args) {
  return execFileSync('git', args, { encoding: 'utf8' }).trim();
}

function lintRange(base, head) {
  // Validate refs before using them in revision expressions; never execute PR text as shell code.
  const baseSha = git('rev-parse', '--verify', '--end-of-options', `${base}^{commit}`);
  const headSha = git('rev-parse', '--verify', '--end-of-options', `${head}^{commit}`);
  return git('rev-list', '--no-merges', `${baseSha}..${headSha}`).split('\n').filter(Boolean)
    .flatMap((sha) => lintCommit(git('show', '-s', '--format=%B', sha)).map((error) => `${sha.slice(0, 8)}: ${error}`));
}

export function main(args) {
  let errors;
  if (args[0] === '--commit-file' && args.length === 2) {
    errors = lintCommit(readFileSync(args[1], 'utf8'));
  } else if (args[0] === '--range' && args.length === 3) {
    errors = lintRange(args[1], args[2]);
  } else if (args[0] === '--event' && args.length === 2) {
    const event = JSON.parse(readFileSync(args[1], 'utf8'));
    if (!event.pull_request) throw new Error('Expected a pull_request event.');
    const pr = event.pull_request;
    errors = [...lintPullRequest(pr.title, pr.body ?? ''), ...lintRange(pr.base.sha, pr.head.sha)];
  } else {
    throw new Error('Usage: node scripts/lint-conventions.mjs --commit-file FILE | --range BASE HEAD | --event FILE');
  }
  if (errors.length) throw new Error(errors.join('\n'));
  console.log('Git conventions passed.');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try { main(process.argv.slice(2)); }
  catch (error) { console.error(error.message); process.exitCode = 1; }
}
