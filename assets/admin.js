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


  function attachPlaceCurrentLocationControl() {
    const button = document.querySelector('[data-place-admin-current]');
    if (!button || button.getAttribute('data-place-admin-current-bound') === '1') {
      return;
    }

    button.setAttribute('data-place-admin-current-bound', '1');
    const latitude = document.querySelector('[data-place-admin-latitude]');
    const longitude = document.querySelector('[data-place-admin-longitude]');
    const status = document.querySelector('[data-place-admin-status]');

    if (!latitude || !longitude || !status) {
      return;
    }

    function finish(message) {
      status.textContent = message;
      button.disabled = false;
    }

    button.addEventListener('click', function () {
      if (!window.isSecureContext) {
        finish('Location access requires HTTPS. Enter coordinates manually or open this page over HTTPS.');
        return;
      }

      if (!navigator.geolocation) {
        finish('This browser does not provide location access. Enter coordinates manually.');
        return;
      }

      button.disabled = true;
      status.textContent = 'Finding your current location…';

      navigator.geolocation.getCurrentPosition(function (position) {
        latitude.value = Number(position.coords.latitude).toFixed(7);
        longitude.value = Number(position.coords.longitude).toFixed(7);
        finish('Current coordinates added. Review the place details, then save.');
      }, function (error) {
        let message = 'The device could not provide a location. Enter coordinates manually.';
        if (error && error.code === 1) {
          message = 'Location permission was denied. Allow location access for this site, then try again.';
        } else if (error && error.code === 2) {
          message = 'Your current location is unavailable. Check the device location setting, then try again.';
        } else if (error && error.code === 3) {
          message = 'The location request timed out. Try again or enter coordinates manually.';
        }
        finish(message);
      }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    });
  }


  function runScheduledPostsHeartbeat() {
    const body = document.body;
    if (!body) {
      return;
    }
    const endpoint = body.getAttribute('data-scheduled-runner-url') || '';
    const csrf = body.getAttribute('data-scheduled-runner-csrf') || '';
    if (!endpoint || !csrf || !window.fetch) {
      return;
    }
    let busy = false;
    const ping = function () {
      if (busy) {
        return;
      }
      busy = true;
      const payload = new URLSearchParams();
      payload.set('csrf_token', csrf);
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
      }).catch(function () {}).finally(function () {
        busy = false;
      });
    };
    window.setTimeout(ping, 5000);
    window.setInterval(ping, 30000);
  }

  document.addEventListener('DOMContentLoaded', function () {
    attachSelectAllControls();
    attachMediaSelectAllControls();
    attachPlaceCurrentLocationControl();
    runScheduledPostsHeartbeat();
  });
}());
