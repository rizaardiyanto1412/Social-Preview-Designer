'use strict';

/**
 * Regression tests for the layer name / label semantics.
 *
 * Root cause covered: admin.js used to unconditionally write a generic,
 * type-derived string into `layer.label` on every control sync (line layers
 * got "Horizontal Line", text layers mirrored their content). That clobbered
 * user-authored custom names ("Bottom blue") whenever selection triggered the
 * debounced control handlers. The fix moves display-name derivation to a pure,
 * render-time helper (ES.deriveLayerName) and never writes a generic label back
 * into the model. These tests model the exact admin.js call sites using only
 * the real ES primitives.
 */

const test = require('node:test');
const assert = require('node:assert');
const ES = require('../../assets/editor-state.js');

// Mirror of admin.js updateSelectedLayerFromControls, reduced to the label
// reconciliation it now performs. The key property: layer.label is only touched
// via ES.reconcileLabelForTypeChange, never derived/overwritten otherwise.
function syncControls(layer, controls) {
	const previousType = layer.type || 'text';
	const previousOrientation = 'vertical' === layer.line_orientation ? 'vertical' : 'horizontal';
	const selectedType = ['image', 'line'].indexOf(controls.type) >= 0 ? controls.type : 'text';
	const selectedOrientation = 'vertical' === controls.line_orientation ? 'vertical' : 'horizontal';

	layer.type = selectedType;
	if ('content' in controls) {
		layer.content = 'line' === selectedType ? '' : controls.content;
	}
	if ('line' === selectedType) {
		layer.line_orientation = selectedOrientation;
	}
	const changed = previousType !== selectedType ||
		('line' === selectedType && previousOrientation !== selectedOrientation);
	layer.label = ES.reconcileLabelForTypeChange(layer.label, changed);
	return layer;
}

test('control sync never overwrites a custom name (the reported bug)', () => {
	const layer = { id: 'l', type: 'line', line_orientation: 'horizontal', label: 'Bottom blue', content: '' };
	// Selection fires the control handler repeatedly with unchanged values.
	syncControls(layer, { type: 'line', line_orientation: 'horizontal' });
	syncControls(layer, { type: 'line', line_orientation: 'horizontal' });
	assert.strictEqual(layer.label, 'Bottom blue');
	assert.strictEqual(ES.deriveLayerName(layer), 'Bottom blue');
});

test('text control sync does not mirror content into the name', () => {
	const layer = { id: 't', type: 'text', label: 'Headline', content: 'Old' };
	syncControls(layer, { type: 'text', content: 'Brand new content' });
	assert.strictEqual(layer.label, 'Headline');
	assert.strictEqual(ES.deriveLayerName(layer), 'Headline');
});

test('render derivation shows content for an unnamed text layer', () => {
	const layer = { id: 't', type: 'text', label: '', content: '{post_title}' };
	assert.strictEqual(ES.deriveLayerName(layer), '{post_title}');
	// resolved preview text wins over raw token content.
	assert.strictEqual(ES.deriveLayerName(layer, 'Hello World'), 'Hello World');
});

test('render derivation shows type label for unnamed image/line layers', () => {
	assert.strictEqual(ES.deriveLayerName({ type: 'image', label: '' }), 'Image Layer');
	assert.strictEqual(ES.deriveLayerName({ type: 'line', label: '', line_orientation: 'vertical' }), 'Vertical Line');
	assert.strictEqual(ES.deriveLayerName({ type: 'line', label: '', line_orientation: 'horizontal' }), 'Horizontal Line');
});

test('generic legacy labels are treated as non-custom and re-derive', () => {
	assert.ok(ES.isGenericLayerLabel('Horizontal Line'));
	assert.ok(ES.isGenericLayerLabel(''));
	assert.ok(ES.isGenericLayerLabel(undefined));
	assert.ok(!ES.isGenericLayerLabel('Bottom blue'));
	// A layer carrying a legacy generic label shows the derived type label, not
	// a stale one.
	const layer = { type: 'line', label: 'Horizontal Line', line_orientation: 'vertical' };
	assert.strictEqual(ES.deriveLayerName(layer), 'Vertical Line');
});

test('type change refreshes a generic name but preserves a custom one', () => {
	const generic = { id: 'g', type: 'text', label: '', content: 'x' };
	syncControls(generic, { type: 'image' });
	assert.strictEqual(generic.label, '');
	assert.strictEqual(ES.deriveLayerName(generic), 'Image Layer');

	const custom = { id: 'c', type: 'text', label: 'Logo slot', content: 'x' };
	syncControls(custom, { type: 'image' });
	assert.strictEqual(custom.label, 'Logo slot');
	assert.strictEqual(ES.deriveLayerName(custom), 'Logo slot');
});

test('orientation change refreshes generic, preserves custom', () => {
	const generic = { id: 'g', type: 'line', line_orientation: 'horizontal', label: '' };
	syncControls(generic, { type: 'line', line_orientation: 'vertical' });
	assert.strictEqual(ES.deriveLayerName(generic), 'Vertical Line');

	const custom = { id: 'c', type: 'line', line_orientation: 'horizontal', label: 'Accent bar' };
	syncControls(custom, { type: 'line', line_orientation: 'vertical' });
	assert.strictEqual(custom.label, 'Accent bar');
});

test('undo/redo preserves the custom name (clone round-trip)', () => {
	const layer = { id: 'l', type: 'line', line_orientation: 'horizontal', label: 'Bottom blue', content: '' };
	const template = { layers: [layer] };
	const snapshot = ES.clone(template);
	// mutate then restore
	template.layers[0].x = 99;
	const restored = ES.clone(snapshot);
	assert.strictEqual(restored.layers[0].label, 'Bottom blue');
	assert.strictEqual(ES.deriveLayerName(restored.layers[0]), 'Bottom blue');
});

test('duplicate preserves the custom name', () => {
	const layer = { id: 'l', type: 'text', label: 'CTA', content: 'Buy now' };
	const copy = ES.clone(layer);
	copy.id = 'l2';
	assert.strictEqual(copy.label, 'CTA');
	assert.strictEqual(ES.deriveLayerName(copy), 'CTA');
});

test('save payload round-trip keeps a custom name unchanged', () => {
	const layer = { id: 'l', type: 'line', line_orientation: 'horizontal', label: 'Bottom blue', content: '' };
	const submitted = ES.clone({ layers: [layer] });
	const result = ES.resolveSave(submitted, { layers: [layer] });
	assert.strictEqual(result.saved.layers[0].label, 'Bottom blue');
});

test('reconcileLabelForTypeChange never emits a generic string', () => {
	// No change: label passes through untouched.
	assert.strictEqual(ES.reconcileLabelForTypeChange('Bottom blue', false), 'Bottom blue');
	assert.strictEqual(ES.reconcileLabelForTypeChange('', false), '');
	// Changed + generic -> cleared (empty), never a generic literal.
	assert.strictEqual(ES.reconcileLabelForTypeChange('Text Layer', true), '');
	// Changed + custom -> preserved.
	assert.strictEqual(ES.reconcileLabelForTypeChange('Bottom blue', true), 'Bottom blue');
});
