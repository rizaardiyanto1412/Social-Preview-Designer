(function ($) {
	'use strict';

	// Shared, framework-free state engine (also used directly by the Node tests).
	var ES = window.WPRemoteOGEditorState;

	var state = {
		template: window.WPRemoteOG && WPRemoteOG.template ? WPRemoteOG.template : null,
		savedSnapshot: null,
		selectedLayerId: null,
		preview: {},
		ready: false,
		suspendControls: false,
		interaction: null,
		mediaFrame: null,
		history: { undo: [], redo: [] },
		dirtySinceSave: false,
		customId: window.WPRemoteOG && WPRemoteOG.activeCustomId ? WPRemoteOG.activeCustomId : ''
	};

	var HISTORY_LIMIT = ES.HISTORY_LIMIT;

	function snapshotTemplate() {
		return clone(state.template);
	}

	// Recompute the dirty flag from the actual state: dirty means the current
	// template differs from the last persisted (saved) snapshot. This makes
	// "undo back to the saved state" clean and "redo away from it" dirty for free.
	function syncDirty() {
		markDirty(ES.isDirty(state.template, state.savedSnapshot));
	}

	function markDirty(flag) {
		state.dirtySinceSave = false !== flag;
		var indicator = $('#wp-remote-og-dirty-indicator');
		if (!indicator.length) {
			return;
		}
		if (state.dirtySinceSave) {
			indicator.addClass('is-dirty').text(indicator.data('unsaved-label') || 'Unsaved changes');
		} else {
			indicator.removeClass('is-dirty').text(indicator.data('saved-label') || 'All changes saved');
		}
	}

	function flashDirtyIndicator(text, cls) {
		var indicator = $('#wp-remote-og-dirty-indicator');
		if (!indicator.length) {
			return;
		}
		if (state.dirtyFlashTimer) {
			window.clearTimeout(state.dirtyFlashTimer);
		}
		indicator.removeClass('is-dirty is-saved is-error').addClass(cls).text(text);
		state.dirtyFlashTimer = window.setTimeout(function () {
			// Revert to the current persistent state (saved/unsaved) after the flash.
			markDirty(state.dirtySinceSave);
		}, 2200);
	}

	function updateHistoryButtons() {
		$('#wp-remote-og-undo').prop('disabled', !state.history.undo.length);
		$('#wp-remote-og-redo').prop('disabled', !state.history.redo.length);
	}

	function pushHistory(snapshot) {
		ES.pushHistory(state.history, snapshot, HISTORY_LIMIT);
		updateHistoryButtons();
	}

	function recordHistory() {
		if (!state.template) {
			return;
		}
		pushHistory(snapshotTemplate());
		markDirty(true);
	}

	function restoreFromHistory(fromStack, toStack) {
		var restored = ES.transferHistory(fromStack, toStack, state.template);
		if (!restored) {
			return;
		}
		state.template = restored;
		if (!state.template.layers.length) {
			state.selectedLayerId = null;
		} else if (!currentLayer()) {
			state.selectedLayerId = state.template.layers[0].id;
		}
		updateHistoryButtons();
		// Value-based: undoing back to the persisted state becomes clean again.
		syncDirty();
		renderLayerList();
		renderCanvas();
		fillControls(currentLayer());
	}

	function undoHistory() {
		restoreFromHistory(state.history.undo, state.history.redo);
	}

	function redoHistory() {
		restoreFromHistory(state.history.redo, state.history.undo);
	}

	function layerTypeIcon(type) {
		if ('image' === type) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
		}
		if ('line' === type) {
			return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16"/></svg>';
		}
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7V5h16v2M9 5v14M7 19h4"/></svg>';
	}

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function clamp(value, min, max) {
		value = parseFloat(value);
		if (Number.isNaN(value)) {
			value = min;
		}
		return Math.max(min, Math.min(max, value));
	}

	function setStatusMessage(element, message, type) {
		if (!element || !element.length) {
			return;
		}
		element.removeClass('is-success is-error is-busy');
		if (type) {
			element.addClass('is-' + type);
		}
		element.text(message || '');
	}

	function availableTokens() {
		if (!window.WPRemoteOG || !Array.isArray(WPRemoteOG.availableTokens)) {
			return [];
		}

		return WPRemoteOG.availableTokens;
	}

	function mergeEnabledFieldsAndTokens() {
		var fields = [];
		var seen = {};

		if (window.WPRemoteOG && Array.isArray(WPRemoteOG.fields)) {
			WPRemoteOG.fields.forEach(function (field) {
				if (!field || !field.token || seen[field.token]) {
					return;
				}
				seen[field.token] = true;
				fields.push({
					token: field.token,
					label: field.label || field.token
				});
			});
		}

		availableTokens().forEach(function (token) {
			if (!token || !token.token || seen[token.token]) {
				return;
			}
			seen[token.token] = true;
			fields.push({
				token: token.token,
				label: token.label || token.token
			});
		});

		return fields;
	}

	function rebuildTokenPicker() {
		var select = $('#wp-remote-og-token-picker');
		var visibilitySelect = $('#wp-remote-og-layer-requires-token');
		if (!select.length && !visibilitySelect.length) {
			return;
		}

		var selected = select.val();
		var selectedVisibility = visibilitySelect.val();
		if (select.length) {
			select.empty();
			select.append('<option value="">Choose token</option>');
		}
		if (visibilitySelect.length) {
			visibilitySelect.empty();
			visibilitySelect.append('<option value="">Always show</option>');
		}
		mergeEnabledFieldsAndTokens().forEach(function (field) {
			var text = field.label ? field.label + ' — ' + field.token : field.token;
			if (select.length) {
				$('<option/>', {
					value: field.token,
					text: text
				}).appendTo(select);
			}
			if (visibilitySelect.length) {
				$('<option/>', {
					value: field.token,
					text: text
				}).appendTo(visibilitySelect);
			}
		});
		if (selected) {
			select.val(selected);
		}
		if (selectedVisibility) {
			visibilitySelect.val(selectedVisibility);
		}
	}

	function currentLayer() {
		if (!state.template || !state.template.layers) {
			return null;
		}
		return state.template.layers.find(function (layer) {
			return layer.id === state.selectedLayerId;
		}) || null;
	}

	function layerDisplayText(layer) {
		if ('image' === layer.type) {
			return layer.label || 'Image Layer';
		}

		if ('line' === layer.type) {
			return layer.label || lineLayerLabel(layer);
		}

		if (state.preview[layer.id] && typeof state.preview[layer.id].resolved === 'string') {
			return state.preview[layer.id].resolved;
		}
		return layer.content || '';
	}

	function layerImageSource(layer) {
		if (state.preview[layer.id] && typeof state.preview[layer.id].resolved === 'string' && state.preview[layer.id].resolved) {
			return state.preview[layer.id].resolved;
		}

		if ('string' === typeof layer.content && layer.content.indexOf('{') !== -1) {
			return '';
		}

		return layer.content || '';
	}

	function imageAspectRatio(layer) {
		if (!layer || 'image' !== layer.type) {
			return 1;
		}

		if (layer.image_aspect_ratio) {
			return layer.image_aspect_ratio;
		}

		var sourceWidth = parseFloat(layer.width);
		var sourceHeight = parseFloat(layer.height);
		if (!sourceWidth || !sourceHeight) {
			return 1;
		}

		return sourceWidth / sourceHeight;
	}

	function constrainedLayerResizeBounds(layer, width, height) {
		var maxWidth = 1200 - layer.x;
		var maxHeight = 630 - layer.y;

		var normalizedWidth = clamp(width, 20, maxWidth);
		var normalizedHeight = clamp(height, 20, maxHeight);
		return {
			width: normalizedWidth,
			height: normalizedHeight
		};
	}

	function applyConstrainedImageAspectRatio(aspectRatio, width, height, preferredDimension, layer) {
		if (!aspectRatio || !isFinite(aspectRatio) || aspectRatio <= 0) {
			return {
				width: width,
				height: height
			};
		}

		var target = constrainedLayerResizeBounds(layer, width, height);
		var finalWidth = target.width;
		var finalHeight = target.height;
		var maxWidth = 1200 - layer.x;
		var maxHeight = 630 - layer.y;

		if ('width' === preferredDimension) {
			finalHeight = Math.max(20, Math.round(finalWidth / aspectRatio));
			if (finalHeight > maxHeight) {
				finalHeight = maxHeight;
				finalWidth = Math.max(20, Math.round(finalHeight * aspectRatio));
			}
		} else {
			finalWidth = Math.max(20, Math.round(finalHeight * aspectRatio));
			if (finalWidth > maxWidth) {
				finalWidth = maxWidth;
				finalHeight = Math.max(20, Math.round(finalWidth / aspectRatio));
			}
		}

		var constrained = constrainedLayerResizeBounds(layer, finalWidth, finalHeight);
		return {
			width: constrained.width,
			height: constrained.height
		};
	}

	function imageShape(layer) {
		return ['rounded', 'circle'].indexOf(layer.image_shape) >= 0 ? layer.image_shape : 'square';
	}

	function imageFit(layer) {
		return layer && ['cover', 'stretch'].indexOf(layer.image_fit) >= 0 ? layer.image_fit : 'contain';
	}

	function lineOrientation(layer) {
		return layer && 'vertical' === layer.line_orientation ? 'vertical' : 'horizontal';
	}

	function lineLayerLabel(layer) {
		return 'vertical' === lineOrientation(layer) ? 'Vertical Line' : 'Horizontal Line';
	}

	function layerMinWidth(layer) {
		return 'line' === layer.type && 'vertical' === lineOrientation(layer) ? 1 : 20;
	}

	function layerMinHeight(layer) {
		return 'line' === layer.type && 'horizontal' === lineOrientation(layer) ? 1 : 20;
	}

	function defaultLineDimensions(layer, orientation) {
		var maxWidth = 1200 - layer.x;
		var maxHeight = 630 - layer.y;
		var length = Math.max(120, parseFloat(layer.width) || 0, parseFloat(layer.height) || 0);
		var thinnerSide = Math.min(parseFloat(layer.width) || 6, parseFloat(layer.height) || 6);
		var thickness = Math.max(1, Math.min(24, thinnerSide || 6));

		if ('vertical' === orientation) {
			return {
				width: Math.max(1, Math.min(maxWidth, thickness)),
				height: Math.max(20, Math.min(maxHeight, length))
			};
		}

		return {
			width: Math.max(20, Math.min(maxWidth, length)),
			height: Math.max(1, Math.min(maxHeight, thickness))
		};
	}

	function canvasScale() {
		var frame = $('.wp-remote-og-canvas-frame');
		if (!frame.length) {
			return 1;
		}
		var available = frame.width();
		if (!available || available <= 0) {
			return 1;
		}
		return Math.min(1, available / 1200);
	}

	function applyCanvasScale() {
		var scale = canvasScale();
		state.canvasScale = scale;
		// Scale the 1200x630 artboard, then collapse its layout footprint with negative
		// margins so the frame does not force horizontal/vertical scrollbars.
		$('#wp-remote-og-canvas').css({
			transform: 'scale(' + scale + ')',
			transformOrigin: 'top left',
			marginRight: -Math.round(1200 * (1 - scale)) + 'px',
			marginBottom: -Math.round(630 * (1 - scale)) + 'px'
		});
	}

	function activeCanvasScale() {
		var scale = state.canvasScale;
		return scale && scale > 0 ? scale : 1;
	}

	function renderCanvas() {
		if (!state.template) {
			return;
		}

		var canvas = $('#wp-remote-og-canvas');
		var stage = $('#wp-remote-og-layer-stage');
		canvas.css('background-image', state.template.background && state.template.background.url ? 'url("' + state.template.background.url + '")' : '');
		stage.empty();

		state.template.layers.forEach(function (layer) {
			if (state.preview[layer.id] && state.preview[layer.id].hidden) {
				return;
			}

			var accessibleLabel = layer.label || ('line' === layer.type ? lineLayerLabel(layer) : ('image' === layer.type ? 'Image Layer' : layer.content)) || layer.id;
			var node = $('<div/>', {
				'class': 'wp-remote-og-layer',
				'data-layer-id': layer.id,
				tabindex: 0,
				role: 'button',
				'aria-label': accessibleLabel + ' (' + layer.type + ' layer)'
			});
			if ('line' === layer.type) {
				node.addClass('wp-remote-og-layer-is-line');
				node.addClass('wp-remote-og-layer-line-' + lineOrientation(layer));
				node.append($('<span/>', {
					'class': 'wp-remote-og-layer-line-fill',
					'aria-hidden': 'true'
				}));
			} else if ('image' === layer.type) {
				node.addClass('wp-remote-og-layer-is-image');
				node.addClass('wp-remote-og-layer-image-shape-' + imageShape(layer));
				node.addClass('wp-remote-og-layer-image-fit-' + imageFit(layer));
				var imageSource = layerImageSource(layer);
				var imageWrap = $('<div/>', {
					'class': 'wp-remote-og-layer-image-content-wrap'
				});
				if (imageSource) {
					imageWrap.append($('<img/>', {
						'class': 'wp-remote-og-layer-image-content',
						src: imageSource,
						alt: layer.label || 'Image layer'
					}));
				} else {
					imageWrap.append($('<span/>', {
						'class': 'wp-remote-og-layer-image-missing',
						text: 'Image missing'
					}));
				}
				node.append(imageWrap);
			} else {
				node.append($('<span/>', {
					'class': 'wp-remote-og-layer-text',
					text: layerDisplayText(layer)
				}));
			}
			['left', 'right', 'corner'].forEach(function (edge) {
				node.append($('<span/>', {
					'class': 'wp-remote-og-resize-handle wp-remote-og-resize-handle-' + edge,
					'data-resize-edge': edge,
					'aria-hidden': 'true'
				}));
			});

			if (layer.id === state.selectedLayerId) {
				node.addClass('is-selected');
			}

			node.css({
				left: layer.x + 'px',
				top: layer.y + 'px',
				width: layer.width + 'px',
				height: layer.height + 'px',
				color: layer.color
			});

			if ('text' === layer.type) {
				node.css({
					fontFamily: fontFamilyFor(layer),
					fontSize: layer.font_size + 'px',
					lineHeight: layer.line_height,
					textAlign: layer.align
				});
			}

			stage.append(node);
		});

		$('.wp-remote-og-layer').on('mousedown', startMoveInteraction).on('click', function () {
			selectLayer($(this).data('layer-id'));
		}).on('focus', function () {
			var id = $(this).data('layer-id');
			if (id !== state.selectedLayerId) {
				selectLayer(id);
				focusCanvasLayer(id);
			}
		}).on('keydown', handleLayerKeydown);
		$('.wp-remote-og-resize-handle').on('mousedown', startResizeInteraction);

		applyCanvasScale();
	}

	function startMoveInteraction(event) {
		if ($(event.target).hasClass('wp-remote-og-resize-handle')) {
			return;
		}

		var id = $(this).data('layer-id');
		selectLayer(id);
		var layer = currentLayer();
		if (!layer) {
			return;
		}

		state.interaction = {
			type: 'move',
			id: id,
			startX: event.clientX,
			startY: event.clientY,
			x: layer.x,
			y: layer.y,
			width: layer.width,
			height: layer.height,
			snapshot: snapshotTemplate()
		};
		bindDocumentInteraction();
		event.preventDefault();
	}

	function startResizeInteraction(event) {
		var id = $(this).closest('.wp-remote-og-layer').data('layer-id');
		selectLayer(id);
		var layer = currentLayer();
		if (!layer) {
			return;
		}

		state.interaction = {
			type: 'resize',
			id: id,
			edge: $(event.target).data('resize-edge') || 'corner',
			startX: event.clientX,
			startY: event.clientY,
			x: layer.x,
			y: layer.y,
			width: layer.width,
			height: layer.height,
			snapshot: snapshotTemplate()
		};
		bindDocumentInteraction();
		event.preventDefault();
		event.stopPropagation();
	}

	function bindDocumentInteraction() {
		$(document)
			.off('mousemove.wpRemoteOgInteraction mouseup.wpRemoteOgInteraction')
			.on('mousemove.wpRemoteOgInteraction', updateInteraction)
			.on('mouseup.wpRemoteOgInteraction', endInteraction);
	}

	function updateInteraction(event) {
		if (!state.interaction) {
			return;
		}

		var layer = currentLayer();
		if (!layer || layer.id !== state.interaction.id) {
			return;
		}

		var scale = activeCanvasScale();

		if (state.interaction.type === 'move') {
			var moved = ES.computeMove(state.interaction, event.clientX, event.clientY, scale);
			layer.x = moved.x;
			layer.y = moved.y;
		} else {
			var resized = ES.computeResize(state.interaction, event.clientX, event.clientY, scale, layerMinWidth(layer), layerMinHeight(layer));
			layer.x = resized.x;
			layer.y = resized.y;
			layer.width = resized.width;
			layer.height = resized.height;
		}

		updateLayerElement(layer);
		fillControls(layer);
		event.preventDefault();
	}

	function endInteraction() {
		var interaction = state.interaction;
		state.interaction = null;
		$(document).off('mousemove.wpRemoteOgInteraction mouseup.wpRemoteOgInteraction');

		if (interaction) {
			var layer = state.template.layers.find(function (candidate) {
				return candidate.id === interaction.id;
			});
			// Only record history / mark dirty when the geometry actually changed.
			// A pure click-to-select (zero movement) leaves everything untouched.
			if (layer && ES.geometryChanged(interaction, layer)) {
				pushHistory(interaction.snapshot);
				syncDirty();
			}
		}

		renderLayerList();
	}

	function updateLayerElement(layer) {
		$('.wp-remote-og-layer[data-layer-id="' + layer.id + '"]').css({
			left: layer.x + 'px',
			top: layer.y + 'px',
			width: layer.width + 'px',
			height: layer.height + 'px'
		});
	}

	function focusCanvasLayer(id) {
		var node = $('.wp-remote-og-layer[data-layer-id="' + id + '"]');
		if (node.length) {
			node.trigger('focus');
		}
	}

	function handleLayerKeydown(event) {
		if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].indexOf(event.key) === -1) {
			return;
		}

		var id = $(this).data('layer-id');
		if (id !== state.selectedLayerId) {
			selectLayer(id);
			focusCanvasLayer(id);
		}
		var layer = currentLayer();
		if (!layer || layer.id !== id) {
			return;
		}

		if (!event.repeat) {
			recordHistory();
		}
		var step = event.shiftKey ? 10 : 1;
		var deltaX = 'ArrowLeft' === event.key ? -step : ('ArrowRight' === event.key ? step : 0);
		var deltaY = 'ArrowUp' === event.key ? -step : ('ArrowDown' === event.key ? step : 0);

		if (event.ctrlKey || event.metaKey) {
			layer.width = Math.round(clamp(layer.width + deltaX, layerMinWidth(layer), 1200 - layer.x));
			layer.height = Math.round(clamp(layer.height + deltaY, layerMinHeight(layer), 630 - layer.y));
		} else {
			layer.x = Math.round(clamp(layer.x + deltaX, 0, 1200 - layer.width));
			layer.y = Math.round(clamp(layer.y + deltaY, 0, 630 - layer.height));
		}

		updateLayerElement(layer);
		fillControls(layer);
		event.preventDefault();
	}

	function fontFamilyFor(layer) {
		if (!layer.font_id || !window.WPRemoteOG || !WPRemoteOG.fonts) {
			return 'Arial, Helvetica, sans-serif';
		}
		var font = WPRemoteOG.fonts.find(function (candidate) {
			return candidate.id === layer.font_id;
		});
		if (!font) {
			return 'Arial, Helvetica, sans-serif';
		}
		return '"' + font.label.replace(/"/g, '') + '", Arial, Helvetica, sans-serif';
	}

	function loadGoogleFontCatalog() {
		var input = $('#wp-remote-og-google-font-family');
		if (!input.length) {
			return;
		}

		var status = $('#wp-remote-og-google-font-status');
		var list = $('#wp-remote-og-google-font-list');
		var picker = input.closest('.wp-remote-og-font-picker');
		var panel = $('#wp-remote-og-google-font-suggestions');
		var toggle = picker.find('.wp-remote-og-font-picker-toggle');
		var fonts = [];
		var seen = {};
		var activeIndex = -1;
		var fallbackMessage = window.WPRemoteOG && WPRemoteOG.strings && WPRemoteOG.strings.googleFontListFallback ? WPRemoteOG.strings.googleFontListFallback : 'Unable to load Google font directory. You can still type the font name manually.';

		function setStatus(message) {
			if (status.length) {
				status.text(message);
			}
		}

		function addFont(family) {
			family = $.trim(String(family || ''));
			if (!family || seen[family]) {
				return false;
			}
			seen[family] = true;
			fonts.push(family);
			return true;
		}

		function loadInitialFonts() {
			if (!list.length) {
				return;
			}

			list.find('option').each(function () {
				addFont($(this).val());
			});
		}

		function closeSuggestions() {
			if (!panel.length) {
				return;
			}
			panel.prop('hidden', true);
			picker.removeClass('is-open');
			input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
			toggle.attr('aria-expanded', 'false');
			activeIndex = -1;
		}

		function openSuggestions() {
			if (!panel.length || !panel.children().length) {
				return;
			}
			panel.prop('hidden', false);
			picker.addClass('is-open');
			input.attr('aria-expanded', 'true');
			toggle.attr('aria-expanded', 'true');
		}

		function matchesFor(query) {
			var search = $.trim(String(query || '')).toLowerCase();
			var starts = [];
			var contains = [];

			fonts.forEach(function (font) {
				var lower = font.toLowerCase();
				if (!search || lower.indexOf(search) === 0) {
					starts.push(font);
					return;
				}
				if (lower.indexOf(search) !== -1) {
					contains.push(font);
				}
			});

			return starts.concat(contains).slice(0, 8);
		}

		function setActive(index) {
			var items = panel.find('.wp-remote-og-font-suggestion:not(.is-empty)');
			items.removeClass('is-active').attr('aria-selected', 'false');
			if (!items.length) {
				activeIndex = -1;
				input.removeAttr('aria-activedescendant');
				return;
			}

			activeIndex = Math.max(0, Math.min(index, items.length - 1));
			var active = items.eq(activeIndex);
			active.addClass('is-active').attr('aria-selected', 'true');
			input.attr('aria-activedescendant', active.attr('id'));
		}

		function renderSuggestions(forceOpen) {
			if (!panel.length) {
				return;
			}

			var query = input.val();
			var matches = matchesFor(query);
			panel.empty();
			activeIndex = -1;
			input.removeAttr('aria-activedescendant');

			if (!matches.length) {
				if ($.trim(query)) {
					$('<div/>', {
						'class': 'wp-remote-og-font-suggestion is-empty',
						text: 'No matching font. You can still add the typed value.'
					}).appendTo(panel);
				} else {
					closeSuggestions();
					return;
				}
			} else {
				matches.forEach(function (font, index) {
					var item = $('<button/>', {
						type: 'button',
						id: 'wp-remote-og-google-font-option-' + index,
						'class': 'wp-remote-og-font-suggestion',
						role: 'option',
						'aria-selected': 'false',
						'data-value': font
					});
					$('<span/>', {
						'class': 'wp-remote-og-font-preview',
						'aria-hidden': 'true',
						text: 'Aa'
					}).appendTo(item);
					$('<span/>', {
						'class': 'wp-remote-og-font-label',
						text: font
					}).appendTo(item);
					item.appendTo(panel);
				});
			}

			if (forceOpen || input.is(':focus')) {
				openSuggestions();
			}
		}

		function selectSuggestion(value) {
			input.val(value).trigger('change');
			closeSuggestions();
		}

		loadInitialFonts();

		input.on('focus input', function () {
			renderSuggestions(true);
		});

		input.on('keydown', function (event) {
			var items;
			if ('ArrowDown' === event.key) {
				event.preventDefault();
				if (panel.prop('hidden')) {
					renderSuggestions(true);
				}
				items = panel.find('.wp-remote-og-font-suggestion:not(.is-empty)');
				if (items.length) {
					setActive((activeIndex + 1) % items.length);
				}
				return;
			}

			if ('ArrowUp' === event.key) {
				event.preventDefault();
				if (panel.prop('hidden')) {
					renderSuggestions(true);
				}
				items = panel.find('.wp-remote-og-font-suggestion:not(.is-empty)');
				if (items.length) {
					setActive(activeIndex <= 0 ? items.length - 1 : activeIndex - 1);
				}
				return;
			}

			if ('Enter' === event.key && !panel.prop('hidden') && activeIndex > -1) {
				event.preventDefault();
				items = panel.find('.wp-remote-og-font-suggestion:not(.is-empty)');
				selectSuggestion(items.eq(activeIndex).data('value'));
				return;
			}

			if ('Escape' === event.key) {
				closeSuggestions();
			}
		});

		toggle.on('click', function (event) {
			event.preventDefault();
			if (!panel.prop('hidden')) {
				closeSuggestions();
				return;
			}
			input.trigger('focus');
			renderSuggestions(true);
		});

		panel.on('mousedown', '.wp-remote-og-font-suggestion:not(.is-empty)', function (event) {
			event.preventDefault();
		});

		panel.on('mouseenter', '.wp-remote-og-font-suggestion:not(.is-empty)', function () {
			setActive(panel.find('.wp-remote-og-font-suggestion:not(.is-empty)').index(this));
		});

		panel.on('click', '.wp-remote-og-font-suggestion:not(.is-empty)', function () {
			selectSuggestion($(this).data('value'));
		});

		picker.on('focusout', function () {
			window.setTimeout(function () {
				if (!picker[0].contains(document.activeElement)) {
					closeSuggestions();
				}
			}, 0);
		});

		$(document).on('mousedown.wpRemoteOgGoogleFonts', function (event) {
			if (!picker.is(event.target) && !picker.has(event.target).length) {
				closeSuggestions();
			}
		});

		if (!window.WPRemoteOG || !WPRemoteOG.ajaxUrl) {
			setStatus(fallbackMessage);
			return;
		}

		if (!list.length) {
			return;
		}

		$.post(WPRemoteOG.ajaxUrl, {
			action: 'wp_remote_og_google_fonts',
			nonce: WPRemoteOG.nonce
		}).done(function (response) {
			if (!response || !response.success || !response.data || !Array.isArray(response.data.fonts)) {
				setStatus('Unable to load Google font directory. You can still type the font name manually.');
				return;
			}

			response.data.fonts.forEach(function (family) {
				if (addFont(family)) {
					$('<option/>', {
						value: family
					}).appendTo(list);
				}
			});

			var count = response.data.count || response.data.fonts.length;
			setStatus('Loaded ' + count + ' Google fonts.');
			renderSuggestions(input.is(':focus'));
		}).fail(function () {
			setStatus('Unable to load Google font directory. You can still type the font name manually.');
		});
	}

	function injectFontFaces() {
		if (!window.WPRemoteOG || !Array.isArray(WPRemoteOG.fonts) || !WPRemoteOG.fonts.length) {
			return;
		}

		var cssImports = [];
		var cssFontFaces = [];

		WPRemoteOG.fonts.forEach(function (font) {
			if (!font.label) {
				return;
			}

			var family = String(font.label).replace(/"/g, '');
			if (font.css_url) {
				cssImports.push('@import url("' + String(font.css_url).replace(/"/g, '') + '");');
			}

			if (!font.url) {
				return;
			}

			cssFontFaces.push('@font-face{font-family:"' + family + '";src:url("' + font.url + '");font-display:swap;}');
		});

		var css = cssImports.concat(cssFontFaces).join('\n');

		if (!css) {
			return;
		}

		$('#wp-remote-og-font-faces').remove();
		$('<style/>', {
			id: 'wp-remote-og-font-faces',
			text: css
		}).appendTo(document.head);
	}

	function renderLayerList() {
		var list = $('#wp-remote-og-layer-list');
		if (!list.length || !state.template) {
			return;
		}

		list.empty();
		var hasSelection = state.template.layers.some(function (layer) {
			return layer.id === state.selectedLayerId;
		});
		state.template.layers.forEach(function (layer, index) {
			var labelText = layer.label || ('line' === layer.type ? lineLayerLabel(layer) : layer.content) || layer.id;
			var isSelected = layer.id === state.selectedLayerId;
			// Roving tabindex: only the selected (or first, if none selected) item is tabbable.
			var tabbable = isSelected || (!hasSelection && 0 === index);
			var item = $('<li/>', {
				'data-layer-id': layer.id,
				tabindex: tabbable ? 0 : -1,
				role: 'option',
				'aria-selected': isSelected ? 'true' : 'false'
			});
			$('<span/>', {
				'class': 'wp-remote-og-layer-icon',
				'aria-hidden': 'true',
				html: layerTypeIcon(layer.type)
			}).appendTo(item);
			$('<span/>', {
				'class': 'wp-remote-og-layer-label',
				text: labelText
			}).appendTo(item);
			if (layer.id === state.selectedLayerId) {
				item.addClass('is-selected');
			}
			list.append(item);
		});
	}

	function selectLayer(id) {
		state.selectedLayerId = id;
		var layer = currentLayer();
		fillControls(layer);
		renderLayerList();
		renderCanvas();
	}

	function fillControls(layer) {
		state.suspendControls = true;
		if (!layer) {
			$('.wp-remote-og-controls input, .wp-remote-og-controls select').val('');
			$('#wp-remote-og-layer-type').val('text');
			$('#wp-remote-og-inspector-empty').show();
			$('#wp-remote-og-inspector-body').hide();
			state.suspendControls = false;
			return;
		}

		$('#wp-remote-og-inspector-empty').hide();
		$('#wp-remote-og-inspector-body').show();
		var inspectorName = layer.label || ('line' === layer.type ? lineLayerLabel(layer) : ('image' === layer.type ? 'Image Layer' : layer.content)) || layer.id;
		$('#wp-remote-og-inspector-name').text(inspectorName);

		var isImage = 'image' === layer.type;
		var isLine = 'line' === layer.type;
		var isText = !isImage && !isLine;

		$('#wp-remote-og-layer-type').val(isImage ? 'image' : (isLine ? 'line' : 'text'));
		$('#wp-remote-og-layer-content').val(layer.content);
		$('#wp-remote-og-layer-font').val(layer.font_id || '');
		$('#wp-remote-og-layer-font-size').val(layer.font_size);
		$('#wp-remote-og-layer-min-font-size').val(layer.min_font_size);
		$('#wp-remote-og-layer-color').val(layer.color).trigger('change');
		$('#wp-remote-og-layer-align').val(layer.align);
		$('#wp-remote-og-layer-line-height').val(layer.line_height);
		$('#wp-remote-og-layer-max-lines').val(layer.max_lines);
		$('#wp-remote-og-layer-x').val(layer.x);
		$('#wp-remote-og-layer-y').val(layer.y);
		$('#wp-remote-og-layer-width').val(layer.width);
		$('#wp-remote-og-layer-height').val(layer.height);
		$('#wp-remote-og-layer-image-shape').val(imageShape(layer));
		$('#wp-remote-og-layer-image-fit').val(imageFit(layer));
		$('#wp-remote-og-layer-line-orientation').val(lineOrientation(layer));
		$('#wp-remote-og-layer-requires-token').val(layer.requires_token || '');
		$('.wp-remote-og-content-controls').toggle(!isLine);
		$('.wp-remote-og-color-controls').toggle(isText || isLine);
		$('.wp-remote-og-text-controls').toggle(isText);
		$('.wp-remote-og-image-controls').toggle(isImage);
		$('.wp-remote-og-line-controls').toggle(isLine);
		state.suspendControls = false;
	}

	function updateSelectedLayerFromControls(changedFieldId) {
		if (!state.ready || state.suspendControls) {
			return;
		}

		var layer = currentLayer();
		if (!layer) {
			return;
		}

		// Value-based change detection: capture the layer (and a full-template
		// snapshot for history) before mutating, then only mark dirty / push history
		// if something actually changed. This is categorical — no timing assumptions —
		// so zero-op invocations from any path (e.g. the debounced color picker firing
		// during selection) never dirty the template or pollute history.
		var beforeLayer = JSON.stringify(layer);
		var beforeSnapshot = snapshotTemplate();

		var selectedTypeValue = $('#wp-remote-og-layer-type').val();
		var selectedType = ['image', 'line'].indexOf(selectedTypeValue) >= 0 ? selectedTypeValue : 'text';
		var previousType = layer.type || 'text';
		var previousOrientation = lineOrientation(layer);
		var content = $('#wp-remote-og-layer-content').val();
		var selectedLineOrientation = 'vertical' === $('#wp-remote-og-layer-line-orientation').val() ? 'vertical' : 'horizontal';
		layer.requires_token = $('#wp-remote-og-layer-requires-token').val() || '';

		layer.type = selectedType;
		layer.x = clamp($('#wp-remote-og-layer-x').val(), 0, 1200);
		layer.y = clamp($('#wp-remote-og-layer-y').val(), 0, 630);
		if ('image' === selectedType) {
			layer.content = content || '';
			layer.label = layer.label || 'Image Layer';
			layer.image_shape = $('#wp-remote-og-layer-image-shape').val() || 'square';
			layer.image_fit = imageFit({
				image_fit: $('#wp-remote-og-layer-image-fit').val()
			});
		} else if ('line' === selectedType) {
			layer.content = '';
			layer.line_orientation = selectedLineOrientation;
			layer.label = lineLayerLabel(layer);
			layer.color = $('#wp-remote-og-layer-color').val() || '#111827';
			if ('line' !== previousType || previousOrientation !== selectedLineOrientation) {
				var defaultDimensions = defaultLineDimensions(layer, selectedLineOrientation);
				$('#wp-remote-og-layer-width').val(defaultDimensions.width);
				$('#wp-remote-og-layer-height').val(defaultDimensions.height);
			}
		} else {
			layer.content = content || '{post_title}';
			layer.label = layer.content;
			layer.font_id = $('#wp-remote-og-layer-font').val() || '';
			layer.font_size = clamp($('#wp-remote-og-layer-font-size').val(), 8, 180);
			layer.min_font_size = clamp($('#wp-remote-og-layer-min-font-size').val(), 6, layer.font_size);
			layer.color = $('#wp-remote-og-layer-color').val() || '#111827';
			layer.align = $('#wp-remote-og-layer-align').val() || 'left';
			layer.line_height = clamp($('#wp-remote-og-layer-line-height').val(), 0.8, 2.5);
			layer.max_lines = clamp($('#wp-remote-og-layer-max-lines').val(), 1, 12);
		}
		var layerWidth = clamp($('#wp-remote-og-layer-width').val(), 20, 1200);
		var layerHeight = clamp($('#wp-remote-og-layer-height').val(), 20, 630);

		if ('image' === layer.type) {
			var bounds = constrainedLayerResizeBounds(layer, layerWidth, layerHeight);
			layer.width = bounds.width;
			layer.height = bounds.height;
		} else if ('line' === layer.type) {
			layer.width = clamp($('#wp-remote-og-layer-width').val(), layerMinWidth(layer), 1200 - layer.x);
			layer.height = clamp($('#wp-remote-og-layer-height').val(), layerMinHeight(layer), 630 - layer.y);
		} else {
			layer.width = layerWidth;
			layer.height = layerHeight;
		}

		if (JSON.stringify(layer) !== beforeLayer) {
			pushHistory(beforeSnapshot);
			syncDirty();
		}

		renderLayerList();
		renderCanvas();
		if ('wp-remote-og-layer-type' === changedFieldId || 'wp-remote-og-layer-line-orientation' === changedFieldId) {
			fillControls(layer);
		}
		if ('wp-remote-og-layer-requires-token' === changedFieldId && $('#wp-remote-og-preview-post').val()) {
			refreshPreview();
		}
	}

	function addLayer() {
		recordHistory();
		var id = 'layer-' + Date.now();
		state.template.layers.push({
			id: id,
			type: 'text',
			content: '{post_title}',
			label: 'Text Layer',
			x: 120,
			y: 120,
			width: 720,
			height: 140,
			font_family: 'system',
			font_id: '',
			font_size: 48,
			min_font_size: 24,
			color: '#111827',
			align: 'left',
			line_height: 1.1,
			max_lines: 2,
			requires_token: ''
		});
		selectLayer(id);
	}

	function addImageLayer() {
		recordHistory();
		var id = 'layer-' + Date.now();
		state.template.layers.push({
			id: id,
			type: 'image',
			content: '',
			label: 'Image Layer',
			x: 120,
			y: 120,
			width: 160,
			height: 160,
			image_shape: 'square',
			image_fit: 'contain',
			image_aspect_ratio: 1,
			font_family: 'system',
			font_id: '',
			font_size: 48,
			min_font_size: 24,
			color: '#111827',
			align: 'left',
			line_height: 1.1,
			max_lines: 2,
			requires_token: ''
		});
		selectLayer(id);
	}

	function addLineLayer(orientation) {
		recordHistory();
		orientation = 'vertical' === orientation ? 'vertical' : 'horizontal';
		var id = 'layer-' + Date.now();
		var isVertical = 'vertical' === orientation;
		state.template.layers.push({
			id: id,
			type: 'line',
			content: '',
			label: isVertical ? 'Vertical Line' : 'Horizontal Line',
			x: isVertical ? 120 : 90,
			y: isVertical ? 90 : 520,
			width: isVertical ? 6 : 420,
			height: isVertical ? 260 : 6,
			line_orientation: orientation,
			image_shape: 'square',
			font_family: 'system',
			font_id: '',
			font_size: 48,
			min_font_size: 24,
			color: '#64748b',
			align: 'left',
			line_height: 1.1,
			max_lines: 2,
			requires_token: ''
		});
		selectLayer(id);
	}

	function duplicateLayer() {
		var layer = currentLayer();
		if (!layer) {
			return;
		}
		recordHistory();
		var copy = clone(layer);
		copy.id = 'layer-' + Date.now();
		copy.x = Math.round(clamp(layer.x + 20, 0, 1200 - layer.width));
		copy.y = Math.round(clamp(layer.y + 20, 0, 630 - layer.height));
		var index = state.template.layers.findIndex(function (item) {
			return item.id === layer.id;
		});
		state.template.layers.splice(index + 1, 0, copy);
		selectLayer(copy.id);
	}

	function selectLayerImage() {
		var layer = currentLayer();
		if (!layer || 'image' !== layer.type || !window.wp || !wp.media) {
			return;
		}

		if (state.mediaFrame) {
			state.mediaFrame.remove();
		}

		state.mediaFrame = wp.media({
			title: 'Select icon/image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false
		});

		state.mediaFrame.on('select', function () {
			var attachment = state.mediaFrame.state().get('selection').first().toJSON();
			if (!attachment || !attachment.url) {
				state.mediaFrame = null;
				return;
			}
			// Media selection must be undoable: capture a pre-change snapshot,
			// apply the mutation, and only record history / mark dirty if the
			// layer actually changed (reselecting the same image is a no-op).
			var beforeSnapshot = snapshotTemplate();
			if (ES.applyMediaSelection(layer, attachment)) {
				pushHistory(beforeSnapshot);
				syncDirty();
			}
			$('#wp-remote-og-layer-content').val(layer.content);
			fillControls(layer);
			renderLayerList();
			renderCanvas();
			state.mediaFrame = null;
		});

		state.mediaFrame.open();
	}

	// Persist the active template. `options.newName` triggers a save-as (creates
	// a linked custom record); otherwise a linked customId updates its record in
	// place. Callers never invoke this for an unlinked, unnamed design: saveTemplate
	// prompts for a name first so there is no silent plain-save path in the UI.
	function persistTemplate(options) {
		options = options || {};
		var status = $('#wp-remote-og-status');
		var button = $('#wp-remote-og-save-template');
		// Capture the exact state being persisted at click time. Edits made
		// while the request is in flight must not be clobbered by the response,
		// and must not be falsely marked clean.
		var submitted = ES.clone(state.template);
		var payload = {
			action: 'wp_remote_og_save_template',
			nonce: WPRemoteOG.nonce,
			template: submitted,
			custom_id: state.customId || ''
		};
		if (options.newName) {
			payload.custom_name = options.newName;
		}
		setStatusMessage(status, 'Saving...', 'busy');
		button.prop('disabled', true).addClass('is-busy');
		$.post(WPRemoteOG.ajaxUrl, payload).done(function (response) {
			if (response.success) {
				// Never replace the live template with the response: it may be
				// stale relative to edits made after the Save click. Only adopt
				// the submitted snapshot as the new saved baseline, then let the
				// value-based dirty check decide cleanliness.
				var resolved = ES.resolveSave(submitted, state.template);
				state.savedSnapshot = resolved.saved;
				// Adopt any newly created / confirmed custom link and refresh the
				// shared custom-template list so other views stay in sync.
				if (response.data && typeof response.data.customId !== 'undefined') {
					state.customId = response.data.customId;
					WPRemoteOG.activeCustomId = response.data.customId;
					updateSaveCopyVisibility();
				}
				if (response.data && Array.isArray(response.data.customTemplates)) {
					WPRemoteOG.customTemplates = response.data.customTemplates;
				}
				syncDirty();
				flashDirtyIndicator(WPRemoteOG.strings.savedShort || 'Saved', 'is-saved');
				renderLayerList();
				renderCanvas();
				setStatusMessage(status, options.newName ? (WPRemoteOG.strings.templateSaved || WPRemoteOG.strings.saved) : WPRemoteOG.strings.saved, 'success');
			} else {
				flashDirtyIndicator(WPRemoteOG.strings.saveError || 'Save failed', 'is-error');
				setStatusMessage(status, response.data && response.data.message ? response.data.message : 'Unable to save template.', 'error');
			}
		}).fail(function () {
			flashDirtyIndicator(WPRemoteOG.strings.saveError || 'Save failed', 'is-error');
			setStatusMessage(status, 'Unable to save template.', 'error');
		}).always(function () {
			button.prop('disabled', false).removeClass('is-busy');
		});
	}

	// Open the accessible naming modal pre-filled with a unique default. On
	// confirm it save-as under the entered name (create + link); on cancel/Escape
	// nothing is persisted and focus returns to the Save button, leaving the
	// design's dirty state untouched.
	function openNamePrompt(promptOpts) {
		promptOpts = promptOpts || {};
		var strings = (window.WPRemoteOG && WPRemoteOG.strings) || {};
		var names = (WPRemoteOG.customTemplates || []).map(function (t) { return t.name; });
		openNameModal({
			title: promptOpts.title || strings.saveAsTitle || 'Save as reusable template',
			desc: promptOpts.desc || strings.saveAsPrompt || '',
			value: ES.generateDefaultName(names, strings.defaultTemplateName || 'My Template'),
			confirmLabel: promptOpts.confirmLabel || strings.save || 'Save',
			cancelLabel: strings.cancel || 'Cancel',
			restoreFocusTo: promptOpts.restoreFocusTo || '#wp-remote-og-save-template',
			onConfirm: function (name) {
				persistTemplate({ newName: name });
			}
		});
	}

	// Overflow "Save a copy…" (only shown for a linked design): create a brand
	// new My Templates record from the current design and link the active editor
	// to that copy. Uses the same transactional custom_name create path.
	function saveCopy() {
		var strings = (window.WPRemoteOG && WPRemoteOG.strings) || {};
		openNamePrompt({
			title: strings.saveCopyTitle || 'Save a copy',
			desc: strings.saveCopyPrompt || strings.saveAsPrompt || '',
			confirmLabel: strings.saveCopy || 'Save a copy',
			restoreFocusTo: '#wp-remote-og-save-as-template'
		});
	}

	// Show the overflow "Save a copy…" item only for a linked design; an unlinked
	// design's primary Save already creates the first record, so a copy is
	// meaningless (and would risk a confusing second obvious save path).
	function updateSaveCopyVisibility() {
		$('#wp-remote-og-save-as-template').prop('hidden', !state.customId);
	}

	function saveTemplate() {
		// One obvious primary Save. An unlinked, unnamed design must be named and
		// created in My Templates (no silent plain save); a linked design updates
		// its record in place.
		var target = ES.resolveSaveTarget({ customId: state.customId });
		if ('prompt' === target.mode) {
			openNamePrompt({ restoreFocusTo: '#wp-remote-og-save-template' });
			return;
		}
		persistTemplate();
	}

	function refreshPreview() {
		var postId = $('#wp-remote-og-preview-post').val();
		var warningsBox = $('#wp-remote-og-preview-warnings');
		if (!postId) {
			state.preview = {};
			setStatusMessage(warningsBox, '', '');
			renderCanvas();
			return;
		}

		setStatusMessage(warningsBox, 'Loading preview...', 'busy');
		$.post(WPRemoteOG.ajaxUrl, {
			action: 'wp_remote_og_preview',
			nonce: WPRemoteOG.nonce,
			post_id: postId,
			template: state.template
		}).done(function (response) {
			if (!response.success) {
				setStatusMessage(warningsBox, response.data && response.data.message ? response.data.message : 'Preview failed.', 'error');
				return;
			}
			state.preview = response.data.layers || {};
			var warnings = response.data.warnings || [];
			setStatusMessage(warningsBox, warnings.join(' | '), '');
			renderCanvas();
		}).fail(function () {
			setStatusMessage(warningsBox, 'Preview failed.', 'error');
		});
	}

	function selectBackground() {
		if (!window.wp || !wp.media) {
			return;
		}
		var frame = wp.media({
			title: 'Select OG background',
			button: { text: 'Use this background' },
			multiple: false
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			recordHistory();
			state.template.background = {
				id: attachment.id,
				url: attachment.url
			};
			renderCanvas();
		});
		frame.open();
	}

	function initEditor() {
		if (!$('#wp-remote-og-canvas').length || !window.WPRemoteOG) {
			return;
		}

		injectFontFaces();
		state.customId = WPRemoteOG.activeCustomId || '';
		state.template = clone(WPRemoteOG.template);
		if (!state.template.layers) {
			state.template.layers = [];
		}
		// The freshly loaded template is the persisted baseline.
		state.savedSnapshot = clone(state.template);
		if (state.template.layers.length) {
			state.selectedLayerId = state.template.layers[0].id;
		}

		$('.wp-remote-og-color').wpColorPicker({
			change: function () {
				// updateSelectedLayerFromControls is value-based, so it is safe to call
				// from the picker's debounced callback: a no-op change (e.g. while
				// fillControls populated the swatch) will not dirty the template.
				setTimeout(updateSelectedLayerFromControls, 0);
			}
		});

		$('#wp-remote-og-add-layer').on('click', addLayer);
		$('#wp-remote-og-save-template').on('click', saveTemplate);
		$('#wp-remote-og-save-as-template').on('click', saveCopy);
		updateSaveCopyVisibility();
		$('#wp-remote-og-background').on('click', selectBackground);
		$('#wp-remote-og-add-image-layer').on('click', addImageLayer);
		$('#wp-remote-og-add-horizontal-line').on('click', function () {
			addLineLayer('horizontal');
		});
		$('#wp-remote-og-add-vertical-line').on('click', function () {
			addLineLayer('vertical');
		});
		$('#wp-remote-og-refresh-preview').on('click', refreshPreview);
		$('#wp-remote-og-preview-post').on('change', refreshPreview);
		$('#wp-remote-og-layer-list').on('click', 'li', function () {
			selectLayer($(this).data('layer-id'));
		});
		$('#wp-remote-og-layer-list').on('keydown', 'li', function (event) {
			if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
				event.preventDefault();
				var items = $('#wp-remote-og-layer-list li');
				var index = items.index(this);
				var next = 'ArrowDown' === event.key ? index + 1 : index - 1;
				if (next < 0) {
					next = items.length - 1;
				} else if (next >= items.length) {
					next = 0;
				}
				var target = items.eq(next);
				if (target.length) {
					selectLayer(target.data('layer-id'));
					$('#wp-remote-og-layer-list li[data-layer-id="' + target.data('layer-id') + '"]').trigger('focus');
				}
				return;
			}
			if ('Enter' !== event.key && ' ' !== event.key && 'Spacebar' !== event.key) {
				return;
			}
			event.preventDefault();
			var id = $(this).data('layer-id');
			selectLayer(id);
			$('#wp-remote-og-layer-list li[data-layer-id="' + id + '"]').trigger('focus');
		});
		$('#wp-remote-og-token-picker').on('change', function () {
			var layer = currentLayer();
			if (!layer) {
				$(this).val('');
				return;
			}
			var token = $(this).val();
			if (!token) {
				return;
			}
			if ('line' === layer.type) {
				$(this).val('');
				return;
			}
			var input = $('#wp-remote-og-layer-content');
			if ('image' === layer.type) {
				input.val(token);
			} else {
				input.val((input.val() ? input.val() + ' ' : '') + token);
			}
			updateSelectedLayerFromControls();
			$(this).val('');
			if ('image' === layer.type && $('#wp-remote-og-preview-post').val()) {
				refreshPreview();
			}
		});
		$('.wp-remote-og-controls').on('input change', 'input, select', function () {
			if ('wp-remote-og-layer-type' === this.id || 'wp-remote-og-layer-content' === this.id || 'wp-remote-og-layer-requires-token' === this.id || 'wp-remote-og-layer-font' === this.id || 'wp-remote-og-layer-font-size' === this.id || 'wp-remote-og-layer-min-font-size' === this.id || 'wp-remote-og-layer-color' === this.id || 'wp-remote-og-layer-align' === this.id || 'wp-remote-og-layer-line-height' === this.id || 'wp-remote-og-layer-max-lines' === this.id || 'wp-remote-og-layer-x' === this.id || 'wp-remote-og-layer-y' === this.id || 'wp-remote-og-layer-width' === this.id || 'wp-remote-og-layer-height' === this.id || 'wp-remote-og-layer-image-shape' === this.id || 'wp-remote-og-layer-image-fit' === this.id || 'wp-remote-og-layer-line-orientation' === this.id) {
				updateSelectedLayerFromControls(this.id);
			}
		});
		$('#wp-remote-og-layer-select-image').on('click', selectLayerImage);
		$('#wp-remote-og-duplicate-layer').on('click', function () {
			closeOverflowMenus();
			duplicateLayer();
		});
		$('#wp-remote-og-delete-layer').on('click', function () {
			closeOverflowMenus();
			if (!state.selectedLayerId || state.template.layers.length <= 1) {
				return;
			}
			recordHistory();
			state.template.layers = state.template.layers.filter(function (layer) {
				return layer.id !== state.selectedLayerId;
			});
			state.selectedLayerId = state.template.layers[0].id;
			selectLayer(state.selectedLayerId);
		});
		$('#wp-remote-og-undo').on('click', undoHistory);
		$('#wp-remote-og-redo').on('click', redoHistory);

		initOverflowMenus();

		$(document).on('keydown.wpRemoteOgEditor', function (event) {
			var key = event.key ? event.key.toLowerCase() : '';
			if (!(event.ctrlKey || event.metaKey) || 'z' !== key && 'y' !== key) {
				return;
			}
			var tag = event.target && event.target.tagName ? event.target.tagName.toLowerCase() : '';
			if ('input' === tag || 'textarea' === tag || 'select' === tag) {
				return;
			}
			event.preventDefault();
			if ('y' === key || (event.shiftKey && 'z' === key)) {
				redoHistory();
			} else {
				undoHistory();
			}
		});

		rebuildTokenPicker();
		$(window).on('resize', applyCanvasScale);

		markDirty(false);
		renderLayerList();
		renderCanvas();
		fillControls(currentLayer());
		updateHistoryButtons();
		state.ready = true;
	}

	// The overflow menu currently open (its trigger + menu jQuery objects), so we
	// can reposition it on scroll/resize. Cleared when all menus close.
	var openOverflow = null;

	// The admin content-area rectangle the overflow menus must stay inside. WP's
	// collapsible sidebar occupies the left edge; #wpcontent is the content column
	// to its right, so its getBoundingClientRect gives the true left boundary.
	// Falls back to the editor app container and finally the viewport.
	function adminContentBoundary() {
		var node = document.getElementById('wpcontent') ||
			document.querySelector('.wp-remote-og-app') ||
			document.getElementById('wpbody-content');
		if (node && node.getBoundingClientRect) {
			var rect = node.getBoundingClientRect();
			if (rect.width > 0 && rect.height > 0) {
				return { left: rect.left, top: rect.top, right: rect.right, bottom: rect.bottom };
			}
		}
		return null;
	}

	// Collision-aware placement: measure the trigger and menu in viewport
	// coordinates and delegate the flip/clamp math to the pure ES helper, then
	// pin the menu with position:fixed so it never clips under the WP admin
	// sidebar or off the edge of the screen.
	function positionOverflowMenu(toggle, menu) {
		var el = menu.get(0);
		if (!el || !toggle.get(0)) {
			return;
		}
		// Clear any prior fixed placement so the menu measures at its natural size.
		el.style.left = '';
		el.style.top = '';
		el.style.right = 'auto';
		el.style.position = 'fixed';

		var triggerRect = toggle.get(0).getBoundingClientRect();
		var menuRect = el.getBoundingClientRect();
		var pos = ES.resolveMenuPlacement({
			triggerRect: triggerRect,
			menuSize: { width: menuRect.width, height: menuRect.height },
			viewport: { width: window.innerWidth, height: window.innerHeight },
			boundary: adminContentBoundary()
		});

		el.style.left = pos.left + 'px';
		el.style.top = pos.top + 'px';
		el.style.right = 'auto';
		menu.toggleClass('is-open-up', pos.openUp);
		menu.toggleClass('is-align-left', pos.alignLeft);
	}

	function repositionOpenOverflow() {
		if (openOverflow && !openOverflow.menu.prop('hidden')) {
			positionOverflowMenu(openOverflow.toggle, openOverflow.menu);
		}
	}

	function closeOverflowMenus(except) {
		$('.wpog-overflow').each(function () {
			if (except && this === except) {
				return;
			}
			$(this).find('.wpog-overflow-menu').prop('hidden', true);
			$(this).find('[data-overflow-toggle]').attr('aria-expanded', 'false');
		});
		if (!except) {
			openOverflow = null;
		}
	}

	function overflowMenuItems(menu) {
		return menu.find('.wpog-overflow-item').filter(':visible');
	}

	function initOverflowMenus() {
		$(document).on('click', '[data-overflow-toggle]', function (event) {
			event.preventDefault();
			event.stopPropagation();
			var wrap = $(this).closest('.wpog-overflow');
			var menu = wrap.find('.wpog-overflow-menu');
			var willOpen = menu.prop('hidden');
			closeOverflowMenus(wrap[0]);
			menu.prop('hidden', !willOpen);
			$(this).attr('aria-expanded', willOpen ? 'true' : 'false');
			if (willOpen) {
				openOverflow = { toggle: $(this), menu: menu };
				positionOverflowMenu($(this), menu);
				overflowMenuItems(menu).first().trigger('focus');
			} else {
				openOverflow = null;
			}
		});
		// Keep the open menu pinned to its trigger, and close it if the layout
		// shifts dramatically, matching native menu behavior.
		$(window).on('resize.wpRemoteOgOverflow scroll.wpRemoteOgOverflow', function () {
			repositionOpenOverflow();
		});
		$(document).on('scroll.wpRemoteOgOverflow', '.wpog-panel, .wpog-workspace', function () {
			repositionOpenOverflow();
		});
		$(document).on('keydown', '[data-overflow-toggle]', function (event) {
			if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
				event.preventDefault();
				var menu = $(this).closest('.wpog-overflow').find('.wpog-overflow-menu');
				if (menu.prop('hidden')) {
					$(this).trigger('click');
					return;
				}
				var items = overflowMenuItems(menu);
				items.eq('ArrowDown' === event.key ? 0 : items.length - 1).trigger('focus');
			}
		});
		$(document).on('keydown', '.wpog-overflow-menu', function (event) {
			if ('ArrowDown' !== event.key && 'ArrowUp' !== event.key) {
				return;
			}
			event.preventDefault();
			var items = overflowMenuItems($(this));
			if (!items.length) {
				return;
			}
			var index = items.index(document.activeElement);
			var next = 'ArrowDown' === event.key ? index + 1 : index - 1;
			if (next < 0) {
				next = items.length - 1;
			} else if (next >= items.length) {
				next = 0;
			}
			items.eq(next).trigger('focus');
		});
		$(document).on('click', '.wpog-overflow-item', function () {
			closeOverflowMenus();
		});
		$(document).on('click.wpRemoteOgOverflow', function (event) {
			if (!$(event.target).closest('.wpog-overflow').length) {
				closeOverflowMenus();
			}
		});
		$(document).on('keydown.wpRemoteOgOverflow', function (event) {
			if ('Escape' !== event.key) {
				return;
			}
			var openWrap = $('.wpog-overflow').filter(function () {
				return !$(this).find('.wpog-overflow-menu').prop('hidden');
			}).first();
			closeOverflowMenus();
			if (openWrap.length) {
				openWrap.find('[data-overflow-toggle]').trigger('focus');
			}
		});
	}

	function initFields() {
		var rows = $('#wp-remote-og-field-rows');
		if (!rows.length) {
			return;
		}

		function addFieldRow(field) {
			field = field || {};
			var index = rows.find('tr').length + '-' + Date.now();
			var checked = field.enabled ? ' checked' : '';
			var token = (field.token || '').replace(/"/g, '&quot;');
			var label = (field.label || '').replace(/"/g, '&quot;');
			var fallback = (field.fallback || '').replace(/"/g, '&quot;');
			rows.append(
				'<tr>' +
				'<td><input type="checkbox" name="fields[' + index + '][enabled]" value="1"' + checked + '></td>' +
				'<td><input type="text" name="fields[' + index + '][token]" value="' + token + '" list="wp-remote-og-field-token-list" class="regular-text"></td>' +
				'<td><input type="text" name="fields[' + index + '][label]" value="' + label + '" class="regular-text"></td>' +
				'<td><input type="text" name="fields[' + index + '][fallback]" value="' + fallback + '" class="regular-text"></td>' +
				'<td><button type="button" class="button wp-remote-og-remove-row">Remove</button></td>' +
				'</tr>'
			);
		}

		function fillTokenList() {
			var datalist = $('#wp-remote-og-field-token-list');
			if (!datalist.length) {
				return;
			}

			datalist.empty();
			mergeEnabledFieldsAndTokens().forEach(function (token) {
				if (!token || !token.token) {
					return;
				}
				$('<option/>', {
					value: token.token,
					text: token.label || token.token
				}).appendTo(datalist);
			});
		}

		function existingTokens() {
			var map = {};
			rows.find('input[name$="[token]"]').each(function () {
				var token = $.trim($(this).val());
				if (token) {
					map[token] = true;
				}
			});
			return map;
		}

		$('#wp-remote-og-add-field-row').on('click', function () {
			var existing = existingTokens();
			var token = '';
			var label = '';
			var fields = mergeEnabledFieldsAndTokens();
			for (var i = 0; i < fields.length; i++) {
				if (!fields[i] || !fields[i].token || existing[fields[i].token]) {
					continue;
				}
				token = fields[i].token;
				label = fields[i].label || '';
				break;
			}
			addFieldRow({
				enabled: true,
				token: token,
				label: label,
				fallback: ''
			});
		});

		$('#wp-remote-og-fill-available-fields').on('click', function () {
			var existing = existingTokens();
			mergeEnabledFieldsAndTokens().forEach(function (token) {
				if (!token || !token.token || existing[token.token]) {
					return;
				}
				addFieldRow({
					enabled: true,
					token: token.token,
					label: token.label || '',
					fallback: ''
				});
				existing[token.token] = true;
			});
		});

		rows.on('click', '.wp-remote-og-remove-row', function () {
			$(this).closest('tr').remove();
		});

		fillTokenList();
	}

	function generatePost(button) {
		var postId = button.data('post-id');
		var status = button.closest('.wp-remote-og-post-box').find('.wp-remote-og-post-status');
		setStatusMessage(status, WPRemoteOG.strings.generating, 'busy');
		button.prop('disabled', true);

		$.post(WPRemoteOG.ajaxUrl, {
			action: 'wp_remote_og_generate_post',
			nonce: WPRemoteOG.nonce,
			post_id: postId
		}).done(function (response) {
			if (response.success) {
				setStatusMessage(status, '', 'success');
				status.html('Generated: <a href="' + response.data.url + '" target="_blank" rel="noopener noreferrer">open image</a>');
			} else {
				setStatusMessage(status, response.data && response.data.message ? response.data.message : 'Generation failed.', 'error');
			}
		}).fail(function () {
			setStatusMessage(status, 'Generation failed.', 'error');
		}).always(function () {
			button.prop('disabled', false);
		});
	}

	function initPostBox() {
		$(document).on('click', '.wp-remote-og-generate-post', function () {
			generatePost($(this));
		});
	}

	function initBulkTools() {
		var progress = $('#wp-remote-og-progress');
		if (!progress.length) {
			return;
		}

		$('.wp-remote-og-bulk').on('click', function () {
			var mode = $(this).data('mode');
			if ('all' === mode && !window.confirm('Regenerate OG images for ALL posts? This may take a while.')) {
				return;
			}
			setStatusMessage(progress, 'Preparing posts...', 'busy');
			$.post(WPRemoteOG.ajaxUrl, {
				action: 'wp_remote_og_bulk_ids',
				nonce: WPRemoteOG.nonce,
				mode: mode
			}).done(function (response) {
				if (!response.success) {
					setStatusMessage(progress, 'Unable to prepare posts.', 'error');
					return;
				}
				processBulk(response.data.ids || [], 0, 0, mode);
			}).fail(function () {
				setStatusMessage(progress, 'Unable to prepare posts.', 'error');
			});
		});

		$('#wp-remote-og-cleanup-orphans').on('click', function () {
			if (!window.confirm('Delete orphaned OG images from the uploads folder? This cannot be undone.')) {
				return;
			}
			setStatusMessage(progress, 'Cleaning orphaned files...', 'busy');
			$.post(WPRemoteOG.ajaxUrl, {
				action: 'wp_remote_og_cleanup_orphans',
				nonce: WPRemoteOG.nonce
			}).done(function (response) {
				if (response.success) {
					setStatusMessage(progress, 'Deleted ' + response.data.deleted + ' orphaned files. Kept ' + response.data.kept + '.', 'success');
				} else {
					setStatusMessage(progress, response.data && response.data.message ? response.data.message : 'Cleanup failed.', 'error');
				}
			}).fail(function () {
				setStatusMessage(progress, 'Cleanup failed.', 'error');
			});
		});
	}

	function renderBulkProgress(done, total) {
		var progress = $('#wp-remote-og-progress');
		var bar = progress.find('.wp-remote-og-progress-bar');
		if (!bar.length) {
			progress.empty();
			$('<span/>', {
				'class': 'wp-remote-og-progress-label'
			}).appendTo(progress);
			bar = $('<div/>', {
				'class': 'wp-remote-og-progress-bar'
			}).appendTo(progress);
			$('<div/>', {
				'class': 'wp-remote-og-progress-bar-fill'
			}).appendTo(bar);
		}
		var percent = total ? Math.round((done / total) * 100) : 0;
		progress.removeClass('is-success is-error').addClass('is-busy');
		progress.find('.wp-remote-og-progress-label').text('Processing ' + done + ' of ' + total + ' posts...');
		progress.find('.wp-remote-og-progress-bar-fill').css('width', clamp(percent, 0, 100) + '%');
	}

	function processBulk(ids, done, errors, mode) {
		var progress = $('#wp-remote-og-progress');
		if (!ids.length) {
			setStatusMessage(progress, 'No posts need processing.', 'success');
			return;
		}

		var chunk = ids.slice(done, done + 5);
		if (!chunk.length) {
			if (mode === 'all') {
				$.post(WPRemoteOG.ajaxUrl, {
					action: 'wp_remote_og_clear_template_notice',
					nonce: WPRemoteOG.nonce
				});
			}
			setStatusMessage(progress, 'Finished. Processed ' + done + ' posts with ' + errors + ' errors.', errors ? 'error' : 'success');
			return;
		}

		renderBulkProgress(done, ids.length);
		$.post(WPRemoteOG.ajaxUrl, {
			action: 'wp_remote_og_bulk_process',
			nonce: WPRemoteOG.nonce,
			ids: chunk
		}).done(function (response) {
			var newErrors = errors;
			if (response.success && response.data.errors) {
				newErrors += response.data.errors.length;
			} else if (!response.success) {
				newErrors += chunk.length;
			}
			processBulk(ids, done + chunk.length, newErrors, mode);
		}).fail(function () {
			processBulk(ids, done + chunk.length, errors + chunk.length, mode);
		});
	}

	var PREVIEW_TOKEN_SAMPLES = {
		'{post_title}': 'Your Post Title Goes Here',
		'{taxonomy:job_location}': 'Location Name',
		'{taxonomy:job_type}': 'Category Name',
		'{acf:company_name}': 'Your Brand Name',
		'{acf:salary_range}': 'Highlight Detail'
	};

	function samplePreviewText(content) {
		if (!content) {
			return '';
		}
		var text = String(content);
		Object.keys(PREVIEW_TOKEN_SAMPLES).forEach(function (token) {
			text = text.split(token).join(PREVIEW_TOKEN_SAMPLES[token]);
		});
		return text.replace(/\{[^}]+\}/g, function (match) {
			return match.replace(/[{}]/g, '').split(':').pop().replace(/_/g, ' ');
		});
	}

	function buildPresetPreview(template, pxWidth) {
		var scale = pxWidth / 1200;
		var canvas = $('<div/>', {
			'class': 'wp-remote-og-preview-canvas'
		}).css({
			width: pxWidth + 'px',
			height: Math.round(630 * scale) + 'px'
		});

		if (!template || !Array.isArray(template.layers)) {
			return canvas;
		}

		template.layers.forEach(function (layer) {
			var node = $('<div/>', {
				'class': 'wp-remote-og-preview-layer'
			}).css({
				left: (layer.x * scale) + 'px',
				top: (layer.y * scale) + 'px',
				width: (layer.width * scale) + 'px',
				height: (layer.height * scale) + 'px'
			});

			if ('line' === layer.type) {
				node.css('background', layer.color || '#111827');
			} else if ('image' === layer.type) {
				node.addClass('is-image-placeholder');
			} else {
				node.css({
					color: layer.color || '#111827',
					fontSize: Math.max(6, (layer.font_size || 32) * scale) + 'px',
					lineHeight: layer.line_height || 1.1,
					textAlign: layer.align || 'left',
					overflow: 'hidden'
				});
				node.text(samplePreviewText(layer.content));
			}

			canvas.append(node);
		});

		return canvas;
	}

	// Shared, accessible name modal (save-as, rename). role=dialog + aria-modal
	// live in the markup; here we manage focus trap, Escape, and focus restore.
	var nameModalState = { onConfirm: null, onExtra: null, lastFocused: null, restoreFocusTo: null };

	function nameModalEl() {
		return $('#wp-remote-og-name-modal');
	}

	function nameModalFocusables() {
		return nameModalEl().find('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
	}

	function setNameError(message) {
		$('#wp-remote-og-name-error').text(message || '');
	}

	function closeNameModal() {
		var modal = nameModalEl();
		if (!modal.length || modal.prop('hidden')) {
			return;
		}
		modal.prop('hidden', true);
		var restore = nameModalState.restoreFocusTo;
		var last = nameModalState.lastFocused;
		nameModalState.onConfirm = null;
		nameModalState.onExtra = null;
		nameModalState.restoreFocusTo = null;
		var target = restore ? $(restore).get(0) : last;
		if (target && target.focus) {
			target.focus();
		}
	}

	function openNameModal(opts) {
		opts = opts || {};
		var strings = (window.WPRemoteOG && WPRemoteOG.strings) || {};
		var modal = nameModalEl();
		if (!modal.length) {
			return;
		}
		nameModalState.lastFocused = document.activeElement;
		nameModalState.onConfirm = opts.onConfirm || null;
		nameModalState.onExtra = opts.onExtra || null;
		nameModalState.restoreFocusTo = opts.restoreFocusTo || null;
		$('#wp-remote-og-name-modal-title').text(opts.title || '');
		$('#wp-remote-og-name-modal-desc').text(opts.desc || '');
		$('#wp-remote-og-name-confirm').text(opts.confirmLabel || strings.save || 'Save');
		var cancel = $('#wp-remote-og-name-cancel');
		cancel.text(opts.onExtra ? (opts.extraLabel || strings.justSave || 'Just save') : (opts.cancelLabel || strings.cancel || 'Cancel'));
		setNameError('');
		var input = $('#wp-remote-og-name-input');
		input.val(opts.value || '');
		modal.prop('hidden', false);
		input.trigger('focus');
		if (input.get(0) && input.get(0).select) {
			input.get(0).select();
		}
	}

	function confirmNameModal() {
		var strings = (window.WPRemoteOG && WPRemoteOG.strings) || {};
		var maxLen = (window.WPRemoteOG && WPRemoteOG.maxCustomNameLength) || 100;
		var check = ES.validateTemplateName($('#wp-remote-og-name-input').val(), maxLen);
		if (!check.valid) {
			setNameError('too_long' === check.reason ? (strings.nameTooLong || '') : (strings.nameRequired || ''));
			$('#wp-remote-og-name-input').trigger('focus');
			return;
		}
		var cb = nameModalState.onConfirm;
		closeNameModal();
		if (cb) {
			cb(check.value);
		}
	}

	function initNameModal() {
		var modal = nameModalEl();
		if (!modal.length) {
			return;
		}
		$('#wp-remote-og-name-confirm').on('click', confirmNameModal);
		$('#wp-remote-og-name-input').on('keydown', function (event) {
			if ('Enter' === event.key) {
				event.preventDefault();
				confirmNameModal();
			}
		});
		$('#wp-remote-og-name-cancel').on('click', function () {
			var extra = nameModalState.onExtra;
			closeNameModal();
			if (extra) {
				extra();
			}
		});
		modal.on('click', '[data-name-close]', function () {
			closeNameModal();
		});
		modal.on('keydown', function (event) {
			if ('Escape' === event.key) {
				closeNameModal();
				return;
			}
			if ('Tab' !== event.key) {
				return;
			}
			var focusables = nameModalFocusables();
			if (!focusables.length) {
				return;
			}
			var activeIndex = focusables.index(document.activeElement);
			var target = ES.focusTrapTarget(focusables.length, activeIndex, event.shiftKey);
			if (target > -1) {
				event.preventDefault();
				focusables[target].focus();
			}
		});
	}

	function initTemplateGallery() {
		var gallery = $('#wp-remote-og-gallery');
		if (!gallery.length || !window.WPRemoteOG || !Array.isArray(WPRemoteOG.presets)) {
			return;
		}

		var strings = WPRemoteOG.strings || {};
		var status = $('#wp-remote-og-gallery-status');
		var modal = $('#wp-remote-og-preset-modal');
		var activeCategory = 'all';
		var modalPreset = null;
		var lastFocused = null;

		function galleryStatus(message, type) {
			setStatusMessage(status, message, type);
		}

		function renderCards() {
			gallery.empty();
			var shown = 0;
			WPRemoteOG.presets.forEach(function (preset) {
				if ('all' !== activeCategory && preset.category !== activeCategory) {
					return;
				}
				shown++;
				var card = $('<div/>', {
					'class': 'wp-remote-og-preset-card',
					'data-preset': preset.key,
					role: 'listitem',
					'aria-label': preset.name + (preset.category ? ' (' + preset.category + ')' : '')
				});
				var thumb = $('<div/>', {
					'class': 'wp-remote-og-preset-thumb'
				});
				thumb.append(buildPresetPreview(preset.template, 300));
				card.append(thumb);
				var body = $('<div/>', {
					'class': 'wp-remote-og-preset-body'
				});
				$('<span/>', {
					'class': 'wp-remote-og-preset-category',
					text: preset.category
				}).appendTo(body);
				$('<h3/>', {
					'class': 'wp-remote-og-preset-name',
					text: preset.name
				}).appendTo(body);
				$('<p/>', {
					'class': 'wp-remote-og-preset-desc',
					text: preset.description
				}).appendTo(body);
				var actions = $('<div/>', {
					'class': 'wp-remote-og-preset-actions'
				});
				$('<button/>', {
					type: 'button',
					'class': 'button',
					text: 'Preview',
					'data-action': 'preview',
					'aria-label': 'Preview ' + preset.name + ' template'
				}).appendTo(actions);
				$('<button/>', {
					type: 'button',
					'class': 'button button-primary',
					text: 'Apply',
					'data-action': 'apply',
					'aria-label': 'Apply ' + preset.name + ' template'
				}).appendTo(actions);
				body.append(actions);
				card.append(body);
				gallery.append(card);
			});
			return shown;
		}

		function announceFilter(count) {
			var label = 1 === count ? '1 template' : count + ' templates';
			if ('all' !== activeCategory) {
				label += ' in ' + activeCategory;
			}
			galleryStatus(label, '');
		}

		function modalFocusables() {
			return modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
		}

		function openModal(preset) {
			modalPreset = preset;
			lastFocused = document.activeElement;
			$('#wp-remote-og-preset-modal-title').text(preset.name);
			modal.find('.wp-remote-og-preset-modal-desc').text(preset.description);
			var previewWrap = modal.find('.wp-remote-og-preset-modal-preview').empty();
			previewWrap.append(buildPresetPreview(preset.template, 640));
			modal.find('.wp-remote-og-preset-modal-note').text(strings.previewNote || '');
			$('#wp-remote-og-preset-apply').text(strings.apply || 'Apply template');
			modal.prop('hidden', false);
			$('#wp-remote-og-preset-apply').trigger('focus');
		}

		function closeModal() {
			if (modal.prop('hidden')) {
				return;
			}
			modal.prop('hidden', true);
			modalPreset = null;
			if (lastFocused && lastFocused.focus) {
				lastFocused.focus();
			}
		}

		modal.on('keydown', function (event) {
			if ('Tab' !== event.key || modal.prop('hidden')) {
				return;
			}
			var focusables = modalFocusables();
			if (!focusables.length) {
				return;
			}
			var activeIndex = focusables.index(document.activeElement);
			var target = ES.focusTrapTarget(focusables.length, activeIndex, event.shiftKey);
			if (target > -1) {
				event.preventDefault();
				focusables[target].focus();
			}
		});

		function presetByKey(key) {
			return WPRemoteOG.presets.filter(function (item) {
				return item.key === key;
			})[0] || null;
		}

		function applyPreset(preset, button) {
			if (!preset) {
				return;
			}
			if (!window.confirm(strings.applyConfirm || 'Apply this template? It replaces your current template.')) {
				return;
			}
			if (button) {
				button.prop('disabled', true);
			}
			galleryStatus('Applying template...', 'busy');
			$.post(WPRemoteOG.ajaxUrl, {
				action: 'wp_remote_og_apply_preset',
				nonce: WPRemoteOG.nonce,
				preset: preset.key
			}).done(function (response) {
				if (response && response.success) {
					galleryStatus(strings.applied || 'Template applied.', 'success');
					$('.wp-remote-og-restore-notice').prop('hidden', false);
					closeModal();
				} else {
					galleryStatus(response && response.data && response.data.message ? response.data.message : (strings.applyFailed || 'Unable to apply the template.'), 'error');
				}
			}).fail(function () {
				galleryStatus(strings.applyFailed || 'Unable to apply the template.', 'error');
			}).always(function () {
				if (button) {
					button.prop('disabled', false);
				}
			});
		}

		gallery.on('click', '.wp-remote-og-preset-card [data-action]', function () {
			var key = $(this).closest('.wp-remote-og-preset-card').data('preset');
			var preset = presetByKey(key);
			if (!preset) {
				return;
			}
			if ('preview' === $(this).data('action')) {
				openModal(preset);
			} else {
				applyPreset(preset, $(this));
			}
		});

		$('.wp-remote-og-gallery-filters').on('click', '.wpog-filter-pill', function () {
			activeCategory = $(this).data('category');
			$('.wpog-filter-pill').removeClass('is-active').attr('aria-pressed', 'false');
			$(this).addClass('is-active').attr('aria-pressed', 'true');
			announceFilter(renderCards());
		});

		// ---- My Templates (custom, reusable) ----------------------------------
		var customGallery = $('#wp-remote-og-custom-gallery');
		var customStatus = $('#wp-remote-og-custom-status');

		function customStatusMsg(message, type) {
			setStatusMessage(customStatus, message, type);
		}

		function customById(id) {
			return (WPRemoteOG.customTemplates || []).filter(function (rec) {
				return rec.id === id;
			})[0] || null;
		}

		function renderCustomCards() {
			if (!customGallery.length) {
				return;
			}
			customGallery.empty();
			var list = WPRemoteOG.customTemplates || [];
			if (!list.length) {
				$('<p/>', { 'class': 'wpog-custom-empty', text: strings.customEmpty || 'You have not saved any templates yet.' }).appendTo(customGallery);
				return;
			}
			list.forEach(function (rec) {
				var card = $('<div/>', {
					'class': 'wp-remote-og-preset-card wp-remote-og-custom-card',
					'data-id': rec.id,
					role: 'listitem',
					'aria-label': rec.name
				});
				// Boot/mutation payloads carry metadata only (no template body), so
				// the card shows a lightweight placeholder; the full design is fetched
				// on demand when the user previews or applies it.
				var thumb = $('<div/>', { 'class': 'wp-remote-og-preset-thumb wp-remote-og-custom-thumb' });
				$('<span/>', { 'class': 'wp-remote-og-custom-thumb-initial', text: (rec.name || '?').trim().charAt(0).toUpperCase() || '?' }).appendTo(thumb);
				card.append(thumb);
				var body = $('<div/>', { 'class': 'wp-remote-og-preset-body' });
				$('<h3/>', { 'class': 'wp-remote-og-preset-name', text: rec.name }).appendTo(body);
				if (rec.updated_at) {
					$('<span/>', { 'class': 'wp-remote-og-preset-category', text: (strings.updatedLabel || 'Updated') + ' ' + rec.updated_at }).appendTo(body);
				}
				var actions = $('<div/>', { 'class': 'wp-remote-og-preset-actions' });
				$('<button/>', { type: 'button', 'class': 'button', text: strings.preview || 'Preview', 'data-custom-action': 'preview', 'aria-label': (strings.preview || 'Preview') + ' ' + rec.name }).appendTo(actions);
				$('<button/>', { type: 'button', 'class': 'button button-primary', text: strings.applyEdit || 'Apply & edit', 'data-custom-action': 'apply', 'aria-label': (strings.applyEdit || 'Apply & edit') + ' ' + rec.name }).appendTo(actions);
				$('<button/>', { type: 'button', 'class': 'button', text: strings.rename || 'Rename', 'data-custom-action': 'rename', 'aria-label': (strings.rename || 'Rename') + ' ' + rec.name }).appendTo(actions);
				$('<button/>', { type: 'button', 'class': 'button', text: strings.duplicate || 'Duplicate', 'data-custom-action': 'duplicate', 'aria-label': (strings.duplicate || 'Duplicate') + ' ' + rec.name }).appendTo(actions);
				$('<button/>', { type: 'button', 'class': 'button is-link-danger wpog-danger', text: strings.delete || 'Delete', 'data-custom-action': 'delete', 'aria-label': (strings.delete || 'Delete') + ' ' + rec.name }).appendTo(actions);
				body.append(actions);
				card.append(body);
				customGallery.append(card);
			});
		}

		function customPost(action, data, onOk) {
			customStatusMsg(strings.generating || 'Working…', 'busy');
			$.post(WPRemoteOG.ajaxUrl, $.extend({ action: action, nonce: WPRemoteOG.nonce }, data)).done(function (response) {
				if (response && response.success) {
					if (response.data && Array.isArray(response.data.customTemplates)) {
						WPRemoteOG.customTemplates = response.data.customTemplates;
						renderCustomCards();
					}
					onOk(response.data || {});
				} else {
					customStatusMsg(response && response.data && response.data.message ? response.data.message : (strings.applyFailed || 'Something went wrong.'), 'error');
				}
			}).fail(function () {
				customStatusMsg(strings.applyFailed || 'Something went wrong.', 'error');
			});
		}

		// Fetch a single custom-template body on demand (boot data is metadata-only).
		function fetchCustomBody(id, onOk) {
			customStatusMsg(strings.generating || 'Working…', 'busy');
			$.post(WPRemoteOG.ajaxUrl, {
				action: 'wp_remote_og_get_custom_template',
				nonce: WPRemoteOG.nonce,
				id: id
			}).done(function (response) {
				if (response && response.success && response.data && response.data.record) {
					customStatusMsg('', '');
					onOk(response.data.record);
				} else {
					customStatusMsg(response && response.data && response.data.message ? response.data.message : (strings.applyFailed || 'Something went wrong.'), 'error');
				}
			}).fail(function () {
				customStatusMsg(strings.applyFailed || 'Something went wrong.', 'error');
			});
		}

		function applyCustomTemplate(id, button) {
			if (!window.confirm(strings.applyConfirm || 'Apply this template?')) {
				return;
			}
			if (button) {
				button.prop('disabled', true);
			}
			customPost('wp_remote_og_apply_custom_template', { id: id }, function () {
				customStatusMsg(strings.applied || 'Template applied.', 'success');
				$('.wp-remote-og-restore-notice').prop('hidden', false);
				closeModal();
				if (WPRemoteOG.editorUrl) {
					window.location.href = WPRemoteOG.editorUrl;
				}
			});
		}

		customGallery.on('click', '[data-custom-action]', function () {
			var id = $(this).closest('.wp-remote-og-custom-card').data('id');
			var rec = customById(id);
			if (!rec) {
				return;
			}
			var action = $(this).data('custom-action');
			if ('preview' === action) {
				fetchCustomBody(id, function (full) {
					openModal({ name: full.name, description: '', template: full.template, customId: full.id });
				});
			} else if ('apply' === action) {
				applyCustomTemplate(id, $(this));
			} else if ('rename' === action) {
				openNameModal({
					title: strings.renamePrompt || 'Rename template',
					value: rec.name,
					confirmLabel: strings.save || 'Save',
					cancelLabel: strings.cancel || 'Cancel',
					onConfirm: function (name) {
						customPost('wp_remote_og_rename_custom_template', { id: id, name: name }, function () {
							customStatusMsg('', '');
						});
					}
				});
			} else if ('duplicate' === action) {
				customPost('wp_remote_og_duplicate_custom_template', { id: id }, function () {
					customStatusMsg('', '');
				});
			} else if ('delete' === action) {
				if (!window.confirm(strings.deleteConfirm || 'Delete this template?')) {
					return;
				}
				customPost('wp_remote_og_delete_custom_template', { id: id }, function () {
					customStatusMsg('', '');
				});
			}
		});

		modal.on('click', '[data-modal-close]', closeModal);
		$('#wp-remote-og-preset-apply').on('click', function () {
			if (modalPreset && modalPreset.customId) {
				applyCustomTemplate(modalPreset.customId, $(this));
			} else {
				applyPreset(modalPreset, $(this));
			}
		});
		$(document).on('keydown.wpRemoteOgModal', function (event) {
			if ('Escape' === event.key && !modal.prop('hidden')) {
				closeModal();
			}
		});

		$('#wp-remote-og-restore-backup').on('click', function () {
			if (!window.confirm(strings.restoreConfirm || 'Restore your previous template?')) {
				return;
			}
			var button = $(this);
			button.prop('disabled', true);
			galleryStatus('Restoring previous template...', 'busy');
			$.post(WPRemoteOG.ajaxUrl, {
				action: 'wp_remote_og_restore_template_backup',
				nonce: WPRemoteOG.nonce
			}).done(function (response) {
				if (response && response.success) {
					galleryStatus(strings.restored || 'Previous template restored.', 'success');
					$('.wp-remote-og-restore-notice').prop('hidden', true);
				} else {
					galleryStatus(response && response.data && response.data.message ? response.data.message : 'Unable to restore the template.', 'error');
					button.prop('disabled', false);
				}
			}).fail(function () {
				galleryStatus('Unable to restore the template.', 'error');
				button.prop('disabled', false);
			});
		});

		renderCards();
		renderCustomCards();
	}

	$(function () {
		initNameModal();
		loadGoogleFontCatalog();
		initEditor();
		initFields();
		initPostBox();
		initBulkTools();
		initTemplateGallery();
	});
})(jQuery);
