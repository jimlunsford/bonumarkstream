(function () {
  'use strict';

  document.documentElement.classList.add('admin-js-ready');

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
      const selector = control.getAttribute('data-select-scope') || '';
      let scope = null;
      if (selector) {
        try {
          scope = document.querySelector(selector);
        } catch (error) {
          scope = null;
        }
      }
      if (!scope) {
        scope = control.closest('table');
      }
      if (!scope) {
        return;
      }
      const boxes = Array.prototype.slice.call(scope.querySelectorAll('input[type="checkbox"][name="selected[]"], tbody input[type="checkbox"]'));
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
      function syncCardState(box) {
        const card = box.closest('[data-media-item]');
        if (card) {
          card.classList.toggle('is-selected', box.checked);
        }
      }
      control.addEventListener('change', function () {
        boxes.forEach(function (box) {
          box.checked = control.checked;
          syncCardState(box);
        });
        setGroupState(control, boxes);
      });
      boxes.forEach(function (box) {
        box.addEventListener('change', function () {
          syncCardState(box);
          setGroupState(control, boxes);
        });
        syncCardState(box);
      });
      setGroupState(control, boxes);
    });
  }


  function attachContentActionMenus() {
    const menus = Array.prototype.slice.call(document.querySelectorAll('[data-content-actions]'));
    if (!menus.length) {
      return;
    }

    menus.forEach(function (menu) {
      if (menu.getAttribute('data-content-actions-bound') === '1') {
        return;
      }
      menu.setAttribute('data-content-actions-bound', '1');
      const summary = menu.querySelector('summary');
      if (summary) {
        summary.addEventListener('click', function () {
          menus.forEach(function (other) {
            if (other !== menu) {
              other.removeAttribute('open');
            }
          });
        });
      }
      menu.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
          return;
        }
        event.preventDefault();
        menu.removeAttribute('open');
        if (summary) {
          summary.focus();
        }
      });
    });

    if (document.documentElement.getAttribute('data-content-actions-document-bound') !== '1') {
      document.documentElement.setAttribute('data-content-actions-document-bound', '1');
      document.addEventListener('click', function (event) {
        if (event.target.closest('[data-content-actions]')) {
          return;
        }
        document.querySelectorAll('[data-content-actions][open]').forEach(function (menu) {
          menu.removeAttribute('open');
        });
      });
    }
  }


  function attachMediaDetailsDialog() {
    const dialog = document.querySelector('[data-media-details-dialog]');
    const triggers = Array.prototype.slice.call(document.querySelectorAll('[data-media-details-open]'));
    if (!dialog || !triggers.length || dialog.getAttribute('data-media-details-bound') === '1') {
      return;
    }

    dialog.setAttribute('data-media-details-bound', '1');
    const name = dialog.querySelector('[data-media-detail-name]');
    const kind = dialog.querySelector('[data-media-detail-kind]');
    const meta = dialog.querySelector('[data-media-detail-meta]');
    const status = dialog.querySelector('[data-media-detail-status]');
    const note = dialog.querySelector('[data-media-detail-note]');
    const captionRow = dialog.querySelector('[data-media-detail-caption-row]');
    const caption = dialog.querySelector('[data-media-detail-caption]');
    const markdownRow = dialog.querySelector('[data-media-detail-markdown-row]');
    const markdown = dialog.querySelector('[data-media-detail-markdown]');
    const trashedRow = dialog.querySelector('[data-media-detail-trashed-row]');
    const trashed = dialog.querySelector('[data-media-detail-trashed]');
    const image = dialog.querySelector('[data-media-detail-image]');
    const fileBadge = dialog.querySelector('[data-media-detail-file-badge]');
    const editLink = dialog.querySelector('[data-media-detail-edit]');
    const viewLink = dialog.querySelector('[data-media-detail-view]');
    const copyControl = dialog.querySelector('[data-media-detail-copy]');
    const scrollRegion = dialog.querySelector('[data-media-details-scroll]');
    const closeControls = Array.prototype.slice.call(dialog.querySelectorAll('[data-media-details-close]'));
    let returnFocus = null;
    let lockedScrollY = 0;

    function setOptionalText(element, row, value) {
      const text = String(value || '').trim();
      if (element) {
        element.textContent = text;
      }
      if (row) {
        row.hidden = text === '';
      }
    }

    function populate(card) {
      const data = card.dataset;
      if (name) { name.textContent = data.mediaName || 'Media item'; }
      if (kind) { kind.textContent = data.mediaKind || 'Media'; }
      if (meta) { meta.textContent = data.mediaMeta || ''; }
      if (status) {
        const safeClass = String(data.mediaStatusClass || 'draft').replace(/[^a-z0-9_-]/gi, '') || 'draft';
        status.className = 'status-pill ' + safeClass;
        status.textContent = data.mediaStatusLabel || 'Not checked';
      }
      setOptionalText(note, note, data.mediaNote || '');
      setOptionalText(caption, captionRow, data.mediaCaption || '');
      setOptionalText(trashed, trashedRow, data.mediaTrashedAt || '');

      const markdownValue = String(data.mediaMarkdown || '');
      if (markdown) { markdown.value = markdownValue; }
      if (markdownRow) { markdownRow.hidden = markdownValue === ''; }
      if (copyControl) { copyControl.hidden = markdownValue === ''; }

      const imageUrl = String(data.mediaImageUrl || '');
      if (image) {
        image.hidden = imageUrl === '';
        image.src = imageUrl;
        image.alt = data.mediaAlt || data.mediaName || '';
      }
      if (fileBadge) {
        fileBadge.hidden = imageUrl !== '';
        fileBadge.textContent = data.mediaKind || 'Media';
      }
      if (editLink) { editLink.href = data.mediaEditUrl || '#'; }
      if (viewLink) { viewLink.href = data.mediaViewUrl || '#'; }
    }

    function closeDialog() {
      if (typeof dialog.close === 'function' && dialog.open) {
        dialog.close();
      }
    }

    triggers.forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        const card = trigger.closest('[data-media-item]');
        if (!card) {
          return;
        }
        populate(card);
        returnFocus = trigger;
        if (typeof dialog.showModal === 'function') {
          lockedScrollY = window.scrollY || window.pageYOffset || 0;
          document.body.style.setProperty('--media-details-scroll-y', '-' + lockedScrollY + 'px');
          dialog.showModal();
          document.body.classList.add('media-details-open');
          if (scrollRegion) { scrollRegion.scrollTop = 0; }
          const closeButton = dialog.querySelector('[data-media-details-close]');
          if (closeButton) {
            window.setTimeout(function () { closeButton.focus(); }, 0);
          }
        } else {
          window.location.href = card.dataset.mediaEditUrl || '#';
        }
      });
    });

    closeControls.forEach(function (control) {
      control.addEventListener('click', closeDialog);
    });

    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) {
        closeDialog();
      }
    });

    dialog.addEventListener('close', function () {
      document.body.classList.remove('media-details-open');
      document.body.style.removeProperty('--media-details-scroll-y');
      if (window.matchMedia('(max-width: 640px)').matches) {
        window.scrollTo(0, lockedScrollY);
      }
      if (image) {
        image.removeAttribute('src');
      }
      if (returnFocus && typeof returnFocus.focus === 'function') {
        returnFocus.focus();
      }
      returnFocus = null;
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
        latitude.dispatchEvent(new Event('input', { bubbles: true }));
        longitude.dispatchEvent(new Event('input', { bubbles: true }));
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


  function attachAdminNavigation() {
    const body = document.body;
    const sidebar = document.getElementById('admin-sidebar');
    const openButton = document.querySelector('[data-admin-nav-open]');
    const closeControls = Array.prototype.slice.call(document.querySelectorAll('[data-admin-nav-close]'));
    const backdrop = document.querySelector('.admin-sidebar-backdrop');
    const mobileQuery = window.matchMedia ? window.matchMedia('(max-width: 900px)') : null;
    const main = document.querySelector('.admin-main');
    let returnFocus = null;
    let lockedScrollY = 0;

    if (!body || !sidebar || !openButton || !backdrop) {
      return;
    }

    function isMobile() {
      return mobileQuery ? mobileQuery.matches : window.innerWidth <= 900;
    }

    function setOpen(open) {
      const shouldOpen = Boolean(open && isMobile());
      body.classList.toggle('admin-nav-open', shouldOpen);
      openButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
      sidebar.setAttribute('aria-hidden', shouldOpen || !isMobile() ? 'false' : 'true');
      backdrop.hidden = !shouldOpen;
      if (main && 'inert' in main) {
        main.inert = shouldOpen;
      }
      if (shouldOpen) {
        sidebar.setAttribute('role', 'dialog');
        sidebar.setAttribute('aria-modal', 'true');
        returnFocus = document.activeElement;
        const firstTarget = sidebar.querySelector('a, button, summary');
        if (firstTarget) {
          window.setTimeout(function () { firstTarget.focus(); }, 0);
        }
      } else {
        sidebar.removeAttribute('role');
        sidebar.removeAttribute('aria-modal');
        if (returnFocus && typeof returnFocus.focus === 'function') {
          returnFocus.focus();
          returnFocus = null;
        }
      }
    }

    openButton.addEventListener('click', function () {
      setOpen(!body.classList.contains('admin-nav-open'));
    });

    closeControls.forEach(function (control) {
      control.addEventListener('click', function () {
        setOpen(false);
      });
    });

    sidebar.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        if (isMobile()) {
          setOpen(false);
        }
      });
    });

    document.addEventListener('keydown', function (event) {
      if (!body.classList.contains('admin-nav-open')) {
        return;
      }
      if (event.key === 'Escape') {
        setOpen(false);
        return;
      }
      if (event.key !== 'Tab') {
        return;
      }
      const focusable = Array.prototype.slice.call(sidebar.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')).filter(function (element) {
        return element.offsetParent !== null;
      });
      if (!focusable.length) {
        return;
      }
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    if (mobileQuery && typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', function () {
        if (!mobileQuery.matches) {
          setOpen(false);
          sidebar.setAttribute('aria-hidden', 'false');
        } else if (!body.classList.contains('admin-nav-open')) {
          sidebar.setAttribute('aria-hidden', 'true');
        }
      });
    }

    sidebar.setAttribute('aria-hidden', isMobile() ? 'true' : 'false');
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

  attachAdminNavigation();

  document.addEventListener('DOMContentLoaded', function () {
    attachSelectAllControls();
    attachMediaSelectAllControls();
    attachMediaDetailsDialog();
    attachContentActionMenus();
    attachPlaceCurrentLocationControl();
    runScheduledPostsHeartbeat();
  });
}());
