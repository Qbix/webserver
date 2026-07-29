(function (Q, $) {
/**
 * @module Q-tools
 */
    
/**
 * Implements expandable containers that work on most modern browsers,
 * including ones on touchscreens.
 * @class Q expandable
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {String|Element} [options.title] Required. The title for the expandable, as HTML or Element.
 *  @param {String|Element|Array} [options.content] Required. The content. Can be an HTML string, an element, array of elements.
 *  @param {Number} [options.count] A number, if any, to display when collapsed.
 *  @param {Number} [options.spaceAbove] How many pixels of space to leave above at the end of the scrolling animation
 *  @param {Boolean} [options.expanded] Whether it should start out expanded
 *  @param {Boolean} [options.evenIfFilled=false] If true, fill tool.element with content event if tool.element have children
 *  @param {Boolean} [options.autoCollapseSiblings=true] Whether, when expanding an expandable, its siblings should be automatically collapsed.
 *  @param {Boolean} [options.scrollContainer] Whether to scroll a parent container when necessary
 *   @param {Q.Event} [options.onRefresh] Event occurs when tool element has rendered with content
 *   @param {Q.Event} [options.onExpand] Event occurs when content has been expanded
 *   @param {Q.Event} [options.beforeExpand] Event occurs when content is about to be expanded
 *   @param {Q.Event} [options.onCollapse] Event occurs when content has been collapsed
 *   @param {Q.Event} [options.beforeCollapse] Event occurs when content is about to be collapsed
 * @return {Q.Tool}
 */
Q.Tool.define('Q/expandable', function (options) {
    var tool = this;
    var state = tool.state;
    var $te = $(tool.element);

    if (state.evenIfFilled || !$te.children().length) {
        // set it up with javascript
        var count = options.count || '';
        var h2 = Q.element('h2', {"class": "Q_clearfix Q_expandable_title"}, [
            Q.element('span', {"class": "Q_expandable_count"}, [count]),
            options.title
        ]);
        var content = Q.isArrayLike(options.content) ? options.content : [options.content];
        var div = Q.element('div', {"class": "Q_expandable_container"}, [
            Q.element('div', {"class": "Q_expandable_content"}, content)
        ]);
        this.element.innerHTML = '';
        this.element.append(h2, div);
        setTimeout(function () {
            Q.handle(state.onRefresh, tool);
        }, 0);
    }
    
    var $h2 = $('>h2', $te);
    if (state.expanded == null) {
        state.expanded = $h2.next().is(':visible');
    } else if (state.expanded) {
        $h2.addClass('Q_expanded')
        .next().addClass('Q_expanded')
        .css('display', 'block');
        $te.addClass('Q_expanded');
    } else {
        $h2.next().removeClass('Q_expanded');
    }
    
    this.element.preventSelections(true);
    $h2.on(Q.Pointer.fastclick, function () {
        if ($h2.hasClass('Q_expanded')) {
            tool.collapse();
        } else {
            tool.expand();
        }
    }).on(Q.Pointer.start, function () {
        var $this = $(this);
        $this.addClass('Q_pressed');
        function f() {
            $this.removeClass('Q_pressed');
            $(window).off(Q.Pointer.end, f);
        }
        $(window).on(Q.Pointer.end, f);
    });
    if (!Q.info.isTouchscreen) {
        $h2.on('mouseenter', function () {
            $(this).addClass('Q_hover');
        }).on('mouseleave', function () {
            $(this).removeClass('Q_hover');
        });
    }
    tool.Q.onStateChanged('count').set(function () {
        $h2.find('.Q_expandable_count').html(state.count);
    });
}, {
    count: 0,
    spaceAbove: null,
    expanded: null,
    autoCollapseSiblings: true,
    scrollContainer: true,
    animation: {
        duration: 300,
        ease: "linear"
    },
    evenIfFilled: false,
    onRefresh: new Q.Event(),
    beforeExpand: new Q.Event(),
    onExpand: new Q.Event(),
    beforeCollapse: new Q.Event(),
    onCollapse: new Q.Event()
}, {
    /**
     * @method expand
     * @param {Object} [options]
     *  @param {Boolean} [options.autoCollapseSiblings] Pass false to skip collapsing siblings even if state.collapseSiblings is currently true
     *  @param {Object} [options.animation]
     *  @param {Object} [options.animation.duration=300] Pass 0 to skip animations
     *  @param {Boolean} [options.scrollContainer] Whether to scroll a parent container
     *  @param {Boolean} [options.scrollToElement] Can be used to specify another element to scroll to when expanding. Defaults to the title element of the expandable.
     *  @param {Number} [options.spaceAbove] How many pixels of space to leave above at the end of the scrolling animation
     * @param {Function} [callback] the function to call once the expanding has completed
     */
    expand: function (options, callback) {
        var tool = this;
        var state = tool.state;
        var $te = $(this.element);
        if (false === Q.handle(state.beforeExpand, this, [])) {
            return false;
        }
        var o = Q.extend({}, tool.state, options);
        var $h2 = $('>h2', $te);
        var $parent = $te.parent();

        if (o.autoCollapseSiblings) {
            $parent[0].forEachTool("Q/expandable", function () {
                if (this.id === tool.id) {
                    return;
                }
                this.collapse(null, {dontScrollIntoView: true});
            });
        }

        var $content = $h2.next();

        // measure natural height before animating
        $content[0].style.display = "block";

		// temporarily enable measurement
		$content.addClass("Q_expandable_measuring");

		// force layout read
		var fullHeight = $content[0].scrollHeight;

		// remove measurement class
		$content.removeClass("Q_expandable_measuring");


        // prepare for animation
        $content[0].style.overflow = "hidden";
        $content[0].style.maxHeight = "0px";

        $te.addClass("Q_expanding");

        Q.Animation.play(function (x, y) {

            // animate like jQuery slideDown: 0 -> fullHeight
            $content[0].style.maxHeight = (y * fullHeight) + "px";

            // scroll logic preserved exactly as before
            var $scrollable = o.scrollContainer
                ? ((o.scrollContainer instanceof Element)
                    ? $(o.scrollContainer) : tool.scrollable()
                ) : $();
            var offset = $scrollable.length
                ? $scrollable.offset()
                : {left: 0, top: 0};
            var $element = o.scrollToElement
                ? $(o.scrollToElement)
                : $h2;
            var t1 = $element.offset().top - offset.top;
            var defaultSpaceAbove = $element.height() / 2;
            var moreSpaceAbove = 0;
            var $ts = $te.closest(".Q_columns_column").find(".Q_columns_title");
            if ($ts.length && $ts.css("position") === "fixed") {
                moreSpaceAbove = $ts.outerHeight();
            } else {
                $("body").children().each(function () {
                    if (!$scrollable.length) {
                        return;
                    }
                    var scrollableRect = $scrollable[0].getBoundingClientRect();
                    var $this = $(this);
                    var fixedRect = this.getBoundingClientRect();
                    var midpoint = (scrollableRect.left + scrollableRect.right) / 2;
                    if ($this.css("position") === "fixed"
                    && fixedRect.left <= midpoint
                    && fixedRect.right >= midpoint
                    && fixedRect.top <= scrollableRect.top) {
                        var top = $this.offset().top - Q.Pointer.scrollTop();
                        if (top < 100) {
                            moreSpaceAbove = top + $this.outerHeight();
                            return false;
                        }
                    }
                });
            }
            defaultSpaceAbove += moreSpaceAbove;
            var spaceAbove = (state.spaceAbove == null)
                ? defaultSpaceAbove
                : state.spaceAbove;
            var isBody = $scrollable.length &&
                ["BODY", "HTML"].indexOf($scrollable[0].tagName.toUpperCase()) >= 0;
            if (isBody) {
                t1 -= Q.Pointer.scrollTop();
            }
            if ($scrollable.length) {
                var t = $element.offset().top - offset.top;
                if (isBody) {
                    t -= Q.Pointer.scrollTop();
                }
                var scrollTop = $scrollable.scrollTop() + t - t1 * (1-y) - spaceAbove * y;
                $scrollable.scrollTop(scrollTop);
            }
        }, state.animation.duration, state.animation.ease || "linear").onComplete.set(_proceed);

        function _proceed() {
            $content[0].style.removeProperty("max-height");
            $content[0].style.removeProperty("overflow");

            $h2.add($content).add($te)
                .removeClass("Q_expanding")
                .addClass("Q_expanded");

            state.expanded = true;
            tool.stateChanged("expanded");

            Q.handle(callback, tool, [options || {}]);
            Q.handle(state.onExpand, tool, [options || {}]);
        }
    },
    
    collapse: function (callback, options) {
        var tool = this;
        var state = this.state;
        var $h2 = $('>h2', this.element);
        var $te = $(this.element);
        if (!$te.hasClass("Q_expanded")) {
            return false;
        }
        var $content = $h2.next();

        // measure full height before collapsing
        var fullHeight = $content[0].scrollHeight;

        $content[0].style.overflow = "hidden";
        $content[0].style.maxHeight = fullHeight + "px";

        $te.addClass("Q_collapsing");

        Q.Animation.play(function (x, y) {
            // natural jQuery style slideUp: fullHeight -> 0
            $content[0].style.maxHeight = ((1 - y) * fullHeight) + "px";
        }, state.animation.duration, state.animation.ease || "linear").onComplete.set(_proceed);

        function _proceed() {
            $content[0].style.maxHeight = "0px";
            $content[0].style.removeProperty("overflow");

            $h2.add($content).add($te)
                .removeClass("Q_expanded Q_collapsing");

            state.expanded = false;
            tool.stateChanged("expanded");

            if (!options || !options.dontScrollIntoView) {
                tool.element.scrollIntoView({behavior: "smooth"});
            }

            Q.handle(callback, tool, []);
            Q.handle(tool.state.beforeCollapse, tool, []);
            Q.handle(tool.state.onCollapse, tool, []);
        }
    },
    
    scrollable: function () {
        return $(this.element.scrollingParent(true, "vertical"));
    }
});

})(Q, jQuery);