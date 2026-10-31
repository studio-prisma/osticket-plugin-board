/*
 * Ticket Board for osTicket: drag and drop, quick view and actions for the
 * agent board.
 *
 * The client board is static HTML; this script only acts on a board with
 * data-mode="staff" and a data-move-url. It never builds markup from ticket
 * data: cards are moved as existing DOM nodes, messages are set via
 * textContent. The one innerHTML is the quick-view panel, which arrives
 * fully rendered and escaped from the server.
 *
 * Keyboard: focus a card, then Alt+ArrowRight / Alt+ArrowLeft moves it to
 * the neighbouring column, Enter opens the quick view, Escape closes it.
 */
(function () {
  'use strict';

  function init(board) {
    if (!board || board.dataset.spbReady === '1') return;
    if (board.dataset.mode !== 'staff' || !board.dataset.moveUrl) return;
    board.dataset.spbReady = '1';

    var i18n = {};
    try {
      i18n = JSON.parse(board.dataset.i18n || '{}');
    } catch (e) {
      i18n = {};
    }
    var wrap = board.closest('.spb-wrap');
    var statusLine = wrap ? wrap.querySelector('.spb-status') : null;
    var dragged = null;
    var busy = false;

    function fmt(template) {
      var args = Array.prototype.slice.call(arguments, 1);
      return String(template || '').replace(/%[sd]/g, function () {
        return args.length ? String(args.shift()) : '';
      });
    }

    function say(text, isError) {
      if (!statusLine) return;
      statusLine.textContent = text;
      statusLine.classList.toggle('spb-error', !!isError);
    }

    function csrfToken() {
      var meta = document.querySelector('meta[name="csrf_token"]');
      return meta ? meta.getAttribute('content') : '';
    }

    function columns() {
      return Array.prototype.slice.call(board.querySelectorAll('.spb-col'));
    }

    function refreshCounts() {
      columns().forEach(function (col) {
        var list = col.querySelector('.spb-cards');
        var n = list.querySelectorAll(':scope > .spb-card').length;
        col.querySelector('.spb-count').textContent = String(n);
        var empty = list.querySelector(':scope > .spb-empty');
        if (empty) empty.hidden = n > 0;
      });
    }

    function move(card, col) {
      if (busy || !card || !col) return;
      var from = card.closest('.spb-col');
      if (from === col) return;

      var number = card.dataset.number;
      var statusName = col.dataset.statusName;
      if (col.dataset.state === 'closed' && !window.confirm(fmt(i18n.confirm_close, number, statusName))) {
        return;
      }

      busy = true;
      var body = new URLSearchParams();
      body.set('ticket_id', card.dataset.ticketId);
      body.set('status_id', col.dataset.statusId);

      fetch(board.dataset.moveUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-CSRFToken': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      })
        .then(function (res) {
          return res.json().then(
            function (data) { return { ok: res.ok, data: data }; },
            function () { return { ok: false, data: {} }; }
          );
        })
        .then(function (r) {
          if (!r.ok || !r.data || !r.data.ok) {
            var detail = r.data && r.data.message ? ' ' + r.data.message : '';
            say(String(i18n.move_failed || 'Move failed.') + detail, true);
            return;
          }
          col.querySelector('.spb-cards').prepend(card);
          refreshCounts();
          say(fmt(i18n.moved, number, statusName), false);
          card.focus();
        })
        .catch(function () {
          say(String(i18n.move_failed || 'Move failed.'), true);
        })
        .then(function () {
          busy = false;
        });
    }

    board.addEventListener('dragstart', function (e) {
      var card = e.target.closest && e.target.closest('.spb-card[draggable="true"]');
      if (!card) return;
      dragged = card;
      card.classList.add('spb-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', card.dataset.ticketId);
    });

    board.addEventListener('dragend', function () {
      if (dragged) dragged.classList.remove('spb-dragging');
      dragged = null;
      columns().forEach(function (c) { c.classList.remove('spb-dropping'); });
    });

    board.addEventListener('dragover', function (e) {
      var col = e.target.closest && e.target.closest('.spb-col');
      if (!col || !dragged) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      columns().forEach(function (c) { c.classList.toggle('spb-dropping', c === col); });
    });

    board.addEventListener('drop', function (e) {
      var col = e.target.closest && e.target.closest('.spb-col');
      if (!col || !dragged) return;
      e.preventDefault();
      col.classList.remove('spb-dropping');
      move(dragged, col);
    });

    board.addEventListener('keydown', function (e) {
      if (!e.altKey || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) return;
      var card = e.target.closest && e.target.closest('.spb-card[draggable="true"]');
      if (!card) return;
      var cols = columns();
      var i = cols.indexOf(card.closest('.spb-col'));
      var target = cols[i + (e.key === 'ArrowRight' ? 1 : -1)];
      if (!target) return;
      e.preventDefault();
      move(card, target);
    });

    initPanel(board, wrap, i18n, say, csrfToken);
    refreshCounts();
  }

  /*
   * Quick view. The panel HTML comes from the board's own endpoint: buttons
   * and form escaped server-side, plus osTicket's ticket preview template.
   * Actions open osTicket's own dialogs through $.dialog() from scp.js, the
   * same way the ticket view's action menu does. After a dialog succeeds,
   * the board reloads and reopens the panel for that ticket.
   */
  function initPanel(board, wrap, i18n, say, csrfToken) {
    var panelUrl = board.dataset.panelUrl;
    var drawer = wrap ? wrap.querySelector('.spb-drawer') : null;
    if (!panelUrl || !drawer) return;
    var content = drawer.querySelector('.spb-drawer-content');
    var current = null;
    var HASH = /^#spb-ticket=(\d+)$/;

    function setHash(id) {
      var base = location.pathname + location.search;
      history.replaceState(null, '', id ? base + '#spb-ticket=' + id : base);
    }

    function open(id) {
      current = String(id);
      drawer.hidden = false;
      drawer.classList.add('spb-loading');
      content.textContent = '';
      setHash(current);
      fetch(panelUrl + encodeURIComponent(current), { credentials: 'same-origin' })
        .then(function (res) {
          if (!res.ok) throw new Error(String(res.status));
          return res.text();
        })
        .then(function (html) {
          if (String(id) !== current) return;
          content.innerHTML = html;
          drawer.focus();
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
      drawer.hidden = true;
      content.textContent = '';
      setHash(null);
    }

    function reloadWithPanel(id) {
      setHash(id);
      location.reload();
    }

    board.addEventListener('click', function (e) {
      var card = e.target.closest && e.target.closest('.spb-card');
      if (!card || e.target.closest('a, button, summary, .spb-children ul')) return;
      open(card.dataset.ticketId);
    });

    board.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.altKey) return;
      var card = e.target.closest && e.target.closest('.spb-card');
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
      var body = new URLSearchParams(new FormData(form));
      var button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      fetch(form.getAttribute('action'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-CSRFToken': csrfToken(),
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      })
        .then(function (res) {
          return res.json().then(function (d) { return { ok: res.ok, data: d }; },
            function () { return { ok: false, data: {} }; });
        })
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
  }

  // Queue picker: switch views on change; the button stays for no-JS use.
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
    Array.prototype.forEach.call(document.querySelectorAll('.spb-board'), init);
    Array.prototype.forEach.call(document.querySelectorAll('select[data-spb-autosubmit]'), initPicker);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
