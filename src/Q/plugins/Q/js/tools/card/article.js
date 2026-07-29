(function (Q, $, window, undefined) {
/**
 * @module Q-tools
 */
/**
 * Article / content card: headline + publication + key claim.
 *
 * @class Q/card/article
 * @constructor
 * @param {Object} [options]
 * @param {String} options.title
 * @param {String} [options.publication]
 * @param {String} [options.keyClaim]
 * @param {String} [options.url]
 * @param {String} [options.imageUrl]
 * @param {String} [options.date]
 * @param {Q.Event} [options.onRefresh]
 */
Q.Tool.define("Q/card/article", function () {
    this.refresh();
},
{
    title: '', publication: '', keyClaim: '', url: '', imageUrl: '', date: '',
    onRefresh: new Q.Event()
},
{
    refresh: function () {
        var tool = this, s = tool.state;
        Q.Template.render('Q/card/article', {
            title: s.title, publication: s.publication, keyClaim: s.keyClaim,
            url: s.url, imageUrl: s.imageUrl, date: s.date,
            hasImage: !!s.imageUrl, hasClaim: !!s.keyClaim,
            hasMeta: !!(s.publication || s.date)
        }, function (err, html) {
            if (err) return;
            tool.element.innerHTML = html;
            Q.handle(s.onRefresh, tool);
        });
    }
});

Q.Template.set('Q/card/article',
    '<div class="Q_card Q_card_article">'
  + '  {{#if hasImage}}<img class="Q_card_article_image" src="{{imageUrl}}" alt="" />{{/if}}'
  + '  {{#if hasMeta}}<div class="Q_card_article_meta">'
  + '    {{#if publication}}<span class="Q_card_article_pub">{{publication}}</span>{{/if}}'
  + '    {{#if date}}<span class="Q_card_article_date">{{date}}</span>{{/if}}'
  + '  </div>{{/if}}'
  + '  <div class="Q_card_article_title">'
  + '    {{#if url}}<a href="{{url}}" target="_blank" rel="noopener">{{title}}</a>'
  + '    {{else}}{{title}}{{/if}}'
  + '  </div>'
  + '  {{#if hasClaim}}<div class="Q_card_article_claim">{{keyClaim}}</div>{{/if}}'
  + '</div>'
);
})(Q, Q.jQuery, window);
