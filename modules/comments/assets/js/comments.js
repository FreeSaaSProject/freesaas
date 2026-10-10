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

  // Edit comment dialog
  var dlg = document.getElementById('comment-edit-dialog');
  var editForm = document.getElementById('comment-edit-form');
  var editId = document.getElementById('comment-edit-id');
  var editText = document.getElementById('comment-edit-text');
  var editTitle = document.getElementById('comment-edit-title');
  var editExtra = document.getElementById('comment-edit-extra');
  var editTpl = document.getElementById('comment-edit-extra-tpl');
  var titleTpl = editTitle ? (editTitle.getAttribute('data-tpl') || editTitle.textContent) : 'Comment #__ID__';

  function closeEditDlg() {
    if (dlg && typeof dlg.close === 'function') dlg.close();
  }

  function openEditDlg(btn) {
    if (!dlg || !editForm || !editId || !editText) return;
    var cid = btn.getAttribute('data-cid') || '0';
    var text = btn.getAttribute('data-text') || '';
    var price = btn.getAttribute('data-price') || '0';
    var deadline = btn.getAttribute('data-deadline') || '';
    var isTicket = btn.getAttribute('data-is-ticket') === '1';
    editId.value = cid;
    editText.value = text;
    if (editTitle) {
      editTitle.textContent = titleTpl.replace(/__ID__/g, cid);
    }
    if (editExtra) {
      editExtra.innerHTML = '';
      editExtra.hidden = true;
      if (isTicket && editTpl && editTpl.content) {
        editExtra.appendChild(editTpl.content.cloneNode(true));
        editExtra.hidden = false;
        var priceInp = editExtra.querySelector('[name="price_offer"]');
        var dlInp = editExtra.querySelector('[name="offer_deadline"]');
        if (priceInp) priceInp.value = price !== '0' ? price : '';
        if (dlInp && deadline) dlInp.value = deadline;
      }
    }
    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      dlg.setAttribute('open', 'open');
    }
    setTimeout(function () { editText.focus(); }, 50);
  }

  document.querySelectorAll('.js-comment-edit').forEach(function (btn) {
    btn.addEventListener('click', function () { openEditDlg(btn); });
  });
  ['comment-edit-cancel', 'comment-edit-cancel-2'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('click', function (e) { e.preventDefault(); closeEditDlg(); });
  });
  if (dlg) {
    dlg.addEventListener('cancel', function () { closeEditDlg(); });
  }
})();
