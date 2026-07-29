(function (Q, $, window, document, undefined) {

/**
 * Q Tools
 * @module Q-tools
 */

/**
 * Makes an infomation block for adding a bookmarklet on the browser's bookmarks bar
 * the way similar to how facebook does: http://www.facebook.com/share_options.php .
 * The main purpose of the tool is to present, in a cross-browser way,
 * user-friendly instructions on how to add bookmarklet in the browser.
 * @class Q bookmarklet
 * @constructor
 * @param {Object} options This is an object with properties for this function
 *	 @param {String} options.title Title for the button which will be added to user's browser bar.
 *	 @param {String} options.usage Text which is appended to instructions, identifying purpose and usage of this bookmarklet.
 *   @param {Array} [options.scripts] Array of one or more script urls (will be run through Q.url()) to load and execute in order
 *   @param {Array} [options.skip] Array of "path.to.object" strings corresponding to options.scripts array, to avoid loading the corresponding script if path.to.object is already defined. Typically names an object which has been defined by the loaded script. Pass nulls in the array for urls you shouldn't skip.
 *	 @param {String} [options.code] Literal Javascript code to execute, typically a function call. If scripts option is provided, this code is executed after the scripts have been loaded.
 *	 @param {String} [options.icon] Icon for the button which will be added to user's browser bar.
 */
Q.Tool.define('Q/bookmarklet', function () {
	
	var tool = this;
	var state = tool.state;
	var $te = $(tool.element);
	
	if (!state.scripts && !state.code) {
		throw new Q.Error("Q/bookmarklet: please provide the bookmarklet's scripts or code");
	}
	if (!state.title) {
		console.warn("Please provide 'title' for bookmarklet.");
	}
	if (!state.title) {
		console.warn("Please provide 'usage' for bookmarklet.");
	}
	
	Q.addStylesheet('{{Q}}/css/tools/inplace.css');
	
	var bookmarkletSettings = {
		common: {
			'instructions': 'Drag me to your Bookmarks Bar to ' + state.usage + '.<br /><br />' +
				'If you can\'t see the Bookmarks Bar, Choose {{command}} from your browser {{menu}} menu.'
		}
	};
	Q.extend(bookmarkletSettings, {
		'safari': {
			'mac': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Show Favorites Bar"
				}),
				'icon': false
			},
			'windows': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Show Favorites Bar"
				}),
				'icon': false
			}
		},
		'chrome': {
			'mac': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Show Bookmarks Bar"
				}),
				'icon': 'chrome_default_icon.png'
			},
			'windows': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Show Bookmarks Bar"
				}),
				'icon': 'chrome_default_icon.png'
			}
		},
		'firefox': {
			'mac': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Toolbars > Bookmarks > Toolbar"
				}),
				'icon': 'firefox_mac_default_icon.png'
			},
			'windows': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Toolbars > Bookmarks > Toolbar"
				}),
				'icon': 'firefox_win_default_icon.png'
			}
		},
		'opera': {
			'mac': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Settings -> Show the bookmarks bar"
				}),
				'icon': 'opera_mac_default_icon.png'
			},
			'windows': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "View", 
					command: "Settings -> Show the bookmarks bar"
				}),
				'icon': 'opera_win_default_icon.png'
			}
		},
		'explorer': {
			'windows': {
				'instructions': bookmarkletSettings.common.instructions.interpolate({
					menu: "Tools", 
					command: "Toolbars -> Favorites"
				}),
				'icon': 'ie_default_icon.png'
			}
		}
	});
	
	var code = null;
	if (state.scripts && state.scripts.length) {
		var scripts = [];
		for (var i=0; i<state.scripts.length; ++i) {
			scripts.push(Q.url(state.scripts[i]));
		}
		var json = JSON.stringify({
			scripts: scripts,
			skip: state.skip,
			code: state.code
		});
		var baseUrlJson = JSON.stringify(Q.info.baseUrl);
		code =
  '(function () {'
+ ' var o = ' + json + ';'
+ '	var i=-1, loaded = {};'
+ ' function loadScript(i, callback) {'
+ '   var url = o.scripts[i];'
+ '   if (loaded[url] || (o.skip && getObject(o.skip[i]))) {'
+ ' 	return callback();'
+ '   }'
+ '   var script = document.createElement("script");'
+ '   script.type = "application/javascript";'
+ '   if (script.readyState) {'
+ '     script.onreadystatechange = function () {'
+ '       if (script.readyState == "loaded" || script.readyState == "complete") {'
+ '         script.onreadystatechange = null;'
+ '         loaded[url] = true;'
+ '         callback();'
+ '       }'
+ '     };'
+ '   } else {'
+ '     script.onload = function () {'
+ '         loaded[url] = true;'
+ '         callback();'
+ '     };'
+ '  }'
+ '  script.src = url;'
+ '  document.getElementsByTagName("head")[0].appendChild(script);'
+ ' }'
+ '	function loadNextScript() {'
+ '   if (++i < o.scripts.length) {'
+ ' 	loadScript(i, loadNextScript);'
+ '   } else {'
+ ' 	afterScripts();'
+ '   }'
+ '	}'
+ '	function afterScripts() {'
+ '   if (o.code) {'
+ '     var f = new Function("baseUrl", o.code);'
+ ' 	f(' + baseUrlJson + ');'
+ '   }'
+ '	}'
+ '	function getObject (name) {'
+ '   if (!name) return;'
+ '   var p, i = 0, c = window;'
+ '   var parts = name.split(".");'
+ '   if (!parts.length) return c;'
+ '   while (c && (p = parts[i++]) !== undefined){'
+ ' 	c = c[p];'
+ '   }'
+ '   return c;'
+ '	}'
+ '	loadNextScript();'
+ '})();';
	} else {
		code = state.code;
	}
	// NOTE: code should be under 2000 total characters
	// see http://stackoverflow.com/a/417184/467460
	code = 'javascript:'+encodeURIComponent(code.replaceAll({
		'\n': ' ',
		'    ': ' ',
		'  ': ' '
	}));
	
	var browser = Q.Browser.detect();

	$te.addClass('Q_clearfix');
	switch (browser.OS) {
	case 'android':
		$te.append( '<div class="Q_bookmarklet_tool_instructions">' +
							'<div class="Q_bookmarklet_tool_step">' +
								'<h3>Step 1: Select the text and copy it.</h3>' +
								'<textarea class="Q_bookmarklet_tool_code">' +
									code +
								'</textarea>' +
								'<ul>' +
									'<li>Tap inside.</li>' +
									'<li>Tap and hold for a bit, then release.</li>' +
									'<li>Tap <b>Select All</b>.</li>' +
									'<li>Tap <b>Copy</b>.</li>' + 
									'<li>Tap <b>Done</b>.</li>' + 
								'</ul>' +
							'</div>' +
							'<div class="Q_bookmarklet_tool_step">' +
								'<h3>Step 2: Bookmark this page.</h3>' +
								'<ol>' +
									'<li>Tap the bookmark icon next to the address bar, then tap &#9733; Add.</li>' +
									'<li>Tap the Location. Then, tap and hold to bring up the contextual menu, and select <b>Paste</b>.</li>' +
									'<li> Save the changes by tapping <b>Done</b> and <b>OK</b>.</li>' +
								'</ol>' +
							'</div>' +
							'<div class="Q_bookmarklet_tool_step">' +
								'<h3>Step 4: Installation complete.</h3>' +
								'<p>Installation should be complete!</p>' +
								'<p>Tap the bookmark icon next to the address bar, then tap "'+state.title+'" to use it on any page.</p>' +
							'</div>' +
						'</div>');
		break;
		
	case 'ios':
		var url = Q.info.browser.mainVersion >= 8
			? '{{Q}}/img/bookmarklet/ios8_action.png'
			: '{{Q}}/img/bookmarklet/ios_action.png';
		var icon = '<img src="'+Q.url(url)+'" class="Q_bookmarklet_ios_action_icon" />';
		if (browser.device === 'iPad') {
			$te.addClass('Q_bookmarklet_tool_iPad');
			$te.append( '<div class="Q_bookmarklet_tool_instructions">' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 1: Bookmark this page.</h3>' +
									'<p>Tap the '+icon+' icon, then tap Add Bookmark, then tap Save.</p>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 2: Select the text and copy it.</h3>' +
									'<textarea class="Q_bookmarklet_tool_code">' +
										code +
									'</textarea>' +
									'<ul>' +
										'<li>Tap inside.</li>' +
										'<li>Tap and hold for a bit, then release.</li>' +
										'<li>Tap <b>Select All</b>.</li>' +
										'<li>Tap <b>Copy</b>.</li>' + 
										'<li>Tap <b>Done</b>.</li>' + 
									'</ul>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 3: Edit the bookmark.</h3>' +
									'<ol>' +
										'<li>Tap the Bookmarks button in the toolbar.</li>' +
										'<li>Tap <b>Edit</b>. Select the "'+state.title+'" bookmark to edit.</li>' +
										'<li>Tap its URL, tap the <b>x</b> to clear it, tap-and-hold for the magnifying glass, then tap <b>Paste</b>.</li>' +
										'<li> Save the changes by tapping <b>Done</b>.</li>' +
									'</ol>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 4: Installation complete.</h3>' +
									'<p>Installation should be complete!</p>' +
									'<p>Select the "'+state.title+'" bookmark from your Bookmarks list to use it on any page.</p>' +
								'</div>' +
							'</div>');
		} else {
			$te.addClass('Q_bookmarklet_tool_iPhone');
			$te.append( '<div class="Q_bookmarklet_tool_instructions">' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 1: Bookmark this page.</h3>' +
									'<p>Tap the '+icon+' icon below, then tap Add Bookmark, then tap Save.</p>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 2: Select the text and copy it.</h3>' +
									'<textarea class="Q_bookmarklet_tool_code">' +
										code +
									'</textarea>' +
									'<ul>' +
										'<li>Tap inside.</li>' +
										'<li>Tap and hold for a bit, then release.</li>' +
										'<li>Tap <b>Select All</b>.</li>' +
										'<li>Tap <b>Copy</b>.</li>' + 
										'<li>Tap <b>Done</b>.</li>' + 
									'</ul>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 3: Edit the bookmark.</h3>' +
									'<ol>' +
										'<li>Tap the Bookmarks button in the toolbar.</li>' +
										'<li>Tap <b>Edit</b>. Select the "'+state.title+'" bookmark to edit.</li>' +
										'<li>Tap its URL, tap the <b>x</b> to clear it, tap-and-hold for the magnifying glass, then tap <b>Paste</b>.</li>' +
										'<li> Save the changes by tapping <b>Done</b>.</li>' +
									'</ol>' +
								'</div>' +
								'<div class="Q_bookmarklet_tool_step">' +
									'<h3>Step 4: Installation complete.</h3>' +
									'<p>Installation should be complete!</p>' +
									'<p>Select the "'+state.title+'" bookmark from your Bookmarks list to use it on any page.</p>' +
								'</div>' +
							'</div>');
		}
		break;
	default:
		$te.append('<div class="Q_bookmarklet_tool_instructions">' + 
						 '<div class="Q_bookmarklet_tool_drag_button">' +
							 '<div class="Q_bookmarklet_tool_sample_button_tip">Drag me to your<br />Bookmarks Bar</div><br />' +
							 '<div class="Q_bookmarklet_tool_sample_button Q_bookmarklet_tool_button_' + browser.name + '_' + browser.OS + '">' +
									 '<div class="Q_bookmarklet_tool_button_left"></div>' +
									 '<div class="Q_bookmarklet_tool_button_middle">' +
										 '<a href="#">' +
											 (bookmarkletSettings[browser.name][browser.OS]['icon'] ?
												'<img src="' +
												(state.icon ? state.icon : Q.info.proxyBaseUrl + '/{{Q}}/img/bookmarklet/' + bookmarkletSettings[browser.name][browser.OS]['icon']) +
												'" alt="" />'
												: '') +
											 state.title +
										 '</a>' +
									 '</div>' +
									 '<div class="Q_bookmarklet_tool_button_right"></div>' +
							 '</div>' +
						 '</div>' +
						 '<div class="Q_bookmarklet_tool_instruction_text">' +
							 bookmarkletSettings[browser.name][browser.OS]['instructions'] +
						 '</div>' +
					 '</div>');
$te.append('<div class="Q_bookmarklet_tool_bookmarks_bar_sample">' +
						 '<div class="Q_bookmarklet_tool_bar_screenshot Q_bookmarklet_tool_bar_screenshot_' + browser.name + '_' + browser.OS + '"">' +
							 '<div class="Q_bookmarklet_tool_sample_button Q_bookmarklet_tool_button_' + browser.name + '_' + browser.OS + '">' +
								 '<div class="Q_bookmarklet_tool_button_left"></div>' +
								 '<div class="Q_bookmarklet_tool_button_middle">' +
									 '<a href="#">' +
										 (bookmarkletSettings[browser.name][browser.OS]['icon'] ?
											'<img src="' +
											(state.icon ? state.icon : Q.info.proxyBaseUrl + '/{{Q}}/img/bookmarklet/' + bookmarkletSettings[browser.name][browser.OS]['icon']) +
											'" alt="" />'
											: '') +
										 state.title +
									 '</a>' +
								 '</div>' +
								 '<div class="Q_bookmarklet_tool_button_right"></div>' +
							 '</div>' +
						 '</div>' +
						 '<div class="Q_bookmarklet_tool_bookmarks_bar_description">' +
							 'After you drag the button to the Bookmarks Bar, it will look like this.' +
						 '</div>' +
					 '</div>');
		var $a = $te.find('.Q_bookmarklet_tool_button_middle a');
		$a.attr('href', code);
		$a.eq(0).on('click.Q_bookmarklet', function() {
			alert(state.clickPrompt);
			return false;
		});
	}
},

{
	icon: null,
	clickPrompt: 'This is a bookmarklet, drag it to your bookmarks bar.'
});

})(Q, Q.jQuery, window, document);