/*
 * Ticket Board for osTicket: drag and drop for the agent board.
 *
 * The client board is static HTML; this script only acts on a board with
 * data-mode="staff" and a data-move-url. It never builds markup from ticket
 * data: cards are moved as existing DOM nodes, messages are set via
 * textContent.
 *
 * Keyboard: focus a card, then Alt+ArrowRight / Alt+ArrowLeft moves it to
 * the neighbouring column.
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

    refreshCounts();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.spb-board'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
