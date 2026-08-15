import { cpSync, mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const staging = mkdtempSync(join(tmpdir(), 'govp-presta-'));
const target = join(staging, 'govpexchange');
const excluded = new Set(['.git', '.github', 'vendor', 'tests', 'scripts', 'dist']);
cpSync(root, target, { recursive: true, filter: (source) => !source.split('/').some((part) => excluded.has(part)) && !source.endsWith('package.json') && !source.endsWith('package-lock.json') });
mkdirSync(resolve(root, 'dist'), { recursive: true });
const output = resolve(root, 'dist/govp-for-prestashop-0.1.0.zip');
rmSync(output, { force: true });
const result = spawnSync('zip', ['-q', '-r', output, 'govpexchange'], { cwd: staging, encoding: 'utf8' });
rmSync(staging, { recursive: true, force: true });
if (result.status !== 0) throw new Error(result.stderr || 'Unable to create module ZIP.');
console.log(output);

