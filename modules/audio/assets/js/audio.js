(function () {
  function getSelectedText() {
    if (window.getSelection) {
      return String(window.getSelection().toString() || '');
    }
    return '';
  }

  function parseJsonEl(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      return null;
    }
  }

  function labels() {
    return window.AudioListI18n || {};
  }

  function armAudio(audio, seekStart) {
    if (!audio) return null;
    var url = audio.getAttribute('data-play-src') || '';
    if (!audio.getAttribute('src') && url) {
      audio.src = url;
      try { audio.load(); } catch (e) {}
    }
    var seek = (typeof seekStart === 'number' && !isNaN(seekStart)) ? seekStart : null;
    if (seek === null) {
      try { audio.play(); } catch (e) {}
      return audio;
    }
    var startPlay = function () {
      try {
        audio.currentTime = seek;
        audio.play();
      } catch (e) {}
    };
    if (audio.readyState >= 1) {
      startPlay();
    } else {
      audio.addEventListener('loadedmetadata', function once() {
        audio.removeEventListener('loadedmetadata', once);
        startPlay();
      });
      try { audio.play(); } catch (e) {}
    }
    return audio;
  }

  function mountRealPlayer(card, seekStart) {
    if (!card) return null;
    var existing = card.querySelector('audio.audio-inline-player');
    if (existing) return armAudio(existing, seekStart);

    var shell = card.querySelector('.audio-player-shell');
    var url = (shell && shell.getAttribute('data-play-src')) || card.getAttribute('data-play-url') || '';
    if (!url) return null;

    var audio = document.createElement('audio');
    audio.className = 'audio-inline-player';
    audio.controls = true;
    audio.preload = 'none';
    audio.setAttribute('data-play-src', url);
    audio.src = url;

    if (shell) {
      shell.replaceWith(audio);
    } else {
      var head = card.querySelector('.audio-main-head');
      if (!head || !head.parentNode) return null;
      if (head.nextSibling) {
        head.parentNode.insertBefore(audio, head.nextSibling);
      } else {
        head.parentNode.appendChild(audio);
      }
    }
    bindTimeHighlight(card);
    return armAudio(audio, seekStart);
  }

  function ensurePlayer(card, seekStart) {
    return mountRealPlayer(card, seekStart);
  }

  function bindPlayerShells(root) {
    (root || document).querySelectorAll('.audio-player-shell').forEach(function (shell) {
      if (shell.getAttribute('data-shell-bound') === '1') return;
      shell.setAttribute('data-shell-bound', '1');
      shell.addEventListener('click', function () {
        var card = shell.closest('tr.audio-record-card') || shell.closest('tr');
        mountRealPlayer(card, null);
      });
    });
  }

  function bindSeek(root) {
    if (!root || root.getAttribute('data-seek-bound') === '1') return;
    root.setAttribute('data-seek-bound', '1');
    root.addEventListener('click', function (ev) {
      if (document.querySelector('.add-key-popover') || getSelectedText() !== '') return;
      var line = ev.target.closest('.transcript-line');
      if (!line || !root.contains(line)) return;
      var start = parseInt(line.getAttribute('data-time-start') || '', 10);
      if (isNaN(start)) return;
      var card = line.closest('tr') || root.closest('tr');
      root.querySelectorAll('.transcript-line.is-active, .transcript-line.transcript-line-active').forEach(function (el) {
        el.classList.remove('is-active', 'transcript-line-active');
      });
      line.classList.add('is-active', 'transcript-line-active');
      var player = ensurePlayer(card, start);
      if (!player) {
        player = document.getElementById('audio-player');
        if (player) {
          try {
            player.currentTime = start;
            player.play();
          } catch (e) {}
        }
      }
    });
  }

  function bindTimeHighlight(card) {
    if (!card || card.getAttribute('data-time-bound') === '1') return;
    var kolonka = card.querySelector('.kolonka, .transcript');
    var audio = card.querySelector('audio');
    if (!kolonka || !audio) return;
    card.setAttribute('data-time-bound', '1');
    audio.addEventListener('timeupdate', function () {
      var ct = audio.currentTime;
      kolonka.querySelectorAll('.transcript-line').forEach(function (line) {
        var start = parseFloat(line.getAttribute('data-time-start') || '');
        var end = parseFloat(line.getAttribute('data-time-end') || '');
        if (!isNaN(start) && !isNaN(end) && ct >= start && ct < end) {
          line.classList.add('transcript-line-active', 'is-active');
        } else {
          line.classList.remove('transcript-line-active', 'is-active');
        }
      });
    });
  }

  function contextForKolonka(kolonkaEl) {
    var card = kolonkaEl && (kolonkaEl.closest('[data-admin-id]')
      || kolonkaEl.closest('[data-record-id]')
      || kolonkaEl.closest('tr.audio-record-card'));
    var aid = card ? parseInt(card.getAttribute('data-admin-id') || '0', 10) : 0;
    var rid = card ? parseInt(card.getAttribute('data-record-id') || '0', 10) : 0;
    if (!rid && kolonkaEl) {
      rid = parseInt(kolonkaEl.getAttribute('data-record') || '0', 10) || 0;
    }
    var byAdmin = parseJsonEl('audio-key-dicts-by-admin');
    if (byAdmin && aid > 0) {
      var scoped = byAdmin[aid] || byAdmin[String(aid)];
      if (scoped && typeof scoped === 'object') {
        return { dicts: scoped, adminId: aid, recordId: rid };
      }
    }
    return { dicts: parseJsonEl('audio-key-dicts') || {}, adminId: aid, recordId: rid };
  }

  function showAddPopover(kolonkaEl, text) {
    text = String(text || '').trim();
    if (!text) return;
    var resolved = contextForKolonka(kolonkaEl);
    var dicts = resolved.dicts;
    var adminId = resolved.adminId || 0;
    var recordId = resolved.recordId || 0;
    if (!dicts || !Object.keys(dicts).length) return;

    var existing = kolonkaEl.querySelector('.add-key-popover');
    if (existing) existing.remove();

    var pop = document.createElement('div');
    pop.className = 'add-key-popover';
    pop.innerHTML =
      '<div class="add-key-row"><span class="add-key-lbl"></span><input type="text" class="add-key-input" /></div>' +
      '<div class="add-key-row"><span class="add-key-lbl" data-lbl="dict"></span><select class="add-key-dict"></select></div>' +
      '<div class="add-key-row add-key-actions">' +
      '<button type="button" class="add-key-btn" data-act="key"></button>' +
      '<button type="button" class="add-key-btn add-key-btn--soft" data-act="key-noreparse"></button>' +
      '<button type="button" class="add-key-btn-trash" data-act="trash"></button>' +
      '<button type="button" class="add-key-cancel"></button>' +
      '<span class="add-key-status"></span></div>' +
      '<div class="add-key-warnings" hidden></div>';

    var L = labels();
    pop.querySelector('.add-key-lbl').textContent = L.key || 'Key';
    var dictLbl = pop.querySelector('[data-lbl="dict"]');
    if (dictLbl) dictLbl.textContent = L.dict || 'Dict';
    pop.querySelector('[data-act="key"]').textContent = L.addKey || 'Add key';
    var noReBtn = pop.querySelector('[data-act="key-noreparse"]');
    if (noReBtn) {
      noReBtn.textContent = L.addKeyNoReparse || 'Key without Re-®';
      noReBtn.title = L.addKeyNoReparseHint || '';
    }
    pop.querySelector('[data-act="trash"]').textContent = L.addTrash || 'Add trash';
    pop.querySelector('.add-key-cancel').textContent = L.cancel || 'Cancel';
    pop.querySelector('.add-key-input').value = text;

    var dictSel = pop.querySelector('.add-key-dict');
    Object.keys(dicts).forEach(function (id) {
      var o = document.createElement('option');
      o.value = id;
      o.textContent = dicts[id];
      dictSel.appendChild(o);
    });

    function escapeHtml(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function showOverlapPanel(warn, overlaps, onForce) {
      warn.hidden = false;
      warn.innerHTML = '';
      var title = document.createElement('div');
      title.className = 'add-key-overlap-title';
      title.textContent = L.confirmOverlaps || 'Similar keys';
      warn.appendChild(title);
      var ul = document.createElement('ul');
      ul.className = 'add-key-overlap-list';
      (overlaps || []).slice(0, 12).forEach(function (o) {
        var id = parseInt(o.id, 10) || 0;
        var li = document.createElement('li');
        var rel = o.relation_label ? ' — ' + escapeHtml(o.relation_label) : '';
        var dict = o.dict_name ? ' <span class="muted">[' + escapeHtml(o.dict_name) + ']</span>' : '';
        li.innerHTML =
          '<a href="/audio/keys/' + id + '/edit" target="_blank" rel="noopener">#' + id +
          ' «' + escapeHtml(o.name || '') + '»</a>' + dict +
          '<span class="add-key-overlap-rel">' + rel + '</span>';
        ul.appendChild(li);
      });
      warn.appendChild(ul);
      var actions = document.createElement('div');
      actions.className = 'add-key-overlap-actions';
      var forceBtn = document.createElement('button');
      forceBtn.type = 'button';
      forceBtn.className = 'add-key-btn';
      forceBtn.textContent = L.createAnyway || 'Create anyway';
      forceBtn.addEventListener('click', function () { onForce(); });
      var reloadBtn = document.createElement('button');
      reloadBtn.type = 'button';
      reloadBtn.className = 'add-key-cancel';
      reloadBtn.textContent = L.reload || 'Reload page';
      reloadBtn.addEventListener('click', function () { location.reload(); });
      actions.appendChild(forceBtn);
      actions.appendChild(reloadBtn);
      warn.appendChild(actions);
    }

    function showExistingKeyPanel(warn, key) {
      warn.hidden = false;
      warn.innerHTML = '';
      var title = document.createElement('div');
      title.className = 'add-key-overlap-title';
      title.textContent = L.keyExistsTitle || (L.keyExists || 'Key already exists');
      warn.appendChild(title);
      var id = parseInt(key && key.id, 10) || 0;
      var name = (key && key.name) || '';
      var dictId = parseInt(key && key.dict_id, 10) || 0;
      var dictName = (key && key.dict_name) || '';
      var editUrl = (key && key.edit_url) || ('/audio/keys/' + id + '/edit');
      var dictUrl = (key && key.dict_edit_url) || (dictId > 0 ? ('/audio/dicts/' + dictId + '/edit') : '');
      var body = document.createElement('div');
      body.className = 'add-key-existing';
      var keyLine = document.createElement('p');
      keyLine.innerHTML =
        '<a href="' + escapeHtml(editUrl) + '" target="_blank" rel="noopener">#' + id +
        ' «' + escapeHtml(name) + '»</a>' +
        (key && key.disabled ? ' <span class="muted">(' + escapeHtml(L.disabled || 'disabled') + ')</span>' : '');
      body.appendChild(keyLine);
      if (dictId > 0) {
        var dictLine = document.createElement('p');
        dictLine.className = 'muted';
        if (dictUrl) {
          dictLine.innerHTML =
            escapeHtml(L.inDict || 'Dictionary') + ': ' +
            '<a href="' + escapeHtml(dictUrl) + '" target="_blank" rel="noopener">#' + dictId +
            (dictName ? ' «' + escapeHtml(dictName) + '»' : '') + '</a>';
        } else {
          dictLine.textContent =
            (L.inDict || 'Dictionary') + ': #' + dictId + (dictName ? ' «' + dictName + '»' : '');
        }
        body.appendChild(dictLine);
      }
      var hint = document.createElement('p');
      hint.className = 'muted small';
      hint.textContent = L.keyExistsHint || '';
      if (hint.textContent) {
        body.appendChild(hint);
      }
      warn.appendChild(body);
      var actions = document.createElement('div');
      actions.className = 'add-key-overlap-actions';
      var openBtn = document.createElement('a');
      openBtn.className = 'add-key-btn';
      openBtn.href = editUrl;
      openBtn.target = '_blank';
      openBtn.rel = 'noopener';
      openBtn.textContent = L.openKey || 'Open key';
      var closeBtn = document.createElement('button');
      closeBtn.type = 'button';
      closeBtn.className = 'add-key-cancel';
      closeBtn.textContent = L.cancel || 'Close';
      closeBtn.addEventListener('click', function () { pop.remove(); });
      var reloadBtn = document.createElement('button');
      reloadBtn.type = 'button';
      reloadBtn.className = 'add-key-cancel';
      reloadBtn.textContent = L.reload || 'Reload page';
      reloadBtn.addEventListener('click', function () { location.reload(); });
      actions.appendChild(openBtn);
      actions.appendChild(closeBtn);
      actions.appendChild(reloadBtn);
      warn.appendChild(actions);
    }

    function postAction(action, force, skipReparse) {
      var status = pop.querySelector('.add-key-status');
      var warn = pop.querySelector('.add-key-warnings');
      var keyText = String(pop.querySelector('.add-key-input').value || '').trim();
      if (!keyText) {
        warn.hidden = false;
        warn.textContent = L.textRequired || 'Enter text';
        return;
      }
      var willReparse = action === 'add_key' && recordId > 0 && !skipReparse;
      status.textContent = willReparse
        ? (L.reparsing || '…')
        : '…';
      warn.hidden = true;
      warn.innerHTML = '';
      var body = new URLSearchParams();
      body.set('action', action);
      body.set('text', keyText);
      if (action === 'add_key') {
        body.set('dict_id', dictSel.value || '0');
        if (skipReparse) {
          body.set('skip_reparse', '1');
        }
      }
      if (adminId > 0) {
        body.set('admin_id', String(adminId));
      }
      if (recordId > 0) {
        body.set('record_id', String(recordId));
      }
      if (force) body.set('force', '1');
      fetch('/audio/api/dict', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        // URLSearchParams (not toString) so core.js can append csrf for hosts that strip headers.
        body: body,
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.needs_confirm && !force) {
            status.textContent = '';
            showOverlapPanel(warn, data.overlaps || [], function () {
              postAction(action, true, skipReparse);
            });
            return;
          }
          if (!data || !data.success) {
            pop.classList.add('add-key-error');
            warn.hidden = false;
            warn.textContent = (data && data.error) || (L.error || 'Error');
            status.textContent = '';
            return;
          }
          if (data.already_exists && data.key) {
            status.textContent = '';
            showExistingKeyPanel(warn, data.key);
            return;
          }
          status.textContent = data.message || (L.done || 'OK');
          // Without Re-®: keep the list open so several keys can be added, then bulk Re-®.
          if (data.reparse_skipped) {
            setTimeout(function () {
              if (window.getSelection) window.getSelection().removeAllRanges();
              pop.remove();
            }, 500);
            return;
          }
          var delay = (data.reparsed && data.reparsed.ok) ? 900 : 600;
          setTimeout(function () { location.reload(); }, delay);
        })
        .catch(function () {
          pop.classList.add('add-key-error');
          warn.hidden = false;
          warn.textContent = L.network || 'Network error';
          status.textContent = '';
        });
    }

    pop.querySelector('[data-act="key"]').addEventListener('click', function () { postAction('add_key', false, false); });
    if (noReBtn) {
      noReBtn.addEventListener('click', function () { postAction('add_key', false, true); });
    }
    pop.querySelector('[data-act="trash"]').addEventListener('click', function () { postAction('add_trash'); });
    pop.querySelector('.add-key-cancel').addEventListener('click', function () {
      if (window.getSelection) window.getSelection().removeAllRanges();
      pop.remove();
    });

    kolonkaEl.appendChild(pop);
    if (window.getSelection) window.getSelection().removeAllRanges();
  }

  function bindSelectionAdd(root) {
    if (!root || root.getAttribute('data-select-bound') === '1') return;
    root.setAttribute('data-select-bound', '1');
    root.addEventListener('mouseup', function () {
      showAddPopover(root, getSelectedText());
    });
    root.addEventListener('touchend', function () {
      setTimeout(function () {
        var t = getSelectedText();
        if (t) showAddPopover(root, t);
      }, 150);
    });
  }

  function bindCardTranscript(card, kolonka) {
    bindSeek(kolonka);
    bindSelectionAdd(kolonka);
    bindTimeHighlight(card);
  }

  function applyBlock(slot, block) {
    var L = labels();
    var card = slot.closest('tr');
    var trashEl = card ? card.querySelector('.audio-meta-trash') : null;
    if (trashEl && block && typeof block.trash === 'number') {
      if (block.trash > 0) {
        trashEl.hidden = false;
        trashEl.textContent = 'T' + block.trash;
        trashEl.title = String(L.trashTitle || 'Trash: {n}').replace('{n}', String(block.trash));
      } else {
        trashEl.hidden = true;
      }
    }
    if (block && block.download_url) {
      var dl = card ? card.querySelector('.audio-download') : null;
      if (!dl && card) {
        var head = card.querySelector('.audio-main-head');
        if (head) {
          dl = document.createElement('a');
          dl.className = 'audio-download';
          dl.href = block.download_url;
          dl.setAttribute('download', '');
          dl.textContent = L.download || '↓';
          head.appendChild(dl);
        }
      }
    }
    var wrap = document.createElement('div');
    wrap.className = 'kolonka transcript transcript--list';
    wrap.setAttribute('data-record', slot.getAttribute('data-lazy-record') || '');
    wrap.innerHTML = (block && block.html) ? block.html : '<p class="muted">—</p>';
    slot.replaceWith(wrap);
    if (block && block.play_url && card) {
      card.setAttribute('data-play-url', block.play_url);
      var shell = card.querySelector('.audio-player-shell');
      if (shell) shell.setAttribute('data-play-src', block.play_url);
      var existing = card.querySelector('audio.audio-inline-player');
      if (existing && !existing.getAttribute('data-play-src')) {
        existing.setAttribute('data-play-src', block.play_url);
      }
    }
    if (card) bindCardTranscript(card, wrap);
  }

  function bindLazyTranscripts() {
    var slots = Array.prototype.slice.call(document.querySelectorAll('.audio-lazy-slot[data-lazy-record]'));
    if (!slots.length) return;

    var L = labels();
    var pending = {};
    var queue = [];
    var timer = null;
    var inflight = false;

    function flush() {
      if (inflight || !queue.length) return;
      var batch = queue.splice(0, 12);
      var ids = [];
      var search = '';
      batch.forEach(function (slot) {
        var id = slot.getAttribute('data-lazy-record') || '';
        if (!id || pending[id] === 'done' || pending[id] === 'loading') return;
        pending[id] = 'loading';
        ids.push(id);
        if (!search) search = slot.getAttribute('data-search') || '';
        var st = slot.querySelector('.audio-lazy-status');
        if (st) st.textContent = L.lazyLoading || '…';
      });
      if (!ids.length) {
        flush();
        return;
      }
      inflight = true;
      var url = '/audio/api/blocks?ids=' + encodeURIComponent(ids.join(','));
      if (search) url += '&search=' + encodeURIComponent(search);
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          inflight = false;
          var blocks = (data && data.blocks) ? data.blocks : {};
          batch.forEach(function (slot) {
            if (!slot.parentNode) return;
            var id = slot.getAttribute('data-lazy-record') || '';
            pending[id] = 'done';
            if (!data || !data.success || !blocks[id]) {
              slot.innerHTML = '<p class="muted">' + (L.lazyError || 'Error') + '</p>';
              return;
            }
            applyBlock(slot, blocks[id]);
          });
          flush();
        })
        .catch(function () {
          inflight = false;
          batch.forEach(function (slot) {
            if (!slot.parentNode) return;
            var id = slot.getAttribute('data-lazy-record') || '';
            pending[id] = 'error';
            slot.innerHTML = '<p class="muted">' + (L.lazyError || 'Error') + '</p>';
          });
          flush();
        });
    }

    function enqueue(slot) {
      var id = slot.getAttribute('data-lazy-record') || '';
      if (!id || pending[id]) return;
      pending[id] = 'queued';
      queue.push(slot);
      clearTimeout(timer);
      timer = setTimeout(flush, 40);
    }

    if (!('IntersectionObserver' in window)) {
      slots.forEach(enqueue);
      flush();
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        io.unobserve(entry.target);
        enqueue(entry.target);
      });
    }, { rootMargin: '240px 0px', threshold: 0.01 });

    slots.forEach(function (slot) { io.observe(slot); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindSeek(document.getElementById('audio-transcript'));
    bindPlayerShells(document);
    document.querySelectorAll('.audio-record-card').forEach(function (card) {
      var kolonka = card.querySelector('.kolonka, .transcript');
      if (kolonka) bindCardTranscript(card, kolonka);
    });
    bindLazyTranscripts();
  });
})();
