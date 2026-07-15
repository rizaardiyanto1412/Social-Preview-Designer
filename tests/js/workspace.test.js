'use strict';

/**
 * Tests for the responsive workspace: the pure width+height canvas fit math
 * (ES.fitScale) and source-level assertions that the editor CSS keeps the
 * 3-region grid and drops the reading-width cap on the editor page.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');
const ES = require('../../assets/editor-state.js');

const CANVAS_W = 1200;
const CANVAS_H = 630;

test('fitScale fits by width when width is the tighter constraint', () => {
	// 600px wide frame, plenty of height -> width-limited to 0.5.
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 600, 2000, 1), 0.5);
});

test('fitScale fits by height when height is the tighter constraint', () => {
	// Wide frame but only 315px tall -> height-limited to 0.5.
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 5000, 315, 1), 0.5);
});

test('fitScale never upscales past the max (1:1)', () => {
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 5000, 5000, 1), 1);
});

test('fitScale ignores unknown (non-positive) dimensions rather than collapsing', () => {
	// Height unknown -> width-only fit.
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 600, 0, 1), 0.5);
	// Both unknown -> max scale, never 0.
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 0, 0, 1), 1);
	assert.ok(ES.fitScale(CANVAS_W, CANVAS_H, -10, -10, 1) > 0);
});

test('fitScale takes the smaller of the two axis fits', () => {
	// width fit 0.4, height fit 0.6 -> 0.4 wins.
	assert.strictEqual(ES.fitScale(CANVAS_W, CANVAS_H, 480, 378, 1), 0.4);
});

test('editor CSS keeps the 3-region grid and full-width editor page', () => {
	const css = fs.readFileSync(path.join(__dirname, '../../assets/admin.css'), 'utf8');
	// 3-column workspace: Structure ~240px, flexible canvas, Inspector ~320px.
	assert.ok(
		/grid-template-columns:\s*240px minmax\(0, 1fr\) 320px/.test(css),
		'workspace should declare the 240 / 1fr / 320 three-column grid'
	);
	// Editor page removes the reading-width cap.
	assert.ok(
		/\.wp-remote-og-editor-page\.wp-remote-og-app\s*\{[^}]*max-width:\s*none/.test(css),
		'editor page should drop the max-width cap'
	);
	// Canvas frame flexes to fill vertical space.
	assert.ok(
		/\.wpog-canvas-zone \.wp-remote-og-canvas-frame\s*\{[^}]*flex:\s*1 1 auto/.test(css),
		'canvas frame should flex to fill available height'
	);
});
