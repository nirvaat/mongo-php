(function () {
  'use strict';

  /**
   * Confirm-form handler:
   * - If the form has an input with name="confirm" that has data-confirm-prompt,
   *   prompt the user for that exact text.
   * - Otherwise use a plain confirm() with data-confirm message.
   */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form.classList.contains('confirm-form')) return;
    var confirmInput = form.querySelector('input[name="confirm"][data-confirm-prompt]');
    if (confirmInput) {
      var expected = confirmInput.value;
      var prompt = confirmInput.dataset.confirmPrompt;
      var answer = window.prompt(prompt, '');
      if (answer === null) {
        e.preventDefault();
        return;
      }
      if (answer !== expected) {
        alert('Confirmation did not match. Expected: ' + expected);
        e.preventDefault();
        return;
      }
      // ensure server gets the expected value
      confirmInput.value = answer;
      return;
    }
    var msg = form.dataset.confirm || 'Are you sure?';
    if (!window.confirm(msg)) {
      e.preventDefault();
    }
  });

  /**
   * Enable capped-size fields only when "capped" checkbox is on.
   */
  document.addEventListener('change', function (e) {
    if (e.target.name === 'capped') {
      var form = e.target.closest('form');
      var size = form.querySelector('[name="cap_size"]');
      var max = form.querySelector('[name="cap_max"]');
      if (size) size.disabled = !e.target.checked;
      if (max) max.disabled = !e.target.checked;
    }
  });

  // Initial sync of capped fields
  document.querySelectorAll('input[name="capped"]').forEach(function (el) {
    var ev = new Event('change');
    el.dispatchEvent(ev);
  });

  /**
   * Auto-expand textareas as content grows (cheap heuristic).
   */
  document.querySelectorAll('textarea.code').forEach(function (ta) {
    var resize = function () {
      var min = parseInt(ta.getAttribute('rows') || '6', 10);
      var lines = ta.value.split('\n').length;
      if (lines > min) ta.rows = Math.min(lines + 1, 40);
    };
    ta.addEventListener('input', resize);
    resize();
  });

  /**
   * Tab key inside code textareas inserts two spaces instead of focus-shift.
   */
  document.querySelectorAll('textarea.code').forEach(function (ta) {
    ta.addEventListener('keydown', function (e) {
      if (e.key !== 'Tab') return;
      e.preventDefault();
      var start = ta.selectionStart, end = ta.selectionEnd;
      var v = ta.value;
      ta.value = v.substring(0, start) + '  ' + v.substring(end);
      ta.selectionStart = ta.selectionEnd = start + 2;
    });
  });
})();
