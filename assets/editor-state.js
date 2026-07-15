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

	// The generic, type-derived layer names the editor may auto-assign. A label
	// matching one of these (or empty) is NOT a user-authored custom name, so it
	// may be refreshed/cleared on a type or orientation change. A label outside
	// this set is a custom name and must never be overwritten by the editor.
	var GENERIC_LAYER_LABELS = [
		'Text Layer',
		'Image Layer',
		'Line Layer',
		'Horizontal Line',
		'Vertical Line'
	];

	/**
	 * Is `label` an auto-assigned generic name (or empty) rather than a
	 * user-authored custom name?
	 */
	function isGenericLayerLabel(label) {
		if (label == null) {
			return true;
		}
		var trimmed = String(label).trim();
		if (!trimmed) {
			return true;
		}
		return GENERIC_LAYER_LABELS.indexOf(trimmed) >= 0;
	}

	function lineOrientationOf(layer) {
		return layer && 'vertical' === layer.line_orientation ? 'vertical' : 'horizontal';
	}

	/**
	 * The generic, type-derived label for a layer (never persisted; used only
	 * as a display fallback and as the "known generic" baseline for migration).
	 */
	function typeLabel(layer) {
		if (!layer) {
			return '';
		}
		if ('image' === layer.type) {
			return 'Image Layer';
		}
		if ('line' === layer.type) {
			return 'vertical' === lineOrientationOf(layer) ? 'Vertical Line' : 'Horizontal Line';
		}
		return 'Text Layer';
	}

	/**
	 * Derive the display name for a layer WITHOUT mutating it.
	 *
	 *  - A non-empty custom label (outside the generic set) always wins and is
	 *    shown verbatim, regardless of type/content/orientation.
	 *  - Otherwise text layers show their resolved preview text (or raw content,
	 *    falling back to the generic "Text Layer"); image/line layers show the
	 *    generic type label.
	 *
	 * This is the single place display names are computed, so selection, control
	 * sync, render, undo/redo and duplicate never need to write into `label`.
	 */
	function deriveLayerName(layer, resolvedText) {
		if (!layer) {
			return '';
		}
		var custom = layer.label == null ? '' : String(layer.label).trim();
		if (custom && !isGenericLayerLabel(custom)) {
			return custom;
		}
		if ('image' === layer.type || 'line' === layer.type) {
			return typeLabel(layer);
		}
		var text = null != resolvedText && '' !== resolvedText ? resolvedText : (layer.content || '');
		return text || 'Text Layer';
	}

	/**
	 * Decide the layer's stored label after a type/orientation change.
	 *
	 * A custom name persists across the change; a generic (or empty) name is
	 * cleared so the display derivation reflects the new type. Returns the label
	 * the caller should store (never a generic string — generics live only in
	 * the render-time derivation).
	 */
	function reconcileLabelForTypeChange(previousLabel, changed) {
		if (!changed) {
			return null == previousLabel ? '' : previousLabel;
		}
		return isGenericLayerLabel(previousLabel) ? '' : previousLabel;
	}

	/**
	 * Fit a fixed-size artboard inside an available frame, honoring BOTH the
	 * available width and height, capped at `maxScale` (never upscale past 1:1).
	 *
	 * A non-positive available dimension is treated as "unknown" and ignored, so
	 * before layout has settled (e.g. a collapsed/hidden frame) the function
	 * degrades to the width-only / max-scale behavior rather than collapsing to 0.
	 * Returns a strictly positive scale.
	 *
	 * @param {number} contentWidth   artboard width  (e.g. 1200)
	 * @param {number} contentHeight  artboard height (e.g. 630)
	 * @param {number} availableWidth  frame inner width in screen px
	 * @param {number} availableHeight frame inner height in screen px
	 * @param {number} [maxScale=1]
	 * @returns {number}
	 */
	function fitScale(contentWidth, contentHeight, availableWidth, availableHeight, maxScale) {
		maxScale = maxScale && maxScale > 0 ? maxScale : 1;
		var scale = maxScale;
		if (availableWidth > 0 && contentWidth > 0) {
			scale = Math.min(scale, availableWidth / contentWidth);
		}
		if (availableHeight > 0 && contentHeight > 0) {
			scale = Math.min(scale, availableHeight / contentHeight);
		}
		if (!(scale > 0)) {
			scale = maxScale;
		}
		return scale;
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
	 * Decide what a primary Save should do for the current editor design.
	 *
	 * - A non-empty requestedName means "save as": create a new custom record
	 *   (used by the name modal's confirm and by "Save a copy…" of a linked design).
	 * - Else a linked customId means update that record in place (no duplicates).
	 * - Else the design is unlinked and unnamed: the caller MUST prompt for a name
	 *   before it can persist, so there is one obvious Save that always lands in
	 *   My Templates. There is no silent plain-save path for a user-authored design.
	 *
	 * @param {{customId?:string, requestedName?:string}} opts
	 * @returns {{mode:string, name?:string, customId?:string}}
	 */
	function resolveSaveTarget(opts) {
		opts = opts || {};
		var name = null == opts.requestedName ? '' : String(opts.requestedName).trim();
		if (name) {
			return { mode: 'create', name: name };
		}
		if (opts.customId) {
			return { mode: 'update', customId: String(opts.customId) };
		}
		return { mode: 'prompt' };
	}

	/**
	 * Validate a custom-template name (mirrors the PHP guard).
	 *
	 * @param {string} name
	 * @param {number} [maxLen=100]
	 * @returns {{valid:boolean, reason:string, value:string}}
	 */
	function validateTemplateName(name, maxLen) {
		maxLen = maxLen || 100;
		var trimmed = (null == name ? '' : String(name)).trim();
		if (!trimmed) {
			return { valid: false, reason: 'empty', value: '' };
		}
		// Count Unicode code points, not UTF-16 units, so a multibyte name is
		// measured the same way the PHP guard (mb_strlen) measures it.
		if (Array.from(trimmed).length > maxLen) {
			return { valid: false, reason: 'too_long', value: trimmed };
		}
		return { valid: true, reason: '', value: trimmed };
	}

	/**
	 * Suggest a default template name unique (case-insensitively) against the
	 * existing names, e.g. "My Template", "My Template 2", "My Template 3".
	 *
	 * @param {string[]} existingNames
	 * @param {string} [base='My Template']
	 * @returns {string}
	 */
	function generateDefaultName(existingNames, base) {
		base = base || 'My Template';
		var taken = {};
		(Array.isArray(existingNames) ? existingNames : []).forEach(function (n) {
			taken[String(n).trim().toLowerCase()] = true;
		});
		if (!taken[base.toLowerCase()]) {
			return base;
		}
		var i = 2;
		while (taken[(base + ' ' + i).toLowerCase()]) {
			i++;
		}
		return base + ' ' + i;
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
	 * Production glue for overflow-menu placement, extracted so the exact
	 * measure->compute->apply mapping admin.js uses is Node-testable.
	 *
	 * Given the measured trigger rect, the menu size, the viewport, and the admin
	 * content-area boundary (e.g. #wpcontent's getBoundingClientRect, which sits to
	 * the right of the WP sidebar), it resolves the final placement. When no usable
	 * boundary is supplied it falls back to the full viewport. The result is the
	 * same shape computeMenuPosition returns, so admin.js can apply it directly.
	 *
	 * opts:
	 *  - triggerRect: measured trigger rect (viewport coords)
	 *  - menuSize:    { width, height }
	 *  - viewport:    { width, height }
	 *  - boundary?:   { left, top, right, bottom } content area to stay inside
	 *  - gap?, margin?: forwarded to computeMenuPosition
	 */
	function resolveMenuPlacement(opts) {
		opts = opts || {};
		var viewport = opts.viewport || {};
		var vpW = viewport.width || 0;
		var vpH = viewport.height || 0;

		// Normalize the boundary: a valid boundary must describe a positive area
		// inside the viewport; otherwise fall back to the whole viewport so the
		// menu is never constrained to a degenerate/empty rect.
		var boundary = opts.boundary;
		var usable = boundary &&
			typeof boundary.left === 'number' &&
			typeof boundary.right === 'number' &&
			typeof boundary.top === 'number' &&
			typeof boundary.bottom === 'number' &&
			boundary.right > boundary.left &&
			boundary.bottom > boundary.top;

		var resolvedBoundary = usable ? {
			left: Math.max(0, boundary.left),
			top: Math.max(0, boundary.top),
			right: Math.min(vpW || boundary.right, boundary.right),
			bottom: Math.min(vpH || boundary.bottom, boundary.bottom)
		} : { left: 0, top: 0, right: vpW, bottom: vpH };

		return computeMenuPosition({
			triggerRect: opts.triggerRect,
			menuSize: opts.menuSize,
			viewport: viewport,
			boundaryRect: resolvedBoundary,
			gap: opts.gap,
			margin: opts.margin
		});
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
		GENERIC_LAYER_LABELS: GENERIC_LAYER_LABELS,
		isGenericLayerLabel: isGenericLayerLabel,
		typeLabel: typeLabel,
		deriveLayerName: deriveLayerName,
		reconcileLabelForTypeChange: reconcileLabelForTypeChange,
		fitScale: fitScale,
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
		resolveSaveTarget: resolveSaveTarget,
		validateTemplateName: validateTemplateName,
		generateDefaultName: generateDefaultName,
		computeMenuPosition: computeMenuPosition,
		resolveMenuPlacement: resolveMenuPlacement,
		focusTrapTarget: focusTrapTarget
	};
});
