(function ($) {
	'use strict';

	var state = {
		template: window.WPRemoteOG && WPRemoteOG.template ? WPRemoteOG.template : null,
		selectedLayerId: null,
		preview: {},
		ready: false,
		suspendControls: false,
		interaction: null,
		mediaFrame: null
	};

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
		var available = frame.width() - 24;
		return Math.min(1, available / 1200);
	}

	function applyCanvasScale() {
		$('#wp-remote-og-canvas').css({
			transform: 'none',
			marginBottom: '0'
		});
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
			y: layer.y
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
			height: layer.height
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

		var deltaX = event.clientX - state.interaction.startX;
		var deltaY = event.clientY - state.interaction.startY;

		if (state.interaction.type === 'move') {
			layer.x = Math.round(clamp(state.interaction.x + deltaX, 0, 1200 - layer.width));
			layer.y = Math.round(clamp(state.interaction.y + deltaY, 0, 630 - layer.height));
		} else {
			var edge = state.interaction.edge || 'corner';
			var minWidth = layerMinWidth(layer);
			var minHeight = layerMinHeight(layer);

			if ('left' === edge) {
				var rightEdge = state.interaction.x + state.interaction.width;
				var nextX = clamp(state.interaction.x + deltaX, 0, rightEdge - minWidth);
				layer.x = Math.round(nextX);
				layer.width = Math.round(rightEdge - nextX);
			} else if ('right' === edge) {
				layer.width = Math.round(clamp(state.interaction.width + deltaX, minWidth, 1200 - layer.x));
			} else {
				layer.width = Math.round(clamp(state.interaction.width + deltaX, minWidth, 1200 - layer.x));
				layer.height = Math.round(clamp(state.interaction.height + deltaY, minHeight, 630 - layer.y));
			}
		}

		updateLayerElement(layer);
		fillControls(layer);
		event.preventDefault();
	}

	function endInteraction() {
		state.interaction = null;
		$(document).off('mousemove.wpRemoteOgInteraction mouseup.wpRemoteOgInteraction');
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
		state.template.layers.forEach(function (layer) {
			var item = $('<li/>', {
				'data-layer-id': layer.id,
				tabindex: 0,
				role: 'button',
				text: layer.label || ('line' === layer.type ? lineLayerLabel(layer) : layer.content) || layer.id
			});
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
			state.suspendControls = false;
			return;
		}

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
			layer.content = attachment.url;
			if (attachment.width && attachment.height) {
				layer.image_aspect_ratio = attachment.width / attachment.height;
			}
			$('#wp-remote-og-layer-content').val(layer.content);
			fillControls(layer);
			renderLayerList();
			renderCanvas();
			state.mediaFrame = null;
		});

		state.mediaFrame.open();
	}

	function saveTemplate() {
		var status = $('#wp-remote-og-status');
		setStatusMessage(status, 'Saving...', 'busy');
		$.post(WPRemoteOG.ajaxUrl, {
			action: 'wp_remote_og_save_template',
			nonce: WPRemoteOG.nonce,
			template: state.template
		}).done(function (response) {
			if (response.success) {
				state.template = response.data.template;
				setStatusMessage(status, WPRemoteOG.strings.saved, 'success');
			} else {
				setStatusMessage(status, response.data && response.data.message ? response.data.message : 'Unable to save template.', 'error');
			}
		}).fail(function () {
			setStatusMessage(status, 'Unable to save template.', 'error');
		});
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
		state.template = clone(WPRemoteOG.template);
		if (!state.template.layers) {
			state.template.layers = [];
		}
		if (state.template.layers.length) {
			state.selectedLayerId = state.template.layers[0].id;
		}

		$('.wp-remote-og-color').wpColorPicker({
			change: function () {
				setTimeout(updateSelectedLayerFromControls, 0);
			}
		});

		$('#wp-remote-og-add-layer').on('click', addLayer);
		$('#wp-remote-og-save-template').on('click', saveTemplate);
		$('#wp-remote-og-background').on('click', selectBackground);
		$('#wp-remote-og-add-image-layer').on('click', addImageLayer);
		$('#wp-remote-og-add-horizontal-line').on('click', function () {
			addLineLayer('horizontal');
		});
		$('#wp-remote-og-add-vertical-line').on('click', function () {
			addLineLayer('vertical');
		});
		$('#wp-remote-og-refresh-preview, #wp-remote-og-preview-post').on('click change', refreshPreview);
		$('#wp-remote-og-layer-list').on('click', 'li', function () {
			selectLayer($(this).data('layer-id'));
		});
		$('#wp-remote-og-layer-list').on('keydown', 'li', function (event) {
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
		$('#wp-remote-og-delete-layer').on('click', function () {
			if (!state.selectedLayerId || state.template.layers.length <= 1) {
				return;
			}
			state.template.layers = state.template.layers.filter(function (layer) {
				return layer.id !== state.selectedLayerId;
			});
			state.selectedLayerId = state.template.layers[0].id;
			selectLayer(state.selectedLayerId);
		});
		rebuildTokenPicker();
		$(window).on('resize', applyCanvasScale);

		renderLayerList();
		renderCanvas();
		fillControls(currentLayer());
		state.ready = true;
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
			setStatusMessage(progress, 'No posts need processing.', '');
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
		'{post_title}': 'Senior Product Designer (Remote)',
		'{taxonomy:job_location}': 'Remote — Worldwide',
		'{taxonomy:job_type}': 'Full-time',
		'{acf:company_name}': 'Northwind Studio',
		'{acf:salary_range}': '$120k – $150k'
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
			WPRemoteOG.presets.forEach(function (preset) {
				if ('all' !== activeCategory && preset.category !== activeCategory) {
					return;
				}
				var card = $('<div/>', {
					'class': 'wp-remote-og-preset-card',
					'data-preset': preset.key
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
					'data-action': 'preview'
				}).appendTo(actions);
				$('<button/>', {
					type: 'button',
					'class': 'button button-primary',
					text: 'Apply',
					'data-action': 'apply'
				}).appendTo(actions);
				body.append(actions);
				card.append(body);
				gallery.append(card);
			});
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
			modal.prop('hidden', true);
			modalPreset = null;
			if (lastFocused && lastFocused.focus) {
				lastFocused.focus();
			}
		}

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
			renderCards();
		});

		modal.on('click', '[data-modal-close]', closeModal);
		$('#wp-remote-og-preset-apply').on('click', function () {
			applyPreset(modalPreset, $(this));
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
	}

	$(function () {
		loadGoogleFontCatalog();
		initEditor();
		initFields();
		initPostBox();
		initBulkTools();
		initTemplateGallery();
	});
})(jQuery);
