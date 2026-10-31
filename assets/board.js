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
    function say(text, isError) {
      if (!statusLine) return;
      clearTimeout(sayTimer);
      statusLine.classList.remove('spb-fading');
      statusLine.textContent = text;
      statusLine.classList.toggle('spb-error', !!isError);
      sayTimer = setTimeout(function () {
        statusLine.classList.add('spb-fading');
        sayTimer = setTimeout(function () {
          statusLine.textContent = '';
          statusLine.classList.remove('spb-fading');
        }, 260);
      }, isError ? 6000 : 3200);
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

    var panel = initPanel(wrap, first, i18n, say);
    var live = initLive(wrap, i18n, say, {
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

  /*
   * Quick view. The panel HTML comes from the board's own endpoint: buttons
   * and form escaped server-side, plus osTicket's ticket preview template.
   * Actions open osTicket's own dialogs through $.dialog() from scp.js, the
   * same way the ticket view's action menu does. After a dialog succeeds,
   * the board reloads and reopens the panel for that ticket.
   */
  function initPanel(wrap, board, i18n, say) {
    var panelUrl = board.dataset.panelUrl;
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
