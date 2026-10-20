(function () {
  'use strict';
  // Live page soft auto-refresh every 5s
  var live = document.querySelector('.video-live[data-camera-id]');
  if (live) {
    setInterval(function () {
      if (document.hidden) return;
      window.location.reload();
    }, 5000);
  }
})();
