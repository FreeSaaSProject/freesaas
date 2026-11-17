(function () {
  'use strict';

  function hasExtraQty(form) {
    var inputs = form.querySelectorAll('input[name^="extra"]');
    for (var i = 0; i < inputs.length; i++) {
      if (parseInt(inputs[i].value, 10) > 0) {
        return true;
      }
    }
    return false;
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.getAttribute || form.getAttribute('data-laundry-accept') !== '1') {
      return;
    }
    if (!hasExtraQty(form)) {
      return;
    }
    var msg = form.getAttribute('data-extra-confirm') || '';
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  }, true);
})();
