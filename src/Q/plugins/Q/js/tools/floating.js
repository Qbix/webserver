(function (Q, $) {
	/**
	 * @module Q-tools
	 */

	/**
	 * This tool make element draggable
	 * @class Q floating
	 * @constructor
	 * @param {Object}   [options] Override various options for this tool
	 * @return {Q.Tool}
	 */
	Q.Tool.define("Q/floating", function (options) {
		var tool = this;
		var $toolElement = $(tool.element);
		var state = tool.state;
		var $body = $('body');
		var dragging = false, dragged = false;

		Q.addStylesheet('{{Q}}/css/tools/floating.css');

		var Q_floating_dragging = function(e) {
			if (!dragging) {
				return;
			}

			dragged = true;
			$toolElement.offset({
				top: Q.Pointer.getY(e) - state.offset.diffTop,
				left: Q.Pointer.getX(e) - state.offset.diffLeft
			});
		};

		$toolElement.on(Q.Pointer.start, function (e) {
			$(e.target).attr({
				"unselectable": "on",
				"data-dragging": true
			});

			state.offset = $toolElement.offset();
			state.offset.diffTop = Q.Pointer.getY(e) - state.offset.top;
			state.offset.diffLeft = Q.Pointer.getX(e) - state.offset.left;

			dragged = false
			$body.on(Q.Pointer.move, Q_floating_dragging);
			dragging = true;
		});
		$body.on(Q.Pointer.end, function (e) {
			$body.off(Q.Pointer.move, Q_floating_dragging);

			$(e.target).removeAttr("unselectable").removeAttr("data-dragging");
			dragging = false;
		});
	},

	{
	},

	{

	});
})(Q, Q.jQuery);
