'use strict';

/**
 * Packaging exclusion regression tests.
 *
 * WordPress Plugin Check flags hidden/dev files (.gitignore, .distignore) when
 * scanning a raw git checkout. The distributable archive built via
 * `wp dist-archive` (or tests/build-dist.sh) must exclude those plus dev-only
 * directories (tests, docs, reference-screens) and dev config (composer.json,
 * README.md). This test builds a clean artifact and asserts the exclusions.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const REPO = path.join(__dirname, '..', '..');
const BUILD = path.join(REPO, 'tests', 'build-dist.sh');

const MUST_BE_EXCLUDED = [
	'.gitignore',
	'.distignore',
	'.git',
	'docs',
	'tests',
	'reference-screens',
	'composer.json',
	'composer.lock',
	'README.md',
	'node_modules',
	'vendor',
];

const MUST_BE_PRESENT = [
	'wp-remote-og-plugins.php',
	'uninstall.php',
	'readme.txt',
	'assets',
];

test('dist artifact excludes dev files and includes shippable files', (t) => {
	let outDir;
	try {
		execFileSync('sh', ['-c', 'command -v rsync'], { stdio: 'ignore' });
	} catch (e) {
		t.skip('rsync not available');
		return;
	}
	outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'spd-dist-'));
	try {
		execFileSync('sh', [BUILD, outDir], { stdio: 'pipe' });
		const dest = path.join(outDir, 'social-preview-designer');
		assert.ok(fs.existsSync(dest), 'dist folder must be created');

		for (const name of MUST_BE_EXCLUDED) {
			assert.ok(
				!fs.existsSync(path.join(dest, name)),
				`dev artifact '${name}' must be excluded from the distributable`
			);
		}
		for (const name of MUST_BE_PRESENT) {
			assert.ok(
				fs.existsSync(path.join(dest, name)),
				`shippable file '${name}' must be present in the distributable`
			);
		}
	} finally {
		fs.rmSync(outDir, { recursive: true, force: true });
	}
});
