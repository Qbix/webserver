(function (Q, $) {
/**
 * @module Q-tools
 */

/**
 * Implements lazy-loading for various types of elements.
 * By default, has implementations for "img" and "Q_tool" selectors.
 * The "Q_tool" handler only works with tools whose elements have a "data-q-lazyload" attribute .
 * Note that the elements must have a stable, nonzero width height set in the CSS even
 * if they are empty, otherwise they might not be lazy-loaded, and they might
 * thrash back and forth if removed.
 * Set Q_no_lazyload on any element that you want Q/lazyload to skip over.
 * @class Q lazyload
 * @constructor
 * @param {Object} [options] Override various options for this tool
 * @param {Array} [options.handle] Set this to 
 * @param {Object} [options.handlers] You can modify the defaults by passing an object
 *    that looks like {handlerName: { selector: String, entering: Function, exiting: Function, preparing: Function}}
 *    Function "entering" receives (element, intersectionObserverEntry)
 *    Function "exiting" receives (element, intersectionObserverEntry)
 *    Function "preparing" receives (element) and is used the first time to prepare the element
 *    Both functions must return true if the element was modified.
 * @param {Element} [options.root=tool.element] The container inside which to watch for intesections
 * @param {Number} [options.waitUntilSlowerThan=1000] How many milliseconds to wait between entering events finally lazy-loading.
 *    Set to 0 if you want to load continuously while scrolling -- but this might cause jitter.
 * @param {Object} [options.observerOptions] Override any options to pass to IntersectionObserver
 * @param {Element} [options.observerOptions.root=tool.element.scrollingParent(true)]
 * @param {String} [options.observerOptions.rootMargin='0px']
 * @param {String} [options.observerOptions.threshold=0]
 * @param {Boolean} [options.dontFreezeDimensions=false] Pass true to skip freezing dimensions when tools are removed
 * @return {Q.Tool}
 */
Q.Tool.define('Q/lazyload', function (options) {

	var tool = this;
	var state = this.state;
	state.root = state.root || this.element;

	tool.onEnteringStopped = new Q.Event();
	tool.frozenDimensions = new WeakMap();

	var Elp = Element.prototype;
	
	Q.ensure('IntersectionObserver', function () {
		// Observe whatever is on the page already
		var p = tool.element.scrollingParent(true);
		if (p === document.body) {
			p = document.documentElement;
		}
		tool.observer = _createObserver(tool, p);
		tool.observe(tool.prepare(tool.element, false));

		// Add mutation observer to detect direct DOM removals
		Q.ensure('MutationObserver', function () {
			var removalObserver = new MutationObserver(function (mutations) {
				for (var mutation of mutations) {
					for (var node of mutation.removedNodes) {
						if (!(node instanceof HTMLElement)) continue;
						tool.unobserve([node]); // safety
						forEachLazyElement(tool, node, function (element) {
							for (var name in tool.state.handlers) {
								var info = tool.state.handlers[name];
								if (element.matches(info.selector)) {
									if (element._Q_lazyload_exited
									&& element._Q_lazyload_exited[name]
									) {
										continue;
									}
									element._Q_lazyload_exited = element._Q_lazyload_exited || {};
									element._Q_lazyload_exited[name] = true;
									info.exiting && info.exiting.call(tool, element);
								}
							}
						});
					}
				}
			});
			removalObserver.observe(state.root || document.body, {
				childList: true,
				subtree: true
			});
		});

		// Override innerHTML

		var originalSet = Object.getOwnPropertyDescriptor(Elp, 'innerHTML').set;
		var originalGet = Object.getOwnPropertyDescriptor(Elp, 'innerHTML').get;

		Object.defineProperty(Elp, 'innerHTML', {
			set: function (html) {
				var element = document.createElement('div');
				var root = state.root || document.documentElement;
				var inside = (root === this) || root.contains(this);
				if (!inside) {
					originalSet.call(this, html);
					return html;
				}
				originalSet.call(element, html);
				var found = false;
				Q.each(state.handlers, function (name, info) {
					var elements = element.querySelectorAll
						? Array.from(element.querySelectorAll(info.selector))
						: [];
					if (elements.length) {
						found = true;
						return false;
					}
				});
				if (found) {
					// prepare all images 
					tool.prepare(element, true);
				}
				originalSet.call(this, originalGet.call(element));
				tool.observe(tool.prepare(this, true));
				return html;
			},
			get: originalGet
		});

		Q.each(['insertBefore', 'appendChild', 'append', 'prepend'], function (i, fn) {
			var orig = Elp[fn];
			Elp[fn] = function smartInsert(element) {
				if (element instanceof DocumentFragment) {
					var children = Array.from(element.children);
					for (var i=0, l=children.length; i<l; i++) {
						smartInsert.apply(this, [children[i], arguments[1]]);
					}
					return;
				}
				if (!(element instanceof HTMLElement)
				|| Q.replace.lazyloadDontPrepare) {
					return orig.apply(this, arguments);
				}
				var root = state.root || document.documentElement;
				var inside = (root === this) || root.contains(this);
				if (!inside) {
					return orig.apply(this, arguments);
				}
				var found = false;
				Q.each(state.handlers, function (name, info) {
					if (element.matches && element.matches(info.selector)) {
						found = true;
						return false;
					}
					var elements = element.querySelectorAll
						? Array.from(element.querySelectorAll(info.selector))
						: [];
					if (elements.length) {
						found = true;
						return false;
					}
				});
				if (found) {
					tool.observe(tool.prepare(element, true));
				}
				return orig.apply(this, arguments);
			};
		});
	});

},

{
	handlers: {
		img: {
			selector: 'img',
			entering: function (img, entry) {
				var tool = this;
				if (Q.Visual.intersection(img, tool.state.root)) {
					return _load();
				}
				tool.timeout && clearTimeout(tool.timeout);
				tool.timeout = setTimeout(function () {
					Q.handle(tool.onEnteringStopped, tool);
				}, tool.state.waitUntilSlowerThan);
				tool.onEnteringStopped.setOnce(_load);
				return true;

				function _load() {
					var src = img.getAttribute('data-lazyload-src');
					if (!src) {
						return;
					}
					img.setAttribute('src', Q.url(src));
					img.removeAttribute('data-lazyload-src');
					setTimeout(function () {
						if (!_loadedImages[src]
						&& !img.complete) {
							img.addClass('Q_lazy_load');
						}
						_loadedImages[src] = true;
						if (img.complete) {
							_loaded();
						}
						img.addEventListener('load', _loaded);
					}, 0);

					function _loaded() {
						img.addClass('Q_lazy_loaded');
					}
				}
			},
			exiting: function (img) {
				return true; // no need to do anything else
			},
			preparing: function (img, beingInsertedIntoDOM) {
				if (!beingInsertedIntoDOM) {
					return true; // too late anyway, browser will load image
				}
				if (img.hasClass('Q_lazy_load')
				|| img.hasClass('Q_lazy_loaded')
				|| img.hasClass('Q_no_lazyload')) {
					return true; // this was already processed by lazy-loading
				}
				var src = img.getAttribute('src');
				if (src && src.substring(0, 5) !== 'data:'
				&& !img.hasAttribute('data-lazyload-src')) {
					img.setAttribute('data-lazyload-src', Q.url(src));
					img.setAttribute('src', Q.url(
						Q.getObject('Q.images.lazyload.loadingSrc')
						|| "{{Q}}/img/throbbers/transparent.gif"	
					));
				}
				return true;
			}
		},
		tool: {
			selector: '.Q_tool',
			entering: function (element, entry) {
				var tool = this;
				if (Q.Visual.intersection(element, tool.state.root)) {
					return _activate();
				}
				tool.timeout && clearTimeout(tool.timeout);
				tool.timeout = setTimeout(function () {
					Q.handle(tool.onEnteringStopped, tool);
				}, tool.state.waitUntilSlowerThan);
				tool.onEnteringStopped.setOnce(_activate);
				return true;
				
				function _activate() {
					var ep = tool.frozenDimensions.get(element);
					var c = element.parentElement;
					var unfreeze = false;
					if (!ep || !c) {
						// element didn't exit before, so its dimensions weren't frozen
					} else if (!tool.state.dontFreezeDimensions) {
						var r = c.getBoundingClientRect();
						if (!ep.containerRect || ep.containerRect.width !== r.width) {
							// container width was resized, so throw away the frozen dimensions
							// because a reflow should happen anyway
							tool.frozenDimensions.delete(element);
							element.removeClass('Q_frozen_dimensions');
						} else {
							// inform tools that their element has frozen dimensions,
							// so the tools may want to revert the frozen dimensions
							element.addClass('Q_frozen_dimensions');
							element.setAttribute('data-Q-frozenDimensions', JSON.stringify(ep));
							unfreeze = true;
						}
					}
					if (element.hasAttribute('data-q-lazyload')
					&& (!element.Q || !element.Q.tool)) {
						element.addClass('Q_lazy_load');
						element.setAttribute('data-q-lazyload', 'activating');
						Q.activate(element, {}, function () {
							element.setAttribute('data-q-lazyload', 'activated');
							element.addClass('Q_lazy_loaded');
							if (unfreeze) {
								tool.unfreezeDimensions(element);
							}
						}, {lazyload: true});
					}
				}
			},
			exiting: function (element, entry) {
				var tool = this;
				if (element.hasAttribute('data-q-lazyload')
				&& element.Q.tool) {
					// Take a snapshot of the current width and height of the element,
					// to restore it when it's later inserted back into the container,
					// and prevent all the elements shifting. However, if the container
					// itself is resizing, then we will remove this snapshot since things
					// will shift anyway.
					var cs = element.computedStyle();
					tool.frozenDimensions.set(element, {
						width: element.style.width,
						height: element.style.height,
						containerRect: element.parentElement.getBoundingClientRect()
					});
					if (!tool.state.dontFreezeDimensions) {
						element.style.width = cs.width;
						element.style.height = cs.height;
					}
					Q.Tool.remove(element);
					element.removeClass('Q_lazy_loading');
					element.removeClass('Q_lazy_loaded');
					element.setAttribute('data-q-lazyload', 'removed');
					if (this.state.handlers.tool.exitingRemoveHTML) {
						element.innerHTML = '';
					}
				}
				return true;
			},
			preparing: function (element) {
				return true;
			},
			exitingRemoveHTML: true
		}
	},
	root: undefined,
	observerOptions: {
		root: undefined,
		rootMargin: '0px',
		threshold: 0
	},
	waitUntilSlowerThan: 1000,
	dontFreezeDimensions: false
}, 

{
	prepare: function (container, beingInsertedIntoDOM) {
		var tool = this;
		var found = [];
		Q.each(tool.state.handlers, function (name, info) {
			var elements = container.querySelectorAll
				? Array.from(container.querySelectorAll(info.selector))
				: [];
			if (container.matches && container.matches(info.selector)) {
				elements.push(container);
			}
			Q.each(elements, function (i, element) {
				let ancestor = element.closest('[data-Q-retain]');
				if (ancestor && Q.replace.retainedElements[ancestor.id]) {
					return;
				}
				if (info.preparing.call(tool, element, beingInsertedIntoDOM) === true) {
					found.push(element);
				}
			});
		});
		return found;
	},
	observe: function (elements) {
		var tool = this;
		tool.observer && Q.each(elements, function (i, element) {
			tool.observer.observe(element);
		});
	},
	unobserve: function (elements) {
		var tool = this;
		tool.observer && Q.each(elements, function (i, element) {
			tool.observer.unobserve(element);
		});
	},
	unfreezeDimensions: function(element) {
		var ep = this.frozenDimensions.get(element);
		if (!ep) {
			return false;
		}
		if (ep.width) {
			element.style.width = ep.width;
		} else {
			element.style.removeProperty('width');
		}
		if (ep.height) {
			element.style.height = ep.height;
		} else {
			element.style.removeProperty('height');
		}
	},
	Q: {
		beforeRemove: function () {
			this.observer && this.observer.disconnect();
		}
	}
});

function _createObserver(tool, container) {
	var o = Q.copy(tool.state.observerOptions);
	if (o.root === undefined) {
		o.root = (container === document.documentElement) ? null : (container || null);
	}
	return new IntersectionObserver(function (entries, observer) {
		Q.each(entries, function (i, entry) {
			Q.each(tool.state.handlers, function (name, info) {
				if (Q.replace.retainedElements[entry.target.id]) {
					// next time around, we'll process this element
					delete Q.replace.retainedElements[entry.target.id];
					return;
				}
				if (entry.target.matches && entry.target.matches(info.selector)) {
					if (entry.isIntersecting) {
						if (entry.target._Q_lazyload_exited) {
							delete entry.target._Q_lazyload_exited[name];
						}
						info.entering.call(tool, entry.target, entry);	
					} else  {
						var rect = entry.target.getBoundingClientRect();
						if (rect.width > 0 && rect.height > 0) {
							// also covers !document.body.contains(entry.target)
							info.exiting.call(tool, entry.target, entry);
						} // otherwise it might get a false positive
					}
				}
			});
		});
	}, o);
}

Q.lazyload = Q.lazyload || {};
var _loadedImages = Q.lazyload.loadedImages = {};

function forEachLazyElement(tool, container, callback) {
	for (var name in tool.state.handlers || {}) {
		var handler = tool.state.handlers[name];
		if (!handler || !handler.selector) {
			continue;
		}
		if (!(container instanceof HTMLElement)) {
			continue;
		}
		var elements = container.querySelectorAll(handler.selector) || [];
		if (container.matches(handler.selector)) {
			callback(container);
		}
		for (var i=0; i<elements.length; ++i) {
			callback(elements[i]);
		}
	}
}

})(Q, Q.jQuery);