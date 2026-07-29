(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Single-metric stat card with optional animated counter.
 *
 * @class Q/card/stat
 * @constructor
 * @param {Object} [options]
 * @param {String|Number} options.value
 * @param {String} [options.unit]          Unit appended to value e.g. "B", "%"
 * @param {String} options.label
 * @param {String|Number} [options.delta]  Change e.g. "+12%" or "-3"
 * @param {Boolean} [options.deltaPositive]
 * @param {String} [options.source]
 * @param {String} [options.url]
 * @param {Boolean} [options.animate=true]
 * @param {Number}  [options.animateMs=800]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/stat", function () { 
    this.refresh();
},
{
    value: '', unit: '', label: '', delta: '', deltaPositive: null,
    source: '', url: '', animate: true, animateMs: 800,
    onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        // Coerce string values from stream attributes
        if (s.deltaPositive === 'true')  s.deltaPositive = true;
        if (s.deltaPositive === 'false') s.deltaPositive = false;
        var numeric = parseFloat(String(s.value).replace(/[^0-9.-]/g, ''));
        var hasNumeric = !isNaN(numeric);
        var deltaClass = s.deltaPositive === true  ? 'Q_card_delta_positive'
                       : s.deltaPositive === false ? 'Q_card_delta_negative' : '';
        Q.Template.render('Q/card/stat', {
            value: s.value, unit: s.unit, label: s.label,
            delta: s.delta, deltaClass: deltaClass,
            hasDelta: !!s.delta, source: s.source, url: s.url,
            hasSource: !!(s.source || s.url)
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            if (s.animate && hasNumeric) {
                var $val = $(tool.element).find('.Q_card_value_number');
                var startTime = null;
                function step(ts) {
                    if (!startTime) startTime = ts;
                    var p = Math.min((ts - startTime) / s.animateMs, 1);
                    var eased = 1 - Math.pow(1 - p, 3);
                    var cur = numeric * eased;
                    $val.text((numeric % 1 === 0 ? Math.round(cur).toLocaleString() : cur.toFixed(1)) + (s.unit ? '' : ''));
                    if (p < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            }
            Q.handle(s.onRefresh, tool);
        });
    }
});

Q.Template.set('Q/card/stat',
    '<div class="Q_card Q_card_stat">'
  + '  <div class="Q_card_value">'
  + '    <span class="Q_card_value_number">{{value}}</span>'
  + '    {{#if unit}}<span class="Q_card_value_unit">{{unit}}</span>{{/if}}'
  + '  </div>'
  + '  {{#if hasDelta}}<div class="Q_card_delta {{deltaClass}}">{{delta}}</div>{{/if}}'
  + '  <div class="Q_card_label">{{label}}</div>'
  + '  {{#if hasSource}}<div class="Q_card_source">'
  + '    {{#if url}}<a href="{{url}}" target="_blank" rel="noopener">{{source}}</a>'
  + '    {{else}}{{source}}{{/if}}'
  + '  </div>{{/if}}'
  + '</div>'
);
})(Q, Q.jQuery, window);
