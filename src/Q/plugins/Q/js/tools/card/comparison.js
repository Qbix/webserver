(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Two-column comparison card.
 * Ephemeral Streams/highlight {elementId} highlights a column ('left'|'right') or row (int index).
 *
 * @class Q/card/comparison
 * @constructor
 * @param {Object} [options]
 * @param {Object} options.left     { label, value }
 * @param {Object} options.right    { label, value }
 * @param {Array}  [options.rows]   [{ label, left, right, delta? }, ...]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/comparison", function () {
    this.refresh();
},
{
    left: { label: '', value: '' }, right: { label: '', value: '' },
    rows: [], onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        Q.Template.render('Q/card/comparison', {
            left: s.left, right: s.right, rows: s.rows,
            hasRows: !!(s.rows && s.rows.length)
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            Q.handle(s.onRefresh, tool);
        });
    },
    /** Called by Media/presentation/card/comparison on Streams/highlight ephemeral */
    highlight: function (elementId) {
        var $el = $(this.element);
        $el.find('.Q_highlighted').removeClass('Q_highlighted');
        if (elementId === 'left')  { $el.find('.Q_card_comparison_left').addClass('Q_highlighted'); return; }
        if (elementId === 'right') { $el.find('.Q_card_comparison_right').addClass('Q_highlighted'); return; }
        var idx = parseInt(elementId, 10);
        if (!isNaN(idx)) $el.find('.Q_card_comparison_row').eq(idx).addClass('Q_highlighted');
    }
});

Q.Template.set('Q/card/comparison',
    '<div class="Q_card Q_card_comparison">'
  + '  <div class="Q_card_comparison_header">'
  + '    <div class="Q_card_comparison_left Q_card_comparison_col">'
  + '      <div class="Q_card_comparison_col_label">{{left.label}}</div>'
  + '      <div class="Q_card_comparison_col_value">{{left.value}}</div>'
  + '    </div>'
  + '    <div class="Q_card_comparison_right Q_card_comparison_col">'
  + '      <div class="Q_card_comparison_col_label">{{right.label}}</div>'
  + '      <div class="Q_card_comparison_col_value">{{right.value}}</div>'
  + '    </div>'
  + '  </div>'
  + '  {{#if hasRows}}<div class="Q_card_comparison_rows">'
  + '    {{#each rows}}<div class="Q_card_comparison_row">'
  + '      <div class="Q_card_comparison_row_label">{{label}}</div>'
  + '      <div class="Q_card_comparison_row_left">{{left}}</div>'
  + '      <div class="Q_card_comparison_row_right">{{right}}</div>'
  + '      {{#if delta}}<div class="Q_card_comparison_row_delta">{{delta}}</div>{{/if}}'
  + '    </div>{{/each}}'
  + '  </div>{{/if}}'
  + '</div>'
);
})(Q, Q.jQuery, window);
