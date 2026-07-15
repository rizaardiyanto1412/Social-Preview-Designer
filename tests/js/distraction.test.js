'use strict';

/**
 * Tests for the distraction-free (fullscreen) controller state machine. The DOM
 * side effects live in admin.js; these lock the pure decisions: enter/exit
 * round-trip, snapshot restore, escape gating while a modal is open, enter/exit
 * idempotency, and the aria-pressed / label bookkeeping.
 */

const test = require('node:test');
const assert = require('node:assert');
const ES = require('../../assets/editor-state.js');

test('enter then exit restores the captured snapshot', () => {
	const ctrl = ES.createDistractionController();
	assert.strictEqual(ctrl.active, false);

	const entered = ES.distractionEnter(ctrl, { scrollX: 0, scrollY: 240 });
	assert.strictEqual(entered.changed, true);
	assert.strictEqual(ctrl.active, true);

	const exited = ES.distractionExit(ctrl);
	assert.strictEqual(exited.changed, true);
	assert.strictEqual(ctrl.active, false);
	assert.deepStrictEqual(exited.snapshot, { scrollX: 0, scrollY: 240 });
	assert.strictEqual(ctrl.snapshot, null);
});

test('double enter is idempotent and keeps the original snapshot', () => {
	const ctrl = ES.createDistractionController();
	ES.distractionEnter(ctrl, { scrollX: 0, scrollY: 100 });
	const second = ES.distractionEnter(ctrl, { scrollX: 0, scrollY: 999 });
	assert.strictEqual(second.changed, false);
	// The mid-session snapshot must NOT clobber the restore data.
	assert.deepStrictEqual(ctrl.snapshot, { scrollX: 0, scrollY: 100 });
});

test('exit while inactive is a no-op', () => {
	const ctrl = ES.createDistractionController();
	const result = ES.distractionExit(ctrl);
	assert.strictEqual(result.changed, false);
	assert.strictEqual(result.snapshot, null);
});

test('escape exits only when active and no overlay is open', () => {
	const active = { active: true, snapshot: null };
	const inactive = { active: false, snapshot: null };
	assert.strictEqual(ES.distractionShouldExitOnEscape(active, false), true);
	// Blocked by an open modal/popover/media frame.
	assert.strictEqual(ES.distractionShouldExitOnEscape(active, true), false);
	// Not active -> never exits on escape.
	assert.strictEqual(ES.distractionShouldExitOnEscape(inactive, false), false);
});

test('aria-pressed reflects the active state', () => {
	assert.strictEqual(ES.distractionAriaPressed(true), 'true');
	assert.strictEqual(ES.distractionAriaPressed(false), 'false');
});

test('labels switch between enter and exit affordances', () => {
	const off = ES.distractionLabels(false, {
		on: 'Exit fullscreen', off: 'Distraction-free',
		announceOn: 'on', announceOff: 'off'
	});
	assert.strictEqual(off.label, 'Distraction-free');
	assert.strictEqual(off.ariaPressed, 'false');
	assert.strictEqual(off.announce, 'off');

	const on = ES.distractionLabels(true, {
		on: 'Exit fullscreen', off: 'Distraction-free',
		announceOn: 'on', announceOff: 'off'
	});
	assert.strictEqual(on.label, 'Exit fullscreen');
	assert.strictEqual(on.ariaPressed, 'true');
	assert.strictEqual(on.announce, 'on');
});
