'use strict';

/**
 * Regression tests for the canonical i18n text domain.
 *
 * WordPress Plugin Check requires the gettext text domain to match the plugin
 * slug (`social-preview-designer`). The plugin previously shipped the legacy
 * domain `wp-remote-og-plugins` in the header and in every gettext call, which
 * produced 394 WordPress.WP.I18n.TextDomainMismatch errors plus 1 header
 * warning. These tests assert the canonical domain is used everywhere and the
 * legacy domain is fully gone from gettext calls and the plugin header.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const PLUGIN_FILE = path.join(__dirname, '..', '..', 'wp-remote-og-plugins.php');
const CANONICAL = 'social-preview-designer';
const LEGACY = 'wp-remote-og-plugins';

const source = fs.readFileSync(PLUGIN_FILE, 'utf8');

test('plugin header Text Domain equals the plugin slug', () => {
	const match = source.match(/^\s*\*\s*Text Domain:\s*(.+)\s*$/m);
	assert.ok(match, 'Text Domain header line must be present');
	assert.strictEqual(match[1].trim(), CANONICAL);
});

test('no gettext call uses the legacy text domain', () => {
	// Match any WordPress gettext-family call and capture its final string arg.
	const gettext = /(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x|esc_attr_x|__|_e|_x|_ex|_n|_nx|_n_noop|_nx_noop)\s*\([^;]*?'([^']+)'\s*\)/g;
	let m;
	let sawCanonical = false;
	while ((m = gettext.exec(source)) !== null) {
		assert.notStrictEqual(
			m[1],
			LEGACY,
			`legacy text domain '${LEGACY}' found in gettext call: ${m[0].slice(0, 80)}`
		);
		if (m[1] === CANONICAL) {
			sawCanonical = true;
		}
	}
	assert.ok(sawCanonical, 'expected at least one canonical text-domain gettext call');
});

test('legacy text domain string is absent from the plugin file', () => {
	assert.ok(
		!source.includes(LEGACY),
		`legacy string '${LEGACY}' must not appear anywhere in the plugin file`
	);
});
