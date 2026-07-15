'use strict';

/**
 * Source-assertion tests guaranteeing EVERY registered plugin admin page uses
 * the one shared full-width app shell. This is structured so that registering a
 * new page whose renderer skips the shared shell (page_open / the editor
 * wrapper carrying `wpog-fullwidth`) fails the suite.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const PHP = fs.readFileSync(path.join(__dirname, '../../wp-remote-og-plugins.php'), 'utf8');
const CSS = fs.readFileSync(path.join(__dirname, '../../assets/admin.css'), 'utf8');

// The authoritative page list: every render_*_page referenced as an
// add_menu_page / add_submenu_page callback.
function registeredRenderers() {
	const names = new Set();
	const re = /array\(\s*__CLASS__,\s*'(render_[a-z_]+_page)'\s*\)/g;
	let m;
	while ((m = re.exec(PHP)) !== null) {
		names.add(m[1]);
	}
	return Array.from(names);
}

// Extract a method body by name (from its `function name(` to the start of the
// next `function ` declaration).
function methodBody(name) {
	const start = PHP.indexOf('function ' + name + '(');
	assert.notStrictEqual(start, -1, 'method should exist: ' + name);
	const nextFn = PHP.indexOf('\n\tpublic static function ', start + 1);
	const nextPriv = PHP.indexOf('\n\tprivate static function ', start + 1);
	const candidates = [nextFn, nextPriv].filter((i) => i !== -1);
	const end = candidates.length ? Math.min.apply(null, candidates) : PHP.length;
	return PHP.slice(start, end);
}

test('at least the seven known plugin pages are registered', () => {
	const renderers = registeredRenderers();
	['render_dashboard_page', 'render_template_page', 'render_templates_page',
		'render_fields_page', 'render_fonts_page', 'render_tools_page',
		'render_diagnostics_page'].forEach((name) => {
		assert.ok(renderers.includes(name), 'expected registered renderer: ' + name);
	});
});

test('every registered page renderer goes through the shared full-width shell', () => {
	registeredRenderers().forEach((name) => {
		const body = methodBody(name);
		const usesSharedOpen = body.includes('self::page_open(');
		const usesFullwidthWrapper = body.includes('wpog-fullwidth');
		assert.ok(
			usesSharedOpen || usesFullwidthWrapper,
			name + ' must render via self::page_open() or the shared wpog-fullwidth wrapper'
		);
	});
});

test('the shared page_open helper emits the full-width shell class', () => {
	const body = methodBody('page_open');
	assert.ok(body.includes('wp-remote-og-app'), 'page_open should render the app shell');
	assert.ok(body.includes('wpog-fullwidth'), 'page_open should carry the full-width class');
});

test('the editor page carries the shared full-width class too', () => {
	assert.ok(
		/wp-remote-og-editor-page[^"]*wpog-fullwidth/.test(PHP),
		'editor wrapper should include wpog-fullwidth'
	);
});

test('no restrictive max-width caps the shared app wrapper', () => {
	// The shared wrapper is explicitly uncapped.
	assert.ok(
		/\.wpog-fullwidth\s*\{[^}]*max-width:\s*none/.test(CSS),
		'.wpog-fullwidth should declare max-width: none'
	);
	// The base app class must not reintroduce a fixed px cap.
	const appBlock = CSS.match(/\.wp-remote-og-app\s*\{[^}]*\}/);
	assert.ok(appBlock, '.wp-remote-og-app rule should exist');
	assert.ok(
		!/max-width:\s*\d/.test(appBlock[0]),
		'.wp-remote-og-app must not set a fixed max-width'
	);
});
