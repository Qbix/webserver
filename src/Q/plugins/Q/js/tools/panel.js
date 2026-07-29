(function (Q, $) {

/**
 * Q Tools
 * @module Q-tools
 */

/**
 * This constructor activates a Q/panel tool rendered on the server side
 * @class Q panel
 * @constructor
 */

Q.Tool.define("Q/panel", function() {

	// constructor & private declarations
	var tool = this;
	var form_val = null;
	var $te = $(tool.element);
	var form = $('form', $te);
	var container = $('.Q_panel_tool_container', $te);
	
	Q.addStylesheet('{{Q}}/css/tools/panel.css');

	this.forEachChild('Q/form', function (options) {
		this.state.onSubmit.set(function() {
			var buttons = $('.Q_panel_tool_buttons', $te);
			buttons.addClass('Q_throb');
		}, tool);
		this.state.onResponse.set(function(err, response) {
			var buttons = $('.Q_panel_tool_buttons', $te);
			buttons.removeClass('Q_throb');
		}, tool);
		this.state.onSuccess.set(function() {
			var container = $('.Q_panel_tool_container', $te);
			form_val = form.serialize();
			container.removeClass('Q_modified');
			container.removeClass('Q_editing');
		}, tool);
		this.slotsToRequest = 'form,static';
	}, 'Q/panel');

	var edit_button = $('.Q_panel_tool_edit', $te);
	var cancel_button = $('button.Q_panel_tool_cancel', $te);
	form_val = form.serialize();
	form.on('change keyup keydown blur', function(e) {
		var new_val = form.serialize();
		if (form_val !== new_val) {
			container.addClass('Q_modified');
		} else {
			container.removeClass('Q_modified');
		}
		if (e.keyCode === 27) {
			cancel_button.click();
		}
	});
	if (container.hasClass('Q_panel_tool_toggle_onclick')) {
		var header = $('.Q_panel_tool_header', container);
		header.click(function() {
			if (container.hasClass('Q_panel_collapsed')) {
				container.removeClass('Q_panel_collapsed');
				container.addClass('Q_expanded');
			} else {
				container.addClass('Q_panel_collapsed');
				container.removeClass('Q_expanded');
			}
		});
	} else if (container.hasClass('Q_panel_tool_toggle_move')) {
		var header = $('.Q_panel_tool_header', container);
		header.mouseenter(function() {
			container.removeClass('Q_panel_collapsed');
			container.addClass('Q_expanded');
		});
		container.mouseleave(function() {
			container.addClass('Q_panel_collapsed');
			container.removeClass('Q_expanded');
		});
	}
	edit_button.click(function() {
		container.addClass('Q_editing');
		container.removeClass('Q_panel_collapsed');
		container.addClass('Q_expanded');
		container.find('.Q_panel_tool_form :input:visible').eq(0).focus();
		return false;
	});
	cancel_button.click(function() {
		container.removeClass('Q_editing');
		container.removeClass('Q_modified');
		return true; // really cancel the form
	});
});

})(Q, Q.jQuery);