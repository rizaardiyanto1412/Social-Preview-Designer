/**
 * Pure, framework-free editor-state primitives for the Social Preview Designer.
 *
 * This module is the single source of truth for the editor's state semantics:
 * deep clone/compare, history stacks, saved-snapshot ("dirty") tracking, the
 * save/edit race resolution, undoable media selection, and the scaled
 * drag/resize geometry math.
 *
 * It is deliberately DOM- and jQuery-free so the exact same code runs in the
 * browser (attached to window.WPRemoteOGEditorState and consumed by admin.js)
 * and under Node's test runner (require('./editor-state.js')). Tests therefore
 * exercise the real logic rather than a duplicate.
 */
(function (root, factory) {
	var api = factory();
	if (typeof module === 'object' && module.exports) {
		module.exports = api;
	}
	if (root) {
		root.WPRemoteOGEditorState = api;
	}
})(typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	var HISTORY_LIMIT = 60;
	var CANVAS_WIDTH = 1200;
	var CANVAS_HEIGHT = 630;

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function equals(a, b) {
		return JSON.stringify(a) === JSON.stringify(b);
	}

	function clamp(value, min, max) {
		value = parseFloat(value);
		if (Number.isNaN(value)) {
			value = min;
		}
		return Math.max(min, Math.min(max, value));
	}

	/**
	 * dirty === current template differs from the last persisted snapshot.
	 */
	function isDirty(current, saved) {
		if (!current) {
			return false;
		}
		return !equals(current, saved);
	}

	/**
	 * Push a pre-mutation snapshot onto the undo stack and clear redo.
	 * Mutates the provided history object ({ undo: [], redo: [] }).
	 */
	function pushHistory(history, snapshot, limit) {
		if (!snapshot) {
			return;
		}
		limit = limit || HISTORY_LIMIT;
		history.undo.push(snapshot);
		if (history.undo.length > limit) {
			history.undo.shift();
		}
		history.redo = [];
	}

	/**
	 * Move one snapshot between stacks (undo <-> redo), returning the template
	 * to restore. `current` is pushed to the opposite stack so the move is
	 * reversible. Returns null when the source stack is empty.
	 */
	function transferHistory(fromStack, toStack, current) {
		if (!fromStack.length) {
			return null;
		}
		toStack.push(clone(current));
		return fromStack.pop();
	}

	/**
	 * Apply a media-library selection to an image layer.
	 * Returns true when the layer actually changed (so the caller can record a
	 * single history entry and recompute dirty), false for a no-op reselect.
	 */
	function applyMediaSelection(layer, attachment) {
		if (!layer || !attachment || !attachment.url) {
			return false;
		}
		var before = JSON.stringify(layer);
		layer.content = attachment.url;
		if (attachment.width && attachment.height) {
			layer.image_aspect_ratio = attachment.width / attachment.height;
		}
		return JSON.stringify(layer) !== before;
	}

	/**
	 * Scaled drag geometry for a move interaction.
	 * `interaction` carries the pointer origin and the layer geometry captured
	 * at mousedown; `scale` is the canvas artboard scale (screen px / artboard px).
	 */
	function computeMove(interaction, clientX, clientY, scale) {
		scale = scale && scale > 0 ? scale : 1;
		var deltaX = (clientX - interaction.startX) / scale;
		var deltaY = (clientY - interaction.startY) / scale;
		return {
			x: Math.round(clamp(interaction.x + deltaX, 0, CANVAS_WIDTH - interaction.width)),
			y: Math.round(clamp(interaction.y + deltaY, 0, CANVAS_HEIGHT - interaction.height))
		};
	}

	/**
	 * Scaled drag geometry for a resize interaction (left / right / corner edge).
	 */
	function computeResize(interaction, clientX, clientY, scale, minWidth, minHeight) {
		scale = scale && scale > 0 ? scale : 1;
		var deltaX = (clientX - interaction.startX) / scale;
		var deltaY = (clientY - interaction.startY) / scale;
		var edge = interaction.edge || 'corner';
		var result = {
			x: interaction.x,
			y: interaction.y,
			width: interaction.width,
			height: interaction.height
		};

		if ('left' === edge) {
			var rightEdge = interaction.x + interaction.width;
			var nextX = clamp(interaction.x + deltaX, 0, rightEdge - minWidth);
			result.x = Math.round(nextX);
			result.width = Math.round(rightEdge - nextX);
		} else if ('right' === edge) {
			result.width = Math.round(clamp(interaction.width + deltaX, minWidth, CANVAS_WIDTH - interaction.x));
		} else {
			result.width = Math.round(clamp(interaction.width + deltaX, minWidth, CANVAS_WIDTH - interaction.x));
			result.height = Math.round(clamp(interaction.height + deltaY, minHeight, CANVAS_HEIGHT - interaction.y));
		}

		return result;
	}

	/**
	 * Did the geometry actually change between the interaction start and the
	 * resulting layer? Used to suppress history/dirty on zero-movement clicks.
	 */
	function geometryChanged(interaction, layer) {
		return layer.x !== interaction.x ||
			layer.y !== interaction.y ||
			layer.width !== interaction.width ||
			layer.height !== interaction.height;
	}

	/**
	 * Save/edit race resolution.
	 *
	 * `submitted` is a snapshot captured at the moment Save was clicked (the
	 * state that will be persisted). `current` is the live editor state when the
	 * response arrives — which may already contain newer edits.
	 *
	 * Rules:
	 *  - The persisted snapshot becomes the new saved baseline.
	 *  - The live `current` template is NEVER replaced by the response, so
	 *    edits made during the request are preserved.
	 *  - The editor is only "clean" when the live state still equals the
	 *    submitted (persisted) snapshot.
	 */
	function resolveSave(submitted, current) {
		return {
			saved: clone(submitted),
			clean: equals(current, submitted)
		};
	}

	/**
	 * Collision-aware popover/overflow-menu placement.
	 *
	 * Pure geometry helper (DOM-free) so admin.js can measure trigger/menu with
	 * getBoundingClientRect and delegate the flip/clamp math here, and Node tests
	 * can exercise the exact same logic. All rects are expressed in viewport
	 * coordinates (as returned by getBoundingClientRect); callers apply the result
	 * with position:fixed so page scroll needs no extra bookkeeping.
	 *
	 * opts:
	 *  - triggerRect: { left, right, top, bottom, width?, height? }
	 *  - menuSize:    { width, height }
	 *  - viewport:    { width, height }
	 *  - boundaryRect?: { left, top, right, bottom } content area to stay inside
	 *  - gap?:        px between trigger and menu (default 6)
	 *  - margin?:     px inset from the boundary edges (default 8)
	 *
	 * Returns { left, top, alignRight, alignLeft, openUp, openDown }.
	 * Default placement is right-aligned + opening downward (matching the current
	 * CSS); it flips horizontally when the right-aligned menu would clip past the
	 * left boundary, flips vertically when there is not enough room below and more
	 * room above, then clamps the final coordinates inside the boundary.
	 */
	function computeMenuPosition(opts) {
		opts = opts || {};
		var trigger = opts.triggerRect || {};
		var menu = opts.menuSize || {};
		var viewport = opts.viewport || {};
		var gap = typeof opts.gap === 'number' ? opts.gap : 6;
		var margin = typeof opts.margin === 'number' ? opts.margin : 8;

		var menuW = menu.width || 0;
		var menuH = menu.height || 0;
		var vpW = viewport.width || 0;
		var vpH = viewport.height || 0;

		var boundary = opts.boundaryRect || { left: 0, top: 0, right: vpW, bottom: vpH };
		var minLeft = boundary.left + margin;
		var maxRight = boundary.right - margin;
		var minTop = boundary.top + margin;
		var maxBottom = boundary.bottom - margin;

		var tLeft = trigger.left || 0;
		var tRight = typeof trigger.right === 'number' ? trigger.right : tLeft + (trigger.width || 0);
		var tTop = trigger.top || 0;
		var tBottom = typeof trigger.bottom === 'number' ? trigger.bottom : tTop + (trigger.height || 0);

		// Horizontal: prefer aligning the menu's right edge with the trigger's.
		var rightAlignedLeft = tRight - menuW;
		var alignRight = true;
		var left = rightAlignedLeft;
		if (rightAlignedLeft < minLeft) {
			// Right-aligned menu would clip past the left edge; flip to left-align.
			alignRight = false;
			left = tLeft;
		}

		// Clamp horizontally inside the boundary.
		var maxLeft = maxRight - menuW;
		if (maxLeft < minLeft) {
			maxLeft = minLeft;
		}
		if (left > maxLeft) {
			left = maxLeft;
		}
		if (left < minLeft) {
			left = minLeft;
		}

		// Vertical: prefer opening downward, flip up when it fits better.
		var openUp = false;
		var top = tBottom + gap;
		var spaceBelow = maxBottom - (tBottom + gap);
		var spaceAbove = (tTop - gap) - minTop;
		if (menuH > spaceBelow && spaceAbove > spaceBelow) {
			openUp = true;
			top = tTop - gap - menuH;
		}

		// Clamp vertically inside the boundary.
		var maxTop = maxBottom - menuH;
		if (maxTop < minTop) {
			maxTop = minTop;
		}
		if (top > maxTop) {
			top = maxTop;
		}
		if (top < minTop) {
			top = minTop;
		}

		return {
			left: Math.round(left),
			top: Math.round(top),
			alignRight: alignRight,
			alignLeft: !alignRight,
			openUp: openUp,
			openDown: !openUp
		};
	}

	/**
	 * Focus-trap wrap target for a Tab / Shift+Tab press inside a modal.
	 *
	 * Given the number of focusable elements, the index of the currently
	 * focused one, and whether Shift is held, returns the index to move focus
	 * to when the trap needs to wrap, or -1 when the browser's default Tab
	 * order should be left alone.
	 */
	function focusTrapTarget(count, activeIndex, shiftKey) {
		if (count <= 0) {
			return -1;
		}
		if (shiftKey) {
			return activeIndex <= 0 ? count - 1 : -1;
		}
		return activeIndex >= count - 1 ? 0 : -1;
	}

	return {
		HISTORY_LIMIT: HISTORY_LIMIT,
		CANVAS_WIDTH: CANVAS_WIDTH,
		CANVAS_HEIGHT: CANVAS_HEIGHT,
		clone: clone,
		equals: equals,
		clamp: clamp,
		isDirty: isDirty,
		pushHistory: pushHistory,
		transferHistory: transferHistory,
		applyMediaSelection: applyMediaSelection,
		computeMove: computeMove,
		computeResize: computeResize,
		geometryChanged: geometryChanged,
		resolveSave: resolveSave,
		computeMenuPosition: computeMenuPosition,
		focusTrapTarget: focusTrapTarget
	};
});
