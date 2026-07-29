(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Horizontal ranked bar chart using D3.
 * Handles Streams/highlight { elementId } to spotlight a bar by index or label.
 *
 * @class Q/chart/bar
 * @constructor
 * @param {Object} [options]
 * @param {Array}  options.items        [{label, value, color?}, ...]
 * @param {String} [options.title]
 * @param {String} [options.unit]       Appended to value labels e.g. "B", "%"
 * @param {Boolean}[options.animate=true]
 * @param {Number} [options.animateMs=600]
 * @param {String} [options.source]
 * @param {String} [options.url]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/chart/bar", function () {
    var tool = this;
    // Load vendored D3 v7; fall back to CDN if not available
    if (typeof d3 !== 'undefined') {
        tool.refresh();
    } else {
        Q.addScript('{{Q}}/js/d3.min.js', function () {
            if (typeof d3 !== 'undefined') {
                tool.refresh();
            } else {
                Q.addScript('{{Q}}/js/d3.min.js', function () {
                    tool.refresh();
                });
            }
        });
    }
},
{
    items: [], title: '', unit: '', animate: true, animateMs: 600,
    source: '', url: '', onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        if (typeof d3 === 'undefined') return;
        // Defensive: parse if stored as JSON string (e.g. from stream attributes)
        if (typeof s.items === 'string') { try { s.items = JSON.parse(s.items); } catch (e) { s.items = []; } }

        var items = s.items || [];
        if (!items.length) return;

        var maxVal = d3.max(items, function (d) { return +d.value; });
        var wrapper = document.createElement('div');
        wrapper.className = 'Q_card Q_chart Q_chart_bar';
        if (s.title) {
            var titleEl = document.createElement('div');
            titleEl.className = 'Q_chart_title';
            titleEl.textContent = s.title;
            wrapper.appendChild(titleEl);
        }

        var rows = document.createElement('div');
        rows.className = 'Q_chart_bar_rows';
        items.forEach(function (item, i) {
            var row = document.createElement('div');
            row.className = 'Q_chart_bar_row';
            row.dataset.index = i;
            row.innerHTML =
                '<div class="Q_chart_bar_label">' + String(item.label).encodeHTML() + '</div>'
              + '<div class="Q_chart_bar_track">'
              + '  <div class="Q_chart_bar_fill" style="width:0%;background:' + _safeColor(item.color, '#7c3aed') + '"></div>'
              + '</div>'
              + '<div class="Q_chart_bar_value">' + String(String(item.value).encodeHTML()) + (s.unit ? ' ' + s.unit : '') + '</div>';
            rows.appendChild(row);
        });
        wrapper.appendChild(rows);

        if (s.source) {
            var src = document.createElement('div');
            src.className = 'Q_card_source';
            src.innerHTML = s.url
                ? '<a href="' + String(s.url).encodeHTML() + '" target="_blank" rel="noopener">' + String(s.source).encodeHTML() + '</a>'
                : String(s.source).encodeHTML();
            wrapper.appendChild(src);
        }

        tool.element.innerHTML = '';
        tool.element.appendChild(wrapper);

        // Animate bars in staggered sequence
        if (s.animate) {
            $(rows).find('.Q_chart_bar_fill').each(function (i) {
                var $fill = $(this);
                var item = items[i];
                var pct = maxVal > 0 ? (+item.value / maxVal * 100) : 0;
                setTimeout(function () {
                    $fill.css('transition', 'width ' + s.animateMs + 'ms cubic-bezier(0.16,1,0.3,1)');
                    $fill.css('width', pct + '%');
                }, i * 80);
            });
        } else {
            $(rows).find('.Q_chart_bar_fill').each(function (i) {
                var item = items[i];
                var pct = maxVal > 0 ? (+item.value / maxVal * 100) : 0;
                $(this).css('width', pct + '%');
            });
        }

        Q.handle(s.onRefresh, tool);
    },

    /** Called by presentation layer on Streams/highlight ephemeral */
    highlight: function (elementId) {
        var $el = $(this.element);
        $el.find('.Q_highlighted').removeClass('Q_highlighted');
        var idx = parseInt(elementId, 10);
        if (!isNaN(idx)) {
            $el.find('.Q_chart_bar_row').eq(idx).addClass('Q_highlighted');
        } else {
            // Match by label substring
            $el.find('.Q_chart_bar_row').each(function () {
                if ($(this).find('.Q_chart_bar_label').text().toLowerCase().indexOf(elementId.toLowerCase()) >= 0) {
                    $(this).addClass('Q_highlighted');
                }
            });
        }
    }
});
// Sanitize color values to prevent CSS injection from LLM-generated data.
// Allows: hex colors, rgb/rgba/hsl/hsla, named CSS colors (word-chars only).
function _safeColor(color, fallback) {
    if (!color) return fallback;
    var s = String(color).trim();
    if (/^#[0-9a-fA-F]{3,8}$/.test(s)) return s;              // #abc, #aabbcc
    if (/^rgba?\([^)]{0,60}\)$/.test(s)) return s;            // rgb()/rgba()
    if (/^hsla?\([^)]{0,60}\)$/.test(s)) return s;            // hsl()/hsla()
    if (/^[a-zA-Z]{1,32}$/.test(s)) return s;                   // named: red, blue...
    return fallback;
}

})(Q, Q.jQuery, window);
