(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Pull quote card.
 *
 * @class Q/card/quote
 * @constructor
 * @param {Object} [options]
 * @param {String} options.quote
 * @param {String} [options.speaker]
 * @param {String} [options.source]
 * @param {String} [options.url]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/quote", function () {
    this.refresh();
},
{ quote: '', speaker: '', source: '', url: '', onRefresh: new Q.Event() },
{
    refresh: function () {
        var tool = this, s = tool.state;
        Q.Template.render('Q/card/quote', {
            quote: s.quote, speaker: s.speaker, source: s.source, url: s.url,
            hasSpeaker: !!s.speaker, hasSource: !!(s.source || s.url)
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            Q.handle(s.onRefresh, tool);
        });
    }
});

Q.Template.set('Q/card/quote',
    '<div class="Q_card Q_card_quote">'
  + '  <div class="Q_card_quote_mark">\u201c</div>'
  + '  <blockquote class="Q_card_quote_text">{{quote}}</blockquote>'
  + '  <div class="Q_card_quote_attribution">'
  + '    {{#if hasSpeaker}}<span class="Q_card_quote_speaker">{{speaker}}</span>{{/if}}'
  + '    {{#if hasSource}}<span class="Q_card_quote_source">'
  + '      {{#if url}}<a href="{{url}}" target="_blank" rel="noopener">{{source}}</a>'
  + '      {{else}}{{source}}{{/if}}'
  + '    </span>{{/if}}'
  + '  </div>'
  + '</div>'
);
})(Q, Q.jQuery, window);
