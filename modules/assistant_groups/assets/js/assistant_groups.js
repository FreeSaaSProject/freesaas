(function () {
  function syncModule(mod) {
    var star = mod.querySelector('[data-ag-module-star]');
    var list = mod.querySelector('[data-ag-ops-list]');
    if (!star || !list) return;
    var on = !!star.checked;
    list.hidden = on;
    list.querySelectorAll('[data-ag-op]').forEach(function (cb) {
      cb.disabled = on;
      if (on) cb.checked = true;
    });
  }

  document.querySelectorAll('[data-ag-ops-catalog] .ag-ops-mod').forEach(function (mod) {
    var star = mod.querySelector('[data-ag-module-star]');
    if (!star) return;
    syncModule(mod);
    star.addEventListener('change', function () {
      syncModule(mod);
      if (star.checked) mod.open = true;
    });
  });

  // Before submit: re-enable disabled ops so unchecked modules don't lose nothing;
  // when star is on, strip individual ids (server prefers module.*).
  document.querySelectorAll('form.ag-settings').forEach(function (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('[data-ag-ops-catalog] .ag-ops-mod').forEach(function (mod) {
        var star = mod.querySelector('[data-ag-module-star]');
        var list = mod.querySelector('[data-ag-ops-list]');
        if (!star || !list) return;
        list.querySelectorAll('[data-ag-op]').forEach(function (cb) {
          if (star.checked) {
            cb.disabled = true;
            cb.checked = false; // covered by module.*
          } else {
            cb.disabled = false;
          }
        });
      });
    });
  });
})();
