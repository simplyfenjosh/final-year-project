/*
 * Three small jobs:
 *  1. Keep the deadline countdowns ticking.
 *  2. On the submit form, show the AI's category suggestion while the student types.
 *  3. Ask "are you sure?" on buttons marked with data-confirm.
 */
(function () {
  'use strict';

  /* ---------- 1. Countdowns ---------- */
  function human(seconds) {
    if (seconds < 90) return seconds + 's';
    if (seconds < 5400) return Math.round(seconds / 60) + 'm';
    if (seconds < 172800) {
      var h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60);
      return h + 'h' + (m ? ' ' + m + 'm' : '');
    }
    var d = Math.floor(seconds / 86400), hh = Math.floor((seconds % 86400) / 3600);
    return d + 'd' + (hh ? ' ' + hh + 'h' : '');
  }

  // When a case deadline passes, the system escalates the case. Reload the page so the person sees it,
  // but never while they are typing, and never more than once every 20 seconds.
  function maybeReload() {
    var typing = Array.prototype.some.call(document.querySelectorAll('textarea, input[type=text]'), function (f) {
      return f.value !== '' || f === document.activeElement;
    });
    if (typing) return;
    var last = 0;
    try { last = Number(sessionStorage.getItem('reloadAt') || 0); } catch (e) { /* private mode */ }
    if (Date.now() - last < 20000) return;
    try { sessionStorage.setItem('reloadAt', String(Date.now())); } catch (e) { /* ignore */ }
    window.location.reload();
  }

  function tick() {
    var now = Date.now();
    document.querySelectorAll('.countdown[data-due]').forEach(function (el) {
      var due = Date.parse(el.getAttribute('data-due'));
      if (isNaN(due)) return;
      var diff = Math.round((due - now) / 1000);
      if (diff >= 0) {
        el.textContent = 'due in ' + human(diff);
        el.classList.remove('overdue');
      } else {
        el.textContent = 'overdue by ' + human(-diff);
        el.classList.add('overdue');
        if (el.getAttribute('data-reload') === '1' && diff < -3) maybeReload();
      }
    });
  }
  if (document.querySelector('.countdown[data-due]')) {
    tick();
    setInterval(tick, 1000);
  }

  /* ---------- 2. AI category hint ---------- */
  var form = document.getElementById('case-form');
  if (form && form.dataset.suggestUrl) {
    var title = document.getElementById('title');
    var desc = document.getElementById('description');
    var select = document.getElementById('category_id');
    var hint = document.getElementById('ai-hint');
    var csrf = document.querySelector('meta[name="csrf"]');
    var timer = null;
    var last = '';

    var ask = function () {
      var text = ((title ? title.value : '') + ' ' + (desc ? desc.value : '')).trim();
      if (text.length < 15 || text === last) return;
      last = text;
      fetch(form.dataset.suggestUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf ? csrf.content : '' },
        body: JSON.stringify({ text: text })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) return;
          var parts = [];
          if (data.category) {
            parts.push('The system would send this to <strong>' + escapeHtml(data.department) + '</strong> as "' +
              escapeHtml(data.category) + '" (' + Math.min(99, Math.floor(data.confidence * 100)) + '% sure).');
          } else {
            parts.push('Keep typing. The system needs a few more details to choose an office.');
          }
          if (data.urgent) {
            parts.push('<strong>This looks urgent</strong>, so it will get a shorter time limit. If you are in danger, contact campus security now.');
          }
          hint.innerHTML = parts.join(' ');
        })
        .catch(function () { /* the form still works without the hint */ });
    };

    var schedule = function () {
      clearTimeout(timer);
      timer = setTimeout(ask, 600);
    };
    if (title) title.addEventListener('input', schedule);
    if (desc) desc.addEventListener('input', schedule);
    if (select) select.addEventListener('change', function () {
      if (hint && select.value !== '0') hint.textContent = '';
      else schedule();
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- 3. Confirm dialogs ---------- */
  document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });
})();
