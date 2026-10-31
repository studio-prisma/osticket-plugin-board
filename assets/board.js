/*
 * Ticket Board for osTicket: drag and drop, quick view, actions and live
 * refresh for the agent board.
 *
 * The client board is static HTML; this script only acts on a board with
 * data-mode="staff" and a data-move-url. It never builds markup from ticket
 * data: cards are moved as existing DOM nodes, messages are set via
 * textContent. Two places take server-rendered HTML as a whole: the
 * quick-view panel and, on live refresh, the board element of a fresh copy
 * of this same page. Both are rendered and escaped on the server.
 *
 * All listeners sit on .spb-wrap and delegate, so the board element can be
 * swapped on live refresh without rebinding anything.
 *
 * Keyboard: focus a card, then Alt+ArrowRight / Alt+ArrowLeft moves it to
 * the neighbouring column (within its row when grouped), Enter opens the
 * quick view, Escape closes it.
 */
(function () {
  'use strict';

  function fmt(template) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(template || '')
      .replace(/%(\d)\$[sd]/g, function (_, n) { return String(args[Number(n) - 1]); })
      .replace(/%[sd]/g, function () { return String(args[i++]); });
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function postForm(url, params) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRFToken': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: params.toString()
    }).then(function (res) {
      return res.json().then(
        function (data) { return { ok: res.ok, data: data }; },
        function () { return { ok: false, data: {} }; }
      );
    });
  }

  function initWrap(wrap) {
    if (!wrap || wrap.dataset.spbReady === '1') return;
    var first = wrap.querySelector('.spb-board');
    if (!first || first.dataset.mode !== 'staff' || !first.dataset.moveUrl) return;
    wrap.dataset.spbReady = '1';

    var i18n = {};
    try {
      i18n = JSON.parse(first.dataset.i18n || '{}');
    } catch (e) {
      i18n = {};
    }
    var statusLine = wrap.querySelector('.spb-status');
    var dragged = null;
    var busy = false;
    var lastInput = 0;

    function board() {
      return wrap.querySelector('.spb-board');
    }

    // Toast: shows, then gets out of the way. Errors stay longer.
    var sayTimer = null;
    function say(text, isError, action) {
      if (!statusLine) return;
      clearTimeout(sayTimer);
      statusLine.classList.remove('spb-fading');
      statusLine.textContent = text;
      statusLine.classList.toggle('spb-error', !!isError);
      if (action) {
        var undo = document.createElement('button');
        undo.type = 'button';
        undo.className = 'spb-toast-action';
        undo.textContent = action.label;
        undo.addEventListener('click', function () {
          undo.disabled = true;
          action.run();
        });
        statusLine.appendChild(document.createTextNode(' '));
        statusLine.appendChild(undo);
      }
      sayTimer = setTimeout(function () {
        statusLine.classList.add('spb-fading');
        sayTimer = setTimeout(function () {
          statusLine.textContent = '';
          statusLine.classList.remove('spb-fading');
        }, 260);
      }, isError ? 6000 : (action ? 8000 : 3200));
    }

    // Columns a card can move between: its own row when grouped.
    function columnsFor(card) {
      var scope = (card && card.closest('.spb-lane')) || board();
      return Array.prototype.slice.call(scope.querySelectorAll('.spb-col'));
    }

    function sameRow(card, col) {
      return card.closest('.spb-lane') === col.closest('.spb-lane');
    }

    function topCards(col) {
      return col.querySelectorAll('.spb-cards > .spb-card').length;
    }

    function setCount(head, n) {
      var count = head.querySelector('.spb-count');
      if (!count) return;
      var limit = Number(count.dataset.limit || 0);
      count.textContent = limit ? fmt(i18n.limit || '%1$d / %2$d', n, limit) : String(n);
      var over = limit > 0 && n > limit;
      head.classList.toggle('spb-over', over);
      if (over) count.title = fmt(i18n.limit_over || '', limit);
      else count.removeAttribute('title');
    }

    function refreshCounts() {
      var b = board();
      Array.prototype.forEach.call(b.querySelectorAll('.spb-col'), function (col) {
        var n = topCards(col);
        var head = col.querySelector(':scope > .spb-col-head');
        if (head) setCount(head, n);
        var empty = col.querySelector('.spb-cards > .spb-empty');
        if (empty) empty.hidden = n > 0;
      });
      Array.prototype.forEach.call(b.querySelectorAll('.spb-col-total'), function (total) {
        var n = 0;
        Array.prototype.forEach.call(b.querySelectorAll('.spb-col[data-status-id="' + total.dataset.statusId + '"]'), function (col) {
          n += topCards(col);
        });
        setCount(total.querySelector('.spb-col-head'), n);
      });
      Array.prototype.forEach.call(b.querySelectorAll('.spb-lane'), function (lane) {
        var count = lane.querySelector('.spb-lane-head .spb-count');
        if (count) count.textContent = String(lane.querySelectorAll('.spb-cards > .spb-card').length);
      });
    }

    function move(card, col) {
      if (busy || !card || !col || !sameRow(card, col)) return;
      if (card.closest('.spb-col') === col) return;

      var number = card.dataset.number;
      var statusName = col.dataset.statusName;
      if (col.dataset.state === 'closed' && !window.confirm(fmt(i18n.confirm_close, number, statusName))) {
        return;
      }

      busy = true;
      var params = new URLSearchParams();
      params.set('ticket_id', card.dataset.ticketId);
      params.set('status_id', col.dataset.statusId);

      postForm(board().dataset.moveUrl, params)
        .then(function (r) {
          if (!r.ok || !r.data || !r.data.ok) {
            var detail = r.data && r.data.message ? ' ' + r.data.message : '';
            say(String(i18n.move_failed || 'Move failed.') + detail, true);
            return;
          }
          col.querySelector('.spb-cards').prepend(card);
          refreshCounts();
          live.expectOwnChange();
          say(fmt(i18n.moved, number, statusName), false);
          // Confirmation where the eye already is: on the card that moved.
          card.classList.add('spb-landed');
          setTimeout(function () { card.classList.remove('spb-landed'); }, 900);
          card.focus();
        })
        .catch(function () {
          say(String(i18n.move_failed || 'Move failed.'), true);
        })
        .then(function () {
          busy = false;
        });
    }

    // --- Drag and drop and keyboard moves ---------------------------------

    function clearDropping() {
      Array.prototype.forEach.call(board().querySelectorAll('.spb-dropping'), function (c) {
        c.classList.remove('spb-dropping');
      });
    }

    wrap.addEventListener('dragstart', function (e) {
      var card = e.target.closest && e.target.closest('.spb-card[draggable="true"]');
      if (!card) return;
      dragged = card;
      lastInput = Date.now();
      card.classList.add('spb-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.dataset.ticketId);
    });

    wrap.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('spb-dragging');
      dragged = null;
      clearDropping();
    });

    wrap.addEventListener('dragover', function (e) {
      var col = e.target.closest && e.target.closest('.spb-col');
      if (!col || !dragged || !sameRow(dragged, col)) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if (!col.classList.contains('spb-dropping')) {
        clearDropping();
        col.classList.add('spb-dropping');
      }
    });

    wrap.addEventListener('drop', function (e) {
      var col = e.target.closest && e.target.closest('.spb-col');
      if (!col || !dragged) return;
      e.preventDefault();
      clearDropping();
      move(dragged, col);
    });

    wrap.addEventListener('keydown', function (e) {
      lastInput = Date.now();
      if (!e.altKey || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
      var card = e.target.closest && e.target.closest('.spb-card[draggable="true"]');
      if (!card) return;
      var cols = columnsFor(card);
      var target = cols[cols.indexOf(card.closest('.spb-col')) + (e.key === 'ArrowRight' ? 1 : -1)];
      if (!target) return;
      e.preventDefault();
      move(card, target);
    });

    // Someone reading or working on the page counts as busy, not just events
    // inside the board: keys and clicks anywhere, scrolling, and the pointer
    // resting on the board.
    function touched() { lastInput = Date.now(); }
    ['keydown', 'pointerdown', 'wheel', 'input'].forEach(function (type) {
      document.addEventListener(type, touched, { passive: true, capture: true });
    });
    wrap.addEventListener('pointermove', touched, { passive: true });

    var live = null;
    var panel = initPanel(wrap, first, i18n, say, {
      board: board,
      reload: function () {
        if (live) live.expectOwnChange();
        return fetchBoard(wrap).then(refreshCounts);
      }
    });
    live = initLive(wrap, i18n, say, {
      board: board,
      idle: function () {
        var popup = document.getElementById('popup');
        var active = document.activeElement;
        return !dragged && !busy
          && Date.now() - lastInput > 8000
          && !(popup && popup.offsetParent)
          && !(active && wrap.contains(active) && /^(TEXTAREA|INPUT|SELECT)$/.test(active.tagName));
      },
      swapped: function () {
        refreshCounts();
        if (panel) panel.refreshIfOpen();
      }
    });
    refreshCounts();
  }

  // Replaces the board with the one from a fresh copy of this page.
  function fetchBoard(wrap) {
    return fetch(location.pathname + location.search, { credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) throw new Error(String(res.status));
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.querySelector('.spb-wrap .spb-board');
        if (!fresh) throw new Error('no board');
        wrap.querySelector('.spb-board').replaceWith(document.adoptNode(fresh));
        return fresh;
      });
  }

  /*
   * Quick view. The panel HTML comes from the board's own endpoint: buttons
   * and form escaped server-side, plus osTicket's ticket preview template.
   * Most actions run on click through /board/act, which applies the same
   * permission checks as osTicket's own dialogs; pickers for agent, team,
   * department and priority open inside the panel. Text in the note field
   * goes along with whichever action runs. Refer and add task still open
   * osTicket's dialogs through $.dialog() from scp.js.
   */
  function initPanel(wrap, board, i18n, say, hooks) {
    var panelUrl = board.dataset.panelUrl;
    var nextAfterClose = board.dataset.nextAfterClose === '1';
    var rules = { to: [], from: [], acts: ['status'] };
    try {
      rules = Object.assign(rules, JSON.parse(board.dataset.next || '{}'));
    } catch (e) { /* defaults */ }
    var ACT_GROUP = {
      status: 'status', claim: 'assign', release: 'assign', assign_agent: 'assign', assign_team: 'assign',
      transfer: 'transfer', priority: 'priority', answered: 'mark', unanswered: 'mark'
    };
    var drawer = wrap.querySelector('.spb-drawer');
    if (!panelUrl || !drawer) return null;
    var content = drawer.querySelector('.spb-drawer-content');
    var current = null;
    var HASH = /^#spb-ticket=(\d+)$/;

    function setHash(id) {
      var base = location.pathname + location.search;
      history.replaceState(null, '', id ? base + '#spb-ticket=' + id : base);
    }

    // Open and close only toggle a class; the CSS transition runs from the
    // current position, so closing mid-slide reverses instead of jumping.
    // `hidden` is set once the slide-out has finished, and cleared again if
    // the panel reopens in the meantime.
    var hideTimer = null;
    function show() {
      clearTimeout(hideTimer);
      drawer.hidden = false;
      void drawer.offsetWidth; // start the transition from the off-screen position
      drawer.classList.add('spb-open');
    }

    function hide() {
      drawer.classList.remove('spb-open');
      clearTimeout(hideTimer);
      hideTimer = setTimeout(function () {
        if (!drawer.classList.contains('spb-open')) {
          drawer.hidden = true;
          content.textContent = '';
        }
      }, 380);
    }

    function open(id) {
      current = String(id);
      show();
      drawer.classList.add('spb-loading');
      setHash(current);
      fetch(panelUrl + encodeURIComponent(current), { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error(String(res.status));
          return res.text();
        })
        .then(function (html) {
          if (String(id) !== current) return;
          content.innerHTML = html;
          if (!drawer.contains(document.activeElement)) drawer.focus();
        })
        .catch(function () {
          close();
          say(String(i18n.panel_failed || 'Quick view failed.'), true);
        })
        .then(function () {
          drawer.classList.remove('spb-loading');
        });
    }

    function close() {
      current = null;
      hide();
      setHash(null);
    }

    /*
     * The card that comes after this one, in the order the board shows:
     * further down the same column, then the next open column (row by row
     * when grouped). Falls back to the card before it when this was the
     * last one, and to null when it was the only open card. Closed columns
     * don't count: the point is the next ticket to work on.
     */
    function nextOpenCard(id) {
      var cards = Array.prototype.slice.call(wrap.querySelectorAll(
        '.spb-board .spb-col[data-state="open"] .spb-cards > .spb-card'));
      var i = cards.findIndex(function (c) { return c.dataset.ticketId === String(id); });
      if (i < 0) return null;
      var next = cards[i + 1] || cards[i - 1];
      return next ? next.dataset.ticketId : null;
    }

    function cardOf(id) {
      return wrap.querySelector('.spb-board .spb-cards > .spb-card[data-ticket-id="' + String(id) + '"]');
    }

    // The next card in the same column (and row, when grouped), or the one
    // before it when this was the last; null when it was the only one.
    function nextInColumn(id) {
      var card = cardOf(id);
      if (!card) return null;
      var next = card.nextElementSibling;
      while (next && !next.classList.contains('spb-card')) next = next.nextElementSibling;
      var prev = card.previousElementSibling;
      while (prev && !prev.classList.contains('spb-card')) prev = prev.previousElementSibling;
      var other = next || prev;
      return other ? other.dataset.ticketId : null;
    }

    /*
     * Where the quick view goes after an action, decided from the board as
     * it is before the action:
     *   column  the ticket sits in a work-through column (settings) and the
     *           action counts there: next card of that column
     *   board   it was closed, or set to a status configured to move on:
     *           next open card anywhere on the board
     *   stay    anything else
     */
    function afterAct(id, act, btn) {
      var card = cardOf(id);
      var col = card && card.closest('.spb-col');
      var from = col ? Number(col.dataset.statusId) : 0;
      if (from && rules.from.indexOf(from) >= 0 && rules.acts.indexOf(ACT_GROUP[act]) >= 0) {
        return { mode: 'column', next: nextInColumn(id), column: col.dataset.statusName || '' };
      }
      if (act === 'status') {
        var to = Number(btn.dataset.value);
        if ((btn.dataset.state === 'closed' && nextAfterClose) || rules.to.indexOf(to) >= 0) {
          return { mode: 'board', next: nextOpenCard(id) };
        }
      }
      return { mode: 'stay', next: id };
    }

    function reloadWithPanel(id) {
      setHash(id);
      location.reload();
    }

    wrap.addEventListener('click', function (e) {
      var card = e.target.closest && e.target.closest('.spb-board .spb-card');
      if (!card || e.target.closest('a, button, summary, .spb-children ul')) return;
      open(card.dataset.ticketId);
    });

    wrap.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.altKey) return;
      var card = e.target.closest && e.target.closest('.spb-board .spb-card');
      if (!card || e.target !== card) return;
      e.preventDefault();
      open(card.dataset.ticketId);
    });

    drawer.querySelector('.spb-drawer-close').addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      // Leave Escape to an open core dialog first.
      var popup = document.getElementById('popup');
      if (e.key === 'Escape' && !drawer.hidden && !(popup && popup.offsetParent)) close();
    });

    drawer.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-spb-dialog]');
      if (!btn) return;
      e.preventDefault();
      var id = current;
      var path = btn.getAttribute('data-spb-dialog');
      var $ = window.jQuery;
      if (!$ || typeof $.dialog !== 'function') {
        // scp.js missing: fall back to the full ticket view.
        location.href = 'tickets.php?id=' + encodeURIComponent(id);
        return;
      }
      // Relative on purpose: the page sets <base href=".../scp/">, exactly
      // what the dialog's own form submission relies on.
      $.dialog('ajax.php/' + path + '?_uid=' + Date.now(), [201], function () {
        reloadWithPanel(id);
      });
    });

    // Pickers: one open at a time, toggled by their button.
    function closeMenus(except) {
      Array.prototype.forEach.call(drawer.querySelectorAll('.spb-menu'), function (m) {
        if (m === except) return;
        m.hidden = true;
        var b = drawer.querySelector('[data-spb-menu="' + m.dataset.menu + '"]');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
    }

    drawer.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-spb-menu]');
      if (!btn) return;
      var menu = drawer.querySelector('.spb-menu[data-menu="' + btn.dataset.spbMenu + '"]');
      if (!menu) return;
      closeMenus(menu);
      menu.hidden = !menu.hidden;
      btn.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
      if (!menu.hidden) {
        var firstOption = menu.querySelector('.spb-option');
        if (firstOption) firstOption.focus();
      }
    });

    function setBusy(on) {
      Array.prototype.forEach.call(drawer.querySelectorAll('.spb-action, .spb-option'), function (b) {
        b.disabled = on;
      });
    }

    function runAct(id, act, value, note) {
      var body = content.querySelector('.spb-panel-body');
      var url = body && body.dataset.actUrl;
      var params = new URLSearchParams();
      params.set('ticket_id', id);
      params.set('act', act);
      params.set('value', value);
      if (note) params.set('note', note);
      return url ? postForm(url, params) : Promise.resolve({ ok: false, data: {} });
    }

    function failed(r) {
      var msg = r && r.data && typeof r.data.message === 'string' && r.data.message
        ? r.data.message : String(i18n.act_failed || 'Failed.');
      say(msg, true);
    }

    drawer.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('[data-spb-act]');
      if (!btn || btn.disabled) return;
      e.preventDefault();
      var id = current;
      var act = btn.dataset.spbAct;
      var field = drawer.querySelector('#spb-note-text');
      var note = field ? field.value.trim() : '';
      var after = afterAct(id, act, btn);
      var then = after.next;
      closeMenus(null);
      setBusy(true);
      runAct(id, act, btn.dataset.value || '', note)
        .then(function (r) {
          if (!(r.ok && r.data && r.data.ok)) {
            failed(r);
            setBusy(false);
            return;
          }
          if (field) field.value = '';
          var text = String(i18n.act_done || 'Done.');
          if (r.data.note === 'saved') text += ' ' + String(i18n.act_note || '');
          if (r.data.note === 'failed') text += ' ' + String(i18n.note_failed || '');
          if (after.mode === 'column' && !then) text += ' ' + fmt(String(i18n.column_done || ''), after.column);
          var prev = act === 'status' && !r.data.unchanged && r.data.previous ? r.data.previous : null;
          say(text, r.data.note === 'failed', prev ? {
            label: String(i18n.undo || 'Undo'),
            run: function () { undo(id, prev.id); }
          } : null);
          return hooks.reload().catch(function () {}).then(function () {
            if (then) open(then); else close();
          });
        })
        .catch(function () {
          failed(null);
          setBusy(false);
        });
    });

    // Undo sets the status the ticket had before, without the note.
    function undo(id, statusId) {
      runAct(id, 'status', String(statusId), '')
        .then(function (r) {
          if (!(r.ok && r.data && r.data.ok)) {
            failed(r);
            return;
          }
          say(String(i18n.undone || 'Undone.'), false);
          return hooks.reload().catch(function () {}).then(function () { open(id); });
        })
        .catch(function () { failed(null); });
    }

    // Thread entries in the preview: older ones come collapsed; a click
    // opens one, a click on the header of an open one folds it again. The
    // core binds this in an inline script, which innerHTML doesn't run.
    drawer.addEventListener('click', function (e) {
      var entry = e.target.closest && e.target.closest('.spb-preview .thread-preview-entry');
      if (!entry || e.target.closest('a')) return;
      if (entry.classList.contains('collapsed')) {
        entry.classList.remove('collapsed');
      } else if (e.target.closest('.header')) {
        entry.classList.add('collapsed');
      }
    });

    drawer.addEventListener('submit', function (e) {
      var form = e.target.closest && e.target.closest('form.spb-note');
      if (!form) return;
      e.preventDefault();
      var id = current;
      var button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      postForm(form.getAttribute('action'), new URLSearchParams(new FormData(form)))
        .then(function (r) {
          if (r.ok && r.data && r.data.ok) {
            say(String(i18n.note_saved || 'Saved.'), false);
            open(id);
          } else {
            say(String(i18n.note_failed || 'Failed.'), true);
            if (button) button.disabled = false;
          }
        })
        .catch(function () {
          say(String(i18n.note_failed || 'Failed.'), true);
          if (button) button.disabled = false;
        });
    });

    var m = HASH.exec(location.hash);
    if (m) open(m[1]);

    return {
      // After a live refresh: reload the panel content too, unless the
      // agent is typing a note in it.
      refreshIfOpen: function () {
        var typing = drawer.contains(document.activeElement) && document.activeElement.tagName === 'TEXTAREA';
        if (current && !typing) open(current);
      }
    };
  }

  /*
   * Live refresh. Every n seconds (only while the tab is visible) the board
   * asks the server for a fingerprint of what it would show now. If it
   * changed, the board element is replaced with the one from a fresh copy
   * of this page, but only while the agent isn't doing anything: not
   * dragging, typing, in a core dialog, or active in the last 8 seconds.
   * Otherwise a button offers the update, so nothing moves under the hand.
   */
  function initLive(wrap, i18n, say, hooks) {
    var b = hooks.board();
    var url = b.dataset.refreshUrl;
    var seconds = Number(b.dataset.refreshSeconds || 0);
    var button = wrap.querySelector('.spb-updates');
    var api = { expectOwnChange: function () {} };
    if (!url || seconds <= 0) return api;

    var known = b.dataset.signature || '';
    var pending = false;
    var own = false;
    var running = false;

    function stateUrl() {
      var q = new URLSearchParams(location.search).get('queue');
      return url + (q ? '?queue=' + encodeURIComponent(q) : '');
    }

    function swap(quiet) {
      if (running) return;
      running = true;
      if (button) button.disabled = true;
      var focused = document.activeElement && document.activeElement.closest
        ? document.activeElement.closest('.spb-card') : null;
      var focusId = focused ? focused.dataset.ticketId : null;
      fetch(location.pathname + location.search, { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error(String(res.status));
          return res.text();
        })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var fresh = doc.querySelector('.spb-wrap .spb-board');
          if (!fresh) throw new Error('no board');
          hooks.board().replaceWith(document.adoptNode(fresh));
          known = fresh.dataset.signature || known;
          pending = false;
          if (button) button.hidden = true;
          hooks.swapped();
          if (focusId) {
            var again = hooks.board().querySelector('.spb-card[data-ticket-id="' + focusId + '"]');
            if (again) again.focus();
          }
          if (!quiet) say(String(i18n.refreshed || 'Board updated.'), false);
        })
        .catch(function () { /* next round tries again */ })
        .then(function () {
          running = false;
          if (button) button.disabled = false;
        });
    }

    function check() {
      if (document.hidden || running) return;
      fetch(stateUrl(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.ok ? res.json() : null; })
        .then(function (data) {
          if (!data || !data.signature || data.signature === known) return;
          if (hooks.idle()) {
            swap(own);
            own = false;
          } else if (!own) {
            pending = true;
            if (button) button.hidden = false;
          }
        })
        .catch(function () {});
    }

    if (button) button.addEventListener('click', function () { swap(false); });
    setInterval(check, seconds * 1000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) check();
    });

    api.expectOwnChange = function () {
      // The agent's own move changed the fingerprint; pick that up quietly
      // instead of announcing it as someone else's update.
      own = true;
    };
    return api;
  }

  // Pickers: switch views on change; the button stays for no-JS use.
  function initPicker(select) {
    if (select.dataset.spbReady === '1') return;
    select.dataset.spbReady = '1';
    var button = select.form && select.form.querySelector('.spb-show');
    if (button) button.hidden = true;
    select.addEventListener('change', function () {
      select.form.submit();
    });
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.spb-wrap'), initWrap);
    Array.prototype.forEach.call(document.querySelectorAll('select[data-spb-autosubmit]'), initPicker);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
