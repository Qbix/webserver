(function (window, Q, $, undefined) {
	/**
	 * Renders a webpage inside an iframe, with optional width-scaling
	 * @module Q
	 * @class Q webpage
	 * @constructor
	 * @param {Object} [options]
	 * @param {String} options.url - The webpage to display
	 * @param {Integer|null} [options.innerWidth=null] - Virtual intended width in px
	 * @param {Float|null} [options.scale=null] - Direct transform scale (if no innerWidth)
	 */
	Q.Tool.define("Q/webpage", function (options) {
		var tool = this;
		var state = tool.state;

		if (!state.url) return;

		Q.Template.render("Q/webpage", {
			url: state.url
		}).then(function (html) {
			Q.replace(tool.element, html);

			var iframe = tool.element.querySelector("iframe");
			if (!iframe) return;

			var wrapper = iframe.parentNode;
			var containerWidth = tool.element.offsetWidth;

			// Priority: innerWidth > scale > no transform
			if (state.innerWidth) {
				var scale = containerWidth / state.innerWidth;
				iframe.style.width = state.innerWidth + "px";
				iframe.style.height = "100%";
				iframe.style.transform = "scale(" + scale + ")";
				iframe.style.transformOrigin = "top left";
			} else if (state.scale) {
				var scale = parseFloat(state.scale) || 1.0;
				iframe.style.width = (100 / scale) + "%";
				iframe.style.height = (100 / scale) + "%";
				iframe.style.transform = "scale(" + scale + ")";
				iframe.style.transformOrigin = "top left";
			} else {
				// No transform, natural iframe size
				iframe.style.width = "100%";
				iframe.style.height = "100%";
			}
		});
	}, {
		url: null,
		innerWidth: null,
		scale: null
	}, {});
})(window, Q, Q.jQuery);