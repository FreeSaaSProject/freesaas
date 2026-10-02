(function () {
  // Comment reply
  var replyPid = document.getElementById('reply_pid');
  var commentField = document.getElementById('comment-field');
  var replyHint = document.getElementById('reply-hint');
  var replyClear = document.getElementById('reply-clear');

  function clearReply() {
    if (replyPid) replyPid.value = '0';
    if (replyHint) {
      replyHint.hidden = true;
      replyHint.textContent = '';
    }
  }

  document.querySelectorAll('.js-reply').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pid = btn.getAttribute('data-pid') || '0';
      var name = btn.getAttribute('data-name') || '';
      if (replyPid) replyPid.value = pid;
      if (commentField) {
        var prefix = name ? (name + ', ') : '';
        if (commentField.value.indexOf(prefix) !== 0) {
          commentField.value = prefix + commentField.value.replace(/^\S+\s+\S+,\s*/, '');
        }
        commentField.focus();
      }
      if (replyHint) {
        replyHint.hidden = false;
        replyHint.textContent = (window.t ? window.t('crm.reply_hint', {id: pid}) : ('Reply to comment #' + pid))
          + (name ? (' · ' + name) : '');
      }
      var form = document.getElementById('comment-form');
      if (form) form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
  });
  if (replyClear) {
    replyClear.addEventListener('click', function () {
      clearReply();
      if (commentField) commentField.value = '';
    });
  }

  // Jump to comment form / focus field
  function focusCommentForm() {
    var form = document.getElementById('comment-form');
    var field = document.getElementById('comment-field');
    if (form) {
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (field) {
      setTimeout(function () { field.focus(); }, 280);
    }
  }
  var jumpComments = document.getElementById('jump-comments');
  if (jumpComments) {
    jumpComments.addEventListener('click', function (e) {
      e.preventDefault();
      if (history.replaceState) {
        history.replaceState(null, '', '#comment-form');
      } else {
        window.location.hash = 'comment-form';
      }
      focusCommentForm();
    });
  }
  if (window.location.hash === '#comment-form' || window.location.hash === '#comments') {
    setTimeout(focusCommentForm, 50);
  }
})();
