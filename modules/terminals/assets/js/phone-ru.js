/**
 * Russian mobile phone mask: +7 (999) 999-99-99
 * Bind: input.js-phone-ru or input[data-phone-ru]
 */
(function () {
  'use strict';

  var PLACEHOLDER = '+7 (___) ___-__-__';

  function digitsOnly(s) {
    return String(s || '').replace(/\D+/g, '');
  }

  /** Normalize to 11 digits starting with 7 (or shorter while typing). */
  function normalizeRu(raw) {
    var d = digitsOnly(raw);
    if (d === '') {
      return '';
    }
    if (d[0] === '8') {
      d = '7' + d.slice(1);
    }
    if (d[0] !== '7') {
      d = '7' + d;
    }
    return d.slice(0, 11);
  }

  function formatRu(d) {
    d = normalizeRu(d);
    if (d === '') {
      return '';
    }
    var out = '+7';
    var rest = d.slice(1);
    if (rest.length === 0) {
      return out;
    }
    out += ' (' + rest.slice(0, 3);
    if (rest.length < 3) {
      return out;
    }
    out += ')';
    if (rest.length === 3) {
      return out;
    }
    out += ' ' + rest.slice(3, 6);
    if (rest.length <= 6) {
      return out;
    }
    out += '-' + rest.slice(6, 8);
    if (rest.length <= 8) {
      return out;
    }
    out += '-' + rest.slice(8, 10);
    return out;
  }

  function isComplete(d) {
    d = normalizeRu(d);
    return d.length === 11 && d[0] === '7';
  }

  /** Cursor position in formatted string after `digitCount` digits (incl. leading 7). */
  function caretAfterDigits(formatted, digitCount) {
    if (digitCount <= 0) {
      return Math.min(2, formatted.length); // after "+7"
    }
    var seen = 0;
    for (var k = 0; k < formatted.length; k++) {
      if (/\d/.test(formatted.charAt(k))) {
        seen++;
        if (seen >= digitCount) {
          return k + 1;
        }
      }
    }
    return formatted.length;
  }

  function bind(input) {
    if (!input || input.getAttribute('data-phone-ru-bound') === '1') {
      return;
    }
    input.setAttribute('data-phone-ru-bound', '1');
    input.setAttribute('inputmode', 'tel');
    input.setAttribute('autocomplete', 'tel');
    if (!input.getAttribute('placeholder')) {
      input.setAttribute('placeholder', PLACEHOLDER);
    }
    if (!input.getAttribute('maxlength')) {
      input.setAttribute('maxlength', '18');
    }

    function apply(preferredDigitCount) {
      var formatted = formatRu(input.value);
      input.value = formatted;
      if (document.activeElement === input && typeof preferredDigitCount === 'number') {
        var pos = caretAfterDigits(formatted, preferredDigitCount);
        try {
          input.setSelectionRange(pos, pos);
        } catch (e) { /* ignore */ }
      }
    }

    input.addEventListener('focus', function () {
      if (digitsOnly(input.value) === '') {
        input.value = '+7';
        try {
          input.setSelectionRange(input.value.length, input.value.length);
        } catch (e) { /* ignore */ }
      } else {
        apply(normalizeRu(input.value).length);
      }
    });

    // Backspace through mask punctuation ( ), -, spaces) without getting stuck.
    input.addEventListener('keydown', function (ev) {
      var key = ev.key || '';
      var isBack = key === 'Backspace' || ev.keyCode === 8;
      if (!isBack) {
        return;
      }
      var start = input.selectionStart | 0;
      var end = input.selectionEnd | 0;
      if (start !== end) {
        return; // selection: default + input handler
      }
      var val = input.value;
      if (start <= 0) {
        return;
      }
      // Char before caret is not a digit → jump over mask and delete previous digit.
      if (!/\d/.test(val.charAt(start - 1))) {
        ev.preventDefault();
        var i = start - 1;
        while (i >= 0 && !/\d/.test(val.charAt(i))) {
          i--;
        }
        if (i < 0) {
          return;
        }
        // Digits left of the one we remove.
        var digitsBefore = digitsOnly(val.slice(0, i)).length;
        var d = normalizeRu(val.slice(0, i) + val.slice(i + 1));
        if (d === '' || d === '7') {
          input.value = '+7';
          try {
            input.setSelectionRange(2, 2);
          } catch (e) { /* ignore */ }
          return;
        }
        input.value = formatRu(d);
        try {
          input.setSelectionRange(
            caretAfterDigits(input.value, digitsBefore),
            caretAfterDigits(input.value, digitsBefore)
          );
        } catch (e2) { /* ignore */ }
      }
    });

    input.addEventListener('input', function () {
      apply(normalizeRu(input.value).length);
    });

    input.addEventListener('blur', function () {
      var d = normalizeRu(input.value);
      if (d === '' || d === '7') {
        input.value = '';
        return;
      }
      apply(d.length);
    });

    input.addEventListener('paste', function () {
      setTimeout(function () {
        apply(normalizeRu(input.value).length);
      }, 0);
    });

    var form = input.form;
    if (form && form.getAttribute('data-phone-ru-submit') !== '1') {
      form.setAttribute('data-phone-ru-submit', '1');
      form.addEventListener('submit', function (ev) {
        var fields = form.querySelectorAll('.js-phone-ru, [data-phone-ru]');
        for (var i = 0; i < fields.length; i++) {
          var el = fields[i];
          if (!el.required && digitsOnly(el.value) === '') {
            continue;
          }
          var d = normalizeRu(el.value);
          if (!isComplete(d)) {
            ev.preventDefault();
            el.focus();
            el.setCustomValidity(el.getAttribute('data-phone-invalid') || 'Укажите телефон: +7 и 10 цифр');
            el.reportValidity();
            return;
          }
          el.setCustomValidity('');
          el.value = d;
        }
      });
    }

    if (input.value) {
      apply(normalizeRu(input.value).length);
    }
  }

  function scan(root) {
    root = root || document;
    var list = root.querySelectorAll('.js-phone-ru, [data-phone-ru]');
    for (var i = 0; i < list.length; i++) {
      bind(list[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { scan(); });
  } else {
    scan();
  }

  window.SitesPhoneRu = { format: formatRu, normalize: normalizeRu, bind: bind, scan: scan, isComplete: isComplete };
})();
