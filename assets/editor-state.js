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
		resolveSave: resolveSave
	};
});
