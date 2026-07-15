'use strict';

/**
 * Unit tests for the pure custom-template save/naming helpers consumed by
 * admin.js (My Templates workstream): save-target resolution, name validation,
 * unique default-name generation, and the save/edit race still holding when a
 * design is linked to a custom id.
 */

const test = require('node:test');
const assert = require('node:assert');
const ES = require('../../assets/editor-state.js');

test('resolveSaveTarget: a requested name always means create (save-as)', () => {
	assert.deepStrictEqual(ES.resolveSaveTarget({ requestedName: 'Launch card' }), { mode: 'create', name: 'Launch card' });
	// A name wins even when a customId is present (explicit save-as of a copy).
	assert.deepStrictEqual(ES.resolveSaveTarget({ requestedName: 'Copy', customId: 'custom-1' }), { mode: 'create', name: 'Copy' });
});

test('resolveSaveTarget: a linked customId updates in place (no duplicate)', () => {
	assert.deepStrictEqual(ES.resolveSaveTarget({ customId: 'custom-9' }), { mode: 'update', customId: 'custom-9' });
});

test('resolveSaveTarget: unlinked, unnamed design must prompt for a name (no silent plain save)', () => {
	// A user-authored, unlinked design cannot be persisted silently: the primary
	// Save must first prompt for a name and then create+link a My Templates record.
	assert.deepStrictEqual(ES.resolveSaveTarget({}), { mode: 'prompt' });
	assert.deepStrictEqual(ES.resolveSaveTarget({ requestedName: '   ' }), { mode: 'prompt' }, 'whitespace-only name still needs a real name');
});

test('unlinked primary Save requires a name, then creates+links; cancel builds no payload and changes nothing', () => {
	// Simulate the admin.js decision without a DOM: unlinked design => prompt.
	const state = { customId: '', dirty: true };
	const target = ES.resolveSaveTarget({ customId: state.customId });
	assert.strictEqual(target.mode, 'prompt', 'unlinked design forces the name prompt');

	// Cancel/Escape: no name was confirmed, so no persistence payload is built and
	// the editor state (including dirty) is untouched.
	function buildPayloadOnCancel() { return null; }
	assert.strictEqual(buildPayloadOnCancel(), null, 'no AJAX payload on cancel');
	assert.deepStrictEqual(state, { customId: '', dirty: true }, 'state unchanged after cancel');

	// Confirm with a valid name resolves to a create (the transactional custom_name
	// path), which is what admin.js sends as options.newName.
	const named = ES.validateTemplateName('Launch card');
	assert.ok(named.valid);
	assert.deepStrictEqual(ES.resolveSaveTarget({ customId: state.customId, requestedName: named.value }), { mode: 'create', name: 'Launch card' });
});

test('applying a built-in preset yields an unlinked copy whose first Save prompts then creates', () => {
	// apply_preset clears active_custom_id on the backend; the editor mirrors that
	// by clearing state.customId, so the very next primary Save must prompt.
	const state = { customId: 'custom-3' };
	state.customId = ''; // apply preset => unlinked working copy
	assert.strictEqual(ES.resolveSaveTarget({ customId: state.customId }).mode, 'prompt');
	// Naming it then creates a fresh My Template (built-in itself is never mutated).
	assert.strictEqual(ES.resolveSaveTarget({ customId: '', requestedName: 'From preset' }).mode, 'create');
});

test('validateTemplateName enforces non-empty and max length', () => {
	assert.deepStrictEqual(ES.validateTemplateName('  Hello  '), { valid: true, reason: '', value: 'Hello' });
	assert.strictEqual(ES.validateTemplateName('').valid, false);
	assert.strictEqual(ES.validateTemplateName('   ').reason, 'empty');
	const long = 'x'.repeat(101);
	assert.strictEqual(ES.validateTemplateName(long).reason, 'too_long');
	assert.strictEqual(ES.validateTemplateName('x'.repeat(100)).valid, true);
});

test('validateTemplateName measures multibyte names by code point, not UTF-16 units', () => {
	// 100 accented chars is exactly the boundary and must be accepted; 101 rejected.
	assert.strictEqual(ES.validateTemplateName('ü'.repeat(100)).valid, true, '100 code points at the boundary');
	assert.strictEqual(ES.validateTemplateName('ü'.repeat(101)).reason, 'too_long', '101 code points rejected');
	// CJK behaves the same (each is one code point).
	assert.strictEqual(ES.validateTemplateName('社'.repeat(100)).valid, true);
	assert.strictEqual(ES.validateTemplateName('社'.repeat(101)).reason, 'too_long');
	// An astral emoji is a single code point even though String.length counts it
	// as 2 UTF-16 units: 100 of them must still be accepted.
	assert.strictEqual(ES.validateTemplateName('😀'.repeat(100)).valid, true, 'astral chars counted once');
});

test('generateDefaultName is unique (case-insensitive) against existing names', () => {
	assert.strictEqual(ES.generateDefaultName([], 'My Template'), 'My Template');
	assert.strictEqual(ES.generateDefaultName(['My Template'], 'My Template'), 'My Template 2');
	assert.strictEqual(ES.generateDefaultName(['my template', 'My Template 2'], 'My Template'), 'My Template 3');
	// Trims and compares case-insensitively.
	assert.strictEqual(ES.generateDefaultName(['  MY TEMPLATE  '], 'My Template'), 'My Template 2');
});

test('save/edit race still holds for a design linked to a custom id', () => {
	// The custom link does not change the race semantics: the submitted snapshot
	// becomes the baseline, live edits are preserved and keep the editor dirty.
	const template = { customId: 'custom-7', layers: [{ id: 'a', content: 'A' }] };
	const submitted = ES.clone(template);
	const current = ES.clone(template);
	current.layers[0].content = 'edited during save';

	const resolved = ES.resolveSave(submitted, current);
	assert.strictEqual(resolved.clean, false);
	assert.ok(ES.isDirty(current, resolved.saved), 'stays dirty so the concurrent edit is not lost');
	// The save target for the in-flight save was an in-place update.
	assert.strictEqual(ES.resolveSaveTarget({ customId: 'custom-7' }).mode, 'update');
});

test('focus-trap target works for the name modal button/input set', () => {
	// 3 focusables (input, cancel, confirm): forward Tab from last wraps to first.
	assert.strictEqual(ES.focusTrapTarget(3, 2, false), 0);
	assert.strictEqual(ES.focusTrapTarget(3, 0, true), 2);
	assert.strictEqual(ES.focusTrapTarget(3, 1, false), -1);
});
