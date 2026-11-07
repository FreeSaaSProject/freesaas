(function () {
  'use strict';

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function isQuoteLine(ln) {
    return /^\s*[«"„]/.test(ln) || /^\s*>\s+/.test(ln);
  }

  function isListLead(ln) {
    return /^\s*[-*•]\s+/.test(ln) || /^\s*\d+\.\s+/.test(ln);
  }

  function inlineMd(s) {
    var esc = escapeHtml(s);
    esc = esc.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    esc = esc.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    esc = esc.replace(/(^|[^*\w])\*([^*\n]+)\*(?=[^*\w]|$)/g, '$1<em>$2</em>');
    return esc;
  }

  function formatReportHtml(text) {
    var raw = String(text || '').replace(/\r\n/g, '\n').trim();
    if (!raw) return '';
    var blocks = raw.split(/\n{2,}/);
    var html = [];
    for (var bi = 0; bi < blocks.length; bi++) {
      var block = blocks[bi].trim();
      if (!block) continue;
      var lines = block.split('\n');
      if (lines.length >= 1 && isListLead(lines[0])
          && lines.every(function (ln, i) {
            return i === 0 ? isListLead(ln) : (isQuoteLine(ln) || isListLead(ln) || /^\s*$/.test(ln));
          })) {
        var ol = /^\s*\d+\.\s+/.test(lines[0]);
        var tag = ol ? 'ol' : 'ul';
        var items = [];
        var cur = null;
        for (var li = 0; li < lines.length; li++) {
          var ln = lines[li];
          if (/^\s*$/.test(ln)) continue;
          if (isListLead(ln)) {
            if (cur) items.push(cur);
            cur = {
              head: ln.replace(/^\s*[-*•]\s+/, '').replace(/^\s*\d+\.\s+/, ''),
              quotes: []
            };
          } else if (cur && isQuoteLine(ln)) {
            cur.quotes.push(ln.replace(/^\s*>\s+/, '').trim());
          }
        }
        if (cur) items.push(cur);
        var listHtml = items.map(function (it) {
          var body = '<div class="arm-item-head">' + inlineMd(it.head) + '</div>';
          for (var qi = 0; qi < it.quotes.length; qi++) {
            body += '<blockquote class="arm-quote">' + inlineMd(it.quotes[qi]) + '</blockquote>';
          }
          return '<li>' + body + '</li>';
        }).join('');
        html.push('<' + tag + ' class="arm-events">' + listHtml + '</' + tag + '>');
        continue;
      }
      var para = lines.map(function (ln) {
        if (isQuoteLine(ln)) {
          return '<blockquote class="arm-quote">' + inlineMd(ln.replace(/^\s*>\s+/, '').trim()) + '</blockquote>';
        }
        return inlineMd(ln);
      }).join('<br>');
      para = para.replace(/(?:<br>)*<blockquote/g, '<blockquote').replace(/<\/blockquote>(?:<br>)*/g, '</blockquote>');
      if (/^(ЕСТЬ СОБЫТИЯ|ЗНАЧИМЫХ СОБЫТИЙ НЕТ|THERE ARE EVENTS|NO SIGNIFICANT EVENTS)/i.test(block.replace(/\*+/g, ''))) {
        html.push('<p class="arm-verdict">' + para + '</p>');
      } else {
        html.push('<div class="arm-block">' + para + '</div>');
      }
    }
    return html.join('');
  }

  // Markdown report formatter shared with LongJobPanel onDone (inline boot in panel view).
  window.AudioReviewModeration = { formatReportHtml: formatReportHtml };
})();
