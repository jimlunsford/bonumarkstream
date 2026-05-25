(function () {
  'use strict';

  function setGroupState(control, boxes) {
    const total = boxes.length;
    const checked = boxes.filter(function (box) { return box.checked; }).length;
    control.checked = total > 0 && checked === total;
    control.indeterminate = checked > 0 && checked < total;
  }

  function attachSelectAllControls() {
    document.querySelectorAll('[data-select-all]').forEach(function (control) {
      if (control.getAttribute('data-select-all-bound') === '1') {
        return;
      }
      control.setAttribute('data-select-all-bound', '1');
      const table = control.closest('table');
      if (!table) {
        return;
      }
      const boxes = Array.prototype.slice.call(table.querySelectorAll('tbody input[type="checkbox"]'));
      control.addEventListener('change', function () {
        boxes.forEach(function (box) {
          box.checked = control.checked;
        });
        setGroupState(control, boxes);
      });
      boxes.forEach(function (box) {
        box.addEventListener('change', function () {
          setGroupState(control, boxes);
        });
      });
      setGroupState(control, boxes);
    });
  }

  function attachMediaSelectAllControls() {
    document.querySelectorAll('[data-media-select-all]').forEach(function (control) {
      if (control.getAttribute('data-media-select-all-bound') === '1') {
        return;
      }
      control.setAttribute('data-media-select-all-bound', '1');
      const form = control.closest('form');
      if (!form) {
        return;
      }
      const boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="media_ids[]"]'));
      control.addEventListener('change', function () {
        boxes.forEach(function (box) {
          box.checked = control.checked;
        });
        setGroupState(control, boxes);
      });
      boxes.forEach(function (box) {
        box.addEventListener('change', function () {
          setGroupState(control, boxes);
        });
      });
      setGroupState(control, boxes);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    attachSelectAllControls();
    attachMediaSelectAllControls();
  });
}());
