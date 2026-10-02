(function () {
  function bindBoardPopups(root) {
    var scope = root || document;
    var info = scope.querySelector('#calendar-board-info') || document.getElementById('calendar-board-info');
    if (!info) return;
    var touch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    var openChip = null;

    function hideInfo() {
      info.hidden = true;
      info.innerHTML = '';
      openChip = null;
    }
    function showInfo(chip, fromTouch) {
      var html = chip.getAttribute('data-info') || '';
      if (!html) return;
      info.innerHTML = html + (fromTouch
        ? '<div class="calendar-info-actions"><button type="button" class="btn-secondary btn-sm" data-info-close>OK</button></div>'
        : '');
      info.hidden = false;
      var rect = chip.getBoundingClientRect();
      info.style.top = (rect.bottom + window.scrollY + 4) + 'px';
      info.style.left = Math.max(8, rect.left + window.scrollX) + 'px';
      openChip = chip;
      var closer = info.querySelector('[data-info-close]');
      if (closer) closer.addEventListener('click', hideInfo);
    }

    scope.querySelectorAll('.calendar-board-chip').forEach(function (chip) {
      if (!touch) {
        chip.addEventListener('mouseenter', function () { showInfo(chip, false); });
        chip.addEventListener('mouseleave', function () { hideInfo(); });
      }
      chip.addEventListener('click', function (e) {
        e.preventDefault();
        if (openChip === chip && !info.hidden) {
          hideInfo();
          return;
        }
        showInfo(chip, true);
      });
    });
    document.addEventListener('click', function (e) {
      if (!info || info.hidden) return;
      if (info.contains(e.target)) return;
      if (openChip && openChip.contains(e.target)) return;
      hideInfo();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { bindBoardPopups(document); });
  } else {
    bindBoardPopups(document);
  }
})();
