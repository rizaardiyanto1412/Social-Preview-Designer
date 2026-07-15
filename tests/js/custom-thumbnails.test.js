'use strict';

/**
 * Tests for the My Templates lazy-thumbnail workstream:
 *
 *  - the pure thumbnail store (cache, bounded concurrency, race/stale guards,
 *    update/duplicate/delete/rename invalidation) that admin.js wires to the
 *    single-template fetch endpoint, and
 *  - source-level regressions that lock in the compact non-stretching grid, the
 *    reserved aspect-ratio box, the XSS-safe / authoritative renderer reuse, and
 *    the accessible loading/error/retry states.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const ES = require('../../assets/editor-state.js');

function read(rel) {
	return fs.readFileSync(path.join(__dirname, rel), 'utf8');
}

const ADMIN_JS = read('../../assets/admin.js');
const ADMIN_CSS = read('../../assets/admin.css');

// A tiny simulator of the admin.js pump loop, over the pure store, so we can
// assert the end-to-end fetch/cache/concurrency behaviour deterministically.
function makeRunner(store, desiredKeys) {
	const inflight = {}; // key -> resolve fn
	const queue = [];
	const idOf = (k) => k; // in tests the key doubles as the fetch id
	function pump() {
		while (queue.length && ES.thumbCanStart(store)) {
			const key = queue.shift();
			if (!ES.thumbShouldFetch(store, key)) { continue; }
			ES.thumbBeginFetch(store, key);
			inflight[key] = idOf(key);
		}
	}
	return {
		enqueue(key) {
			if (ES.thumbGetCached(store, key)) { return; }
			if (!ES.thumbShouldFetch(store, key)) { return; }
			if (queue.indexOf(key) === -1) { queue.push(key); }
			pump();
		},
		inflightCount() { return Object.keys(inflight).length; },
		resolve(key, template) {
			delete inflight[key];
			const painted = ES.thumbResolveSuccess(store, key, template, desiredKeys.has(key));
			pump();
			return painted;
		},
		fail(key) {
			delete inflight[key];
			const shown = ES.thumbResolveError(store, key, desiredKeys.has(key));
			pump();
			return shown;
		}
	};
}

test('thumbVersionKey encodes id + version so a body change is a cache miss', () => {
	assert.strictEqual(ES.thumbVersionKey('custom-1', '2026-07-15 10:00:00'), 'custom-1@2026-07-15 10:00:00');
	assert.notStrictEqual(
		ES.thumbVersionKey('custom-1', 'v1'),
		ES.thumbVersionKey('custom-1', 'v2'),
		'a new updated_at yields a different key'
	);
	assert.strictEqual(ES.thumbVersionKey(null, null), '@');
});

test('store defaults to a bounded concurrency of 3 and is overridable', () => {
	assert.strictEqual(ES.createThumbStore().concurrency, 3);
	assert.strictEqual(ES.createThumbStore({ concurrency: 2 }).concurrency, 2);
	// A non-positive/invalid concurrency falls back to the safe default.
	assert.strictEqual(ES.createThumbStore({ concurrency: 0 }).concurrency, 3);
	assert.strictEqual(ES.createThumbStore({ concurrency: -5 }).concurrency, 3);
});

test('concurrency is bounded: never more than N fetches in flight at once', () => {
	const store = ES.createThumbStore({ concurrency: 2 });
	const keys = ['a', 'b', 'c', 'd', 'e'];
	const desired = new Set(keys);
	const run = makeRunner(store, desired);
	keys.forEach(run.enqueue);
	assert.strictEqual(run.inflightCount(), 2, 'only 2 start immediately');
	assert.strictEqual(store.active, 2);
	run.resolve('a', { layers: [] });
	assert.strictEqual(run.inflightCount(), 2, 'one finishing pulls exactly one more');
	run.resolve('b', { layers: [] });
	run.resolve('c', { layers: [] });
	run.resolve('d', { layers: [] });
	run.resolve('e', { layers: [] });
	assert.strictEqual(run.inflightCount(), 0);
	assert.strictEqual(store.active, 0, 'the concurrency budget is fully released');
});

test('in-memory cache: a cached key is never re-fetched', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const desired = new Set(['a']);
	const run = makeRunner(store, desired);
	run.enqueue('a');
	run.resolve('a', { layers: [{ id: 'x' }] });
	assert.ok(ES.thumbGetCached(store, 'a'), 'body cached after success');
	assert.strictEqual(ES.thumbShouldFetch(store, 'a'), false, 'cached key is not fetchable');
	run.enqueue('a'); // second visit
	assert.strictEqual(run.inflightCount(), 0, 'no network on a cache hit');
});

test('stale/deleted response is dropped and never paints a wrong card', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	// The card is on screen when the fetch starts, but is deleted before it lands.
	const desired = new Set(['gone']);
	const run = makeRunner(store, desired);
	run.enqueue('gone');
	assert.strictEqual(store.active, 1);
	desired.delete('gone'); // card removed from the DOM meanwhile
	const painted = run.resolve('gone', { layers: [] });
	assert.strictEqual(painted, false, 'a no-longer-desired response must not paint');
	assert.strictEqual(ES.thumbGetCached(store, 'gone'), null, 'and must not be cached');
	assert.strictEqual(store.active, 0, 'but its concurrency slot is still released');
});

test('update invalidation: a new version key misses cache and refetches a fresh body', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const v1 = ES.thumbVersionKey('custom-1', 'v1');
	const v2 = ES.thumbVersionKey('custom-1', 'v2');
	let desired = new Set([v1]);
	let run = makeRunner(store, desired);
	run.enqueue(v1);
	run.resolve(v1, { layers: [{ content: 'old' }] });
	assert.ok(ES.thumbGetCached(store, v1));

	// Save/update bumps updated_at -> the card now has v2. Re-render prunes v1.
	ES.thumbPrune(store, [v2]);
	assert.strictEqual(ES.thumbGetCached(store, v1), null, 'the stale version is evicted');
	desired = new Set([v2]);
	run = makeRunner(store, desired);
	assert.strictEqual(ES.thumbShouldFetch(store, v2), true, 'the new version must fetch');
	run.enqueue(v2);
	assert.strictEqual(store.active, 1, 'a fresh body is fetched for the updated design');
	run.resolve(v2, { layers: [{ content: 'new' }] });
	assert.deepStrictEqual(ES.thumbGetCached(store, v2), { layers: [{ content: 'new' }] });
});

test('duplicate invalidation: the new id fetches its own body; the source stays cached', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const src = ES.thumbVersionKey('custom-1', 'v1');
	const dup = ES.thumbVersionKey('custom-2', 'v1');
	const desired = new Set([src, dup]);
	const run = makeRunner(store, desired);
	run.enqueue(src);
	run.resolve(src, { layers: [{ content: 'A' }] });
	run.enqueue(dup);
	assert.strictEqual(store.active, 1, 'the duplicate is a cache miss and fetches');
	run.resolve(dup, { layers: [{ content: 'A' }] });
	assert.ok(ES.thumbGetCached(store, src), 'source preview untouched by the duplicate');
	assert.ok(ES.thumbGetCached(store, dup), 'duplicate gets its own preview');
});

test('delete invalidation: prune drops the removed record so nothing leaks', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const a = ES.thumbVersionKey('custom-1', 'v1');
	const b = ES.thumbVersionKey('custom-2', 'v1');
	const desired = new Set([a, b]);
	const run = makeRunner(store, desired);
	run.enqueue(a); run.enqueue(b);
	run.resolve(a, { layers: [] });
	run.resolve(b, { layers: [] });
	// Delete custom-2: re-render keeps only 'a'.
	ES.thumbPrune(store, [a]);
	assert.ok(ES.thumbGetCached(store, a));
	assert.strictEqual(ES.thumbGetCached(store, b), null, 'deleted record body is gone');
	assert.ok(!Object.prototype.hasOwnProperty.call(store.status, b), 'status cleared too');
});

test('rename does not alter the preview: same version key => cache hit, no refetch', () => {
	// A rename that does not touch the body keeps the same version key, so the
	// existing cached preview is reused verbatim with zero network.
	const store = ES.createThumbStore({ concurrency: 3 });
	const key = ES.thumbVersionKey('custom-1', 'v1');
	const desired = new Set([key]);
	const run = makeRunner(store, desired);
	run.enqueue(key);
	const body = { layers: [{ content: 'Design' }] };
	run.resolve(key, body);
	// Re-render after rename keeps the same key.
	ES.thumbPrune(store, [key]);
	assert.deepStrictEqual(ES.thumbGetCached(store, key), body, 'preview body is unchanged');
	assert.strictEqual(ES.thumbShouldFetch(store, key), false, 'no refetch on rename');
	run.enqueue(key);
	assert.strictEqual(run.inflightCount(), 0);
});

test('error state parks the key and only Retry re-enables fetching', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const key = 'a';
	const desired = new Set([key]);
	const run = makeRunner(store, desired);
	run.enqueue(key);
	assert.strictEqual(run.fail(key), true, 'a desired failure surfaces the error state');
	assert.strictEqual(store.status[key], 'error');
	assert.strictEqual(ES.thumbShouldFetch(store, key), false, 'errored card does not auto-hammer the server');
	run.enqueue(key);
	assert.strictEqual(run.inflightCount(), 0, 'still no fetch until an explicit retry');
	ES.thumbRetry(store, key);
	assert.strictEqual(ES.thumbShouldFetch(store, key), true, 'Retry re-enables the fetch');
	run.enqueue(key);
	assert.strictEqual(store.active, 1);
});

test('a failure for a card that vanished meanwhile is dropped (no error paint)', () => {
	const store = ES.createThumbStore({ concurrency: 3 });
	const desired = new Set(['gone']);
	const run = makeRunner(store, desired);
	run.enqueue('gone');
	desired.delete('gone');
	assert.strictEqual(run.fail('gone'), false, 'no error UI for a removed card');
	assert.ok(!Object.prototype.hasOwnProperty.call(store.status, 'gone'), 'status not parked in error');
	assert.strictEqual(store.active, 0);
});

// -------------------------------------------------------------------------
// Source-level regressions
// -------------------------------------------------------------------------

test('CSS: My Templates uses auto-fill + a capped max track, never a 1fr stretch', () => {
	const block = ADMIN_CSS.match(/\.wp-remote-og-custom-gallery\s*\{[^}]*\}/);
	assert.ok(block, 'custom gallery grid rule exists');
	const rule = block[0];
	assert.ok(/display:\s*grid/.test(rule), 'the gallery is a grid');
	// auto-fill (not auto-fit) keeps empty tracks so a single card cannot stretch.
	assert.ok(/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(\s*\d+px\s*,\s*\d+px\s*\)\)/.test(rule),
		'columns use auto-fill with an intentional max card width (px..px), not 1fr');
	assert.ok(!/1fr/.test(rule), 'a single card must not stretch via 1fr');
	assert.ok(/justify-content:\s*start/.test(rule), 'tracks are left-aligned');
	// Narrow/mobile collapses to one column.
	assert.ok(/max-width:\s*600px[\s\S]*?\.wp-remote-og-custom-gallery\s*\{\s*grid-template-columns:\s*1fr/.test(ADMIN_CSS),
		'narrow viewports collapse to a single full-width column');
});

test('CSS: sizing is consistent with the Built-in Templates grid (shared card min track)', () => {
	// Built-in uses minmax(280px, 1fr); custom uses minmax(260px, 300px). Both are
	// ~280px cards so the two galleries visually match.
	assert.ok(/\.wp-remote-og-gallery\s*\{[^}]*minmax\(280px, 1fr\)/.test(ADMIN_CSS));
	const custom = ADMIN_CSS.match(/\.wp-remote-og-custom-gallery\s*\{[^}]*\}/)[0];
	const max = parseInt(custom.match(/minmax\(\s*\d+px\s*,\s*(\d+)px\s*\)/)[1], 10);
	assert.ok(max >= 280 && max <= 320, 'the capped card width matches built-in card dimensions');
});

test('CSS: thumbnail reserves a 1200:630 aspect-ratio box (no layout shift)', () => {
	assert.ok(/\.wp-remote-og-custom-thumb-frame\s*\{[^}]*aspect-ratio:\s*1200 \/ 630/.test(ADMIN_CSS),
		'the thumb frame reserves the OG aspect ratio before its body loads');
	assert.ok(/\.wp-remote-og-custom-thumb-frame\s*\{[^}]*overflow:\s*hidden/.test(ADMIN_CSS),
		'previews are clipped inside the card (no bleed/clipping regressions)');
});

test('CSS: loading/error states exist and honour prefers-reduced-motion', () => {
	assert.ok(/\.wp-remote-og-custom-thumb-frame\.is-loading/.test(ADMIN_CSS), 'loading skeleton state');
	assert.ok(/\.wp-remote-og-custom-thumb-frame\.is-error/.test(ADMIN_CSS), 'error state');
	assert.ok(/@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.wp-remote-og-thumb-spinner\s*\{\s*animation:\s*none/.test(ADMIN_CSS),
		'spinner animation is disabled under reduced-motion');
});

test('JS: the initial "M" placeholder is gone; thumbnails render via the authoritative renderer', () => {
	// The old placeholder initial must not be reintroduced.
	assert.ok(!/wp-remote-og-custom-thumb-initial/.test(ADMIN_JS), 'the "M" initial placeholder is removed');
	// The gallery thumbnail is painted by buildPresetPreview — the very same
	// function the big Preview modal uses — guaranteeing rendering parity.
	assert.ok(/function paintFrameBody\([^)]*\)\s*\{[\s\S]*?buildPresetPreview\(/.test(ADMIN_JS),
		'thumbnails render through buildPresetPreview');
	assert.ok(/\.wp-remote-og-preset-modal-preview[\s\S]*?buildPresetPreview\(/.test(ADMIN_JS),
		'the big Preview modal renders through the same buildPresetPreview');
});

test('JS: thumbnails go through the metadata-only lazy pipeline (bounded, cached, guarded)', () => {
	// Uses the single-template endpoint on demand (no bodies in list payloads).
	assert.ok(/action:\s*'wp_remote_og_get_custom_template'/.test(ADMIN_JS), 'lazy single-template fetch');
	assert.ok(/ES\.createThumbStore\(\{\s*concurrency:\s*\d+\s*\}\)/.test(ADMIN_JS), 'bounded-concurrency store');
	assert.ok(/ES\.thumbResolveSuccess\([^)]*thumbKeyIsDesired\(/.test(ADMIN_JS), 'race guard: paint only still-desired cards');
	assert.ok(/new IntersectionObserver\(/.test(ADMIN_JS), 'only visible cards fetch');
	assert.ok(/ES\.thumbPrune\(/.test(ADMIN_JS), 'stale/deleted bodies are pruned on re-render');
});

test('JS: text is rendered as text (XSS-safe) — buildPresetPreview never injects HTML', () => {
	const fn = ADMIN_JS.match(/function buildPresetPreview\([\s\S]*?\n\t\}/);
	assert.ok(fn, 'buildPresetPreview located');
	const body = fn[0];
	assert.ok(/\.text\(samplePreviewText\(/.test(body), 'layer content goes through jQuery .text() (escaped)');
	assert.ok(!/\.html\(/.test(body), 'no raw .html() sink for user content');
});

test('JS: accessible thumbnail — role=img, alt/aria-label, aria-busy, and a Retry control', () => {
	assert.ok(/role:\s*'img'/.test(ADMIN_JS), 'thumb frame is an image role');
	assert.ok(/thumbAltFor\(rec\.name\)/.test(ADMIN_JS), 'thumb carries a descriptive aria-label');
	assert.ok(/'aria-busy':\s*'true'/.test(ADMIN_JS), 'loading state is announced via aria-busy');
	assert.ok(/wpog-thumb-retry/.test(ADMIN_JS), 'error state exposes a Retry button');
});
