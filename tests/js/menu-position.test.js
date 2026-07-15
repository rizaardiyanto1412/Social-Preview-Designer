'use strict';

/**
 * Unit tests for the collision-aware overflow/popover placement helper
 * (ES.computeMenuPosition) consumed by admin.js's initOverflowMenus.
 *
 * All rects are viewport coordinates (as from getBoundingClientRect); the menu
 * is applied with position:fixed, so these cases model the real browser inputs.
 */

const test = require('node:test');
const assert = require('node:assert');
const ES = require('../../assets/editor-state.js');

const VIEWPORT = { width: 1280, height: 800 };
const MENU = { width: 220, height: 200 };

test('default placement is right-aligned, opening downward, when there is room', () => {
	// Trigger comfortably in the middle of the viewport.
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 600, right: 640, top: 300, bottom: 330 },
		menuSize: MENU,
		viewport: VIEWPORT
	});
	assert.strictEqual(pos.alignRight, true);
	assert.strictEqual(pos.openDown, true);
	// Right edge of menu aligns with right edge of trigger.
	assert.strictEqual(pos.left, 640 - 220);
	assert.strictEqual(pos.top, 330 + 6);
});

test('near the left edge, a right-aligned menu flips to left-align (opens right)', () => {
	// This is the CEO-screenshot bug: the Add Layer trigger sits near the left
	// sidebar, so the default right-aligned menu would clip off-canvas.
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 20, right: 52, top: 120, bottom: 152 },
		menuSize: MENU,
		viewport: VIEWPORT
	});
	assert.strictEqual(pos.alignLeft, true, 'flips to open rightward from the trigger');
	assert.strictEqual(pos.left, 20, 'left edge aligns with the trigger left');
	assert.ok(pos.left >= 8, 'stays inside the left margin');
});

test('near the right edge, the menu stays left of the trigger (no overflow)', () => {
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 1240, right: 1272, top: 60, bottom: 92 },
		menuSize: MENU,
		viewport: VIEWPORT
	});
	assert.strictEqual(pos.alignRight, true);
	// Never spills past the right margin (viewport width - margin).
	assert.ok(pos.left + MENU.width <= VIEWPORT.width - 8);
});

test('near the bottom, the menu flips up when there is more room above', () => {
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 600, right: 640, top: 760, bottom: 790 },
		menuSize: MENU,
		viewport: VIEWPORT
	});
	assert.strictEqual(pos.openUp, true);
	// Sits above the trigger: bottom of menu is above the trigger top.
	assert.ok(pos.top + MENU.height <= 790);
	assert.ok(pos.top >= 8);
});

test('a small viewport clamps the menu fully inside the boundary', () => {
	const smallVp = { width: 320, height: 240 };
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 200, right: 240, top: 200, bottom: 230 },
		menuSize: MENU,
		viewport: smallVp
	});
	assert.ok(pos.left >= 8);
	assert.ok(pos.left + MENU.width <= smallVp.width - 8);
	assert.ok(pos.top >= 8);
	assert.ok(pos.top + MENU.height <= smallVp.height - 8);
});

test('a scrolled trigger (negative client top) clamps to the top margin', () => {
	// getBoundingClientRect yields viewport coords; a trigger scrolled above the
	// fold reports a negative top. The fixed-positioned menu must not go off-screen.
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 600, right: 640, top: -50, bottom: -20 },
		menuSize: MENU,
		viewport: VIEWPORT
	});
	assert.ok(pos.top >= 8, 'clamped to the top margin regardless of scroll');
});

test('a boundary rect constrains placement to the admin content area', () => {
	// Simulate the WP admin sidebar occupying the left 160px: the menu must not
	// be placed under it.
	const pos = ES.computeMenuPosition({
		triggerRect: { left: 170, right: 202, top: 120, bottom: 152 },
		menuSize: MENU,
		viewport: VIEWPORT,
		boundaryRect: { left: 160, top: 32, right: 1280, bottom: 800 }
	});
	assert.ok(pos.left >= 160 + 8, 'stays right of the sidebar boundary');
});

test('resolveMenuPlacement (production glue) keeps the menu right of the sidebar boundary', () => {
	// This exercises the exact measure->compute->apply mapping admin.js calls:
	// a trigger sitting just inside the content column, with the WP sidebar as the
	// left boundary. Without the boundary the right-aligned menu would clip under
	// the sidebar; the glue must constrain it.
	const boundary = { left: 160, top: 32, right: 1280, bottom: 800 };
	const withBoundary = ES.resolveMenuPlacement({
		triggerRect: { left: 168, right: 200, top: 120, bottom: 152 },
		menuSize: MENU,
		viewport: VIEWPORT,
		boundary: boundary
	});
	assert.ok(withBoundary.left >= boundary.left + 8, 'never crosses the boundary left edge (WP sidebar)');
	assert.ok(withBoundary.left + MENU.width <= boundary.right - 8, 'never spills past the boundary right edge');
});

test('resolveMenuPlacement falls back to the viewport for a missing/degenerate boundary', () => {
	const base = {
		triggerRect: { left: 600, right: 640, top: 300, bottom: 330 },
		menuSize: MENU,
		viewport: VIEWPORT
	};
	const noBoundary = ES.resolveMenuPlacement(base);
	const viewportBoundary = ES.computeMenuPosition(Object.assign({}, base, {
		boundaryRect: { left: 0, top: 0, right: VIEWPORT.width, bottom: VIEWPORT.height }
	}));
	assert.deepStrictEqual(noBoundary, viewportBoundary, 'missing boundary behaves like a full-viewport boundary');

	// A degenerate (zero-area) boundary must not trap the menu in an empty rect.
	const degenerate = ES.resolveMenuPlacement(Object.assign({}, base, {
		boundary: { left: 500, top: 500, right: 500, bottom: 500 }
	}));
	assert.deepStrictEqual(degenerate, viewportBoundary, 'degenerate boundary falls back to the viewport');
});
