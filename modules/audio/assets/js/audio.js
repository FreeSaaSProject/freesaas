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
      var line = ev.target.closest('.transcript-line[data-time-start]');
      if (!line || !root.contains(line)) return;

      // Shift+click: contiguous cue span → one voice sample (first→last, VAD gaps ok).
      if (ev.shiftKey && labels().voiceEnrollment) {
        ev.preventDefault();
        ev.stopPropagation();
        var lines = cueLines(root);
        var idx = lines.indexOf(line);
        if (idx < 0) return;
        var anchor = parseInt(root.getAttribute('data-voice-anchor') || '-1', 10);
        if (isNaN(anchor) || anchor < 0 || anchor >= lines.length) {
          anchor = idx;
        }
        root.setAttribute('data-voice-anchor', String(anchor));
        var span = rangeFromCueSpan(lines, anchor, idx);
        if (!span) return;
        markVoicePick(root, span.fromIdx, span.toIdx);
        // Shift+span: no word selection — use full cue text as a starting phrase.
        showAddPopover(root, span.cueText || '…', span);
        return;
      }

      root.setAttribute('data-voice-anchor', String(cueLines(root).indexOf(line)));
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

  function syncTranscriptHighlight(kolonka, ct) {
    if (!kolonka || typeof ct !== 'number' || isNaN(ct)) return;
    var filter = String(kolonka.getAttribute('data-speaker-filter') || '');
    var lines = kolonka.querySelectorAll('.transcript-line[data-time-start]');
    var best = null;
    var bestDur = Infinity;
    var fallback = null;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (filter && String(line.getAttribute('data-speaker') || '') !== filter) continue;
      if (filter && line.classList.contains('is-speaker-excluded')) continue;
      var start = parseFloat(line.getAttribute('data-time-start') || '');
      var end = parseFloat(line.getAttribute('data-time-end') || '');
      if (isNaN(start)) continue;
      if (isNaN(end) || end <= start) end = start + 1;
      if (ct >= start && ct < end) {
        var dur = end - start;
        // Prefer the tightest containing cue when asr_dia segments overlap.
        if (dur < bestDur || (dur === bestDur && start > parseFloat((best && best.getAttribute('data-time-start')) || '-1'))) {
          bestDur = dur;
          best = line;
        }
      } else if (ct >= start) {
        fallback = line;
      }
    }
    var active = best || fallback;
    var prev = kolonka.querySelector('.transcript-line.is-active, .transcript-line.transcript-line-active');
    if (prev === active) return;
    kolonka.querySelectorAll('.transcript-line.is-active, .transcript-line.transcript-line-active').forEach(function (el) {
      el.classList.remove('is-active', 'transcript-line-active');
    });
    if (active) {
      active.classList.add('is-active', 'transcript-line-active');
      try {
        if (typeof active.scrollIntoView === 'function') {
          active.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      } catch (e) {}
    }
  }

  /** Next filtered-cue start strictly after `afterSec`, or null if none. Skips excluded. */
  function nextSpeakerCueStart(kolonka, filter, afterSec) {
    if (!kolonka || !filter) return null;
    var best = null;
    var lines = kolonka.querySelectorAll('.transcript-line[data-time-start]');
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      if (String(line.getAttribute('data-speaker') || '') !== filter) continue;
      if (line.classList.contains('is-speaker-excluded')) continue;
      var start = parseFloat(line.getAttribute('data-time-start') || '');
      if (isNaN(start) || start <= afterSec + 0.05) continue;
      if (best === null || start < best) best = start;
    }
    return best;
  }

  /** True when currentTime sits inside a filtered (non-excluded) speaker cue. */
  function insideSpeakerCue(kolonka, filter, ct) {
    if (!kolonka || !filter) return true;
    var lines = kolonka.querySelectorAll('.transcript-line[data-speaker="' + String(filter).replace(/"/g, '') + '"]');
    for (var i = 0; i < lines.length; i++) {
      if (lines[i].classList.contains('is-speaker-excluded')) continue;
      var start = parseFloat(lines[i].getAttribute('data-time-start') || '');
      var end = parseFloat(lines[i].getAttribute('data-time-end') || '');
      if (isNaN(start)) continue;
      if (isNaN(end) || end <= start) end = start + 1;
      if (ct >= start - 0.05 && ct < end + 0.12) return true;
    }
    return false;
  }

  function clearSpeakerExcludes(kolonka) {
    if (!kolonka) return;
    kolonka.querySelectorAll('.transcript-line.is-speaker-excluded').forEach(function (el) {
      el.classList.remove('is-speaker-excluded');
    });
    kolonka.querySelectorAll('.transcript-line.is-speaker-adopted').forEach(function (el) {
      el.classList.remove('is-speaker-adopted', 'is-speaker-focus');
    });
    kolonka.querySelectorAll('.audio-speaker-cue-exclude, .audio-speaker-cue-adopt').forEach(function (btn) {
      btn.remove();
    });
  }

  function excludedSpeakerRanges(kolonka) {
    var out = [];
    if (!kolonka) return out;
    kolonka.querySelectorAll('.transcript-line.is-speaker-excluded[data-time-start]').forEach(function (line) {
      var t0 = parseFloat(line.getAttribute('data-time-start') || '');
      var t1 = parseFloat(line.getAttribute('data-time-end') || '');
      if (isNaN(t0)) return;
      if (isNaN(t1) || t1 <= t0) t1 = t0 + 1;
      out.push({ t0: t0, t1: t1 });
    });
    return out;
  }

  function includedSpeakerRanges(kolonka) {
    var out = [];
    if (!kolonka) return out;
    kolonka.querySelectorAll('.transcript-line.is-speaker-adopted[data-time-start]').forEach(function (line) {
      var t0 = parseFloat(line.getAttribute('data-time-start') || '');
      var t1 = parseFloat(line.getAttribute('data-time-end') || '');
      if (isNaN(t0)) return;
      if (isNaN(t1) || t1 <= t0) t1 = t0 + 1;
      var row = { t0: t0, t1: t1 };
      var lineNum = parseInt(line.getAttribute('data-line') || '0', 10) || 0;
      if (lineNum > 0) row.line = lineNum;
      out.push(row);
    });
    return out;
  }

  function syncExcludeStatus(kolonka) {
    var status = document.getElementById('audio-speaker-filter-status');
    if (!status || !kolonka) return;
    var filter = String(kolonka.getAttribute('data-speaker-filter') || '');
    if (!filter) return;
    var n = kolonka.querySelectorAll('.transcript-line.is-speaker-excluded').length;
    var a = kolonka.querySelectorAll('.transcript-line.is-speaker-adopted').length;
    var L = labels();
    var clear = L.speakerAssignClear || 'Clear';
    var parts = [clear + ': ' + filter];
    if (n > 0) {
      parts.push((L.speakerAssignExcludedN || 'Excluded: {n}').replace('{n}', String(n)));
    }
    if (a > 0) {
      parts.push((L.speakerAssignAdoptedN || 'Added: {n}').replace('{n}', String(a)));
    }
    status.hidden = false;
    status.textContent = parts.join(' · ');
  }

  function mountExcludeButtons(kolonka, filter) {
    if (!kolonka || !filter) return;
    var L = labels();
    var labelOff = L.speakerAssignExclude || 'Off';
    var labelOn = L.speakerAssignInclude || 'On';
    kolonka.querySelectorAll('.transcript-line.is-speaker-focus').forEach(function (line) {
      if (line.classList.contains('is-speaker-adopted')) return;
      if (line.querySelector('.audio-speaker-cue-exclude')) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'audio-speaker-cue-exclude';
      btn.textContent = line.classList.contains('is-speaker-excluded') ? labelOn : labelOff;
      btn.title = labelOff;
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var on = line.classList.toggle('is-speaker-excluded');
        btn.textContent = on ? labelOn : labelOff;
        syncExcludeStatus(kolonka);
        // If we just excluded the playing cue, jump to next kept one.
        var audio = document.getElementById('audio-player');
        if (on && audio && !audio.paused) {
          var ct = audio.currentTime;
          var start = parseFloat(line.getAttribute('data-time-start') || '');
          var end = parseFloat(line.getAttribute('data-time-end') || '');
          if (!isNaN(start) && !isNaN(end) && ct >= start - 0.05 && ct < end + 0.2) {
            var next = nextSpeakerCueStart(kolonka, filter, ct);
            if (next !== null) {
              try { audio.currentTime = next; } catch (e) {}
            } else {
              try { audio.pause(); } catch (e2) {}
            }
          }
        }
      });
      var timeEl = line.querySelector('.transcript-time');
      if (timeEl && timeEl.nextSibling) {
        line.insertBefore(btn, timeEl.nextSibling);
      } else {
        line.appendChild(btn);
      }
    });
  }

  /** Dimmed other-speaker cues → adopt into the filtered cluster for samples/rebind. */
  function mountAdoptButtons(kolonka, filter) {
    if (!kolonka || !filter) return;
    var L = labels();
    var labelAdd = L.speakerAssignAdopt || 'To me';
    var labelUndo = L.speakerAssignAdoptUndo || 'Undo';
    kolonka.querySelectorAll('.transcript-line[data-time-start]').forEach(function (line) {
      var spk = String(line.getAttribute('data-speaker') || '');
      if (spk === filter && !line.classList.contains('is-speaker-adopted')) return;
      if (line.querySelector('.audio-speaker-cue-adopt')) return;
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'audio-speaker-cue-adopt';
      btn.textContent = line.classList.contains('is-speaker-adopted') ? labelUndo : labelAdd;
      btn.title = labelAdd;
      btn.addEventListener('click', function (ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var on = !line.classList.contains('is-speaker-adopted');
        if (on) {
          line.classList.add('is-speaker-adopted', 'is-speaker-focus');
          line.classList.remove('is-speaker-excluded');
          line.setAttribute('data-speaker-adopt-from', spk);
          line.setAttribute('data-speaker', filter);
          btn.textContent = labelUndo;
        } else {
          var from = line.getAttribute('data-speaker-adopt-from') || '';
          line.classList.remove('is-speaker-adopted', 'is-speaker-focus');
          if (from) line.setAttribute('data-speaker', from);
          line.removeAttribute('data-speaker-adopt-from');
          btn.textContent = labelAdd;
        }
        syncExcludeStatus(kolonka);
      });
      var timeEl = line.querySelector('.transcript-time');
      if (timeEl && timeEl.nextSibling) {
        line.insertBefore(btn, timeEl.nextSibling);
      } else {
        line.appendChild(btn);
      }
    });
  }

  function syncAssignButtons(/* activeSpeakerId */) {
    // Bind buttons stay visible; chip.is-active is enough visual cue.
  }

  function bindAudioTranscript(audio, kolonka, bindRoot) {
    var root = bindRoot || audio || kolonka;
    if (!audio || !kolonka || !root) return;
    if (root.getAttribute('data-time-bound') === '1') return;
    root.setAttribute('data-time-bound', '1');
    var seekingFilter = false;
    var tick = function () {
      var filter = String(kolonka.getAttribute('data-speaker-filter') || '');
      var ct = audio.currentTime;
      if (filter && !seekingFilter && !audio.paused) {
        if (!insideSpeakerCue(kolonka, filter, ct)) {
          var next = nextSpeakerCueStart(kolonka, filter, ct);
          if (next !== null && next > ct + 0.08) {
            seekingFilter = true;
            try {
              audio.currentTime = next;
            } catch (e) {
              seekingFilter = false;
            }
            return;
          }
          if (next === null) {
            try { audio.pause(); } catch (e2) {}
          }
        }
      }
      syncTranscriptHighlight(kolonka, audio.currentTime);
    };
    audio.addEventListener('timeupdate', tick);
    audio.addEventListener('seeked', function () {
      seekingFilter = false;
      tick();
    });
    audio.addEventListener('play', tick);
  }

  function bindTimeHighlight(card) {
    if (!card) return;
    var kolonka = card.querySelector('.kolonka, .transcript');
    var audio = card.querySelector('audio');
    bindAudioTranscript(audio, kolonka, card);
  }

  /** Standalone /audio/{id}: #audio-player + #audio-transcript (not a list card). */
  function bindViewPagePlayback() {
    var audio = document.getElementById('audio-player');
    var kolonka = document.getElementById('audio-transcript');
    if (!audio || !kolonka) return;
    bindAudioTranscript(audio, kolonka, kolonka);
  }

  function setSpeakerFilter(kolonka, speakerId, play) {
    if (!kolonka) return;
    var sid = String(speakerId || '');
    var prev = String(kolonka.getAttribute('data-speaker-filter') || '');
    if (sid && prev === sid) {
      sid = '';
    }
    clearSpeakerExcludes(kolonka);
    kolonka.querySelectorAll('.transcript-line.is-speaker-focus').forEach(function (el) {
      el.classList.remove('is-speaker-focus');
    });
    document.querySelectorAll('.audio-speaker-filter[aria-pressed="true"]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', 'false');
      var chip = btn.closest('.audio-speaker-chip');
      if (chip) chip.classList.remove('is-active');
    });
    var status = document.getElementById('audio-speaker-filter-status');
    if (!sid) {
      kolonka.removeAttribute('data-speaker-filter');
      kolonka.classList.remove('is-speaker-filtered');
      syncAssignButtons('');
      if (status) {
        status.hidden = true;
        status.textContent = '';
        status.onclick = null;
      }
      return;
    }
    kolonka.setAttribute('data-speaker-filter', sid);
    kolonka.classList.add('is-speaker-filtered');
    var first = null;
    kolonka.querySelectorAll('.transcript-line[data-speaker="' + sid.replace(/"/g, '') + '"]').forEach(function (line) {
      line.classList.add('is-speaker-focus');
      if (!first) first = line;
    });
    var btn = document.querySelector('.audio-speaker-filter[data-speaker-filter="' + sid.replace(/"/g, '') + '"]');
    if (btn) {
      btn.setAttribute('aria-pressed', 'true');
      var chip = btn.closest('.audio-speaker-chip');
      if (chip) chip.classList.add('is-active');
    }
    syncAssignButtons(sid);
    mountExcludeButtons(kolonka, sid);
    mountAdoptButtons(kolonka, sid);
    if (status) {
      status.style.cursor = 'pointer';
      status.onclick = function () { setSpeakerFilter(kolonka, '', false); };
      syncExcludeStatus(kolonka);
    }
    if (play && first) {
      var start = parseFloat(first.getAttribute('data-time-start') || '');
      if (!isNaN(start)) {
        var player = document.getElementById('audio-player');
        if (player) {
          try {
            player.currentTime = start;
            player.play();
          } catch (e) {}
        }
        first.classList.add('is-active', 'transcript-line-active');
        try {
          first.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e2) {}
      }
    }
  }

  function bindSpeakerUi() {
    var list = document.getElementById('audio-speakers-list');
    var kolonka = document.getElementById('audio-transcript');
    if (!list || !kolonka) return;
    if (list.getAttribute('data-speaker-ui') === '1') return;
    list.setAttribute('data-speaker-ui', '1');

    var grid = document.querySelector('.audio-view-grid');
    var recordId = grid
      ? parseInt(grid.getAttribute('data-record-id') || '0', 10)
      : parseInt(kolonka.getAttribute('data-record') || '0', 10);
    var bar = document.getElementById('audio-speaker-assign-bar');
    var adminSel = document.getElementById('audio-speaker-assign-admin');
    var confirmBtn = document.getElementById('audio-speaker-assign-confirm');
    var cancelBtn = document.getElementById('audio-speaker-assign-cancel');
    var statusEl = document.getElementById('audio-speaker-assign-status');
    var pendingLocal = '';

    // Ensure bar stays closed until «Привязать».
    if (bar) bar.hidden = true;
    syncAssignButtons('');

    function hideAssignBar() {
      pendingLocal = '';
      if (bar) bar.hidden = true;
      if (statusEl) statusEl.textContent = '';
    }

    function matchedAdminForLocal(localId) {
      var chip = list.querySelector('.audio-speaker-chip[data-speaker-id="' + String(localId).replace(/"/g, '') + '"]');
      if (!chip) return 0;
      return parseInt(chip.getAttribute('data-matched-admin') || '0', 10) || 0;
    }

    function showAssignBar(localId) {
      if (!bar || !adminSel || recordId < 1) return;
      pendingLocal = String(localId || '');
      if (statusEl) statusEl.textContent = '';
      adminSel.innerHTML = '';
      bar.hidden = false;
      var L = labels();
      var preferAdmin = matchedAdminForLocal(pendingLocal);
      fetch('/audio/api/voice-sample?staff_list=1&record_id=' + encodeURIComponent(String(recordId)), {
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!bar || bar.hidden || pendingLocal !== localId) return;
          var staff = (data && data.staff) || [];
          adminSel.innerHTML = '';
          if (!staff.length) {
            if (statusEl) statusEl.textContent = L.voiceNoStaff || '';
            return;
          }
          staff.forEach(function (s) {
            var o = document.createElement('option');
            var id = parseInt(s.id, 10) || 0;
            o.value = String(id);
            var acr = s.acronym ? String(s.acronym) : '';
            o.textContent = (acr ? acr + ' — ' : '') + (s.name || ('#' + id));
            adminSel.appendChild(o);
          });
          var def = null;
          if (preferAdmin > 0) {
            def = staff.filter(function (s) { return parseInt(s.id, 10) === preferAdmin; })[0] || null;
          }
          if (!def) def = staff.filter(function (s) { return s.default; })[0] || staff[0];
          if (def) adminSel.value = String(def.id || 0);
        })
        .catch(function () {
          if (statusEl) statusEl.textContent = L.network || 'Network error';
        });
    }

    list.addEventListener('click', function (ev) {
      var assignBtn = ev.target.closest('[data-speaker-assign]');
      if (assignBtn && list.contains(assignBtn)) {
        ev.preventDefault();
        ev.stopPropagation();
        var lid = assignBtn.getAttribute('data-speaker-assign') || '';
        // Form only after filter is active on this unmatched speaker.
        if (String(kolonka.getAttribute('data-speaker-filter') || '') !== lid) {
          setSpeakerFilter(kolonka, lid, true);
        }
        showAssignBar(lid);
        return;
      }
      var filterBtn = ev.target.closest('[data-speaker-filter]');
      if (filterBtn && list.contains(filterBtn)) {
        ev.preventDefault();
        hideAssignBar();
        setSpeakerFilter(kolonka, filterBtn.getAttribute('data-speaker-filter') || '', true);
      }
    });

    if (cancelBtn) {
      cancelBtn.addEventListener('click', function () {
        hideAssignBar();
      });
    }
    if (confirmBtn) {
      confirmBtn.addEventListener('click', function () {
        if (!pendingLocal || recordId < 1 || !adminSel) return;
        var aid = parseInt(adminSel.value || '0', 10) || 0;
        if (aid < 1) return;
        var L = labels();
        confirmBtn.disabled = true;
        if (statusEl) statusEl.textContent = L.speakerAssignWorking || '…';
        var body = new URLSearchParams();
        body.set('assign_speaker', '1');
        body.set('record_id', String(recordId));
        body.set('admin_id', String(aid));
        body.set('local_id', pendingLocal);
        var excl = excludedSpeakerRanges(kolonka);
        if (excl.length) {
          body.set('exclude_json', JSON.stringify(excl));
        }
        var incl = includedSpeakerRanges(kolonka);
        if (incl.length) {
          body.set('include_json', JSON.stringify(incl));
        }
        fetch('/audio/api/voice-sample', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body,
          credentials: 'same-origin',
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            confirmBtn.disabled = false;
            if (!data || !data.success) {
              if (statusEl) statusEl.textContent = (data && data.error) || (L.error || 'Error');
              return;
            }
            if (statusEl) statusEl.textContent = data.message || (L.done || 'OK');
            window.setTimeout(function () {
              window.location.reload();
            }, 600);
          })
          .catch(function () {
            confirmBtn.disabled = false;
            if (statusEl) statusEl.textContent = L.network || 'Network error';
          });
      });
    }
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

  function formatCueTime(sec) {
    var n = Math.max(0, Math.floor(Number(sec) || 0));
    var m = Math.floor(n / 60);
    var s = n % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function cueLines(kolonkaEl) {
    if (!kolonkaEl) return [];
    return Array.prototype.slice.call(
      kolonkaEl.querySelectorAll('.transcript-line[data-time-start]')
    );
  }

  function lineTimeBounds(line) {
    if (!line) return null;
    var a = parseFloat(line.getAttribute('data-time-start') || '');
    var b = parseFloat(line.getAttribute('data-time-end') || '');
    if (isNaN(a)) return null;
    if (isNaN(b) || b <= a) b = a + 1;
    return { t0: a, t1: b };
  }

  /** Majority data-speaker-admin on cue lines (0 if none / mixed). */
  function speakerAdminFromLines(lineEls) {
    var votes = {};
    var bestId = 0;
    var bestN = 0;
    (lineEls || []).forEach(function (line) {
      var aid = parseInt(line.getAttribute('data-speaker-admin') || '0', 10) || 0;
      if (aid < 1) return;
      votes[aid] = (votes[aid] || 0) + 1;
      if (votes[aid] > bestN) {
        bestN = votes[aid];
        bestId = aid;
      }
    });
    return bestId;
  }

  /** Full cue-slot times from first→last line (VAD gaps included). Optional cueText = whole lines, not the user selection. */
  function rangeFromCueSpan(lines, fromIdx, toIdx) {
    if (!lines || lines.length < 1) return null;
    var a = Math.min(fromIdx, toIdx);
    var b = Math.max(fromIdx, toIdx);
    if (a < 0 || b >= lines.length) return null;
    var first = lineTimeBounds(lines[a]);
    var last = lineTimeBounds(lines[b]);
    if (!first || !last || last.t1 <= first.t0) return null;
    var texts = [];
    var slice = lines.slice(a, b + 1);
    for (var i = a; i <= b; i++) {
      var t = String((lines[i].querySelector('.transcript-text') || lines[i]).textContent || '')
        .replace(/\s+/g, ' ')
        .trim();
      if (t) texts.push(t);
    }
    return {
      t0: first.t0,
      t1: last.t1,
      fromIdx: a,
      toIdx: b,
      cueText: texts.join(' '),
      lines: slice,
      speakerAdmin: speakerAdminFromLines(slice),
    };
  }

  function markVoicePick(kolonkaEl, fromIdx, toIdx) {
    if (!kolonkaEl) return;
    var lines = cueLines(kolonkaEl);
    var a = Math.min(fromIdx, toIdx);
    var b = Math.max(fromIdx, toIdx);
    lines.forEach(function (line, i) {
      if (i >= a && i <= b) line.classList.add('is-voice-pick');
      else line.classList.remove('is-voice-pick');
    });
  }

  function clearVoicePick(scope) {
    (scope || document).querySelectorAll('.transcript-line.is-voice-pick').forEach(function (el) {
      el.classList.remove('is-voice-pick');
    });
  }

  function selectionTimeRange(kolonkaEl, range) {
    var sel = window.getSelection && window.getSelection();
    if (!range) {
      if (!sel || sel.rangeCount < 1 || !kolonkaEl) return null;
      range = sel.getRangeAt(0);
    }
    if (!range || !kolonkaEl || range.collapsed) return null;

    function lineOf(node) {
      if (!node) return null;
      var el = node.nodeType === 1 ? node : node.parentElement;
      if (!el || !el.closest) return null;
      return el.closest('.transcript-line[data-time-start]');
    }

    var lines = cueLines(kolonkaEl);
    var hitIdx = [];
    lines.forEach(function (line, i) {
      var hit = false;
      try {
        hit = range.intersectsNode(line);
      } catch (e) {
        hit = false;
      }
      if (!hit && (line.contains(range.startContainer) || line.contains(range.endContainer))) {
        hit = true;
      }
      if (hit) hitIdx.push(i);
    });
    if (hitIdx.length < 1) {
      var s = lines.indexOf(lineOf(range.startContainer));
      var e = lines.indexOf(lineOf(range.endContainer));
      if (s >= 0) hitIdx.push(s);
      if (e >= 0 && e !== s) hitIdx.push(e);
    }
    if (hitIdx.length < 1) return null;
    // Contiguous span: first hit → last hit in DOM order (covers VAD-split speech).
    return rangeFromCueSpan(lines, hitIdx[0], hitIdx[hitIdx.length - 1]);
  }

  var popoverCloseTimer = null;

  function clearAddPopovers(scope) {
    if (popoverCloseTimer) {
      clearTimeout(popoverCloseTimer);
      popoverCloseTimer = null;
    }
    // Popovers live on document.body (fixed) — always clear globally.
    document.querySelectorAll('.add-key-popover').forEach(function (el) {
      el.remove();
    });
    clearVoicePick(scope);
  }

  /**
   * Fixed to viewport near the cue — escapes CSS columns + table overflow,
   * flips above when near the bottom of the page.
   */
  function placeAddPopover(pop, kolonkaEl, anchorLine) {
    if (!pop) return;
    document.body.appendChild(pop);
    var aRect = (anchorLine || kolonkaEl || document.body).getBoundingClientRect();
    var margin = 8;
    pop.style.top = Math.max(margin, aRect.bottom + 4) + 'px';
    pop.style.left = Math.max(margin, aRect.left) + 'px';
    requestAnimationFrame(function () {
      if (!pop.parentNode) return;
      var pr = pop.getBoundingClientRect();
      var top = aRect.bottom + 4;
      if (top + pr.height > window.innerHeight - margin) {
        top = aRect.top - pr.height - 4;
      }
      if (top < margin) {
        top = margin;
      }
      var left = Math.max(
        margin,
        Math.min(aRect.left, window.innerWidth - pr.width - margin)
      );
      pop.style.top = top + 'px';
      pop.style.left = left + 'px';
      var maxH = window.innerHeight - 2 * margin;
      if (pr.height > maxH) {
        pop.style.maxHeight = maxH + 'px';
        pop.style.overflow = 'auto';
      }
    });
  }

  function showAddPopover(kolonkaEl, text, timeRange) {
    text = String(text || '').trim();
    if (!text || !kolonkaEl) return;
    var resolved = contextForKolonka(kolonkaEl);
    var dicts = resolved.dicts;
    var adminId = resolved.adminId || 0;
    var recordId = resolved.recordId || 0;
    var hasDicts = dicts && Object.keys(dicts).length > 0;
    // Voice sample: module audio_diarize + record; staff picker can override badge wearer.
    var L0 = labels();
    var canVoice = !!L0.voiceEnrollment && recordId > 0;
    if (!hasDicts && !canVoice) return;

    if (!timeRange) timeRange = selectionTimeRange(kolonkaEl);

    clearAddPopovers(kolonkaEl);

    var pop = document.createElement('div');
    pop.className = 'add-key-popover';
    var html = '';
    if (hasDicts) {
      html +=
        '<div class="add-key-row"><span class="add-key-lbl"></span><input type="text" class="add-key-input" /></div>' +
        '<div class="add-key-row"><span class="add-key-lbl" data-lbl="dict"></span><select class="add-key-dict"></select></div>' +
        '<div class="add-key-row"><span class="add-key-lbl" data-lbl="replace"></span>' +
        '<input type="text" class="add-key-replace" autocomplete="off" /></div>';
    }
    html +=
      '<div class="add-key-row add-key-actions">' +
      (hasDicts
        ? '<button type="button" class="add-key-btn" data-act="key"></button>' +
          '<button type="button" class="add-key-btn add-key-btn--soft" data-act="key-noreparse"></button>' +
          '<button type="button" class="add-key-btn-trash" data-act="trash"></button>'
        : '') +
      (canVoice ? '<button type="button" class="add-key-btn" data-act="voice"></button>' : '') +
      (canVoice && timeRange ? '<span class="add-key-voice-range muted" data-voice-range></span>' : '') +
      '<button type="button" class="add-key-cancel"></button>' +
      '<span class="add-key-status"></span></div>' +
      (canVoice
        ? '<div class="add-key-row add-key-voice-staff" data-voice-staff-row hidden>' +
          '<span class="add-key-lbl" data-lbl="voice-staff"></span>' +
          '<select class="add-key-voice-admin" data-voice-admin></select></div>' +
          '<div class="add-key-voice-meta muted small" data-voice-meta></div>'
        : '') +
      '<div class="add-key-warnings" hidden></div>';
    pop.innerHTML = html;

    // Clicks inside the popover must not bubble to transcript mouseup/seek.
    pop.addEventListener('mouseup', function (ev) { ev.stopPropagation(); });
    pop.addEventListener('mousedown', function (ev) { ev.stopPropagation(); });
    pop.addEventListener('click', function (ev) { ev.stopPropagation(); });

    var L = labels();
    if (hasDicts) {
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
      pop.querySelector('.add-key-input').value = text;
      var replLbl = pop.querySelector('[data-lbl="replace"]');
      if (replLbl) replLbl.textContent = L.replaceLabel || 'Replace with';
      var replInput = pop.querySelector('.add-key-replace');
      if (replInput) replInput.placeholder = L.replaceHint || '';
    }
    var voiceBtn = pop.querySelector('[data-act="voice"]');
    var rangeEl = pop.querySelector('[data-voice-range]');
    var metaEl = pop.querySelector('[data-voice-meta]');
    var voiceAdminSel = pop.querySelector('[data-voice-admin]');
    var voiceStaffRow = pop.querySelector('[data-voice-staff-row]');
    var voiceStaffLbl = pop.querySelector('[data-lbl="voice-staff"]');
    if (voiceStaffLbl) voiceStaffLbl.textContent = L.voiceStaff || 'Staff';
    var voiceMin = parseFloat(L.voiceMinSec != null ? L.voiceMinSec : 3);
    var voiceMax = parseFloat(L.voiceMaxSec != null ? L.voiceMaxSec : 120);
    if (isNaN(voiceMin) || voiceMin < 1) voiceMin = 3;
    if (isNaN(voiceMax) || voiceMax < voiceMin) voiceMax = 120;
    var rangeLabel = '';
    var voiceDur = 0;
    var voiceAllowed = false;
    var selectedVoiceAdmin = 0;
    function currentVoiceAdmin() {
      if (voiceAdminSel && voiceAdminSel.value) {
        return parseInt(voiceAdminSel.value, 10) || 0;
      }
      return selectedVoiceAdmin || 0;
    }
    function setVoiceStaffVisible(on) {
      if (voiceStaffRow) {
        if (on) voiceStaffRow.removeAttribute('hidden');
        else voiceStaffRow.setAttribute('hidden', '');
      }
    }
    if (timeRange) {
      rangeLabel = formatCueTime(timeRange.t0) + '–' + formatCueTime(timeRange.t1);
      voiceDur = Math.max(0, timeRange.t1 - timeRange.t0);
    }
    function setVoiceMeta(msg) {
      if (metaEl) metaEl.textContent = msg || '';
    }
    function setVoiceEnabled(on, reason) {
      voiceAllowed = !!on;
      if (!voiceBtn) return;
      voiceBtn.disabled = !on;
      voiceBtn.title = reason || '';
    }
    if (voiceBtn) {
      voiceBtn.textContent = L.voiceSample || 'Voice sample';
      if (!timeRange) {
        setVoiceEnabled(false, L.voiceNeedTimes || '');
        setVoiceMeta(L.voiceNeedTimes || '');
      } else if (voiceDur < voiceMin) {
        setVoiceEnabled(false, (L.voiceDurShort || '')
          .replace('{sec}', voiceDur.toFixed(1))
          .replace('{min}', String(voiceMin)));
        setVoiceMeta(voiceBtn.title);
      } else if (voiceDur > voiceMax) {
        setVoiceEnabled(false, (L.voiceDurLong || '')
          .replace('{sec}', voiceDur.toFixed(1))
          .replace('{max}', String(voiceMax)));
        setVoiceMeta(voiceBtn.title);
      } else {
        // Duration OK — disable until duplicate check returns.
        setVoiceEnabled(false, L.voiceChecking || '…');
        setVoiceMeta((L.voiceChecking || '…') + ' ' + voiceDur.toFixed(1) + 's');
      }
    }
    if (rangeEl && rangeLabel) {
      var cueCount =
        timeRange && timeRange.fromIdx != null && timeRange.toIdx != null
          ? timeRange.toIdx - timeRange.fromIdx + 1
          : 1;
      rangeEl.textContent = (L.voiceRange || '{t0}–{t1}')
        .replace('{t0}', formatCueTime(timeRange.t0))
        .replace('{t1}', formatCueTime(timeRange.t1));
      rangeEl.title =
        rangeLabel +
        ' (' +
        voiceDur.toFixed(1) +
        's' +
        (cueCount > 1 ? ', ×' + cueCount : '') +
        ')' +
        (cueCount > 1 && L.voiceHintSpan ? ' — ' + L.voiceHintSpan : '');
    }
    pop.querySelector('.add-key-cancel').textContent = L.cancel || 'Cancel';
    var dictSel = pop.querySelector('.add-key-dict');
    if (dictSel && hasDicts) {
      Object.keys(dicts).forEach(function (id) {
        var o = document.createElement('option');
        o.value = id;
        o.textContent = dicts[id];
        dictSel.appendChild(o);
      });
    }

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

    function showReplaceSuggestion(warn, keyText, keyId, sug, reparsed) {
      warn.hidden = false;
      warn.innerHTML = '';
      var title = document.createElement('div');
      title.className = 'add-key-overlap-title';
      title.textContent = L.replaceSuggestTitle || 'Suggested replacement';
      warn.appendChild(title);
      var line = document.createElement('p');
      line.textContent = '«' + String(sug.name) + '»';
      warn.appendChild(line);
      var actions = document.createElement('div');
      actions.className = 'add-key-overlap-actions';
      var acceptBtn = document.createElement('button');
      acceptBtn.type = 'button';
      acceptBtn.className = 'add-key-btn';
      acceptBtn.textContent = L.replaceAccept || 'Accept';
      acceptBtn.addEventListener('click', function () {
        postSetReplace(keyText, keyId, String(sug.name));
      });
      var skipBtn = document.createElement('button');
      skipBtn.type = 'button';
      skipBtn.className = 'add-key-cancel';
      skipBtn.textContent = L.replaceSkip || 'Skip';
      skipBtn.addEventListener('click', function () {
        if (reparsed) {
          location.reload();
        } else {
          pop.remove();
        }
      });
      actions.appendChild(acceptBtn);
      actions.appendChild(skipBtn);
      warn.appendChild(actions);
    }

    function postSetReplace(keyText, keyId, replaceName) {
      var status = pop.querySelector('.add-key-status');
      var warn = pop.querySelector('.add-key-warnings');
      var body = new URLSearchParams();
      body.set('action', 'set_replace');
      body.set('text', keyText);
      body.set('key_id', String(keyId));
      body.set('replace_text', replaceName);
      fetch('/audio/api/dict', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body,
        credentials: 'same-origin',
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.success) {
            warn.hidden = false;
            warn.textContent = (data && data.error) || (L.error || 'Error');
            return;
          }
          status.textContent = data.message || (L.done || 'OK');
          setTimeout(function () { location.reload(); }, 500);
        })
        .catch(function () {
          warn.hidden = false;
          warn.textContent = L.network || 'Network error';
        });
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
        var replaceInputEl = pop.querySelector('.add-key-replace');
        var replaceVal = replaceInputEl ? String(replaceInputEl.value || '').trim() : '';
        if (replaceVal !== '') {
          body.set('replace_text', replaceVal);
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
          // Fast hint from the same dictionary: staff accepts or skips; nothing is applied silently.
          if (action === 'add_key' && data.replace && data.replace.suggest && data.replace.suggest.name) {
            showReplaceSuggestion(warn, keyText, data.keyId, data.replace.suggest, !!data.reparsed);
            return;
          }
          // Without Re-®: keep the list open so several keys can be added, then bulk Re-®.
          if (data.reparse_skipped) {
            if (popoverCloseTimer) clearTimeout(popoverCloseTimer);
            popoverCloseTimer = setTimeout(function () {
              popoverCloseTimer = null;
              clearAddPopovers(kolonkaEl);
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

    var keyBtn = pop.querySelector('[data-act="key"]');
    if (keyBtn) {
      keyBtn.addEventListener('click', function () { postAction('add_key', false, false); });
    }
    var noReBtn2 = pop.querySelector('[data-act="key-noreparse"]');
    if (noReBtn2) {
      noReBtn2.addEventListener('click', function () { postAction('add_key', false, true); });
    }
    var trashBtn = pop.querySelector('[data-act="trash"]');
    if (trashBtn) {
      trashBtn.addEventListener('click', function () { postAction('add_trash'); });
    }
    if (voiceBtn && timeRange && voiceDur >= voiceMin && voiceDur <= voiceMax) {
      function runVoiceCheck() {
        var aid = currentVoiceAdmin();
        if (aid < 1) {
          setVoiceEnabled(false, L.voiceNoStaff || L.voiceStaff || '');
          setVoiceMeta(L.voiceNoStaff || L.voiceStaff || '');
          return;
        }
        setVoiceEnabled(false, L.voiceChecking || '…');
        setVoiceMeta((L.voiceChecking || '…') + ' ' + voiceDur.toFixed(1) + 's');
        var checkBody = new URLSearchParams();
        checkBody.set('check', '1');
        checkBody.set('record_id', String(recordId));
        checkBody.set('admin_id', String(aid));
        checkBody.set('t0', String(timeRange.t0));
        checkBody.set('t1', String(timeRange.t1));
        fetch('/audio/api/voice-sample', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: checkBody,
          credentials: 'same-origin',
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!pop.parentNode) return;
            if (data && data.success) {
              var okMsg = (L.voiceDurOk || '{sec}s')
                .replace('{sec}', voiceDur.toFixed(1));
              setVoiceEnabled(true, okMsg);
              setVoiceMeta(okMsg);
              return;
            }
            var reason = (data && data.error) || (L.voiceDuplicate || L.error || 'Error');
            setVoiceEnabled(false, reason);
            setVoiceMeta(reason);
          })
          .catch(function () {
            if (!pop.parentNode) return;
            setVoiceEnabled(false, L.network || 'Network error');
            setVoiceMeta(L.network || 'Network error');
          });
      }

      if (voiceAdminSel) {
        setVoiceStaffVisible(false);
        fetch('/audio/api/voice-sample?staff_list=1&record_id=' + encodeURIComponent(String(recordId)), {
          credentials: 'same-origin',
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!pop.parentNode || !voiceAdminSel) return;
            var staff = (data && data.staff) || [];
            voiceAdminSel.innerHTML = '';
            if (!staff.length) {
              selectedVoiceAdmin = 0;
              setVoiceStaffVisible(false);
              setVoiceEnabled(false, L.voiceNoStaff || L.voiceStaff || '');
              setVoiceMeta(L.voiceNoStaff || L.voiceStaff || '');
              return;
            }
            // Prefer matched speaker of the selection over badge wearer (avoids writing samples to wrong staff).
            var preferId = (timeRange && timeRange.speakerAdmin) ? (parseInt(timeRange.speakerAdmin, 10) || 0) : 0;
            var preferOk = false;
            staff.forEach(function (s) {
              var o = document.createElement('option');
              var id = parseInt(s.id, 10) || 0;
              o.value = String(id);
              var acr = s.acronym ? String(s.acronym) : '';
              o.textContent = (acr ? acr + ' — ' : '') + (s.name || ('#' + id));
              voiceAdminSel.appendChild(o);
              if (preferId > 0 && id === preferId) preferOk = true;
            });
            if (preferOk) {
              voiceAdminSel.value = String(preferId);
            } else {
              var def = staff.filter(function (s) { return s.default; })[0] || staff[0];
              if (def) voiceAdminSel.value = String(def.id || 0);
            }
            selectedVoiceAdmin = currentVoiceAdmin();
            setVoiceStaffVisible(true);
            runVoiceCheck();
          })
          .catch(function () {
            if (!pop.parentNode) return;
            selectedVoiceAdmin = 0;
            setVoiceStaffVisible(false);
            setVoiceEnabled(false, L.network || 'Network error');
            setVoiceMeta(L.network || 'Network error');
          });
        voiceAdminSel.addEventListener('change', function () {
          selectedVoiceAdmin = currentVoiceAdmin();
          runVoiceCheck();
        });
      } else {
        runVoiceCheck();
      }

      voiceBtn.addEventListener('click', function (ev) {
        if (ev) {
          ev.preventDefault();
          ev.stopPropagation();
        }
        if (!voiceAllowed || voiceBtn.disabled) return;
        var aid = currentVoiceAdmin();
        if (aid < 1) return;
        var status = pop.querySelector('.add-key-status');
        var warn = pop.querySelector('.add-key-warnings');
        status.textContent = '…';
        warn.hidden = true;
        setVoiceEnabled(false, '');
        var body = new URLSearchParams();
        body.set('record_id', String(recordId));
        body.set('admin_id', String(aid));
        body.set('t0', String(timeRange.t0));
        body.set('t1', String(timeRange.t1));
        fetch('/audio/api/voice-sample', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body,
          credentials: 'same-origin',
        })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.success) {
              warn.hidden = false;
              warn.textContent = (data && data.error) || (L.error || 'Error');
              status.textContent = '';
              setVoiceEnabled(false, warn.textContent);
              setVoiceMeta(warn.textContent);
              return;
            }
            status.textContent = data.message || (L.done || 'OK');
            if (metaEl && data.manual_count != null) {
              var rec = data.recommended || 8;
              var n = data.manual_count;
              if (n > rec) {
                metaEl.textContent = (L.voiceProgressExtra || L.voiceProgress || '')
                  .replace('{n}', String(n))
                  .replace('{rec}', String(rec))
                  .replace('{remain}', '0');
              } else {
                metaEl.textContent = (L.voiceProgress || '')
                  .replace('{n}', String(n))
                  .replace('{rec}', String(rec))
                  .replace('{remain}', String(data.remaining != null ? data.remaining : ''));
              }
            }
            if (popoverCloseTimer) clearTimeout(popoverCloseTimer);
            popoverCloseTimer = setTimeout(function () {
              popoverCloseTimer = null;
              clearAddPopovers(kolonkaEl);
            }, 700);
          })
          .catch(function () {
            warn.hidden = false;
            warn.textContent = L.network || 'Network error';
            status.textContent = '';
            setVoiceEnabled(false, warn.textContent);
          });
      });
    }
    pop.querySelector('.add-key-cancel').addEventListener('click', function (ev) {
      if (ev) {
        ev.preventDefault();
        ev.stopPropagation();
      }
      if (window.getSelection) window.getSelection().removeAllRanges();
      clearAddPopovers(kolonkaEl);
    });

    // Place near the last selected cue; absolute under .kolonka (not between column lines).
    var anchorLine = null;
    if (timeRange) {
      var cand = kolonkaEl.querySelectorAll('.transcript-line[data-time-start]');
      cand.forEach(function (line) {
        var a = parseFloat(line.getAttribute('data-time-start') || '');
        var b = parseFloat(line.getAttribute('data-time-end') || '');
        if (isNaN(a)) return;
        if (isNaN(b) || b <= a) b = a + 1;
        if (a < timeRange.t1 && b > timeRange.t0) anchorLine = line;
      });
    }
    placeAddPopover(pop, kolonkaEl, anchorLine);
    // Clear selection on next tick so this mouseup/click sequence stays stable.
    setTimeout(function () {
      if (window.getSelection) window.getSelection().removeAllRanges();
    }, 0);
  }

  function bindSelectionAdd(root) {
    if (!root || root.getAttribute('data-select-bound') === '1') return;
    root.setAttribute('data-select-bound', '1');
    root.addEventListener('mouseup', function (ev) {
      if (ev.button != null && ev.button !== 0) return;
      if (ev.shiftKey) return; // handled by click → contiguous cue span
      if (ev.target && ev.target.closest && ev.target.closest('.add-key-popover')) return;
      var sel = window.getSelection && window.getSelection();
      if (!sel || sel.rangeCount < 1 || sel.isCollapsed) return;
      var text = String(sel.toString() || '').trim();
      if (!text) return;
      var range = null;
      try {
        range = sel.getRangeAt(0).cloneRange();
      } catch (e) {
        range = null;
      }
      // Time = full cue slot(s); phrase = only the highlighted words (do not expand to whole line).
      var timeRange = selectionTimeRange(root, range);
      if (timeRange && timeRange.fromIdx != null && timeRange.toIdx != null) {
        markVoicePick(root, timeRange.fromIdx, timeRange.toIdx);
      }
      showAddPopover(root, text, timeRange);
    });
    root.addEventListener('touchend', function (ev) {
      if (ev.target && ev.target.closest && ev.target.closest('.add-key-popover')) return;
      setTimeout(function () {
        var t = getSelectedText();
        if (!t) return;
        var timeRange = selectionTimeRange(root);
        if (timeRange && timeRange.fromIdx != null && timeRange.toIdx != null) {
          markVoicePick(root, timeRange.fromIdx, timeRange.toIdx);
        }
        showAddPopover(root, t, timeRange);
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

  /** Record page «Run diarization» → LongJobPanel + status line. */
  function initRecordDiarize() {
    var boot = document.getElementById('audio-record-diarize-boot');
    var overlay = document.getElementById('audio-record-asr-dia-overlay');
    var btn = document.getElementById('audio-run-diarize');
    var statusEl = document.getElementById('audio-diarize-status');
    if (!boot || !overlay || !btn) return;

    var tries = 0;
    function start() {
      if (!window.LongJobPanel) {
        if (++tries < 80) setTimeout(start, 50);
        else if (statusEl) {
          statusEl.textContent = 'LongJobPanel unavailable';
        }
        return;
      }
      if (boot.getAttribute('data-bound') === '1') return;
      boot.setAttribute('data-bound', '1');

      var i18n = {};
      try {
        i18n = JSON.parse(boot.getAttribute('data-i18n') || '{}') || {};
      } catch (e) {
        i18n = {};
      }
      var csrf = boot.getAttribute('data-csrf')
        || (window.freesaasCsrfToken ? window.freesaasCsrfToken() : '');
      var panel = LongJobPanel.mount({
        root: overlay,
        startUrl: '/audio/diarize/longjob',
        tickUrl: '/audio/diarize/longjob',
        cancelUrl: '/audio/diarize/longjob',
        dualBars: false,
        showBytes: false,
        reloadOnOk: true,
        csrf: csrf,
        i18n: {
          title: i18n.title || '',
          starting: i18n.ljStarting || i18n.starting || '…',
          badJson: i18n.badJson || '',
          doneTitle: i18n.doneTitle || 'OK',
          errorTitle: i18n.errorTitle || 'Error',
          cancelledTitle: i18n.cancelledTitle || '',
          ok: i18n.ok || 'OK',
        },
        onJob: function (job) {
          if (!statusEl || !job) return;
          var msg = job.message || job.status || '';
          if (job.progress != null) msg += ' (' + Math.round(job.progress) + '%)';
          statusEl.textContent = msg;
        },
        onFinish: function (status, message) {
          if (!statusEl) return;
          if (status === 'error') {
            statusEl.textContent = (i18n.error || 'Error: {msg}').replace('{msg}', message || status);
          } else if (message) {
            statusEl.textContent = message;
          }
        },
      });
      if (!panel) {
        if (statusEl) statusEl.textContent = 'LongJobPanel mount failed';
        return;
      }

      btn.addEventListener('click', function () {
        if (i18n.confirm && !window.confirm(i18n.confirm)) return;
        if (statusEl) statusEl.textContent = i18n.starting || '…';
        var rid = btn.getAttribute('data-record-id') || boot.getAttribute('data-record-id') || '';
        var reuse = (btn.getAttribute('data-reuse') || boot.getAttribute('data-reuse')) === '1';
        var fd = new FormData();
        fd.set('ids_csv', rid);
        fd.set('apply_speakers', '1');
        fd.set('apply_transcription', reuse ? '0' : '1');
        if (reuse) fd.set('asr_model', 'reuse');
        fd.set('ret', '/audio/' + rid);
        panel.runFormData(fd, {}).catch(function (err) {
          if (statusEl) {
            statusEl.textContent = (i18n.error || 'Error: {msg}').replace(
              '{msg}',
              (err && err.message) ? err.message : String(err)
            );
          }
        });
      });
    }
    start();
  }

  /** Bulk «Send to diarization» → LongJobPanel (clear speakers, wait for edge worker). */
  function initDiarizeBulk() {
    var boot = document.getElementById('audio-diarize-boot');
    var overlay = document.getElementById('audio-diarize-overlay');
    if (!boot || !overlay) return;

    var tries = 0;
    function start() {
      if (!window.LongJobPanel) {
        if (++tries < 80) setTimeout(start, 50);
        return;
      }
      if (boot.getAttribute('data-bound') === '1') return;
      boot.setAttribute('data-bound', '1');

      var i18n = {};
      try {
        i18n = JSON.parse(boot.getAttribute('data-i18n') || '{}') || {};
      } catch (e) {
        i18n = {};
      }
      var csrf = boot.getAttribute('data-csrf')
        || (window.freesaasCsrfToken ? window.freesaasCsrfToken() : '');
      var day = boot.getAttribute('data-day') || '';
      var busy = false;
      var panel = LongJobPanel.mount({
        root: overlay,
        startUrl: '/audio/diarize/longjob',
        tickUrl: '/audio/diarize/longjob',
        cancelUrl: '/audio/diarize/longjob',
        dualBars: false,
        showBytes: false,
        reloadOnOk: true,
        csrf: csrf,
        i18n: {
          starting: i18n.starting || '…',
          badJson: i18n.badJson || '',
          doneTitle: i18n.doneTitle || 'OK',
          errorTitle: i18n.errorTitle || 'Error',
          cancelledTitle: i18n.cancelledTitle || '',
          ok: i18n.ok || 'OK',
        },
        onFinish: function () { busy = false; },
      });
      if (!panel) return;

      function disableDiarizeOption() {
        document.querySelectorAll('[data-bulk-action] option[value="queue_diarize"]').forEach(function (opt) {
          opt.disabled = true;
          var tip = i18n.missingProfiles || '';
          if (tip) opt.textContent = (opt.textContent || '') + ' — ' + tip;
        });
      }
      if (boot.getAttribute('data-profiles-ready') !== '1') {
        disableDiarizeOption();
      }

      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.getAttribute || form.getAttribute('data-bulk-form') === null) return;
        var actionSel = form.querySelector('[data-bulk-action]');
        if (!actionSel || String(actionSel.value || '') !== 'queue_diarize') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (boot.getAttribute('data-profiles-ready') !== '1') {
          var msg = (i18n.missingProfiles || '') + '\n' + (i18n.fillProfiles || '');
          if (window.confirm(msg)) {
            window.location.href = boot.getAttribute('data-profiles-url') || '/audio/voice-profiles';
          }
          return;
        }
        if (busy) {
          window.alert(i18n.busy || 'Busy');
          return;
        }
        var confirmMsg = i18n.confirm || '';
        var opt = actionSel.options[actionSel.selectedIndex];
        if (opt && opt.getAttribute('data-confirm')) {
          confirmMsg = opt.getAttribute('data-confirm') || confirmMsg;
        }
        if (confirmMsg && !window.confirm(confirmMsg)) return;

        var ids = Array.prototype.slice.call(form.querySelectorAll('[data-bulk-id]'))
          .filter(function (c) { return c.checked && !c.disabled; })
          .map(function (c) { return parseInt(c.value, 10) || 0; })
          .filter(function (id) { return id > 0; });
        if (!ids.length && !day) {
          window.alert(i18n.empty || 'Empty');
          return;
        }
        busy = true;
        var fd = new FormData();
        if (ids.length) fd.set('ids_csv', ids.join(','));
        if (day) fd.set('day', day);
        fd.set('apply_transcription', '1');
        fd.set('apply_speakers', '1');
        fd.set('ret', '/audio');
        panel.runFormData(fd, {}).then(function () {
          busy = false;
        }, function () {
          busy = false;
        });
      }, true);
    }
    start();
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindSeek(document.getElementById('audio-transcript'));
    bindViewPagePlayback();
    bindSpeakerUi();
    bindPlayerShells(document);
    document.querySelectorAll('.audio-record-card').forEach(function (card) {
      var kolonka = card.querySelector('.kolonka, .transcript');
      if (kolonka) bindCardTranscript(card, kolonka);
    });
    bindLazyTranscripts();
    initDiarizeBulk();
    initRecordDiarize();
  });
})();
