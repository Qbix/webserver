(function (Q, $, window, document, undefined) {

/**
 * Q Tools
 * @module Q-tools
 * @main Q-tools
 */
	
/**
 * Inplace text editor tool
 * @class Q inplace
 * @constructor
 * @param {Object} [options] This is an object of parameters for this function
 *  @param {String} options.action Required url of the action to issue the request to on save.
 *  @param {String} [options.method='put'] The HTTP verb to use.
 *  @param {String} [options.type='textarea'] The type of the input field. Can be "textarea", "text", or "select"
 *  @param {String} [options.capitalize=false] Whether to automatically capitalize the first letter
 *  @param {String} [options.options={}] If the type is "select", then this would be an object of {value: optionTitle} pairs
 *  @param {Boolean} [options.editing=true] Whether to start out in editing mode
 *  @param {Boolean} [options.editOnClick=true] Whether to enter editing mode when clicking on the text.
 *  @param {Boolean} [options.selectOnEdit=true] Whether to select everything in the input field when entering edit mode.
 *  @param {Boolean} [options.showEditButtons=false] Set to true to force showing the edit buttons on touchscreens
 *  @param {Number} [options.maxWidth=null] The maximum width that the field can grow to
 *  @param {Number} [options.minWidth=100] The minimum width that the field can shrink to
 *  @param {String} [options.staticHtml] The static HTML to start out with
 *  @param {String} [options.placeholder=null] Text to show in the staticHtml or input field when the editor is empty
 *  @param {Object} [options.template]  Can be used to override info for the tool's view template.
 *	@param {String} [options.template.dir='{{Q}}/views']
 *	@param {String} [options.template.name='Q/inplace/tool']
 *  @param {Q.Event} [options.beforeSave] This event triggers before save
 *  @param {Q.Event} [options.onSave] This event triggers after save
 *  @param {Q.Event} [options.onCancel] This event triggers after canceling
 */
Q.Tool.define("Q/inplace", function (options) {
	var tool = this;
	var state = tool.state;
	var $te = $(tool.element);
	var container = tool.$('.Q_inplace_tool_container');
	if (container.length) {
		return _Q_inplace_tool_constructor.call(tool, this.element, state);
	}
	
	Q.addStylesheet('{{Q}}/css/tools/inplace.css');
	
	// if activated with JS should have following options:
	//	- action: required. the form action to save tool value
	//	- name: required. the name for input field
	//	- method: request method, defaults to 'PUT'
	//	- type: type of the input - 'text' or 'textarea', defaults to 'textarea'

	if (!state.action) {
		return console.error("Q/inplace tool: missing option 'action'", state);
	}
	if (!state.field) {
		return console.error("Q/inplace tool: missing option 'field'", state);
	}
	var staticHtml = state.staticHtml || $te.html();
	var staticClass = state.type === 'textarea'
		? 'Q_inplace_tool_blockstatic' 
		: 'Q_inplace_tool_static';
	if (state.type !== 'textarea' && state.type !== 'text' && state.type !== 'select') {
		throw new Q.Exception("Q/inplace: type must be textarea, text or select");
	}
	Q.Template.render(
		'Q/inplace/tool',
		{
			'classes': state.editing ? 'Q_editing Q_nocancel' : '',
			staticClass: staticClass,
			staticHtml: staticHtml || '<span class="Q_placeholder">state.placeholder.encodeHTML()</div>',
			method: state.method || 'put',
			action: state.action,
			field: state.field,
			isText: (state.type === 'text'),
			isTextarea: (state.type === 'textarea'),
			isSelect: (state.type === 'select'),
			options: state.options,
			placeholder: state.placeholder,
			text: staticHtml.decodeHTML(),
			type: state.type || 'text'
		},
		function (err, html) {
			if (err) {
				return;
			}
			Q.replace(tool.element, html);

			return _Q_inplace_tool_constructor.call(tool, tool.element, state, staticHtml);
		},
		state.template
	);
},

{
	method: 'put',
	type: 'textarea',
	capitalize: false,
	editOnClick: true,
	selectOnEdit: true,
	showEditButtons: false,
	maxWidth: null,
	minWidth: '.Q_placeholder',
	placeholder: 'Type something...',
	cancelPrompt: "Would you like to save your changes?",
	template: {
		dir: '{{Q}}/views',
		name: 'Q/inplace/tool'
	},
	timing: {
		waitingInterval: 500,
	},
	onLoad: new Q.Event(),
	beforeSave: new Q.Event(),
	onSave: new Q.Event(),
	onCancel: new Q.Event()
},

{
	/**
	 * Hide Q/actions, if any
	 * @method hideActions
	 */
	hideActions: function () { // Temporarily hide Q/actions if any
		this.actionsContainer = $('.Q_actions_container');
		this.actionsContainerVisibility = this.actionsContainer.css('visibility');
		this.actionsContainer.css('visibility', 'hidden');
	},
	
	/**
	 * Restore Q/actions, if any
	 * @method restoreActions
	 */
	restoreActions: function () { // Restore Q/actions if any
		if (!this.actionsContainer) return;
		this.actionsContainer.css('visibility', this.actionsContainerVisibility);
		delete this.actionsContainer;
		delete this.actionsContainerVisibility;
	}
}

);

function _setSelRange(inputEl, selStart, selEnd) {
	if ('setSelectionRange' in inputEl) {
		inputEl.focus();
		inputEl.setSelectionRange(selStart, selEnd);
	} else if (inputEl.createTextRange) {
		var range = inputEl.createTextRange();
		range.collapse(true);
		range.moveEnd('character', selEnd);
		range.moveStart('character', selStart);
		range.select();
	}
}

function _Q_inplace_tool_constructor(element, options, staticHtml) {

	// constructor & private declarations
	var tool = this;
	var state = tool.state;
	var blurring = false;
	var focusedOn = null;
	var dialogMode = false;
	var previousValue = null;
	var noCancel = false;
	var $te = $(tool.element);
	var changedMaxWidth, changedMaxHeight;

	var container_span = tool.$container = tool.$('.Q_inplace_tool_container');
	var static_span = tool.$static = tool.$(
		'.Q_inplace_tool_static, .Q_inplace_tool_blockstatic'
	).eq(0);
	function _waitUntilVisible() {
		if (tool.removed) return;
		if (!$te.is(':visible')) {
			return setTimeout(_waitUntilVisible, state.timing.waitingInterval);
		}
		tool.$('.Q_inplace_tool_editbuttons').css({ 
			'margin-top': static_span.outerHeight() + 'px',
			'line-height': '1px'
		});
		fieldinput.plugin('Q/placeholders', function () {
			fieldinput.plugin('Q/autogrow', {
				maxWidth: state.maxWidth || maxWidth,
				minWidth: state.minWidth || 0
			}, _adjustButtonLayout);
		});
		_sizing();
	}
	var edit_button = tool.$edit = tool.$('button.Q_inplace_tool_edit');
	var save_button = tool.$save = tool.$('button.Q_inplace_tool_save');
	var cancel_button = tool.$cancel = tool.$('button.Q_inplace_tool_cancel');
	var fieldinput = tool.$input = tool.$(':input[type!=hidden]').not('button').eq(0)
		.addClass('Q_inplace_tool_fieldinput');
	if (state.capitalize) {
		tool.element.addClass('Q_capitalize');
	}
	var undermessage = tool.$('.Q_inplace_tool_undermessage');
	var throbber_img = $('<img />')
		.attr('src', Q.url('{{Q}}/img/throbbers/bars16.gif'));
	if (container_span.hasClass('Q_nocancel')) {
		noCancel = true;
	}
	Q.onLayout($te[0]).set(_waitUntilVisible, tool);
	_waitUntilVisible();
	if (state.type === 'select') {
		fieldinput.val(staticHtml && staticHtml.decodeHTML());
	}
	if (staticHtml && state.editOnClick) {
		static_span.attr('title', state.placeholder);
	}
	previousValue = fieldinput.val();
	var maxWidth = state.maxWidth;
	if (!maxWidth) {
		$te.parents().each(function () {
			var $this = $(this);
			var display = $this.css('display');
			if (display === 'block' || display === 'table-cell'
			|| (display === 'inline-block' && this.style.width)) {
				maxWidth = this;
				return false;
			}
		});
	}
	setTimeout(function () {
		state.onLoad.handle();
	}, 0); // hopefully it will be inserted into the DOM by then
	function _sizing() {
		fieldinput.css({
			fontSize: static_span.css('fontSize'),
			fontFamily: static_span.css('fontFamily'),
			fontWeight: static_span.css('fontWeight'),
			letterSpacing: static_span.css('letterSpacing'),
			textAlign: static_span.css('textAlign')
		});
		if (!fieldinput.data('inplace')) {
			fieldinput.data('inplace', {});
		}
		if (container_span.hasClass('Q_editing')) {
			fieldinput.data('inplace').widthWasAdjusted = true;
			fieldinput.data('inplace').heightWasAdjusted = true;
		}
		if (fieldinput.is('textarea') && !fieldinput.val()) {
			var height = static_span.outerHeight() + 'px';
			fieldinput.add(static_span).css('min-height', height);
		}
	}
	this.handleClick = function(event) {
		event.preventDefault();
		event.stopPropagation();
		_sizing();
		var field_width = static_span.outerWidth();
		var field_height = static_span.outerHeight();
		changedMaxWidth = changedMaxHeight = false;
		if (container_span.css('max-width') === 'none') {
			container_span.css('max-width', container_span.width());
			changedMaxWidth = true;
		}
		if (container_span.css('max-height') === 'none') {
			container_span.css('max-height', container_span.height());
			changedMaxHeight = true;
		}
		container_span.addClass('Q_editing');
		container_span.addClass('Q_discouragePointerEvents');
		_beganEditing(container_span);
		if (state.bringToFront) {
			var $bringToFront = $(state.bringToFront);
			var pos = $bringToFront.css('position');
			$bringToFront.data(_stateKey_zIndex, $bringToFront.css('zIndex'))
				.data(_stateKey_position, pos)
				.css({
					zIndex: 99999,
					position: (pos === 'static') ? 'relative' : pos
				});
		}
		fieldinput.plugin('Q/placeholders', {}, function () {
			this.plugin('Q/autogrow', {
				maxWidth: state.maxWidth || maxWidth,
				minWidth: state.minWidth || 0,
				onResize: {"Q/inplace": _adjustButtonLayout}
			});
		});
		if (fieldinput.is('select')) {
			field_width += 40;
		} else if (fieldinput.is('input[type=text]')) {
			field_width += 5;
			field_height = static_span.css('line-height');
		} else if (fieldinput.is('textarea')) {
			field_height = Math.max(field_height, 10);
		}
		fieldinput.css({
			fontSize: static_span.css('fontSize'),
			fontFamily: static_span.css('fontFamily'),
			fontWeight: static_span.css('fontWeight'),
			letterSpacing: static_span.css('letterSpacing'),
			width: field_width + 'px'
		});

		tool.hideActions();
		
		previousValue = fieldinput.val();
		if (!fieldinput.is('select')) {
			fieldinput.data('inplace').widthWasAdjusted = true;
			try {
				fieldinput.data('inplace').heightWasAdjusted = true;
			} catch (e) {

			}
			fieldinput.trigger('autogrowCheck');
		}
		_updateSaveButton();
		undermessage.empty().css('display', 'none').addClass('Q_error');
		focusedOn = 'fieldinput';
		fieldinput.focus();
		var selStart = 0;
		if (state.selectOnEdit) {
			if (fieldinput.attr('type') == 'text' && fieldinput.select) {
				fieldinput.select();
			}
		} else {
			selStart = fieldinput.val().length;
			if (fieldinput.attr('type') == 'text') {
				var v = fieldinput.val();
				fieldinput.val('');
				fieldinput.val(v); // put cursor at the end
			}
		}
		if (fieldinput.is('textarea')) {
			_setSelRange(
				fieldinput[0],
				selStart,
				fieldinput.val().length
			);
		}
		tool.$('.Q_inplace_tool_buttons').css({
			width: container_span.outerWidth() + 'px'
		});
		event && event.preventDefault && event.preventDefault();
		return false;
	};
	function onSave () {
		var form = $('.Q_inplace_tool_form', $te);
		if (state && state.beforeSave) {
			if (false === Q.handle(state.beforeSave, this, [form])) {
				return false;
			}
		}
		undermessage.html(throbber_img)
			.css('display', 'block')
			.removeClass('Q_error');
		focusedOn = 'fieldinput';
		var method = state.method || (form.length && form.attr('method')) || 'post';
		var url = form.attr('action');

		if (state.capitalize) {
			fieldinput.val(fieldinput.val().toCapitalized());
		} else {
			fieldinput.attr('autocapitalize', 'off');
		}

		var used_placeholder = false;
		if (fieldinput.attr('placeholder')
		&& fieldinput.val() === fieldinput.attr('placeholder')) {
			// this is probably due to a custom placeholder mechanism
			// so clear the field, rather than saving the placeholder text
			fieldinput.val('');
			used_placeholder = true;
		}

		Q.request(url, ['Q_inplace'], function (err, response) {
			if (typeof response !== 'object') {
				onSaveErrors("returned data is not an object");
				return;
			}
			if (response.errors && response.errors.length) {
				onSaveErrors(response.errors[0].message);
				return;
			}

			function afterLoad(alreadyLoaded) {
				if (('scriptLines' in response) && ('Q_inplace' in response.scriptLines)) {
					eval(response.scriptLines.Q_inplace);
				}
			}

			if(response.scripts && response.scripts.Q_inplace && response.scripts.Q_inplace.length) {
				Q.addScript(response.scripts.Q_inplace, afterLoad);
			} else {
				afterLoad();
			}

			onSaveSuccess(response);
		}, {
			method: method,
			fields: Q.queryString(form[0])
		});

		if (used_placeholder) {
			fieldinput.val(fieldinput.attr('placeholder'));
		}
	};
	function onSaveErrors (message) {
		alert(message);
		fieldinput.focus();
		undermessage.css('display', 'none');
		_restoreZ();
		/*
			.html(message)
			.css('whiteSpace', 'nowrap')
			.css('bottom', (-undermessage.height()-3)+'px');
		*/
	};
	function onSaveSuccess (response) {
		var newval = fieldinput.val();
		if ('slots' in response) {
			if ('Q_inplace' in response.slots) {
				newval = response.slots.Q_inplace;
			}
		}
		if (state.type === 'select') {
			newval = String(state.options[newval] || '').encodeHTML();
		}
		_restoreZ();
		if (newval) {
			static_span.html(newval);
		} else {
			static_span.html('<span class="Q_placeholder">'
				+state.placeholder.encodeHTML()+'</span>'
			);
		}
		_adjustButtonLayout();
		static_span.attr('title', state.placeholder);
		undermessage.empty().css('display', 'none').addClass('Q_error');
		tool.restoreActions();
		fieldinput.blur();
		container_span.removeClass('Q_editing')
			.removeClass('Q_nocancel')
			.removeClass('Q_discouragePointerEvents');
		_finishedEditing(container_span);
		_hideEditButtons();
		noCancel = false;
		Q.handle(state.onSave, tool, [response.slots.Q_inplace]);
	};
	function onCancel (dontAsk) {
		if (noCancel) {
			return;
		}
		_restoreZ();
		if (!dontAsk && fieldinput.val() != previousValue) {
			dialogMode = true;
			var continueEditing = confirm(state.cancelPrompt);
			dialogMode = false;
			if (continueEditing) {
				onSave();
				return;
			}
		}
		fieldinput.val(previousValue);
		fieldinput.blur();
		focusedOn = null;
		tool.restoreActions();
		container_span.removeClass('Q_editing')
			.removeClass('Q_discouragePointerEvents');
		_finishedEditing(container_span);
		_hideEditButtons();
		Q.handle(state.onCancel, tool);
		Q.Pointer.cancelClick(true, null, null);
	};
	function onBlur() {
		if (noCancel && fieldinput.val() !== previousValue) {
			return onSave();
		}
		setTimeout(function () {
			if (focusedOn
			 || dialogMode
			 || !container_span.hasClass('Q_editing')
			) {
				return _restoreZ();
			}
			onCancel();
		}, 100);
	};
	function _restoreZ()
	{
		if (changedMaxWidth) {
			container_span.css('max-width', 'none');
		}
		if (changedMaxHeight) {
			container_span.css('max-height', 'none');
		}
		if (!state.bringToFront) return;
		var $bringToFront = $(state.bringToFront);
		$bringToFront.css('zIndex', $bringToFront.data(_stateKey_zIndex))
			.css('position', $bringToFront.data(_stateKey_position))
			.removeData(_stateKey_zIndex)
			.removeData(_stateKey_position);
	}
	function _editButtons() {
		if (Q.info.isTouchscreen) {
			if (!state.editOnClick || state.showEditButtons) {
				$('.Q_inplace_tool_editbuttons', $te).css({ 
					'margin-top': static_span.outerHeight() + 'px',
					'line-height': '1px',
					'display': 'inline'
				});
			}
		} else {
			container_span.mouseover(function() {
				container_span.addClass('Q_hover');
				$('.Q_inplace_tool_editbuttons', $te).css({ 
					'margin-top': static_span.outerHeight() + 'px',
					'line-height': '1px',
					'display': 'inline'
				});
			}).mouseout(function () {
				$('.Q_inplace_tool_editbuttons', $te).css({ 
					'display': 'none'
				});
			});
		}
	}
	function _hideEditButtons() {
		if (!Q.info.isTouchscreen) {
			$('.Q_inplace_tool_editbuttons', container_span).css('display', 'none');
		}
	}
	function _adjustButtonLayout() {
		var $placeholder = fieldinput.closest('.Q_placeholders_container')
			.find('.Q_placeholder');
		var $element = fieldinput.is(":visible")
			? fieldinput
			: ($placeholder.is(":visible") ? $placeholder : static_span);
		var margin = $element.outerHeight() + parseInt($element.css('margin-top'));
		tool.$('.Q_inplace_tool_editbuttons').css('margin-top', margin+'px');
	}
	_editButtons();
	container_span.mouseout(function() {
		container_span.removeClass('Q_hover');
	});
	container_span.on([Q.Pointer.fastclick, '.Q_inplace'], function (event) {
		if ((state.editOnClick && event.target === static_span[0])
		|| $(event.target).is('button')) {
			Q.Pointer.cancelClick(true, event, null);
			Q.Pointer.ended();
			event.stopPropagation();
		}
	});
	edit_button.on(Q.Pointer.start, function (event) {
		Q.Pointer.cancelClick(true, event, null);
	});
	if (this.state.editOnClick) {
		// happens despite canceled click
		static_span.on([Q.Pointer.fastclick, '.Q_inplace'], this.handleClick);
	} else {
		$te.addClass('Q_inplace_noEditOnClick');
	}
	edit_button.on(Q.Pointer.start, this.handleClick); // happens despite canceled click
	cancel_button.on(Q.Pointer.start, function() {
		onCancel(true); 
		return false;
	});
	cancel_button.add(save_button).add(edit_button).on(Q.Pointer.end, function() {
		return false;
	});
	cancel_button.on('focus '+Q.Pointer.start.eventName, function() {
		setTimeout(function() {
			focusedOn = 'cancel_button';
		}, 50);
	});
	cancel_button.blur(function() {
		if (this.__ignoreBlur) {
			return;
		}
		focusedOn = null;
		setTimeout(onBlur, 100); 
	});
	save_button.on(Q.Pointer.fastclick, function() { onSave(); return false; });
	save_button.on('focus '+Q.Pointer.start.eventName, function() {
		setTimeout(function() {
			focusedOn = 'save_button';
		}, 50);
	});
	save_button.blur(function() { 
		focusedOn = null;
		setTimeout(onBlur, 100);
	});
	fieldinput.on('keyup input', _updateSaveButton);
	fieldinput.focus(function() {
		focusedOn = 'fieldinput';
	});
	fieldinput.blur(function() {
		focusedOn = null;
		setTimeout(onBlur, 100); 
	});
	fieldinput.keydown(function _Q_inplace_keydown(e) {
		if (!focusedOn) {
			return false;
		}
		var kc = (window.event) ? (event.keyCode || event.which) : e.keyCode;
		var sk = (window.event) ? event.shiftKey : e.shiftKey;
		if (kc == 13) {
			if (! fieldinput.is('textarea')) {
				onSave(); return false;
			}
		} else if (kc == 27) {
			onCancel();
			return false;
		} else if (kc == 9) {
			var tags = 'input,textarea,select,.Q_inplace_tool';
			var $elements = $(tags).not('.Q_inplace_tool :input');
			var $input = $elements.eq($elements.index(tool.element) + (sk ? -1 : 1));
			if (!$input.hasClass('Q_inplace_tool')) {
				$input.plugin('Q/clickfocus');
			} else {
				Q.Tool.from($input[0], 'Q/inplace').handleClick();
			}
		}
	});
	fieldinput.closest('form').submit(function (e) {
		onSave();
		e.preventDefault();
	});
	fieldinput.on(Q.Pointer.end, function (event) {
		Q.Pointer.cancelClick(true, event, null);
		Q.Pointer.ended();
		event.stopPropagation();
	});
	fieldinput.click(function (event) {
		event.stopPropagation();
	});
	function _updateSaveButton() {
		save_button.css('display', (fieldinput.val() == previousValue)
			? 'none' 
			: 'inline'
		);
	}
}

function _beganEditing(container_span) {
	container_span.parents().each(function () {
		var $this = $(this);
		$this.data(_stateKey_editing, $this.hasClass('Q_editing'))
			.addClass('Q_editing');
	});
}

function _finishedEditing(container_span) {
	container_span.parents().each(function () {
		var $this = $(this);
		if (!$this.data(_stateKey_editing)) {
			$this.removeClass('Q_editing');
		}
	});
}

var _stateKey_zIndex = 'Q/inplace zIndex';
var _stateKey_position = 'Q/inplace position';
var _stateKey_editing = 'Q/inplace Q_editing';

Q.Template.set('Q/inplace/tool', 
"<div class='Q_inplace_tool_container {{classes}}' style='position: relative;'>"
+	"<div class='Q_inplace_tool_editbuttons'>"
+		"<button class='Q_inplace_tool_edit basic16 basic16_edit'>Edit</button>"
+	"</div>"
+	"<form class='Q_inplace_tool_form' method='{{method}}' action='{{action}}'>"
+		"{{#if isTextarea}}"
+			"<textarea name='{{field}}' placeholder='{{placeholder}}' rows='5' cols='80'>{{text}}</textarea>"
+		"{{/if}}"
+		"{{#if isText}}"
+			"<input name='{{field}}' placeholder='{{placeholder}}' value='{{text}}' type='{{type}}' >"
+		"{{/if}}"
+		"{{#if isSelect}}"
+			"<select name='{{field}}'>"
+			"{{#each options}}"
+				"<option value='{{@key}}' {{#if @first}}selected='selected'{{/if}}>{{this}}</option>"
+			"{{/each}}"
+			"</select>"
+		"{{/if}}"
+		"<div class='Q_inplace_tool_buttons'>"
+			"<button class='Q_inplace_tool_cancel basic16 basic16_cancel'>Cancel</button>"
+			"<button class='Q_inplace_tool_save basic16 basic16_save'>Save</button>"
+		"</div>"
+	"</form>"
+	"<div class='Q_inplace_tool_static_container {{staticClass}}'>{{{staticHtml}}}</div>"
+"</div>"
);

})(Q, Q.jQuery, window, document);
