'use strict';

/**
 * Unit tests for the editor-state engine that admin.js consumes.
 *
 * These exercise the exact primitives shipped to the browser (required
 * directly from assets/editor-state.js), covering the reviewer-flagged
 * behaviors: media-selection dirty/history, undo-to-saved cleanliness,
 * save/edit race, scaled drag/resize math, zero-movement no-ops, keyboard
 * grouping of moves in history, and focus-trap wrapping.
 */

const test = require('node:test');
const assert = require('node:assert');
const ES = require('../../assets/editor-state.js');

function baseTemplate() {
	return {
		background: { id: 0, url: '' },
		layers: [
			{ id: 'a', type: 'text', content: 'Hello', x: 100, y: 100, width: 400, height: 120 },
			{ id: 'img', type: 'image', content: '', x: 200, y: 200, width: 160, height: 160, image_aspect_ratio: 1 }
		]
	};
}

// Small model of the admin.js editor loop, built ONLY from the real ES
// primitives, so history/dirty semantics are validated end-to-end.
function makeEditor(template) {
	const state = {
		template: ES.clone(template),
		savedSnapshot: ES.clone(template),
		history: { undo: [], redo: [] }
	};
	return {
		state,
		snapshot() { return ES.clone(state.template); },
		isDirty() { return ES.isDirty(state.template, state.savedSnapshot); },
		commit(before) {
			if (!ES.equals(state.template, before)) {
				ES.pushHistory(state.history, before, ES.HISTORY_LIMIT);
			}
		},
		undo() {
			const restored = ES.transferHistory(state.history.undo, state.history.redo, state.template);
			if (restored) { state.template = restored; }
		},
		redo() {
			const restored = ES.transferHistory(state.history.redo, state.history.undo, state.template);
			if (restored) { state.template = restored; }
		},
		layer(id) { return state.template.layers.find((l) => l.id === id); }
	};
}

test('deep equals/clone are value-based and independent', () => {
	const a = baseTemplate();
	const b = ES.clone(a);
	assert.ok(ES.equals(a, b));
	b.layers[0].x = 999;
	assert.ok(!ES.equals(a, b), 'mutating the clone does not affect the original');
});

test('media selection records one history entry and marks dirty', () => {
	const ed = makeEditor(baseTemplate());
	assert.strictEqual(ed.isDirty(), false);

	const before = ed.snapshot();
	const changed = ES.applyMediaSelection(ed.layer('img'), { url: 'https://x/logo.png', width: 200, height: 100 });
	assert.strictEqual(changed, true);
	ed.commit(before);

	assert.strictEqual(ed.isDirty(), true, 'media selection dirties the template');
	assert.strictEqual(ed.state.history.undo.length, 1, 'exactly one history entry');
	assert.strictEqual(ed.layer('img').content, 'https://x/logo.png');
	assert.strictEqual(ed.layer('img').image_aspect_ratio, 2);
});

test('reselecting the identical image is a no-op (no history, no dirty)', () => {
	const ed = makeEditor(baseTemplate());
	ed.layer('img').content = 'https://x/logo.png';
	ed.state.savedSnapshot = ed.snapshot();

	const before = ed.snapshot();
	const changed = ES.applyMediaSelection(ed.layer('img'), { url: 'https://x/logo.png' });
	assert.strictEqual(changed, false);
	ed.commit(before);
	assert.strictEqual(ed.state.history.undo.length, 0);
	assert.strictEqual(ed.isDirty(), false);
});

test('undoing back to the saved state becomes clean; redo away becomes dirty', () => {
	const ed = makeEditor(baseTemplate());
	const before = ed.snapshot();
	ed.layer('a').content = 'Changed';
	ed.commit(before);
	assert.strictEqual(ed.isDirty(), true);

	ed.undo();
	assert.strictEqual(ed.isDirty(), false, 'undo to saved state is clean');
	assert.strictEqual(ed.layer('a').content, 'Hello');

	ed.redo();
	assert.strictEqual(ed.isDirty(), true, 'redo away from saved state is dirty');
	assert.strictEqual(ed.layer('a').content, 'Changed');
});

