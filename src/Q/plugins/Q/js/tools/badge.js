(function (Q, $) {
/**
 * @module Q-tools
 */

/**
 * Add little badge to one or multiple corners of tool element
 * @class Q badge
 * @constructor
 * @param {Object}   options Override various options for this tool
 *  @param {string}  [options.tl] settings for top left badge. If == null - badge remove.
 *  	@param {string}  [options.tl.icon] Badge icon. Can be "{{Q}}/img/..." or "../img/...". If icon=null - badge remove.
 *  	@param {string}  [options.tl.size=options.size] Badge width.
 *  	@param {string}  [options.tl.top=0] Badge top position.
 *  	@param {string}  [options.tl.left=0] Badge left position.
 *  	@param {string}  [options.tl.right] Badge right position. if defined - left position ignored.
 *  	@param {string}  [options.tl.bottom] Badge bottom position. if defined - top position ignored.
 *  	@param {string}  [options.tl.font-size] Badge content font size.
 *  	@param {string}  [options.tl.content] Badge content.
 *  	@param {string}  [options.tl.onClick] Badge click event handler.
 *  	@param {string}  [options.tl.className] Name of class to add to element
 *  	@param {string}  [options.tl.display] css "display" style for corner element.
 *		@param {Q.Event} [options.tl.onCreate] Event executed every time badge element created. Get tool as context and badge element, corner, style as arguments.
	*  @param {string}  [options.tr] settings for top right badge. If == null - badge remove.
	*  	@param {string}  [options.tr.icon] Badge icon. Can be "{{Q}}/img/..." or "../img/...". If icon=null - badge remove.
	*  	@param {string}  [options.tr.size=options.size] Badge width.
	*  	@param {string}  [options.tr.top=0] Badge top position.
	*  	@param {string}  [options.tr.right=0] Badge right position.
	*  	@param {string}  [options.tr.left] Badge left position. if defined - right position ignored.
	*  	@param {string}  [options.tr.bottom] Badge bottom position. if defined - top position ignored.
	*  	@param {string}  [options.tr.font-size] Badge content font size.
	*  	@param {string}  [options.tr.content] Badge content.
	*  	@param {string}  [options.tr.onClick] Badge click event handler.
	*  	@param {string}  [options.tr.className] Name of class to add to element
	*  	@param {string}  [options.tr.display] css "display" style for corner element.
	*	@param {Q.Event} [options.tr.onCreate] Event executed every time badge element created. Get tool as context and badge element, corner, style as arguments.
	*  @param {string}  [options.br] settings for bottom right badge. If == null - badge remove.
	*  	@param {string}  [options.br.icon] Badge icon. Can be "{{Q}}/img/..." or "../img/...". If icon=null - badge remove.
	*  	@param {string}  [options.br.size=options.size] Badge width.
	*  	@param {string}  [options.br.right=0] Badge right position.
	*  	@param {string}  [options.br.bottom=0] Badge bottom position.
	*  	@param {string}  [options.br.left] Badge left position. if defined - right position ignored.
	*  	@param {string}  [options.br.top] Badge top position. if defined - bottom position ignored.
	*  	@param {string}  [options.br.font-size] Badge content font size.
	*  	@param {string}  [options.br.content] Badge content.
	*  	@param {string}  [options.br.onClick] Badge click event handler.
	*  	@param {string}  [options.br.className] Name of class to add to element
	*  	@param {string}  [options.br.display] css "display" style for corner element.
	*	@param {Q.Event} [options.br.onCreate] Event executed every time badge element created. Get tool as context and badge element, corner, style as arguments.
	*  @param {string}  [options.bl] settings for bottom left badge
	*  	@param {string}  [options.bl.icon] Badge icon. Can be "{{Q}}/img/..." or "../img/...". If icon=null - badge remove.
	*  	@param {string}  [options.bl.size=options.size] Badge width.
	*  	@param {string}  [options.bl.bottom=0] Badge bottom position.
	*  	@param {string}  [options.bl.left=0] Badge left position.
	*  	@param {string}  [options.bl.right] Badge right position. if defined - left position ignored.
	*  	@param {string}  [options.bl.top] Badge top position. if defined - bottom position ignored.
	*  	@param {string}  [options.bl.font-size] Badge content font size.
	*  	@param {string}  [options.bl.content] Badge content.
	*  	@param {string}  [options.bl.onClick] Badge click event handler.
	*  	@param {string}  [options.bl.className] Name of class to add to element
	*  	@param {string}  [options.bl.display] css "display" style for corner element.
	*	@param {Q.Event} [options.bl.onCreate] Event executed every time badge element created. Get tool as context and badge element, corner, style as arguments.
	*  @param {string}  [options.size="15px"] Default badge size.
	*  @param {boolean} [skipOverlapped=false] If true skip overflow:'clip' parents
	* @return {Q.Tool}
	*/
Q.Tool.define("Q/badge", function () {
	var tool = this;
	var $te = $(tool.element);

	// if position of tool element "static" - need to change to "relative"
	// this is important condition for placing badges
	if ($te.css("position") === "static") {
		$te.css("position", "relative");
	}

	tool.refresh();

	// observe tool childs removed
	(new MutationObserver(function (mutations) {
		mutations.forEach(function(mutation) {
			if (mutation.type !== 'childList' || Q.isEmpty(mutation.removedNodes)) {
				return;
			}

			mutation.removedNodes.forEach(function(removedElement) {
				if (Q.instanceOf(removedElement, Element) && removedElement.classList.contains("Q_badge")) {
					setTimeout(tool.refresh.bind(tool), 500);
				}
			});
		});
	})).observe(tool.element, { 
		attributes: true, 
		childList: true, 
		characterData: true 
	});
},
{
	tl: null,
	tr: null,
	br: null,
	bl: null,
	size: "15px",
	skipOverlapped: false
},
{
	refresh: function () {
		var tool = this;
		var state = tool.state;
		var $te = $(tool.element);
		var corners = ['tl', 'tr', 'br', 'bl'];

		Q.each(corners, function(i, corner){
			var badgeStyle = state[corner];
			var $badgeElement = tool[corner];

			// if empty corner - remove this badge
			if (Q.typeOf(badgeStyle) !== "object") {
				if ($badgeElement) {
					$badgeElement.removeClass('Q_badge').remove();
				}
				return;
			}

			// default size
			badgeStyle.size = badgeStyle.size || state.size;

			var style = {
				width: badgeStyle.size,
				height: badgeStyle.size,
				"line-height": badgeStyle.size,
				'font-size': Q.getObject(['font-size'], badgeStyle) || 'auto'
			};

			if (badgeStyle.icon) {
				style['background-image'] = 'url(' + Q.url(badgeStyle.icon) + ')';
			}

			if (badgeStyle.display) {
				style.display = badgeStyle.display;
			}

			var defaultPos = -parseInt(badgeStyle.size)/3;

			// default position
			switch (corner) {
				case 'tl':
					if (badgeStyle.bottom) {
						style.bottom = badgeStyle.bottom;
						delete style.top;
					} else {
						style.top = badgeStyle.top || defaultPos;
					}

					if (badgeStyle.right) {
						style.right = badgeStyle.right;
						delete style.left;
					} else {
						style.left = badgeStyle.left || defaultPos;
					}

					break;
				case 'tr':
					if (badgeStyle.bottom) {
						style.bottom = badgeStyle.bottom;
						delete style.top;
					} else {
						style.top = badgeStyle.top || defaultPos;
					}

					if (badgeStyle.left) {
						style.left = badgeStyle.left;
						delete style.right;
					} else {
						style.right = badgeStyle.right || defaultPos;
					}

					break;
				case 'br':
					if (badgeStyle.top) {
						style.top = badgeStyle.top;
						delete style.bottom;
					} else {
						style.bottom = badgeStyle.bottom || defaultPos;
					}

					if (badgeStyle.left) {
						style.left = badgeStyle.left;
						delete style.right;
					} else {
						style.right = badgeStyle.right || defaultPos;
					}

					break;
				case 'bl':
					if (badgeStyle.top) {
						style.top = badgeStyle.top;
						delete style.bottom;
					} else {
						style.bottom = badgeStyle.bottom || -defaultPos;
					}

					if (badgeStyle.right) {
						style.right = badgeStyle.right;
						delete style.left;
					} else {
						style.left = badgeStyle.left || defaultPos;
					}

					break;
			}

			// if badge element don't exist - create one
			if (!($badgeElement instanceof jQuery) || !$badgeElement.is(":visible")) {
				if ($badgeElement instanceof jQuery && !$badgeElement.is(":visible")) {
					$badgeElement.removeClass('Q_badge').remove();
				}

				$badgeElement = $("<div class='Q_badge'>").appendTo($te);

				if (Q.typeOf(badgeStyle.onClick) === 'function') {
					$badgeElement.on(Q.Pointer.fastclick, badgeStyle.onClick);
				}

				if (badgeStyle.className) {
					$badgeElement.addClass(badgeStyle.className);
				}

				tool[corner] = $badgeElement;

				// execute onCreate event every time element created
				Q.handle(badgeStyle.onCreate, tool, [$badgeElement, corner, style]);
			}

			tool.badge = $badgeElement[0];

			if (badgeStyle.content) {
				$badgeElement.html(badgeStyle.content).activate();
			}

			// remove old styles and apply new
			$badgeElement.removeProp("style").css(style);

			if (!state.skipOverlapped) {
				var badgeRect = $badgeElement[0].getBoundingClientRect();
				var scrollingParent = tool.element.scrollingParent(false, 'vertical', true);
				var $jq = $te.parents().addBack()
					.filter(function (i, e) {
						if (e === scrollingParent || e.contains(scrollingParent)) {
							return false;
						}

						var parentRect = e.getBoundingClientRect();
						return (badgeRect.left < parentRect.left
							|| badgeRect.right > parentRect.right
							|| badgeRect.top < parentRect.top
							|| badgeRect.bottom > parentRect.bottom)
							&& ($(e).css('overflow-x').toLowerCase() === 'hidden'
								|| $(e).css('overflow-y').toLowerCase() === 'hidden');
					}).incrementClass('Q_badge_parent_overlapped');
				$te.data('Q_badge incrementedClass', $jq);
			}
		});

		// remove copied elements
		$(".Q_badge").each(function(){
			var $this = $(this);
			
			if (!$this.parent().hasClass("Q_badge_tool")) {
				$this.remove();
			}
		}).addClass('Q_badge_positioned');
	},
	Q: {
		beforeRemove: function () {
			var interval = Q.getObject(["state", "interval"], this);
			interval && clearInterval(interval);

			if (!this.state.skipOverlapped) {
				var incrementedClass = $(this.element).data('Q_badge incrementedClass');
				incrementedClass && incrementedClass.decrementClass('Q_badge_parent_overlapped');
			}
		}
	}
});

if (Q.info.browser.name === 'safari') {
	// overflow-clip doesn't work so this is a fallback:
	document.head.appendChild(Q.element('style', {
		'type': 'text/css'
	}, '.Q_badge_parent_overlapped:not(.Q_badge_parent_clip) {'
		+ ' overflow: visible !important; '
		+ '}'
	));
}

})(Q, Q.jQuery);
