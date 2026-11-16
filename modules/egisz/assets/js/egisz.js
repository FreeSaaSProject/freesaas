(function () {
  'use strict';

  function debounce(fn, ms) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, ms);
    };
  }

  function attachIcd(input) {
    if (!input || input.dataset.egiszIcd === '1') return;
    input.dataset.egiszIcd = '1';
    var box = document.createElement('div');
    box.className = 'egisz-icd-suggest';
    box.hidden = true;
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(box);

    var run = debounce(function () {
      var q = (input.value || '').trim();
      var last = q.split(/[\s,;]+/).pop() || '';
      if (last.length < 1) {
        box.hidden = true;
        return;
      }
      fetch('/egisz/icd/search?ajax=1&q=' + encodeURIComponent(last), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      }).then(function (r) { return r.json(); }).then(function (data) {
        box.innerHTML = '';
        var items = (data && data.items) || [];
        if (!items.length) {
          box.hidden = true;
          return;
        }
        items.forEach(function (it) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.textContent = it.code + ' — ' + it.name;
          btn.addEventListener('click', function () {
            var parts = input.value.split(/[\s,;]+/).filter(Boolean);
            if (parts.length) parts[parts.length - 1] = it.code;
            else parts = [it.code];
            input.value = parts.join(', ');
            box.hidden = true;
            input.focus();
          });
          box.appendChild(btn);
        });
        box.hidden = false;
      }).catch(function () { box.hidden = true; });
    }, 250);

    input.addEventListener('input', run);
    input.addEventListener('blur', function () {
      setTimeout(function () { box.hidden = true; }, 200);
    });
  }

  function scan() {
    document.querySelectorAll(
      'input[name="dia_codes"], textarea[name="dia_codes"],' +
      'input[name="f[dia_codes]"], textarea[name="f[dia_codes]"],' +
      'input[data-field-id="dia_codes"]'
    ).forEach(attachIcd);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
  document.addEventListener('docs:fields-rendered', scan);
})();
