(function () {
  function bind(root) {
    var selected = root.querySelector('[data-payments-alloc-selected]');
    var all = root.querySelector('[data-payments-alloc-all]');
    var boxes = root.querySelectorAll('input[type="checkbox"][data-payments-alloc-sum]');
    if (!selected || !boxes.length) return;

    function refresh() {
      var sum = 0;
      var checked = 0;
      boxes.forEach(function (el) {
        if (el.checked) {
          checked++;
          sum += parseFloat(el.getAttribute('data-payments-alloc-sum') || '0') || 0;
        }
      });
      selected.textContent = sum.toFixed(2);
      if (all) {
        all.checked = checked > 0 && checked === boxes.length;
        all.indeterminate = checked > 0 && checked < boxes.length;
      }
    }

    if (all) {
      all.addEventListener('change', function () {
        boxes.forEach(function (el) {
          el.checked = all.checked;
        });
        refresh();
      });
    }
    boxes.forEach(function (el) {
      el.addEventListener('change', refresh);
    });
    refresh();
  }
  document.querySelectorAll('[data-payments-alloc]').forEach(bind);
})();
