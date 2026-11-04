/*
 * Three small jobs:
 *  1. Keep the deadline countdowns ticking.
 *  2. On the submit form, show the AI's category suggestion while the student types.
 *  3. Limit office choices to the selected department.
 *  4. Ask "are you sure?" on buttons marked with data-confirm.
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
            if (data.fallback) {
              parts.push('The AI is unsure. The system will use <strong>' + escapeHtml(data.category) + '</strong> (' +
                escapeHtml(data.department) + ') as the fallback. You can choose another category.');
            } else if (data.auto_route) {
              parts.push('The system would send this to <strong>' + escapeHtml(data.department) + '</strong> as "' +
                escapeHtml(data.category) + '" (' + Math.min(99, Math.floor(data.confidence * 100)) + '% confidence).');
            } else {
              parts.push('Suggested category: <strong>' + escapeHtml(data.category) + '</strong> (' +
                Math.min(99, Math.floor(data.confidence * 100)) + '% confidence). The AI is unsure, so choose the correct category before submitting.');
            }
          } else {
            parts.push('Keep typing, or choose a category yourself if the AI cannot identify the right office.');
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

  /* ---------- 3. Department and office selection ---------- */
  var department = document.getElementById('department_id');
  var office = document.getElementById('office_id');
  if (department && office) {
    var placeholder = office.options[0].cloneNode(true);
    var officeOptions = Array.prototype.slice.call(office.options, 1).map(function (option) {
      return option.cloneNode(true);
    });
    var filterOffices = function () {
      var selectedOffice = office.value;
      while (office.options.length) {
        office.remove(0);
      }
      office.add(placeholder.cloneNode(true));

      var selectedMatches = false;
      officeOptions.forEach(function (option) {
        if (department.value && option.getAttribute('data-department') === department.value) {
          var visibleOption = option.cloneNode(true);
          if (visibleOption.value === selectedOffice) {
            visibleOption.selected = true;
            selectedMatches = true;
          }
          office.add(visibleOption);
        }
      });
      if (!selectedMatches) office.value = '';
    };
    department.addEventListener('change', filterOffices);
    filterOffices();
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- 4. Confirm dialogs ---------- */
  document.addEventListener('submit', function (event) {
    var message = event.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });
})();
