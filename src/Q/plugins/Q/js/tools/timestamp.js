(function (Q, $) {
/**
 * Q Tools
 * @module Q-tools
 */

/**
 * This tool makes a timestamp which is periodically updated.
 * Initially shows time offsets in '<some_time> ago' manner.
 * Later represents time depending on format,
 * wisely excluding unnecessary detais
 * (i.e. 'year' if timestamp has been made this year, 'year' and 'month if in this month etc).
 * @class Q timestamp
 * @constructor
 * @param {Object} [options] This is an object of parameters for this tool
 *    @param {Number} [options.time=new Date().getTime()/1000] Unix timestamp (in seconds).
 *    @param {String} [options.format='{day-week} {date+week} {year+year} %l:%M %P'] formatting string which makes specific timestamp representation. Can contain placeholders supported by strftime() and also few special placeholders with specific functionality.
 *    Placeholders can include:
 *    @param {Boolean} [options.relative=true] Whether to show times relative to the current time when they are close to it
 *    @param {Boolean} [options.countdown=true] Pass false to avoid displaying a countdown of seconds in the relative times
 *    @param {Boolean} [options.capitalized=false] Whether to capitalize the displayed day name
 *    * time: the time of the day, based on the locale
 *    * time-day: same as time, but doesn't show on a different day
 *    * time-week: same as time, but doesn't after 7 days in the future or before 1 day in the past
 *    * day: the day's name, like "Sun", but can also be "last night", "yesterday", "tom morn", etc.
 *    * day-week: same as day, but doesn't show after 7 days in the future or before 1 day in the past
 *    * longday: the day's long name, like "Sunday", but can also be "last night", "yesterday", "tom morn", etc.
 *    * longday-week: same as longday, but doesn't show after 7 days in the future or before 1 day in the past
 *    * date: something like "Jan 04"
 *    * date+week: same as date but only shows after 7 days in the future or before 1 day in the past
 *    * year: four digit year such as 2017
 *    * year+year: same as year but only shows if different than current year
 *   @param {Q.Event} [options.beforeRefresh] Return false from this event to cancel refresh
 */
Q.Tool.define('Q/timestamp', function () {
	var tool = this;

	var pipe = new Q.Pipe(['phpjs', 'text'], function () {
		tool.refresh();
	});

	Q.Text.get("Q/content", function (err, text) {
		var msg = Q.firstErrorMessage(err);
		if (msg) {
			return console.warn("Q/timestamp: " + msg);
		}

		tool.text = text.timestamp;
		pipe.fill('text')();
	});

	Q.ensure('Q.PHPJS', pipe.fill('phpjs'));

	tool.Q.onStateChanged('time')
	.or(tool.Q.onStateChanged('format'))
	.set(function () {
		tool.refresh();
	});
}, {
	time: null,
	relative: true,
	countdown: true,
	format: '{day-week} {date+week} {year+year} %l:%M %P',
	beforeRefresh: new Q.Event()
}, {
	refresh: function _Q_timestamp_refresh() {
		if (!Q.PHPJS) {
			return; // not yet
		}
		var tool = this;
		var state = this.state;
		var strftime = Q.PHPJS.strftime.bind(Q.PHPJS);
		var date = isNaN(state.time) ? new Date(state.time) : Date.fromTimestamp(state.time);
		var time = date.getTime() / 1000;
		var now = Date.now() / 1000;
		date = new Date();
		var today = new Date(
			date.getFullYear(), date.getMonth(), date.getDate()
		).getTime() / 1000;
		var diffToday = time - today;
		var diff = time - now;
		var dayLength = 3600 * 24;
		var day = strftime('%a', time);
		var longday = strftime('%A', time);
		var t = tool.text;
		if (state.relative) {
			if (diffToday < 0 && diffToday > -dayLength / 8) {
				day = longday = t.lastNight;
			} if (diffToday < 0 && diffToday > -dayLength / 4) {
				day = longday = t.lastEvening;
			} else if (diffToday < 0 && diffToday > -dayLength) {
				day = longday = t.yesterday;
			} else if (diffToday < dayLength * 0.4) {
				day = longday = t.thisMorning;
			} else if (diffToday < dayLength * 3 / 4) {
				day = longday = t.today;
			} else if (diffToday < dayLength) {
				day = longday = t.tonight;
			} else if (diffToday < dayLength * 1.4) {
				day = longday = t.tomorrowMorning
			} else if (diffToday < dayLength * 2) {
				day = longday = t.tomorrow
			}
		}
		if (state.capitalized) {
			day = day.toCapitalized();
		}
		
		if (state.timeout) {
			clearTimeout(state.timeout);
			state.timeout = null;
		}

		// regular formatting using strftime()
		var format = state.format.replace('%#d', '%d');
		var result = '';
		var refreshAfterSeconds = 3600;
		var s, m, h;
		if (diff < -dayLength || !state.relative) {
			result = strftime(format, time);
		} else if (diff <= -3600 * 2) {
			if (format.indexOf('{day') < 0 || diffToday >= 0) {
				result = t.hoursAgo.interpolate({
					h: Math.floor(-diff / 3600)
				});
			} else {
				result = strftime(format, time);
			}
		} else if (diff <= -3600) {
			result = t.hourAgo.interpolate({h: 1});
		} else if (diff < -60 * 2) {
			result = t.minutesAgo.interpolate({
				m: Math.floor(-diff / 60)
			});
			refreshAfterSeconds = 60 - (-diff%60);
		} else if (diff <= -60) {
			result = t.minuteAgo.interpolate({ m: 1 });
			refreshAfterSeconds = 60 - (-diff%60);
		} else if (diff <= -10) {
			result = t.secondsAgo.interpolate({ s: Math.floor(-diff) });
			refreshAfterSeconds = 60 - (-diff%60);
		} else if (diff < 0) {
			result = t.fewSecondsAgo;
			refreshAfterSeconds = 60 - (-diff%60);
		} else if (diff == 0) {
			result = t.rightNow;
			refreshAfterSeconds = 1;
		} else if (diff <= 60) {
			if (state.countdown) {
				s = Math.floor(diff);
				result = (s == 1 ? t.inSecond : t.inSeconds).interpolate({s : s});
				refreshAfterSeconds = 1;
			} else {
				result = t.inUnderMinute;
			}
		} else if (diff < 3600) {
			m = Math.floor(diff / 60);
			result = (m == 1 ? t.inMinute : t.inMinutes).interpolate({m : m});
			refreshAfterSeconds = (diff%60) || 60;
		} else if (diff < dayLength) {
			h = Math.floor(diff / 3600);
			if (format.indexOf('{day') < 0) {
				result = (s == 1 ? t.inHour : t.inHours).interpolate({h : h});
			} else {
				result = strftime(format, time);
			}
			refreshAfterSeconds = (diff - 3600 || 3600);
		} else {
			result = strftime(format, time);
		}

		if (refreshAfterSeconds) {
			state.timeout = setTimeout(function _refreshSeconds() {
				tool.refresh();
			}, refreshAfterSeconds * 1000);
		}

		var needZeroCorrection = state.format.indexOf('%#d') != -1;
		if (needZeroCorrection) {
			result = result.replace(/\s0(\d+)/g, ' $1');
		}

		// special formatting
		if (result.indexOf('{time') != -1) {
			if (result.indexOf('{time-week}') != -1
			&& (diffToday <= -dayLength || diffToday > dayLength * 7)) {
				result = result.replace('{time-week}', '').replace(/\s+/g, ' ').trim();
			} else if (result.indexOf('{time-day}') != -1 && diffToday > dayLength) {
				result = result.replace('{time-day}', '').replace(/\s+/g, ' ').trim();
			} else {
				result = result.replace(/\{time-week\}|\{time-day\}|\{time\}/g, strftime('%X', time));
			}
		}

		if (result.indexOf('{day') != -1) {
			if (result.indexOf('{day-week}') != -1
			&& (diffToday <= -dayLength || diffToday > dayLength * 7)) {
				result = result.replace('{day-week}', '').replace(/\s+/g, ' ').trim();
			} else {
				result = result.replace(/\{day-week\}|\{day\}/g, day);
			}
		}

		if (result.indexOf('{longday') != -1) {
			if (result.indexOf('{longday-week}') != -1
			&& (diffToday <= -dayLength || diffToday > dayLength * 7)) {
				result = result.replace('{longday-week}', '').replace(/\s+/g, ' ').trim();
			} else {
				result = result.replace(/\{longday-week\}|\{longday\}/g, longday);
			}
		}

		if (result.indexOf('{date') != -1) {
			if (result.indexOf('{date+week}') != -1) {
				if (diffToday < -dayLength || diffToday > dayLength * 7) {
					result = result.replace('{date+week}', strftime('%b %e', time));
				} else {
					result = result.replace('{date+week}', '').replace(/\s+/g, ' ').trim();
				}
			} else if (result.indexOf('{date}') != -1) {
				result = result.replace('{date}', strftime('%b %e', time));
			}
		}

		if (result.indexOf('{year') != -1) {
			var thisYear = new Date(date.getFullYear(), 0, 1).getTime() / 1000;
			var nextYear = new Date(date.getFullYear()+1, 0, 1).getTime() / 1000;
			if (result.indexOf('{year+year}') != -1) {
				if (result.indexOf('{year+year}') != -1
				&& (time < thisYear || time > nextYear)) {
					result = result.replace('{year+year}', strftime('%Y', time));
				} else {
					result = result.replace('{year+year}', '').replace(/\s+/g, ' ').trim();
				}
			} else {
				result = result.replace('{year}', strftime('%Y', time));
			}
		}

		if (state.beforeRefresh.handle.call(tool, result, diff) === false) {
			return;
		}
		
		this.element.innerHTML = result;
	}
});

})(Q, Q.jQuery);