test('save/edit race: edits during save are preserved and stay dirty', () => {
	const ed = makeEditor(baseTemplate());

	// User edits, then clicks Save (snapshot captured).
	let before = ed.snapshot();
	ed.layer('a').content = 'Save me';
	ed.commit(before);
	const submitted = ES.clone(ed.state.template);

	// While the request is in flight, the user makes another edit.
	before = ed.snapshot();
	ed.layer('a').content = 'Newer edit after click';
	ed.commit(before);

	// Response arrives.
	const resolved = ES.resolveSave(submitted, ed.state.template);
	ed.state.savedSnapshot = resolved.saved;

	assert.strictEqual(resolved.clean, false, 'not clean because state moved past the submitted snapshot');
	assert.strictEqual(ed.isDirty(), true, 'stays dirty so the newer edit is not lost');
	assert.strictEqual(ed.layer('a').content, 'Newer edit after click', 'live edit is never overwritten by the response');
});

test('save with no concurrent edits marks the editor clean', () => {
	const ed = makeEditor(baseTemplate());
	const before = ed.snapshot();
	ed.layer('a').content = 'Final';
	ed.commit(before);

	const submitted = ES.clone(ed.state.template);
	const resolved = ES.resolveSave(submitted, ed.state.template);
	ed.state.savedSnapshot = resolved.saved;

	assert.strictEqual(resolved.clean, true);
	assert.strictEqual(ed.isDirty(), false);
});

test('scaled move math divides pointer delta by canvas scale and clamps', () => {
	const interaction = { x: 100, y: 100, width: 400, height: 120, startX: 500, startY: 500 };
	// 50 screen px at 0.5 scale == 100 artboard px.
	const moved = ES.computeMove(interaction, 550, 560, 0.5);
	assert.strictEqual(moved.x, 200);
	assert.strictEqual(moved.y, 220);

	// Clamps to the artboard: cannot exceed 1200 - width / 630 - height.
	const far = ES.computeMove(interaction, 100000, 100000, 0.5);
	assert.strictEqual(far.x, 1200 - 400);
	assert.strictEqual(far.y, 630 - 120);
});

test('scaled resize math honors edge, scale, and minimums', () => {
	const right = ES.computeResize({ x: 100, y: 100, width: 400, height: 120, startX: 0, startY: 0, edge: 'right' }, 100, 0, 0.5, 20, 20);
	assert.strictEqual(right.width, 400 + 200, '100 screen px at 0.5 scale grows width by 200');

	const left = ES.computeResize({ x: 100, y: 100, width: 400, height: 120, startX: 0, startY: 0, edge: 'left' }, 100, 0, 0.5, 20, 20);
	// left edge moves right by 200 artboard px; right edge stays at 500.
	assert.strictEqual(left.x, 300);
	assert.strictEqual(left.width, 200);
});

test('zero-movement interaction reports no geometry change (no dirty)', () => {
	const interaction = { x: 100, y: 100, width: 400, height: 120, startX: 500, startY: 500 };
	const moved = ES.computeMove(interaction, 500, 500, 1);
	const layer = { x: moved.x, y: moved.y, width: interaction.width, height: interaction.height };
	assert.strictEqual(ES.geometryChanged(interaction, layer), false);
});

test('keyboard nudges group into a single history entry per key burst', () => {
	const ed = makeEditor(baseTemplate());

	// A key burst records history once (on the first, non-repeat keydown),
	// then applies every repeat to the same layer.
	const before = ed.snapshot();
	let recorded = false;
	const events = [
		{ repeat: false },
		{ repeat: true },
		{ repeat: true }
	];
	events.forEach((ev) => {
		if (!ev.repeat && !recorded) {
			ES.pushHistory(ed.state.history, before, ES.HISTORY_LIMIT);
			recorded = true;
		}
		ed.layer('a').x += 1;
	});

	assert.strictEqual(ed.state.history.undo.length, 1, 'one grouped entry for the whole burst');
	assert.strictEqual(ed.layer('a').x, 103);

	ed.undo();
	assert.strictEqual(ed.layer('a').x, 100, 'undo reverts the entire grouped burst at once');
});

test('focus-trap wraps Tab/Shift+Tab at the modal edges only', () => {
	// Forward Tab from last wraps to first; elsewhere no override.
	assert.strictEqual(ES.focusTrapTarget(3, 2, false), 0);
	assert.strictEqual(ES.focusTrapTarget(3, 1, false), -1);
	// Shift+Tab from first wraps to last; elsewhere no override.
	assert.strictEqual(ES.focusTrapTarget(3, 0, true), 2);
	assert.strictEqual(ES.focusTrapTarget(3, 1, true), -1);
	// No focusables: leave default behavior.
	assert.strictEqual(ES.focusTrapTarget(0, -1, false), -1);
});
