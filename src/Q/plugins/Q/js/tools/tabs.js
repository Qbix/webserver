(function (Q, $) {

/**
 * @module Q-tools
 */

/**
 * This tool renders a nice set of tabs that adapts to different environments.
 * It automatically tries to figure out what tab should be selecte, but you can
 * set the beforeRefresh handler to help it along.
 * @class Q tabs
 * @constructor
 * @param {Object} [options] This object contains properties for this function
 *  @param {Object} [options.tabs] An object of name: title pairs.
 *  @param {Object} [options.urls] An object name: url pairs to override the default urls.
 *  @param {Object} [options.windowThemeColors] You can pass an object of name: color or name: [colorLight, colorDark] here to set custom statusbar colors
 *  @param {String} [options.field='tab'] Uses this field when urls doesn't contain the tab name.
 *  @param {Boolean|Object} [options.retain] Pass true to retain slots from all tabs, or object of {name: Boolean} for individual tabs. Makes switchTo avoid reloading tab url by default, instead it restores last-seen slot contents, url and title.
 *  @param {Boolean} [options.checkQueryString=false] Whether the default getCurrentTab should check the querystring when determining the current tab
 *  @param {boolean} [options.touchlabels=false] Whether to show touchlabels on the tabs
 *  @param {Boolean} [options.vertical=false] Stack the tabs vertically instead of horizontally
 *  @param {Boolean} [options.compact=false] Display the tabs interface in a compact space with a contextual menu
 *  @param {Object} [options.overflow] Object defined element with overflowed menu items. If false, don't crop overflowed menu elements.
 *  @param {String} [options.overflow.content] The html that is displayed when the tabs overflow. You can interpolate {{count}}, {{text}} or {{html}} in the string.
 *  @param {String} [options.overflow.alreadyVisible] The html that is displayed when the tabs overflow but current tab is already visible. You can interpolate {{count}}, {{text}} or {{html}} in the string.
 *  @param {String} [options.overflow.glyph] Override the glyph that appears next to the overflow text. You can interpolate {{count}} here
 *  @param {String} [options.overflow.defaultText] The text to interpolate {{text}} in the content when no tab is selected
 *  @param {String} [options.overflow.defaultHtml] The html to interpolate {{html}} in the content when no tab is selected
 *  @param {String} [options.selectors] Object of {slotName: selector} pairs, where the values are CSS style selectors indicating the element to update with javascript, and can be a parent of the tabs. Set to null to reload the page.
 *  @param {String} [options.slot] The name of the slot to request when changing tabs with javascript.
 *  @param {Function} [options.loader] Name of a function which takes url, slot, callback. It should call the callback and pass it an object with the response info. Can be used to implement caching, etc. instead of the default HTTP request. This function shall be Q.batcher getter
 *  @param {Q.Event} [options.onClick] Event when a tab was clicked, with arguments (name, element). Returning false cancels the tab switching.
 *  @param {Q.Event} [options.beforeSwitch] Event when tab switching begins. Returning false cancels the switching.
 *  @param {Function} [options.beforeScripts] Name of the function to execute after tab is loaded but before its javascript is executed.
 *  @param {Function} [options.onCurrent] Event after a tab has been visually selected. Note that this is in the view layer, so your handlers would trigger recursion if they call Q.layout().
 *  @param {Function} [options.onActivate] Event after a tab's linked content has been loaded and activated.
 *  @param {Function} [options.beforeRefresh] Event before tabs are going to be refreshed.
 *   The first parameter is a callback you can call and pass the name of the tab
 *   to display as being selected, otherwise the tool tries to figure it out on its own.
 *  @param {Function} [options.onRefresh] Event after tabs have been refreshed
 * @return {Q.Tool}
 */
Q.Tool.define("Q/tabs", function(options) {

	var tool = this;
	var state = tool.state;
	var $te = $(tool.element);
	
	tool.retained = {};

	state.tabName = null;
	
	if (state.touchlabels === undefined) {
		state.touchlabels = false;
	}
	
	if (state.contextualHandler == null) {
		state.contextualHandler = function (element, event) {
			tool.switchTo([element.getAttribute('data-name'), element], {
				event: event
			});
		}
	}

	var refresh = Q.debounce(function () {
		tool.refresh();
	});
	Q.onPopState.set(refresh, tool);
	Q.Page.onPush.set(refresh, tool);

	state.defaultTabName = state.defaultTabName || null;

	// catches events that bubble up from any child elements
	_addListeners(tool, $te);

	tool.$tabs = tool.$('.Q_tabs_tab');
	tool.element.removeClass('Q_tabs_arranged');	
	setTimeout(function () {
		Q.onLayout(tool).add(Q.throttle(function () {
			tool.refresh();
		}, 100, true), tool);
		tool.refresh();
	}, 100);
	Q.handle(state.onActivate, tool, [state.tab, tool.getName(state.tab)]);

	Q.addScript('{{Q}}/js/Color.js', function () {
		// NOTE: if someone else changes color in the meantime, we still remember this one:
		tool.originalWindowThemeColor = tool.originalWindowThemeColor || Q.Color.getWindowTheme();
	});
},

{
	field: 'tab',
	slot: 'content,title',
	selectors: { content: '#content_slot' },
	checkQueryString: false,
	touchlabels: undefined,
	contextualHandler: null,
	overflow: {
		content: '<span><span>{{count}} {{more}}</span></span>',
		alreadyVisible: '<span><span>{{count}} {{more}}</span></span>',
		glyph: '<svg class="Q_overflow_glyph_svg" viewBox="0 0 100 80" width="40" height="40">'
			+ '<rect y="10" width="100" height="10" rx="8"></rect>'
			+ '<rect y="40" width="100" height="10" rx="8"></rect>'
			+ '<rect y="70" width="100" height="10" rx="8"></rect>'
			+'</svg>',
		defaultText: null,
		defaultHtml: null
	},
	retain: {},
	loaderOptions: {
		retainPropertiesOf: '.Q_overflow,.Q_column_slot'
	},
	loader: Q.req,
	onClick: new Q.Event(),
	beforeSwitch: new Q.Event(),
	beforeRefresh: new Q.Event(),
	onActivate: new Q.Event(),
	onCurrent: new Q.Event(),
	onRefresh: new Q.Event(),
	onContextual: new Q.Event(),
	tabName: null, // set by indicateCurrent
	tab: null // set by indicateCurrent
},

{
	/**
	 * Call this method to correctly switch to another tab.
	 * @method switchTo
	 * @param {String|Array} name the name of the tab to switch to.
	 *  Can also be [name, tabElement]
	 * @param {Object} [loaderOptions] any options to merge on top of
	 *  tool.state.loaderOptions
	 * @param {Boolean} [loaderOptions.reload]
	 *  Reload the tab's url from the server, even if it was retained
	 * @param {Mixed} [extra] anything to pass to beforeSwitch handlers
	 */
	switchTo: function (name, loaderOptions, extra) {
		var tool = this;
		var state = this.state;
		var tab;
		loaderOptions = loaderOptions || {};
		if (tool.switching) {
			return;
		}
		tool.switching = true;
		setTimeout(function () {
			tool.switching = false;
		}, 300);
		if (Q.typeOf(name) === 'array') {
			tab = name[1];
			name = name[0];
		}
		if (tab === undefined) {
			tool.$('.Q_tabs_tab').each(function () {
				if (this.getAttribute('data-name') === name) {
					tab = this;
					return false;
				}
			});
			if (tab === undefined) {
				console.warn('Q/tabs: no tab with name "' + name + '"');
				return false;
			}
		}

		tool.$('.Q_current').addClass('Q_tabs_switchingFrom');
		$(tab).addClass('Q_tabs_switchingTo');

		state.slots = typeof state.slot === "string"
			? state.slot.split(',')
			: state.slot;

		var slots = state.slots;

		var href = tool.getUrl(tab);

		if (false === Q.handle(state.beforeSwitch, tool, [tab, href, extra])) {
			return false;
		}

		if (href && !state.selectors) {
			if (Q.Visual.shouldOpenInNewWindow(loaderOptions.event)) {
				window.open(href);
			} else {
				Q.handle(href);
			}
			return;
		}

		if (!slots || !state.selectors || !href) {
			return;
		}
		
		var fromTabName = state.tabName;
		var fromUrl = window.location.href;
		var onActivate = state.onActivate
		var o = Q.extend({
			slotNames: slots,
			onError: new Q.Event(function (msg) {
				alert(msg);
				tool.$tabs.filter('.Q_tabs_switchingFrom')
					.removeClass('Q_tabs_switchingFrom');
				tool.$tabs.removeClass('Q_tabs_switchingTo');
			}, "Q/tabs"),
			onActivate: new Q.Event(function () {
				tool.indicateCurrent(tool.getName(tab));
				tool.refresh();

				Q.handle(state.onActivate, tool, [tab, name]);
			}, "Q/tabs"),
			loadExtras: 'session',
			ignorePage: tool.isInDialog(),
			ignoreHistory: tool.isInDialog(),
			slotContainer: slotContainer,
			beforeFillSlots: beforeFillSlots,
			loader: loader,
			handler: handler
		}, 10, state.loaderOptions, 10, loaderOptions);

		if (Q.Visual.shouldOpenInNewWindow(loaderOptions.event)) {
			window.open(href);
		} else {
			Q.loadUrl(href, o);
		}

		function slotContainer(slotName) {
			var container = null;
			var selector = state.selectors[slotName];
			$(tool.element).parents().each(function () {
				var $jq = $(this).find(selector);
				if (container = $jq[0]) {
					return false;
				}
			});
			return container || document.getElementById(slotName+"_slot");
		}
		
		function beforeFillSlots(response, url, options) {
			// retain contents of existing slots
			if (state.retain === true
			|| (state.retain && state.retain[fromTabName])) {
				if (tool.retained[fromTabName] === undefined) {
					tool.retained[fromTabName] = {};
				}
				var retained = tool.retained[fromTabName];
				if (url !== fromUrl) {
					Q.extend(retained, {
						url: fromUrl,
						title: document.title,
						stored: {},
						elementsWithProperties: {}
					});
					Q.each(slots, function (i, slotName) {
						if (slotName === 'title') {
							return;
						}
						var s = retained.stored[slotName] = document.createElement('div');
						var c = slotContainer(slotName);
						var r = retained.elementsWithProperties[slotName];
						if (!r) {
							r = [s];
						}
						r = r.concat(Array.prototype.slice.call(
							c.querySelectorAll(o.retainPropertiesOf)
						));
						retained.elementsWithProperties[slotName] = r;
						Q.each(r, function (i) {
							this.Q_retained_properties = {
								scrollTop: this.scrollTop,
								scrollLeft: this.scrollLeft
							};
						});
						Q.Tool.remove(c);
						Q.each(c && c.childNodes, function () {
							s.appendChild(this);
						});
					});
				}
			}
		}
		
		function loader(urlToLoad, slotNames, callback, options) {
			var retainedFrom = tool.retained[fromTabName];
			// TODO: analyze what metas, stylesheets and scriptLines to restore later
			var response = {};
			Q.each(slots, function (i, slotName) {
				var selectors = {
					metas: 'meta[data-slot="{{slotName}}"]',
					stylesheets: 'link[data-slot="{{slotName}}"]'
				};
				for (var type in selectors) {
					response[type] = response[type] || {};
					response[type][slotName] = response[type][slotName] || [];
					var selector = selectors[type].interpolate({
						slotName: slotName
					});
					var elements = document.querySelectorAll(selector);
					Q.each(elements, function (i, element) {
						var info = {};
						Q.each(element.getAttributeNames(), function (i, name) {
							info[name] = element.getAttribute(name);
						});
						response[type][slotName].push(info);
					});
				}
			});
			tool.retained[fromTabName] = Q.extend({}, retainedFrom, 2, {
				uri: Q.info.uri,
				response: response
			});

			var url = Q.getObject([name, 'url'], tool.retained);
			if (!(state.retain === true || (state.retain && state.retain[name])) || !url) {
				// use default loader
				var _loader = loaderOptions.loader || state.loader 
					|| Q.loadUrl.options.loader || Q.request;
				return _loader.apply(this, arguments);
			}

			var request = new Q.Request(urlToLoad, slotNames, callback, options);
			var retained = tool.retained[name] || {};
			if (!retained.response) {
				// NOTE: this will skip any additional stylesheets and javascripts
				// that might appear in a real response to a server request
				retained.response = {};
			}
			if (retained.response && retained.response.slots) {
				for (var slotName in retained.slots) {
					// the slots are going to be filled in a different way
					retained.response.slots[slotName] = '';
				}
			}
			// the retained response will cause the stylesheets to load again
			Q.handle(callback, request, [null, retained.response, false]);
		}
		
		function handler(response, url, options) {
			if (state.retain === true || (state.retain && state.retain[name])) {
				// load retained url and slots back into new tab
				var retained = tool.retained[name];
				if (!Q.isEmpty(response)) {
					tool.retained[name] = {
						response: response
					}; // reset it so if we switch to this tab right away again, it will reload
				}
				if (retained && retained.stored
				&& (!loaderOptions || !loaderOptions.reload)) {
					if (retained.uri) {
						Q.info.uri = retained.uri;
					}
					history.replaceState(retained.url, retained.title);
					var elements = [];
					Q.each(retained.stored, function (slotName) {
						var element = slotContainer(slotName);
						var ep = retained.elementsWithProperties
							&& retained.elementsWithProperties[slotName];
						Q.replace(element, this);
						Q.each (ep, function () {
							for (var k in this.Q_retained_properties) {
								this[k] = this.Q_retained_properties[k];
							}
							delete this.Q_retained_properties;
						});
						Q.activate(element);
						elements.push(element);
					});
					// elements have already been activated, so return empty
					return;
				}
			}
			// use default handler
			var _handler = loaderOptions.handler || state.handler
				|| Q.loadUrl.options.handler;

			return _handler.apply(this, arguments);
		}
	},

	/**
	 * @method isInDialog
	 * @return {Boolean} whether the tabs are rendered inside an overlay / dialog
	 */
	isInDialog: function() {
		return !!$(this.element).parents('.Q_overlay').length;
	},

	/**
	 * Visually indicate the current tab.
	 * Triggers the onCurrent event
	 * @method indicateCurrent
	 * @param {String} [tab] a possible tab the caller requested to indicate as current
	 */
	indicateCurrent: function (tab) {
		var name;
		var tool = this;
		var state = tool.state;

		if (typeof tab === 'string') {
			name = tab;
			var slashed = (name + '')
				.replace(/[\\"']/g, '\\$&')
				.replace(/\u0000/g, '\\0');
			tab = tool.$tabs.filter('[data-name="'+slashed+'"]')[0];
		}
		if (!$(tool.element).closest('body').length) {
			// the replaced html probably included the tool's own element,
			// so let's find something with the same id on the page
			var key = Q.Tool.onActivate(tool.id).set(function () {
				this.indicateCurrent();
				Q.Tool.onActivate(tool.id).remove(key);
			});
			return;
		}

		var selectedTab = window.location.search.queryField(state.field);
		if (!selectedTab && state.defaultTabName != null) {
			selectedTab = state.defaultTabName;
		}
		if (selectedTab) {
			tool.$tabs.each(function (k, t) {
				var tdn = tool.getName(t);
				if (tdn === selectedTab) {
					state.defaultTabName = tdn; // overridden from URL
					state.defaultTab = t;
					return false;
				}
			});
		}

		tab = tool.getCurrentTab(tab);
		tool.$tabs.removeClass('Q_current Q_tabs_switchingTo Q_tabs_switchingFrom');
		if (tab instanceof Element) {
			tab.classList.add('Q_current');
		}

		_copyClassToOverflow(tool);
		state.tab = tab;
		state.tabName = name || tool.getName(tab);

		Q.handle(state.onCurrent, tool, [tab, state.tabName]);

		if (!Q.info.isMobile) {
			return; // don't set theme color since it's not on mobile
		}

		var tools = Q.Tool.byName('Q/columns');
		for (var toolId in tools) {
			if (tools[toolId].state.max > 0) {
				return; // don't set theme color since columns already did it
			}
		}

		var color = Q.getObject(['windowThemeColors', state.tabName], state);
		if (color instanceof Array) {
			color = color[Q.Browser.getDarkMode() ? 1 : 0];
		}
		Q.addScript('{{Q}}/js/Color.js', function () {
			Q.Color.setWindowTheme(color || tool.originalWindowThemeColor);
		});
	},

	/**
	 * Called by indicateCurrent. You can override this function to provide your
	 * own mechanisms for indicating the current tab and returning it.
	 * @method getCurrentTab
	 * @param {String} [tab] a possible tab the caller requested to indicate as current,
	 *  as a fallback if no other tab was designated as current.
	 * @return {Element} The current tab element.
	 */
	getCurrentTab: function (tab) {
		var tool = this;
		var state = tool.state;
		var $tabs = tool.$tabs;
		var name = tool.getName(tab);
		name = name || state.tabName;

		var url = location.hash.queryField('url');
		if (url === undefined) {
			url = window.location.href.split('#')[0];
			if (!state.checkQueryString) {
				url = url.split('?')[0];
			}
		} else {
			url = Q.url(url);
		}
		var urls = [url], uri;
		if (Q.info.urls && Q.info.uriString) {
			if (uri = Q.info.urls[Q.info.uriString]) {
				var uriMainPart = uri.split(/\s+/)[0];
				urls.push(Q.info.urls[uriMainPart]);	
			}
		}
		var defaultTab = null;
		if (!tab) {
			$tabs.each(function (k, t) {
				var tdn = tool.getName(t);
				var tu = tool.getUrl(t);
				if (tu === url) {
					tab = t;
					return false;
				}
			});
		}
		if (!tab) {
			$tabs.each(function (k, t) {
				var tdn = tool.getName(t);
				var tu = tool.getUrl(t);
				for (var i=0; i<urls.length; ++i) {
					var u = urls[i];
					if ((tdn && tdn === name)
					|| (!name && (
						tu === u
						|| tu === u.split('?')[0]))
					) {
						tab = t;
						return false;
					}
				}

				if (state.defaultTabName === tdn) {
					defaultTab = t;
				}
			});
		}
		if (!tab) {
			tab = defaultTab;
		}
		return tab;
	},

	/**
	 * @method getName
	 * @param {HTMLElement} tab corresponds to the tab
	 * @return {String} the name of the tab
	 */
	getName: function (tab) {
		return (tab ? tab.getAttribute("data-name") : '') || '';
	},

	/**
	 * @method getUrl
	 * @param {HTMLElement} tab corresponds to the tab
	 * @return {String} the url that the tab links to
	 */
	getUrl: function (tab) {
		var $tab = $(tab);
		var state = this.state;
		var href = tab.getAttribute('href');
		var name = tab.getAttribute("data-name");
		if (!href) {
			href = state.urls && state.urls[name];
		}
		if (!href && state.field && name) {
			var s = window.location.search;
			href = window.location.href.split('?')[0]
				+ s.queryField(state.field, name);
		}
		return href;
	},

	/**
	 * Render the tabs element again and indicate the selected tab
	 * @method refresh
	 */
	refresh: Q.preventRecursion('Q/tabs refresh', function (callback) {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);
		var html, $copied;
		var w = Math.ceil(Math.min(
			$te[0].getBoundingClientRect().width,
			$te[0].remainingWidth(true)
		));
		var w2 = 0, w3 = 0, w4 = 0, index = -10;
		var $o = $('.Q_tabs_overflow', $te);
		Q.handle(state.beforeRefresh, tool, [function (tabName) {
			var found = false;
			tool.$tabs.each(function () {
				var name = $(this).attr('data-name');
				if (name === tabName) {
					found = true;
				}
			});
			if (found && tabName) {
				tool.state.tabName = tabName;
			}
		}]);
		tool.indicateCurrent();
		if ($o.length) {
			var cs = $o.state('Q/contextual');
			if (cs) {
				if (cs.contextual) {
					$('.Q_tabs_tab', cs.contextual).insertAfter($o);
				}
				$o.plugin("Q/contextual", "remove");
			}
			$o.remove();
		}
		var $tabs = tool.$tabs = $('.Q_tabs_tab', $te);
		var $overflow, $lastVisibleTab, tabAlreadyVisible = false;
		if (state.vertical) {
			tool.element.addClass('Q_tabs_vertical');
			tool.element.addClass('Q_tabs_arranged');
			Q.handle(state.onRefresh, this);
			return callback && callback.call(this);
		} else {
			tool.element.addClass('Q_tabs_horizontal');
		}
		if (state.compact) {
			index = 0;
		} else {
			$tabs.each(function (i) {
				var cs = this.computedStyle();
				var $t = $(this);
				$t.data('index', i);
				w3 = w2;
				w2 += this.getBoundingClientRect().width
					+ parseFloat(cs.marginLeft)
					+ parseFloat(cs.marginRight);
				if (Math.floor(w2) > w) {
					index = i-1;
					return false;
				}
				w4 = w3;
			});
		}
		if (state.touchlabels) {
			$tabs.each(function () {
				if (!this.hasAttribute('data-touchlabel')) {
					this.setAttribute('data-touchlabel', $(this).text());
				}
			});
		} else {
			$tabs.removeAttr('data-touchlabel');
		}
		var $tab = $(state.tab);
		var $clone = $tab.clone().css({
			visibility: 'visible',
			display: 'inline-block',
			position: 'absolute', // so appending won't mess up layout
			top: "-1000px",
			left: "-1000px"
		});
		$clone.appendTo('body').find('*:not(:visible)').remove();
		$clone.remove();
		var visibleCount = 0;
		$tabs.each(function () {
			if ($(this).css('display') !== 'none') {
				++visibleCount;
			}
		});
		var text = $clone.text().trim();
		var values = {
			count: visibleCount - index - 1,
			text: text || state.overflow.defaultText || Q.text.Q.tabs.Menu,
			html: (text && $clone.html()) || state.overflow.defaultHtml || Q.text.Q.tabs.Menu,
			more: Q.text.Q.tabs.more,
			classes: $tab.attr('class')
		};
		var html;
		if (index >= 0 && state.overflow) {
			tabAlreadyVisible = ($tab.data('index') < index);
			html = this.state.overflow.content.interpolate(values);
			$copied = $('<span class="Q_tabs_copiedTitle">').html(html);
			$overflow = $('<li class="Q_tabs_tab Q_tabs_overflow" />')
				.empty().append($copied);
			if (!tabAlreadyVisible) {
				$overflow.addClass(values.classes); // copy its style
			}
			if (state.overflow.glyph) {
				var $glyph = $('<span class="Q_tabs_overflowGlyph" />')
				.html(state.overflow.glyph.interpolate(values));
				if (Q.info.isMobile) {
					$overflow.append($glyph);
				} else {
					$overflow.prepend($glyph);
				}
			}
			$lastVisibleTab = $tabs.eq(index);
			var oneLess = !!state.compact;
			if (!oneLess) {
				$overflow.insertAfter($lastVisibleTab);
				// REFLOW happens here
				if ($overflow.outerWidth(true) >  w - w3 - 1) {
					oneLess = true;
					++values.count;
				}
			}
			html = this.state.overflow.content.interpolate(values);
			if (oneLess) {
				--index;
				values.count = $tabs.length - index - 1;
				$copied = $('<span class="Q_tabs_copiedTitle">').html(html);
				$overflow.insertBefore($lastVisibleTab).empty().append($copied);
				if (state.overflow.glyph) {
					var $glyph = $('<span class="Q_tabs_overflowGlyph" />')
						.html(state.overflow.glyph.interpolate(values))
						.appendTo($overflow);
					if (!Q.info.isMobile && !state.compact && $overflow.outerWidth(true) > w - w4) {
						$glyph.remove(); // better to at least fit on the line
					}
				}
			}
		}
		if (!$overflow) {
			tool.$overflow = null;
			tool.element.addClass('Q_tabs_arranged');
			Q.handle(state.onRefresh, this);
			return callback && callback.call(tool);
		}
		if (tabAlreadyVisible) {
			$overflow.addClass('Q_tabs_alreadyVisible')
			.find('.Q_tabs_copiedTitle').html(
				this.state.overflow.alreadyVisible.interpolate(values)
			);
		} else {
			_copyClassToOverflow(tool);
			$overflow.addClass('Q_current');
		}
		tool.overflowIndex = index;
		setTimeout(function () {
			var $ct = $te.find('.Q_tabs_tab .Q_tabs_copiedTitle');
			if ($ct.length) {
				$ct[0].setClassIf($ct[0].isOverflowed(), 'Q_overflowed');
			}
		});
		// if (tool.$overflow
		// && (
		// 	!tool.$overflow.data('contextual')
		// 	|| tool.$overflow.closest('html').length)
		// ) {
		// 	// it's already set up and wasn't removed
		// 	tool.$tabs.css('visibility', 'visible');
		// 	Q.handle(state.onRefresh, this);
		// 	Q.handle(callback, tool);
		// 	$overflow.css('visibility', 'visible');
		// 	return;
		// }
		tool.$overflow = $overflow;
		Q.addScript("{{Q}}/js/contextual.js", function () {
			var elements = [];
			for (var i=index+1; i<$tabs.length; ++i) {
				elements.push($tabs[i]);
			}
			$(elements).removeAttr('data-touchlabel');
			$overflow.plugin("Q/contextual", "remove")
			.plugin("Q/contextual", {
				elements: elements,
				className: "Q_tabs_contextual",
				defaultHandler: state.contextualHandler,
				onConstruct: function (contextual) {
					_addListeners(tool, $(contextual));
					Q.handle(state.onRefresh, this);
					Q.handle(callback, tool);
					tool.element.addClass('Q_tabs_arranged');
					$overflow.css('visibility', 'visible');
				},
				onShow: function (cs) {
					Q.handle(state.onContextual, this, arguments);
				}
			}).data('contextual', true);
			tool.$overflowed = $(elements);
			if (Q.Contextual.current != -1) {
				// it was open, show it again
				Q.Masks.hide('Q.screen.mask');
				Q.Contextual.show(Q.Contextual.current);
			}
		});
	}),
});

function _copyClassToOverflow(tool) {
	var state = tool.state;
	var tab = tool.getCurrentTab();
	var classNames = tab && tab.className.split(' ');
	var currentClass = null;
	Q.each(classNames, function (i, className) {
		className = className.trim();
		if (className.substring(0, 6) === 'Q_tab_') {
			currentClass = className;
			return false;
		}
	});
	if (tool.$overflow) {
		tool.$overflow
			.removeClass(state.lastClass)
			.addClass(currentClass || 'Q_tabs_noMatchingTab');
	};
	state.lastClass = currentClass;
}

function _addListeners(tool, $jq) {
	var selector = '.Q_tabs_tab';
	$jq.off([Q.Pointer.fastclick, '.Q_tabs'], selector)
	.on([Q.Pointer.fastclick, '.Q_tabs'], selector, function (event) {
		if (false === tool.state.onClick.handle.call(tool, this.getAttribute('data-name'), this)) {
			return;
		}
		if (Q.Pointer.canceledClick	|| $('.Q_discouragePointerEvents', tool.element).length) {
			return;
		}
		Q.Pointer.cancelClick(true, event, {});
		var element = this;
		setTimeout(function () {
			tool.switchTo([element.getAttribute('data-name'), element], {
				event: event
			});
		}, 0);
	}).off('click.Q_tabs_preventDefault')
	.on('click.Q_tabs_preventDefault', function (event) {
		event.preventDefault();
		return false;
	});
}

})(Q, Q.jQuery);