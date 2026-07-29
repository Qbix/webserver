(function (window, Q, $, undefined) {
	
/**
 * @module Q
 */
	
/**
 * Render tool to select clip start/end position
 * @class Q clip
 * @constructor
 * @param {Object} [options] Override various options for this tool
 *  @param {String|Integer} [options.startPosition] Start position
 *  @param {String|Integer} [options.endPosition] End position
 *  @param {Q.Event} onStart event trigger when clip start position defined
 *  @param {Q.Event} onEnd event trigger when clip end position defined
 */
Q.Tool.define("Q/clip", function (options) {
	var tool = this;

	var p = Q.pipe(['stylesheet', 'text'], tool.refresh.bind(tool));
	
	Q.addStylesheet("{{Q}}/css/tools/clip.css", p.fill('stylesheet'), { slotName: 'Q' });
	Q.Text.get('Q/content', function (err, text) {
		tool.text = text.clip;
		p.fill('text')();
	});

	tool.originalHTML = tool.element.innerHTML;
},

{ // default options here
	startPosition: null,
	endPosition: null,
	onStart: new Q.Event(),
	onEnd: new Q.Event()
},

{ // methods go here
	/**
	 * Refreshes the appearance of the tool completely
	 * @method refresh
	 */
	refresh: function () {
		var tool = this;
		var $toolElement = $(this.element);
		var state = tool.state;

		Q.Template.render('Q/clip', {
			startPositionDisplay: state.startPositionDisplay,
			endPositionDisplay: state.endPositionDisplay,
			startPosition: state.startPosition,
			endPosition: state.endPosition,
			startFixed: state.startPosition !== null ? 'Q_clip_fixed' : '',
			endFixed: state.endPosition !== null ? 'Q_clip_fixed' : '',
			text: tool.text
		}, function (err, html) {
			$toolElement.html(html + tool.originalHTML);

			$("button", $toolElement).on(Q.Pointer.fastclick, function () {
				var $this = $(this);
				var name = $this.prop("name").toCapitalized();

				$this.toggleClass("Q_clip_fixed");

				if ($this.hasClass('Q_clip_fixed')) {
					Q.handle(state["on" + name], tool, [true]);
				} else {
					Q.handle(state["on" + name], tool, [false]);
				}
			});
		});
	},
	/**
	 * Set new position of start/end clip position
	 * @method setPosition
	 * @param {int} position
	 * @param {string} positionDisplay Human readable position
	 * @param {string} which Can be 'start' or 'end'
	 */
	setPosition: function (position, positionDisplay, which) {
		var tool = this;
		var oppositePos;
		var $button = $("button[name=" + which + "]", this.element);

		// check conformity
		switch (which) {
			case "start":
				oppositePos = parseInt(tool.getPosition("end"));
				if (!isNaN(oppositePos) && position > oppositePos) {
					$button.removeClass("Q_clip_fixed");
					return Q.alert(tool.text.StartMoreEnd);
				}
				break;
			case "end":
				oppositePos = parseInt(tool.getPosition("start"));
				if (!isNaN(oppositePos) && position < oppositePos) {
					$button.removeClass("Q_clip_fixed");
					return Q.alert(tool.text.EndLessStart);
				}
				break;
			default:
				$button.removeClass("Q_clip_fixed");
				return Q.alert(tool.text.WhichInvalid);
		}

		$(".Q_clip_position", $button).text(position);
		$(".Q_clip_position_display", $button).text(positionDisplay);
	},
	/**
	 * Get position of start/end clip position
	 * @method getPosition
	 * @param {string} which Can be 'start' or 'end'
	 * @return {integer} position
	 */
	getPosition: function (which) {
		var position = $("button[name=" + which + "].Q_clip_fixed .Q_clip_position", this.element).text();
		return position;
	}
});

Q.Template.set('Q/clip', `
	<div class="Q_clip_buttons">
		<button name="start" class="{{startFixed}}" type="button">
			<span class="Q_clip_defined">{{text.ClipStart}}: </span>
			<span class="Q_clip_set">{{text.SetClipStart}}</span>
			<span class="Q_clip_position_display">{{startPositionDisplay}}</span>
			<span class="Q_clip_position">{{startPosition}}</span>
		</button>
		<button name="end" class="{{endFixed}}" type="button">
			<span class="Q_clip_defined">{{text.ClipEnd}}: </span>
			<span class="Q_clip_set">{{text.SetClipEnd}}</span>
			<span class="Q_clip_position_display">{{endPositionDisplay}}</span>
			<span class="Q_clip_position">{{endPosition}}</span>
		</button>
	</div>
`);

})(window, Q, Q.jQuery);