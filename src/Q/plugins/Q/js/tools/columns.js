(function (Q, $) {
	
/**
 * @module Q-tools
 */

var dataKey_index = 'index';
var dataKey_scrollTop = 'scrollTop';
var dataKey_hide = 'hide';
var dataKey_lastShow = 'lastShow';
var dataKey_opening = 'opening';
	
/**
 * This tool contains functionality to show things in columns
 * @class Q columns
 * @constructor
 * @param {Object}   [options] Override various options for this tool
 *  @param {String}  [options.title] You can put a default title for all columns here (which is shown as they are loading)
 *  @param {String}  [options.column] You can put a default content for all columns here (which is shown as they are loading)
 *  @param {String}  [options.controls] You can put default controls HTML for all columns here (which is shown as they are loading)
 *  @param {Object}  [options.data] Any data you want to associate with the column, to be retrieved later by the tool.data() method
 *  @param {Object}  [options.expandOnMobile] Whether to expand the top/bottom of columns as they are opened on a mobile device, to fill the scren
 *  @param {Boolean} [options.expandOnMobile.top=true] 
 *  @param {Boolean} [options.expandOnMobile.bottom=true] 
 *  @param {Object}  [options.attributes] Any attributes you want to add to the column element
 *  @param {Object}  [options.animation] For customizing animated transitions
 *  @param {Number}  [options.animation.duration] The duration of the transition in milliseconds, defaults to 500
 *  @param {Object}  [options.animation.hide] The css properties in "hide" state of animation
 *  @param {Object}  [options.back] For customizing the back button on mobile
 *  @param {String}  [options.back.src] The src of the image to use for the back button
 *  @param {Boolean} [options.back.hide] Whether to hide the back button. Defaults to false, but you can pass true on android, for example.
 *  @param {Object}  [options.close] For customizing the back button on desktop and tablet
 *  @param {String}  [options.close.src] The src of the image to use for the close button
 *  @param {Object}  [options.close.clickable] If not null, enables the Q/clickable tool with options from here. Defaults to null.
 *  @param {Object}  [options.textfill={}] Options for Q/textfill on the title, or pass null here to skip this effect.
 *  @param {Boolean} [options.closeFromSwipeDown=true] on a touchscreen, close a column after a swipe-down gesture starting from the title
 *  @param {boolean} [options.closeFromTitleClick=false] Whether the whole title would be a trigger for the back button. Defaults to true.
 *  @param {Object}  [options.scrollbarsAutoHide] If an object, enables Q/scrollbarsAutoHide functionality with options from here. Enabled by default.
 *  @param {Object}  [options.classes] Pairs of columnName: cssClass which is added to the column when it's opened
 *  @param {Object}  [options.handlers] Pairs of columnName: handler where the handler can be a function or a string, in which you assign a function to Q.exports .
 *  @param {Boolean} [options.fullscreen] Whether to use fullscreen mode on mobile phones, using document to scroll instead of relying on possibly buggy "overflow" CSS implementation. Defaults to true on Android stock browser, false everywhere else.
 *  @param {Boolean} [options.dontUpdateThemeColor] pass true here to prevent updating the window's theme color
 *  @param {Boolean} [options.hideOverlappedColumns=true] Whether to hide background columns on mobile (perhaps improving browser rendering).
 *  @param {Boolean} [options.stretchFirstColumn=true] If true, stretch first column to whole page width if no other columns appear.
 *  @param {Boolean|String} [options.pagePushUrl=true] if this is true and the url of the column
 *    is specified, then Q.Page.push() is called with this URL. You can also pass a string here,
 *    to override the url (in case, for example, the url of the column is not specified, because it is rendered client-side).
 *  @param {Boolean|Number} [options.pagePushUrlOnClose=300] Whether to call Q.Page.pushUrl(), if pagePushUrl is true as well
 *    You can also put a number of milliseconds to wait and pushUrl (if pagePushUrl is true) as long as columns.open() isn't called during that time.
 *  @param {Q.Event} [options.beforeOpen] Event that happens before a column is opened. Return false to prevent opening. Receives (options, index).
 *  @param {Q.Event} [options.beforeClose] Event that happens before a column is closed. Receives (index, indexAfterClose, columnElement). Return false to prevent closing.
 *  @param {Q.Event} [options.onClose] Event that happens after a column is opened.
 *  @param {Q.Event} [options.onClose] Event that happens after a column is closed.
 *  @param {Q.Event} [options.onActivate] Event that happens after a column is opened and activated. Receives (options, index, columnElement).
 *  @param {Q.Event} [options.onTransitionEnd] Event that happens after a css transition compete. Have tool as context and index, div as arguments.
 *  @param {Q.Event} [options.afterDelay] Event that happens after a column is opened, after a delay intended to wait out various animations. Receives (options, index, columnElement).
 * @return {Q.Tool}
 */
Q.Tool.define("Q/columns", function(options) {
	var tool = this;
	var state = tool.state;
	var $toolElement = $(tool.element);
	options = options || {};

	//state.triggers = [];
	prepareColumns(tool);

	if (state.stretchFirstColumn) {
		$toolElement.addClass("Q_columns_stretchFirstColumn");
	}

	Q.addStylesheet('{{Q}}/css/tools/columns.css', function () {
		if (state.title === undefined) {
			state.title = $('<div />').append('<img class="Q_columns_loading" src="'
				+ Q.url(Q.info.imgLoading)
				+'" alt="">')[0];
		}

		var selector = '.Q_close';
		if (Q.info.isMobile && state.closeFromTitleClick) {
			selector = '.Q_columns_title';
		}
		$toolElement.on(Q.Pointer.fastclick, selector, function(){
			var index = $(this).closest('.Q_columns_column').data(dataKey_index);
			if (state.locked) return;
			if (index > 0) {
				tool.close(index);
			}
		}); // no need for key, it will be removed when tool element is removed

		Q.onScroll.set(Q.debounce(function () {
			if (state.$currentColumn) {
				state.$currentColumn.data(dataKey_scrollTop, Q.Pointer.scrollTop());
			}
		}, 100), tool);

		if (Q.info.isAndroidStock) {
			var w = Q.Visual.windowWidth();
			$toolElement.parents().andSelf().each(function () {
				$(this).data('Q/columns maxWidth', this.style.maxWidth)
					.css('max-width', w);
			});
		}

		// Call setControls whenever a controls slot or a parent element is activated
		Q.onActivate.set(function (element) {
			var isContained = !!$toolElement.closest(element).length;
			for (var i=0, l=state.columns.length; i<l; ++i) {
				var column = tool.column(i);
				if (!column) continue;
				var $controlsSlot = $('.Q_controls_slot', column);
				if (($controlsSlot[0] && isContained)
					|| $controlsSlot[0] === element) {
					var html = $controlsSlot.html();
					column.setClassIf(html, 'Q_columns_hasControls')
					presentColumn(tool);
				}
			}
		}, tool);

		tool.refresh();
		// if (Q.info.isMobile) {
		// 	tool.startAdjustingPositions();
		// }
		setTimeout(function () {
			if (state.animateWidth) {
				$toolElement.addClass("Q_columns_animateWidth");
			}
		}, 100);
		Q.onLayout(tool.element.parentElement).set(function () {
			tool.refresh();
		}, tool);
	}, { slotName: 'Q' });
},

{
	flat: false,
	animation: { 
		duration: 300, // milliseconds
		scrollDuration: 600, //milliseconds
		css: {
			hide: {
				opacity: 0.1, 
				top: '40%',
				height: '0'
			}
		}
	},
	delay: {
		duration: 300 // until it's safe to register clicks
	},
	back: {
		src: "{{Q}}/img/back-v.png",
		hide: false
	},
	close: {
		src: "{{Q}}/img/x.png",
		clickable: null
	},
	classes: {},
	handlers: {},
	stretchFirstColumn: true,
	animateWidth: true,
	title: undefined,
	column: undefined,
	columns: [],
	controls: undefined,
	pagePushUrl: true,
	pagePushUrlOnClose: 300,
	scrollbarsAutoHide: {},
	closeFromTitleClick: false,
	closeFromSwipeDown: true,
	expandOnMobile: {
		top: true,
		bottom: true
	},
	textfill: null,
	fullscreen: Q.info.useFullscreen,
	hideOverlappedColumns: true,
	beforeOpen: new Q.Event(function (options, index) {
		setTimeout(_updateAttributes.bind(this), 0);
	}, 'Q/columns'),
	beforeClose: new Q.Event(),
	onOpen: new Q.Event(),
	onActivate: new Q.Event(function (div, options, index) {
		var tool = this;
		Q.Pointer.stopHints();
		div.addEventListener('transitionend', function () {
			Q.handle(tool.state.onTransitionEnd, tool, [index, div]);
		});

		var $div = $(div);
		var _updateDataWidthRange = Q.debounce(function () {
			$div.attr('data-width-range', Math.round($div.width()/300) || 1);
		}, 100);
		_updateDataWidthRange();
		Q.onLayout(div).add(_updateDataWidthRange, this);
	}, 'Q/columns'),
	onClose: new Q.Event(function (div, index, data, skipUpdateAttributes) {
		if (!skipUpdateAttributes) {
			setTimeout(_updateAttributes.bind(this), 0);
		}
	}, 'Q/columns'),
	onTransitionEnd: new Q.Event(),
	afterDelay: new Q.Event()
},

{
	max: function () {
		return this.state.max || 0;
	},
	
	/**
	 * Opens a new column at the end
	 * @method push
	 * @param {Object} options Can be used to override various tool options
	 * @param {Function} callback Called when the column is opened
	 */
	push: function (options, callback) {
		this.open(options, this.max()+1, callback);
		return this;
	},
	
	/**
	 * Closes the last column
	 * @method pop
	 * @param {Function} callback Called when the column is closed
	 * @param {Object} options Can be used to override various tool options
	 */
	pop: function (callback, options) {
		this.close(this.max(), callback, options);
		return this;
	},
	
	/**
	 * Opens a column
	 * @method open
	 * @param {Object} options Can be used to override various tool options,
	 *  including events such as "onActivate" and "onClose". Additional options include:
	 *  @param {String} [options.name] any name to assign to the column
	 *  @param {String} [options.columnClass] a CSS class name or space-separated list of classes to append to the column container
	 *  @param {String} [options.className] treated same as columnClass, used for compatibility with Q.invoke()
	 *  @param {Object} [options.data] to add data on the column element with jQuery
	 *  @param {Object} [options.template] name of the template to render for the "column" slot
	 *  @param {Object} [options.fields] fields for the template, if any
	 *  @param {Object} [options.activateOptions] any options to pass to Q.activate() for the column
	 *  @param {Object} [options.title] override the title of the column
	 *  @param {Object} [options.column] override the html of the column
	 *  @param {Object} [options.url] a url to request the slots "title" and "column" from
	 *  @param {Object} [options.focusAfterActivate] pass true here to focus the first input after the column is activated
	 *  @param {Boolean} [options.dontUpdateThemeColor] pass true here to prevent updating the window's theme color
	 * @param {Number} index The index of the column to open
	 * @param {Function} callback Called when the column is opened
	 * @return {Boolean} Whether the column will be opened
	 */
	open: function (options, index, callback, internal) {
		var tool = this;
		var state = this.state;
		var max = tool.max();
		if (index === undefined) {
			index = parseInt(max) + 1;
		}
		if (typeof options === 'number') {
			options = {};
			callback = index;
			index = options;
		}
		var o = Q.extend({}, 10, state, 10, options);

		if (index > max + 1) {
			throw new Q.Exception("Q/columns open: index is too big");
		}
		if (index < 0) {
			throw new Q.Exception("Q/columns open: index is negative");
		}
		
		if (false === state.beforeOpen.handle.call(tool, o, index)
		 || false === Q.handle(o.beforeOpen, tool, [o, index])) {
			return false;
		}
		
		if (!internal && state.columns[index]) {
			this.close(index, null, {animation: {duration: 0}}, true);
		}
		
		var div = this.column(index);
		var titleSlot, columnSlot, controlsSlot;
		var $div, $mask, $close, $title, $controls, $tc, $ts;
		var createdNewDiv = false;
		if (!div) {
			createdNewDiv = true;
			div = document.createElement('div').addClass('Q_columns_column');
			// div.style.display = 'none';
			div.style.visibility = 'hidden';
			$div = $(div);
			++this.state.max;
			this.state.columns[index] = div;
			$tc = $('<div class="Q_columns_title_container">');
			$ts = $('<h2 class="Q_title_slot"></h2>').appendTo($tc);
			titleSlot = $ts[0];
			$title = $('<div class="Q_columns_title"></div>').append($tc);
			columnSlot = document.createElement('div').addClass('Q_column_slot');
			$controls = $('<div class="Q_controls_slot" class="Q_fixed_bottom"></div>');
			controlsSlot = $controls[0];
			state.container = tool.$('.Q_columns_container')[0];
			$div.append($title, columnSlot, controlsSlot)
				.data(dataKey_index, index)
				.data(dataKey_scrollTop, Q.Pointer.scrollTop());
			var $columns = $('.Q_columns_column', state.container);
			if ($columns.length) {
				$div.insertAfter($columns.last());
			} else {
				$div.appendTo($(state.container));
			}
			if (o.fullscreen) {
				$(window).scrollTop(0);
			}
			setTimeout(function () {
				// give the browser a chance to calculate dimensions of elements
				div.setClassIf(controlsSlot.innerHTML, 'Q_columns_hasControls')
				presentColumn(tool, $div, o.fullscreen);
			});
		} else {
			$div = $(div);
			$close = $('.Q_close', div);
			$tc = $('.Q_columns_title_container', div);
			$title = $('.Q_columns_title', div);
			titleSlot = $('.Q_title_slot', div)[0];
			columnSlot = $('.Q_column_slot', div)[0];
			controlsSlot = $('.Q_controls_slot', div)[0];
			$div.attr('data-title', $(titleSlot).text() || document.title);
		}
		columnSlot.addClass('Q_content_container');
		if (state.closeFromSwipeDown && index > 0) {
			Q.addEventListener($title[0], 'touchstart', function (e1) {
				var x1 = Q.Pointer.getX(e1);
				var y1 = Q.Pointer.getY(e1);
				Q.addEventListener(document.body, 'touchmove', _onTouchmove, false, true);
				Q.addEventListener(document.body, 'touchend', _onTouchend, false, true);
				var $div = $(div);
				var originalTop = $div.css('top');
				var originalOpacity = $div.css('opacity');
				var _closed = false;
				var _addedEventListener = false;
				function _onTouchmove(e2) {
					var x2 = Q.Pointer.getX(e2);
					var y2 = Q.Pointer.getY(e2);
					var threshold = (typeof state.closeFromSwipeDown === 'number')
						? state.closeFromSwipeDown
						: Q.getObject(['originalEvent', 'touches', 0, 'radiusY'], e1)*2 || 50;
					var z = (y2 - y1) / threshold;
					$div.css('top', parseInt(originalTop)+Math.max(0, y2-y1));
					$div.css('opacity', 1-z);
					if (Q.info.isMobile && index > 0) {
						var $prevColumn = $(state.columns[index-1]);
						if (z > 0) {
							$prevColumn.addClass('Q_columns_overlapped');
						} else {
							$prevColumn.removeClass('Q_columns_overlapped');
						}
					}
					if (y2 - y1 > threshold
					&& Math.abs((y2-y1)/(x2-x1)) > 2) { //generally down direction
						if (!_addedEventListener) {
							Q.addEventListener(document.body, 'touchmove', _cancelScroll, false, true);	
							Q.Masks.show('Q.click.mask');
							_addedEventListener = true;
						}
						tool.close(index);
						_closed = true;
						Q.removeEventListener(document.body, 'touchmove', _onTouchmove);
					}
				}
				function _onTouchend(e2) {
					if (!_closed) {
						$div.animate({
							top: originalTop,
							opacity: originalOpacity
						}, 100);
						if (Q.info.isMobile && index > 0) {
							$(state.columns[index-1]).hide;
						}
					}
					setTimeout(function () {
						Q.Masks.hide('Q.click.mask');
					}, state.animation.duration);
					Q.removeEventListener(document.body, 'touchmove', _onTouchmove);
					Q.removeEventListener(document.body, 'touchend', _onTouchend);
					Q.removeEventListener(document.body, 'touchmove', _cancelScroll);
					_addedEventListener = false;
				}
				function _cancelScroll(e) {
					e.preventDefault();
				}
			}, false, true);
		}
		var url = null;
		if (typeof o.pagePushUrl === 'string') {
			url = Q.url(o.pagePushUrl);
		} else if (o.url) {
			url = Q.url(o.url);
		}
		if (url) {
			$div.attr('data-url', url);
			$div.attr('data-prevUrl', location.href);
		}
		if (o.columnClass) {
			$div.addClass(o.columnClass);
		}
		if (o.className) {
			$(div).addClass(o.className);
		}
		if (o.name && state.classes && state.classes[o.name]) {
			$(div).addClass(state.classes[o.name]);
		}
		var dataMore = div.getAttribute('data-more');
		tool.state.data[index] = Q.extend(
			{},
			dataMore && JSON.parse(dataMore),
			o.data
		);
		if (o.attributes) {
			$div.attr(attributes);
		}
		if (!$close || !$close.length) {
			$close = !index ? $() : $('<div class="Q_close"></div>');
			if (Q.info.isMobile) {
				$close.addClass('Q_back').append(
					$('<img alt="Back" />').attr('src', Q.url(o.back.src))
				);
			} else {
				$close.append(
					$('<img alt="Close" />').attr('src', Q.url(o.close.src))
				);
			}
			if (index) {
				$title.prepend($close);
			}
		}
		state.$currentColumn = $div;
		state.currentIndex = index;
		if (o.back.hide) {
			$close.hide();
		}
		
		if (Q.info.isMobile) {
			$div.css('position', 'absolute');
		} else if ($div.css('position') === 'static') {
			$div.css('position', 'relative');
		}
		var _position = $div.css('position');
		$div.css('position', 'absolute');

		$div.attr('data-index', index).addClass('Q_column_'+index);
		if (o.name) {
			var n = Q.normalize(o.name, null, null, null, true);
			$div.attr('data-name', o.name)
				.addClass('Q_column_'+n);
		}
		
		if (o.template) {
			Q.Template.render(o.template, o.fields, function (err, html) {
				o.column = html;
				_open();
			});
		} else {
			_open();
		}
		return true;

		function _open() {
			var $te = $(tool.element);
			var $titleSlot = $(titleSlot);
			var $columnSlot = $(columnSlot);
			var $controlsSlot = $(controlsSlot);
			var p = Q.pipe();
			var _suddenlyClosing = false;
			var waitFor = ['animation'];
			if (o.url) {
				waitFor.push('activated');
				function _suddenClose() {
					_suddenlyClosing = true;
					$mask.remove();
					$div.removeClass('Q_columns_loading');
					tool.close(index);
				}
				var params = Q.extend({
					slotNames: ["title", "column", "controls"], 
					slotContainer: {
						title: titleSlot,
						column: columnSlot
					},
					quiet: true,
					ignoreHistory: true,
					ignorePage: true,
					onError: {"Q/columns": _suddenClose},
					onRedirect: {"Q": _suddenClose}
				}, 10, o);
				params.handler = function _handler(response) {
					var elementsToActivate = {};
					if ('title' in response.slots) {
						Q.Tool.clear(titleSlot);
						$(titleSlot).html(response.slots.title);
						elementsToActivate['title'] = titleSlot;
					}
					if ('controls' in response.slots) {
						Q.Tool.clear(controlsSlot);
						$(controlsSlot).html(response.slots.controls);
						elementsToActivate['controls'] = controlsSlot;
					}
					Q.Tool.clear(columnSlot);
					columnSlot.innerHTML = response.slots.column;
					elementsToActivate['column'] = columnSlot;
					return elementsToActivate;
				};
				params.onActivate = p.fill('activated');
				// this.state.triggers[index] = o.trigger || null;
				Q.loadUrl(o.url, params);
			}
			
			if (o.title != undefined) {
				if (Q.instanceOf(o.title, Element)) {
					$titleSlot.empty().append(o.title);
				} else {
					$titleSlot.empty().html(o.title);
				}
				$div.attr('data-title', $titleSlot.text());
				$div.attr('data-prevTitle', document.title);
			}
			if (o.column != undefined) {
				$columnSlot.empty().append(
					Q.instanceOf(o.column, Element) ? $(o.column) : o.column
				);
			}
			if (o.controls != undefined) {
				$controlsSlot.empty().append(
					Q.instanceOf(o.controls, Element) ? $(o.controls) : o.controls
				);
			}

			Q.onLayout($tc[0]).set(function () {
				presentColumn(tool, $div, o.fullscreen, true);
			}, tool);

			Q.handle(o.onOpen, tool, [div, o, index]);
   
			waitFor.push('activated1', 'activated2', 'activated3');
			$titleSlot.activate(o.activateOptions, p.fill('activated1'));
			$columnSlot.activate(o.activateOptions, p.fill('activated2'));
			$controlsSlot.activate(o.activateOptions, p.fill('activated3'));
			p.add(waitFor, function () {
				var data = tool.data(index);
				if ($(div).closest('html').length) {
					$div.css('position', _position);
					var name = $(div).attr('data-name');
					var js = state.handlers && state.handlers[name];
					if (js) {
						if (typeof js === 'string') {
							Q.require(js, function (js) {
								Q.handle(js, tool, [o, index, div, data]);
							});
						} else {
							Q.handle(js, tool, [o, index, div, data]);
						}
					}
					// check url before document location changed
					var url = $div.attr('data-url');
					$div.attr('data-title', document.title);
					if (o.pagePushUrl && createdNewDiv && url && url !== location.href) {
						Q.Page.push(url);
					}
					Q.Pointer.clearSelection();

					// call the callback before the events,
					// so something custom can be done first
					Q.handle(callback, tool, [div, o, index, data]);
					Q.handle(o.onActivate, tool, [div, o, index, data]);
					state.onActivate.handle.call(tool, div, o, index, data);
					setTimeout(function () {
						$mask.remove();
						$div.removeClass('Q_columns_loading');
						Q.handle(o.afterDelay, tool, [div, o, index, data]);
						Q.handle(state.afterDelay, tool, [div, o, index, data]);
					}, o.delay.duration);
				} else {
					$mask.remove();
					$div.removeClass('Q_columns_loading');
				}
				div.setClassIf($controlsSlot[0] && !!$controlsSlot[0].innerHTML, 'Q_columns_hasControls');
				setTimeout(function () { // let the layout update
					Q.layout(tool.element);
				}, 1000);
				if (o.focusAfterActivate) {
					$('input[type=text],textarea', $columnSlot)
					.slice(0, 1).plugin('Q/clickfocus');
					setTimeout(function () {
						$('input[type=text],textarea', $columnSlot)
						.slice(0, 1).plugin('Q/clickfocus');
					}, 1000);
				}
			}).run();
			
			Q.each(['on', 'before'], function (k, prefix) {
				var event = o[prefix+'Close'];
				var stateEvent = state[prefix+'Close'];
				event = Q.extend(new Q.Event(), event);
				var key = stateEvent.set(function (i) {
					if (i != index) return;
					event.handle.apply(this, arguments);
					stateEvent.remove(key);
				});
			});
			
			var expandTop = index > 0 && Q.info.isMobile && state.expandOnMobile && state.expandOnMobile.top;
			var expandBottom = index > 0 && Q.info.isMobile && state.expandOnMobile && state.expandOnMobile.bottom;
			var $sc = $(state.container);
			var containerRect = $sc[0].getBoundingClientRect();
			var statusBackground = document.getElementById('status_background')
				|| document.getElementsByClassName('Q_top_background')[0];
			var margin = (statusBackground && statusBackground.getBoundingClientRect().height) || 0;
			var top = expandTop
				? -containerRect.top + margin
				: 0;
			var show = {
				opacity: 1,
				top: top
			};

			tool.oldMinHeight = undefined;
			var hide = o.animation.css.hide;
			$div.css('position', 'absolute');
			if (Q.info.isMobile) {
				var h = expandBottom
					? Q.Visual.windowHeight() - containerRect.top - top
					: state.container.clientHeight;
				show.width = tool.element.clientWidth;
				show.height = h;
			} else {
				$div.css('display', 'inline-block');
				show.height = tool.element.clientHeight;
				for (var k in hide) {
					var str = hide[k].toString();
					if (str.substring(str.length-1) === '%') {
						hide[k] = parseFloat(show.height) * parseInt(hide[k]) / 100;
					}
				}
				$div.css('position', 'relative');
			}

			var lastShow = $div.data(dataKey_lastShow);
			if (lastShow) {
				show = lastShow;
			}
			if (hide.top[hide.top.length-1] === '%') {
				hide.top = show.top + show.height * parseFloat(hide.top) / 100;
			}
			$div.data(dataKey_hide, hide);
			
			if (expandTop || expandBottom) {
				var $parents = $(tool.element).parents();
				$parents.addClass('Q_columns_containsExpanded');
				$parents.not('body,html').siblings().addClass('Q_columns_siblingContainsExpanded');
			}

			document.documentElement.setAttribute('data-q-columns-depth', state.columns.length);
			
			state.locked = true;
			openAnimation();

			function openAnimation(){
				// open animation
				var duration = o.animation.duration;
				var $cs = $('.Q_column_slot', $div);
				var $ct = $('.Q_columns_title', $div);

				if (!o.dontUpdateThemeColor) {
					_updateThemeColor(index-1, index, duration);
				}
				
				var $prev = $div.prev();
				$div.css('z-index', parseInt($prev.css('z-index'))+1 || 1);
				
				$div.data(dataKey_lastShow, show)
					.data(dataKey_opening, true);
				tool.oldMinHeight = $div.css('min-height');
				$div.css('min-height', 0);
				
				if (o.fullscreen) {
					$ct.css('position', 'absolute');
					$cs.css('overflow', 'hidden');
				} else if (Q.info.isMobile) {
					$('html').addClass('Q_overflowHidden');
				}
				$mask = $('<div class="Q_columns_mask" />')
				.appendTo($div);
				$div.css('visibility', 'visible')
				.addClass('Q_columns_opening')
				.css(o.animation.css.hide)
				.stop()
				.animate(show, duration, function() {
					if (o.textfill) {
						$tc.plugin('Q/textfill', o.textfill);
					}
					setTimeout(function () {
						$div.data(dataKey_opening, false);
						afterAnimation($cs, $sc, $ct);
					}, 0);
				});
				$div.addClass('Q_columns_loading');

				if (!_suddenlyClosing && o.hideOverlappedColumns) {
					$div.prev().addClass('Q_columns_overlapped');
				}
				$div.addClass('Q_columns_latest');
				$div.prevAll('.Q_columns_column').removeClass('Q_columns_latest');

				setTimeout(function () {
					if (Q.info.isMobile) {
						$div.add($ct).css('width', '100%');
					} else {
						var width = 0;
						var $toScroll = ($te.css('overflow') === 'visible')
							? $te.parents()
							: $te;
						$toScroll.each(function () {
							var $this = $(this);
							if (o.animation.scrollDuration) {
								$this.animate({
									scrollLeft: this.scrollWidth
								}, o.animation.scrollDuration);
							} else {
								this.scrollTo(this.scrollWidth, 0);
							}
							if ($this.css('overflow') !== 'visible') {
								return false;
							}
						});
					}
				}, 0);
			}

			function afterAnimation($cs, $sc, $ct){
				
				if (Q.info.isMobile) {
					if (!_suddenlyClosing && o.hideOverlappedColumns) {
						$div.prev().addClass('Q_columns_overlapped');
					}
					$div.css('width', '100%');
				} else {
					if (o.close.clickable) {
						$close.plugin("Q/clickable", o.close.clickable);
					}
				}
				
				$div.removeClass('Q_columns_opening').addClass('Q_columns_opened');

				presentColumn(tool, $div, o.fullscreen, true);

				if (Q.info.isTouchscreen) {
					if (o.fullscreen) {
						$cs.css({
							'overflow': 'visible', 
							'height': 'auto'
						});
					} else {
						$cs.addClass('Q_overflow');
					}
				} else if (o.scrollbarsAutoHide) {
					$cs.plugin('Q/scrollbarsAutoHide', o.scrollbarsAutoHide);
				}
				
				state.locked = false;

				p.fill('animation')();
			}
		}
	},

	/**
	 * Closes a column
	 * @method close
	 * @param {Number|Array|Object|Element} index The index of the column to close.
	 *  You can pass an array of indexes here, or an object with "min" and
	 *  optional "max". You can also pass an element to match instead, like with
	 *  Q.Dialogs.close(element).
	 * @param {Function} callback Called when the column is closed, or if no column
	 *  Receives (index, column) where the column could be null if it wasn't found.
	 * @param {Object} options Can be used to override some values taken from tool state
	 * @param {Boolean} [pagePushUrlOnClose] Set to true to call Q.Page.push() even when closing a column
 	 *  You can also put a number of milliseconds to wait and pushUrl (if pagePushUrl is true) as long as columns.open() isn't called during that time.
	 * @param {Boolean} skipUpdateAttributes Whether to skip updating the attributes
	 *  of the tool element because some columns are about to be opened, and we want
	 *  to avoid thrashing.
	 * @return {Boolean} Whether the column was actually closed.
	 */
	close: function (index, callback, options, skipUpdateAttributes) {
		var tool = this;
		var state = tool.state;
		var t = Q.typeOf(index);
		var p, waitFor = [];
		if (index instanceof Element) {
			Q.each(state.columns, function (i) {
				if (this === index) {
					index = i;
				}
			});
			if (index instanceof Element) {
				return false;
			}
		}
		if (t === 'object') {
			p = new Q.Pipe();
			Q.each(index.max||state.max, index.min||0, -1, function (i) {
				try { tool.close(i, p.fill(i), options, true); } catch (e) {}
				waitFor.push(i);
			});
			if (!skipUpdateAttributes) {
				setTimeout(_updateAttributes.bind(this), 0);
			}
		} else if (t === 'array') {
			p = new Q.Pipe();
			Q.each(index, function (k, i) {
				try { tool.close(i, p.fill(i), options, true); } catch (e) {}
				waitFor.push(i);
			}, {ascending: false});
			if (!skipUpdateAttributes) {
				setTimeout(_updateAttributes.bind(this), 0);
			}
		}
		var div = tool.column(index);
		if (p) {
			p.add(waitFor, function () {
				Q.handle(callback, tool, [index, div]);
			}).run();
			return false;
		}
		if (!div) {
			Q.handle(callback, tool, [index, div]);
			return false;
		}
		var o = Q.extend({}, 10, state, 10, options);
		var $div = $(div);
		var width = $div.outerWidth(true);
		var w = $div.outerWidth(true);
		var duration = o.animation.duration;
		var $prev = $div.prev();
		
		var prevIndex = $prev.attr('data-index');
		var shouldContinue = o.beforeClose.handle.call(tool, index, prevIndex, div);
		if (shouldContinue === false) {
			return false;
		}
		
		if (Q.info.isMobile && o.hideOverlappedColumns) {
			$prev.removeClass('Q_columns_overlapped');
			$prev.addClass('Q_columns_latest');
		}
		if (state.fullscreen) {
			$(window).scrollTop($prev.data(dataKey_scrollTop) || 0);
			// make the title move while animating, until it is removed
			$('.Q_columns_title', $div).css('position', 'absolute');
		}
		state.$currentColumn = $prev;
		state.currentIndex = prevIndex;
		if (index === state.columns.length -1) {
			state.columns.pop();
		} else {
			state.columns[index] = null;
		}
		presentColumn(tool);
	
		$div.css('min-height', 0);
		
		if (index === state.max) {
			var max = 0;
			Q.each(state.columns, function (i, c) {
				if (c) {
					max = i;
				}
			});
			state.max = parseInt(max);
		}
		
		Q.Pointer.cancelClick();
		Q.Masks.show('Q.click.mask');
		
		var expandTop = index > 0 && Q.info.isMobile && state.expandOnMobile && state.expandOnMobile.top;
		var expandBottom = index > 0 && Q.info.isMobile && state.expandOnMobile && state.expandOnMobile.bottom;
		if (state.max === 0 && (expandTop || expandBottom)) {
			var $parents = $(tool.element).parents();
			$parents.removeClass('Q_columns_containsExpanded');
			$parents.siblings().removeClass('Q_columns_siblingContainsExpanded');
		}
		
		_updateThemeColor(index, index-1, duration);

		if (duration) {
			Q.Visual.animationStarted(duration);
			$div.animate($div.data(dataKey_hide), duration, _close);
		} else {
			$div.hide();
			_close();
		}
		
		return true;
		
		function _close() {
			Q.removeElement(div, true); // remove it correctly)

			if (!Q.info.isMobile) {
				var ival = setInterval(function () {
					tool.element.scrollTo(tool.element.scrollWidth, 0);
				}, 25);
				setTimeout(function () {
					clearTimeout(ival);
				}, state.animation.duration + 25);
			}

			presentColumn(tool);
			Q.Pointer.clearSelection();
			Q.handle(callback, tool, [index, div]);
			state.onClose.handle.call(tool, index, div, skipUpdateAttributes);
			var url = $prev.attr('data-url') || $div.attr('data-prevUrl');
			var title = $prev.attr('data-title') || $div.attr('data-prevTitle');
			if (o.pagePushUrl && url && url !== location.href) {
				var t, k;
				if (o.pagePushUrlOnClose == true) {
					_pagePushUrl();
				} else if (Number.isFinite(o.pagePushUrlOnClose)) {
					t = setTimeout(_pagePushUrl);
					k = state.beforeOpen.setOnce(function () {
						clearInterval(t);
					});
				}
				function _pagePushUrl() {
					Q.Page.push(url, title);
					state.beforeOpen.remove(k);
				}
			}
			Q.layout(tool.element);
			Q.Visual.animationStarted(state.animation.duration);
			setTimeout(function () {
				Q.Masks.hide('Q.click.mask');
			}, state.animation.duration);
			document.documentElement.setAttribute('data-q-columns-depth', state.columns.length);
		}
	},

	/**
	 * Get the column element by column index
	 * @param {Number} index
	 */
	column: function (index) {
		return this.state.columns[index] || null;
	},
	
	/**
	 * Get the data object associated to a certain column, by its index.
	 * @param {Number} index
	 */
	data: function (index) {
		return this.state.data[index] || null;
	},
	
	/**
	 * Refresh the tool and make it render its columns
	 */
	refresh: function () {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);
		var $columns = $('.Q_columns_column', $te);
		var $container = $('.Q_columns_container', $te);
		var top = Q.fixedOffset('top', ['Q_drawers_drawer', tool.element]);
		
		if (Q.info.isMobile) {
			$te.css('top', top + 'px');
			if (!state.fullscreen) {
				var h = Q.Visual.windowHeight() - Q.fixedOffset('top') - Q.fixedOffset('bottom');
				$te.add($container).height(h);
				$columns.each(function () {
					$(this).height(h - parseInt($(this).css('top')));
				});
			}
		}
		$columns.each(function () {
			presentColumn(tool, $(this), state.fullscreen, true);
		});

		if (state.fullscreen) {
			$te.addClass('Q_fullscreen');
		}
		
		var overshoot = Q.Pointer.scrollTop() + $(document).height()
			- Q.Pointer.windowHeight();
		if (overshoot > 0) {
			$(window).scrollTop( $(window).scrollTop()-overshoot );
		}
		return this;
	},
	
	setControls: function (index, html, callback) {
		var tool = this;
		var column = tool.column(index);
		if (!column) {
			return null;
		}
		var $controlsSlot = $('.Q_controls_slot', column);
		if (html) {
			$(column).addClass('Q_columns_hasControls');
			$controlsSlot.html(html).show().activate(function () {
				Q.handle(callback, tool);
			});
		} else {
			$(column).removeClass('Q_columns_hasControls');
			$controlsSlot.empty().hide();
		}
		presentColumn(tool);
		return $controlsSlot[0];
	},
	
	startAdjustingPositions: function (milliseconds) {
		milliseconds = milliseconds || 300;
		var tool = this;
		tool.startAdjustingPositions.interval = setInterval(function () {
			var $tsc = $(tool.state.container);
			if (!$tsc.is(":visible")) {
				return;
			}
			var top = $tsc.offset().top;
			if (tool.startAdjustingPositions.top === top) {
				return;
			}
			var diff = tool.startAdjustingPositions.top - top;
			tool.startAdjustingPositions.top = top;
			if (!isNaN(diff)) {
				Q.each(tool.state.columns, function (i, column) {
					var rect = column.getBoundingClientRect();
					$(column).css('top', rect.top + diff);
				});
			}
		}, milliseconds);
	},
	
	stopAdjustingPositions: function () {
		this.startAdjustingPositions.interval
		&& clearInterval(this.startAdjustingPositions.interval);
	},
	
	Q: {
		onRetain: function(newOptions, incomingElement) {
			Q.replace(this.element, incomingElement);
			var tool = this;
			setTimeout(function () {
				prepareColumns(tool);
				tool.refresh();
			}, 0);
		},
		beforeRemove: function () {
			if (Q.info.isAndroidStock) {
				var w = Q.Pointer.windowWidth();
				$(this.element).parents().andSelf().each(function () {
					this.style.monlyaaxWidth = $(this).data('Q/columns maxWidth');
				});
			}
			this.stopAdjustingPositions();
			this.$('.Q_columns_column').each(function () {
				$(this).removeClass('Q_columns_opening')
					.removeClass('Q_columns_opened');
			});
		}
	}
}
);

Q.Template.set('Q/columns/column',
	'<div class="Q_contextual"><ul class="Q_listing"></ul></div>'
);

function presentColumn(tool, $column, fullscreen, recalculateHeights) {
	var state = tool.state;
	var $currentColumn = Q.getObject('$currentColumn', state);

	$currentColumn.removeClass('Q_columns_overlapped');

	if (!$column) {
		$column = $currentColumn;
		fullscreen = tool.state.fullscreen;
		if (!$column || !$column.length) {
			return;
		}
	}
	var $ct = $('.Q_columns_title', $column);

	// set document title to current column title
	if ($currentColumn) {
		document.title = $currentColumn.find('.Q_title_slot').text();
	}

	var hideTitle = $column.hasClass('Q_columns_hideTitle');
	var $cs = $('.Q_column_slot', $column);
	var titleOuterHeight = $ct.outerHeight(true);
	if (hideTitle) {
		$cs.css('top', '-' + titleOuterHeight + 'px');
	}
	var $controls = $column.find('.Q_controls_slot');
	var cth = $ct.is(":visible") && !hideTitle ? $ct.outerHeight(true) : 0;
	var controlsh = $controls.is(":visible") ? $controls.outerHeight(true) : 0;
	var index = parseInt($column.attr('data-index'));
	var heightToBottom;
	if (Q.info.isMobile) {
		var $sc = $(tool.state.container);
		var expandTop = index > 0 && state.expandOnMobile && state.expandOnMobile.top;
		var expandBottom = index > 0 && state.expandOnMobile && state.expandOnMobile.bottom;
		if (document.documentElement.scrollTop < 0) {
			document.documentElement.scrollTop = 0; // iOS Safari bug
		}
		var containerRect = $sc[0].getBoundingClientRect();
		if (!fullscreen && expandTop) {
			var statusBackground = document.getElementById('status_background')
				|| document.getElementsByClassName('Q_status_background')[0];
			var margin = (statusBackground && statusBackground.getBoundingClientRect().height) || 0;
			var top = expandTop ? -containerRect.top + margin : 0;
			$column.css('top', top + 'px');
		}
		var columnRect = $cs[0].getBoundingClientRect();
		heightToBottom = (expandBottom ? Q.Pointer.windowHeight() : containerRect.bottom)
			- columnRect.top
			- parseInt($cs.css('padding-top'))
			- parseInt($cs.css('padding-bottom'))
			- controlsh;
		if (fullscreen) {
			$cs.add($column).css('height', 'auto');
			$cs.css('min-height', heightToBottom);
		} else {
			$cs.height(heightToBottom);
			if (hideTitle) {
				$column.css('height', heightToBottom + titleOuterHeight);
			} else {
				$column.css('height', 'auto');
			}
		}
	} else {
		heightToBottom = $(tool.element).height()
			- $cs.offset().top + $cs.parent().offset().top
			- parseInt($cs.css('padding-top'))
			- parseInt($cs.css('padding-bottom'))
			- controlsh;

		$cs.height(heightToBottom);
		if (0 && !recalculateHeights) {
			$column.css('min-height', tool.oldMinHeight);
			var show = $column.data(dataKey_lastShow);
			if (show && show.height) {
				$cs.css('height', show.height - cth - controlsh + 'px');
			}
		}
	}
	if (!fullscreen) {
		return;
	}
	$ct.css('position', 'fixed');
	$ct.css('top', $(tool.element).offset().top + 'px');
	var paddingTop = $ct.outerHeight(true);
	$cs.css('padding-top', paddingTop);
	if (Q.info.isMobile) {
		heightToBottom = Q.Pointer.windowHeight()
			- $cs.offset().top
			- paddingTop
			- parseInt($cs.css('padding-bottom'))
			- $controls.height();
		$cs.add($column).css('height', 'auto');
		$cs.css('min-height', heightToBottom);
	} else {
		$column.css('min-height', tool.oldMinHeight);
	}
}

function prepareColumns(tool) {
	var state = tool.state;
	state.max = 0;
	state.data = [];
	state.container = tool.$('.Q_columns_container')[0];
	if (!state.container) {
		state.container = document.createElement('div')
			.addClass('Q_columns_container Q_clearfix');
		tool.element.appendChild(state.container);
	} else {
		state.columns = [];
		tool.$('.Q_columns_column').each(function (index) {
			var $this = $(this);
			state.columns.push(this);
			$this.data(dataKey_index, index)
				.data(dataKey_scrollTop, Q.Pointer.scrollTop());
			if (index > 0) {
				state.max = parseInt(index);
			}
			if (!$this.hasClass('Q_columns_opened')
			 && !$this.hasClass('Q_columns_opening')) {
				tool.open({
					title: undefined,
					column: undefined,
					animation: {duration: 0}
				}, index, null, true);
			}
		});
	}
}

function _topZ() {
	var topZ = 0;
	$('body').children().each(function () {
		var $this = $(this);
		if ($this.hasClass('Q_click_mask')) {
			return;
		}
		var z = parseInt($this.css('z-index'));
		if (!isNaN(z)) {
			topZ = Math.max(topZ, z)
		}
	});
	return topZ;
}

function _updateAttributes() {
	var max = this.max();
	var count = max ? max + 1 : 1;
	var $te = $(this.element);
	$te.attr('data-column-count', count);
	if (count > 3) {
		$te.addClass('Q_columns_over3');
	} else {
		$te.removeClass('Q_columns_over3');
	}
}

Q.invoke.handlers.unshift(function (options, methods) {
	var index, columns;
	var node = options.trigger;
	if (!node || (options && options.skipColumns)) {
		return;
	}

	while (node) {
		if (node.hasClass) {
			if (node.hasClass('Q_columns_column')) {
				index = typeof options.columnIndex === "number" ? options.columnIndex : parseInt(node.getAttribute('data-index'));

				// if columnIndex==="current" open stuff in same column
				if (options.columnIndex === "current" && index > 0) {
					index -= 1;
				}
			}
			if (node.hasClass('Q_columns_tool')) {
				columns = node.Q.tools['q_columns'];
				break;
			}
		}
		node = node.parentNode;
	}
	if (columns) {
		var closed = columns.close({min: index+1}, null, {animation: {duration: 0}});
		columns.push(Q.extend({}, options, {
			animation: closed ? { scrollDuration: 0 } : {},
			column: options.content,
			onActivate: options.onActivate || function () {}
		}));
		var max = columns.max();
		methods.close = function (options) {
			columns.close(max, function () { }, options);
		};
		return false;
	}
});

var initialColor;
function _updateThemeColor(fromIndex, toIndex, duration) {
	Q.addScript('{{Q}}/js/Color.js', function () {
		initialColor = initialColor || Q.Color.getWindowTheme();
		if (fromIndex < 0 || !$('html').hasClass('Q_columns_animationFX')) {
			return;
		}
		var black = '#000000';
		var shades = [0, 0.36, 0.42, 0.47, 0.5];
		fromIndex = Math.min(shades.length-1, fromIndex);
		toIndex = Math.max(0, Math.min(shades.length-1, toIndex));
		var fromColor = Q.Color.between(initialColor, black, shades[fromIndex]);
		var toColor = Q.Color.between(initialColor, black, shades[toIndex]);
		Q.Animation.play(function (x, y) {
			Q.Color.setWindowTheme('#' + Q.Color.between(fromColor, toColor, y));
		}, duration);
	});
}

})(Q, Q.jQuery);