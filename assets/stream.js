(function () {
  function onReady(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback);
      return;
    }
    callback();
  }


  function isPreviewMode() {
    return document.body && (document.body.classList.contains('bonumark-preview-mode') || document.body.classList.contains('is-preview-mode'));
  }

  function setupMenu() {
    var navToggle = document.querySelector('[data-stream-menu-toggle], .site-nav-toggle');
    var siteNav = document.getElementById('site-primary-nav');

    if (!navToggle || !siteNav) {
      return;
    }

    document.body.classList.add('nav-enhanced');

    function setMenuState(isOpen) {
      siteNav.classList.toggle('is-open', isOpen);
      navToggle.classList.toggle('is-active', isOpen);
      document.body.classList.toggle('stream-menu-open', isOpen);
      navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      navToggle.setAttribute('aria-label', isOpen ? 'Close menu' : 'Open menu');
    }

    navToggle.addEventListener('click', function (event) {
      event.stopPropagation();
      setMenuState(!siteNav.classList.contains('is-open'));
    });

    document.addEventListener('click', function (event) {
      if (!siteNav.classList.contains('is-open')) {
        return;
      }
      if (navToggle.contains(event.target) || siteNav.contains(event.target)) {
        return;
      }
      setMenuState(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !siteNav.classList.contains('is-open')) {
        return;
      }
      setMenuState(false);
      navToggle.focus();
    });
  }

  function setupLocalPlaces(root) {
    var scope = root || document;
    var pickers = scope.querySelectorAll('[data-local-places]');

    pickers.forEach(function (picker) {
      if (picker.dataset.localPlacesInitialized === '1') {
        return;
      }
      picker.dataset.localPlacesInitialized = '1';

      var form = picker.closest('form');
      if (!form) {
        return;
      }
      var toggle = form.querySelector('[data-local-places-toggle]');
      var placeId = picker.querySelector('[data-place-id]');
      var modeInput = picker.querySelector('[data-place-display-mode]');
      var select = picker.querySelector('[data-place-select]');
      var pickerBody = picker.querySelector('[data-place-picker-body]');
      var selected = picker.querySelector('[data-place-selected]');
      var selectedPrimary = picker.querySelector('[data-place-selected-primary]');
      var selectedSecondary = picker.querySelector('[data-place-selected-secondary]');
      var change = picker.querySelector('[data-place-change]');
      var remove = picker.querySelector('[data-place-remove]');
      var nearbyButton = picker.querySelector('[data-place-nearby]');
      var nearbyResults = picker.querySelector('[data-place-nearby-results]');
      var status = picker.querySelector('[data-place-status]');
      var createOpen = picker.querySelector('[data-place-create-open]');
      var createModal = picker.querySelector('[data-place-create-modal]');
      var createCloseButtons = picker.querySelectorAll('[data-place-create-close]');
      var saveButton = picker.querySelector('[data-place-save]');
      var createStatus = picker.querySelector('[data-place-create-status]');
      var newName = picker.querySelector('[data-place-new-name]');
      var newPublicLabel = picker.querySelector('[data-place-new-public-label]');
      var newLatitude = picker.querySelector('[data-place-new-latitude]');
      var newLongitude = picker.querySelector('[data-place-new-longitude]');
      var nearbyEndpoint = picker.getAttribute('data-nearby-endpoint') || '';
      var saveEndpoint = picker.getAttribute('data-save-endpoint') || '';
      var lastFocused = null;

      function csrfToken() {
        var token = form.querySelector('input[name="csrf_token"]');
        return token ? token.value || '' : '';
      }

      function setStatus(message, isError) {
        if (!status) {
          return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
      }

      function setCreateStatus(message, isError) {
        if (!createStatus) {
          return;
        }
        createStatus.textContent = message || '';
        createStatus.classList.toggle('is-error', !!isError);
      }

      function locationLine(place) {
        var parts = [];
        if (place.locality) parts.push(place.locality);
        if (place.region && parts.indexOf(place.region) === -1) parts.push(place.region);
        if (!parts.length && place.country) parts.push(place.country);
        return parts.join(', ');
      }

      function labelsFor(place, mode) {
        mode = mode || place.default_display_mode || 'exact';
        var primary = '';
        var secondary = '';
        if (mode === 'city') {
          primary = place.locality || place.region || place.country || place.name || '';
          secondary = place.locality && place.region ? place.region : ((place.country || '') !== primary ? (place.country || '') : '');
        } else if (mode === 'area') {
          primary = place.area_label || place.locality || place.name || '';
          var parts = [];
          if (place.locality && place.locality !== primary) parts.push(place.locality);
          if (place.region && parts.indexOf(place.region) === -1) parts.push(place.region);
          secondary = parts.join(', ');
          if (!secondary && place.country && place.country !== primary) secondary = place.country;
        } else {
          primary = place.name || '';
          secondary = locationLine(place);
        }
        return { primary: primary, secondary: secondary, mode: mode };
      }

      function optionToPlace(option) {
        if (!option || !option.value) {
          return null;
        }
        return {
          id: parseInt(option.value, 10) || 0,
          name: option.getAttribute('data-place-name') || '',
          area_label: option.getAttribute('data-place-area') || '',
          locality: option.getAttribute('data-place-locality') || '',
          region: option.getAttribute('data-place-region') || '',
          country: option.getAttribute('data-place-country') || '',
          default_display_mode: option.getAttribute('data-place-mode') || 'exact'
        };
      }

      function ensureOption(place) {
        if (!select || !place || !place.id) {
          return null;
        }
        var option = select.querySelector('option[value="' + String(place.id) + '"]');
        if (!option) {
          option = document.createElement('option');
          option.value = String(place.id);
          select.appendChild(option);
        }
        option.setAttribute('data-place-name', place.name || '');
        option.setAttribute('data-place-area', place.area_label || '');
        option.setAttribute('data-place-locality', place.locality || '');
        option.setAttribute('data-place-region', place.region || '');
        option.setAttribute('data-place-country', place.country || '');
        option.setAttribute('data-place-mode', place.default_display_mode || 'exact');
        var labels = labelsFor(place, place.default_display_mode || 'exact');
        option.textContent = labels.primary + (labels.secondary ? ' · ' + labels.secondary : '');
        return option;
      }

      function closeCreateModal() {
        if (!createModal || createModal.hidden) {
          return;
        }
        createModal.hidden = true;
        document.body.classList.remove('local-places-modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') {
          lastFocused.focus();
        }
      }

      function openCreateModal() {
        if (!createModal) {
          return;
        }
        lastFocused = document.activeElement;
        createModal.hidden = false;
        setCreateStatus('', false);
        document.body.classList.add('local-places-modal-open');
        window.setTimeout(function () {
          if (newName) newName.focus();
        }, 0);
      }

      function setSelected(place, mode) {
        if (!place || !place.id) {
          clearSelected();
          return;
        }
        mode = mode || place.default_display_mode || 'exact';
        if (placeId) placeId.value = String(place.id);
        if (modeInput) modeInput.value = mode;
        var labels = labelsFor(place, mode);
        if (selectedPrimary) selectedPrimary.textContent = labels.primary;
        if (selectedSecondary) selectedSecondary.textContent = labels.secondary;
        if (selected) selected.classList.add('is-selected');
        if (change) change.hidden = false;
        if (remove) remove.hidden = false;
        if (pickerBody) pickerBody.hidden = true;
        var option = ensureOption(place);
        if (select && option) select.value = String(place.id);
        if (toggle) {
          toggle.classList.add('is-active');
          toggle.setAttribute('aria-label', 'Change location');
          toggle.setAttribute('title', 'Change location');
        }
        closeCreateModal();
      }

      function clearSelected() {
        if (placeId) placeId.value = '';
        if (modeInput) modeInput.value = 'exact';
        if (select) select.value = '';
        if (selectedPrimary) selectedPrimary.textContent = '';
        if (selectedSecondary) selectedSecondary.textContent = '';
        if (selected) selected.classList.remove('is-selected');
        if (change) change.hidden = true;
        if (remove) remove.hidden = true;
        if (pickerBody) pickerBody.hidden = false;
        if (toggle) {
          toggle.classList.remove('is-active');
          toggle.setAttribute('aria-label', 'Add location');
          toggle.setAttribute('title', 'Add location');
        }
      }

      function requestPosition(callback, forCreate) {
        var notify = forCreate ? setCreateStatus : setStatus;
        if (!navigator.geolocation) {
          notify('This browser does not provide location access. You can still choose a saved place.', true);
          return;
        }
        notify('Finding your current location…', false);
        navigator.geolocation.getCurrentPosition(function (position) {
          var latitude = Number(position.coords.latitude);
          var longitude = Number(position.coords.longitude);
          var accuracy = Number(position.coords.accuracy || 0);
          if (newLatitude) newLatitude.value = latitude.toFixed(7);
          if (newLongitude) newLongitude.value = longitude.toFixed(7);
          callback(latitude, longitude, accuracy);
        }, function () {
          notify('Location permission was denied or the device could not provide a location.', true);
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
      }

      function renderNearby(places) {
        if (!nearbyResults) {
          return;
        }
        nearbyResults.innerHTML = '';
        if (!places || !places.length) {
          nearbyResults.hidden = true;
          setStatus('No saved places were found nearby. Add a new place if needed.', false);
          return;
        }
        var heading = document.createElement('strong');
        heading.textContent = 'Nearby saved places';
        nearbyResults.appendChild(heading);
        places.forEach(function (place) {
          var button = document.createElement('button');
          button.type = 'button';
          button.className = 'local-places-nearby-result';
          var distance = typeof place.distance_meters === 'number' ? ' · ' + place.distance_meters + ' m' : '';
          button.textContent = (place.name || 'Saved place') + (locationLine(place) ? ' · ' + locationLine(place) : '') + distance;
          button.addEventListener('click', function () {
            setSelected(place, place.default_display_mode || 'exact');
            setStatus('Location attached to this post.', false);
          });
          nearbyResults.appendChild(button);
        });
        nearbyResults.hidden = false;
        setStatus('Choose a nearby saved place.', false);
      }

      function findNearby() {
        if (!nearbyEndpoint || !window.fetch) {
          setStatus('Nearby search is unavailable in this browser. You can still choose a saved place.', true);
          return;
        }
        if (nearbyButton) nearbyButton.disabled = true;
        requestPosition(function (latitude, longitude, accuracy) {
          var body = new URLSearchParams();
          body.set('csrf_token', csrfToken());
          body.set('latitude', String(latitude));
          body.set('longitude', String(longitude));
          body.set('accuracy', String(accuracy));
          window.fetch(nearbyEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
          }).then(function (response) {
            return response.json().then(function (json) {
              if (!response.ok || !json || json.ok !== true) {
                throw new Error(json && json.message ? json.message : 'Nearby places could not be loaded.');
              }
              return json.places || [];
            });
          }).then(renderNearby).catch(function (error) {
            setStatus(error && error.message ? error.message : 'Nearby places could not be loaded.', true);
          }).finally(function () {
            if (nearbyButton) nearbyButton.disabled = false;
          });
        });
      }

      function saveCurrentPlace() {
        if (!saveEndpoint || !window.fetch) {
          setStatus('Saving places is unavailable in this browser.', true);
          return;
        }
        var saveWithCoordinates = function (latitude, longitude) {
          var name = newName ? newName.value.trim() : '';
          var publicLabel = newPublicLabel ? newPublicLabel.value.trim() : '';
          if (!name) {
            setCreateStatus('Enter a place name before saving.', true);
            if (newName) newName.focus();
            return;
          }
          if (saveButton) saveButton.disabled = true;
          setCreateStatus('Saving this place locally…', false);
          var body = new URLSearchParams();
          body.set('csrf_token', csrfToken());
          body.set('name', name);
          body.set('category', 'other');
          body.set('area_label', '');
          body.set('locality', publicLabel);
          body.set('region', '');
          body.set('country', '');
          body.set('default_display_mode', 'exact');
          body.set('latitude', String(latitude));
          body.set('longitude', String(longitude));
          window.fetch(saveEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString()
          }).then(function (response) {
            return response.json().then(function (json) {
              if (!response.ok || !json || json.ok !== true) {
                throw new Error(json && json.message ? json.message : 'The place could not be saved.');
              }
              return json.place;
            });
          }).then(function (place) {
            setSelected(place, place.default_display_mode || 'exact');
            setStatus('Place saved locally and attached to this post.', false);
            if (newName) newName.value = '';
            if (newPublicLabel) newPublicLabel.value = '';
          }).catch(function (error) {
            setCreateStatus(error && error.message ? error.message : 'The place could not be saved.', true);
          }).finally(function () {
            if (saveButton) saveButton.disabled = false;
          });
        };

        var latitude = newLatitude && newLatitude.value ? parseFloat(newLatitude.value) : NaN;
        var longitude = newLongitude && newLongitude.value ? parseFloat(newLongitude.value) : NaN;
        if (!isNaN(latitude) && !isNaN(longitude)) {
          saveWithCoordinates(latitude, longitude);
          return;
        }
        requestPosition(function (lat, lng) {
          saveWithCoordinates(lat, lng);
        }, true);
      }

      if (toggle) {
        toggle.addEventListener('click', function (event) {
          event.preventDefault();
          picker.hidden = !picker.hidden;
          toggle.setAttribute('aria-expanded', picker.hidden ? 'false' : 'true');
          if (!picker.hidden && !placeId.value && select) select.focus();
        });
      }
      if (select) {
        select.addEventListener('change', function () {
          var place = optionToPlace(select.options[select.selectedIndex]);
          if (!place) {
            clearSelected();
            return;
          }
          setSelected(place, place.default_display_mode || 'exact');
          setStatus('Location attached to this post.', false);
        });
      }
      if (change) {
        change.addEventListener('click', function () {
          if (pickerBody) pickerBody.hidden = false;
          if (select) select.focus();
        });
      }
      if (remove) {
        remove.addEventListener('click', function () {
          clearSelected();
          setStatus('Location removed from this post.', false);
        });
      }
      if (nearbyButton) nearbyButton.addEventListener('click', findNearby);
      if (createOpen) createOpen.addEventListener('click', openCreateModal);
      createCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeCreateModal);
      });
      if (saveButton) saveButton.addEventListener('click', saveCurrentPlace);
      if (createModal) {
        createModal.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' && event.target && event.target.tagName === 'INPUT') {
            event.preventDefault();
            saveCurrentPlace();
          }
        });
      }
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && createModal && !createModal.hidden) {
          closeCreateModal();
        }
      });
    });
  }

  function setupComposer(root) {
    var scope = root || document;
    var forms = scope.querySelectorAll('[data-stream-form]');

    forms.forEach(function (form) {
      if (form.dataset.streamInitialized === '1') {
        return;
      }
      form.dataset.streamInitialized = '1';
      setupLocalPlaces(form);

      var input = form.querySelector('[data-stream-file]');
      var preview = form.querySelector('[data-stream-preview]');
      var textarea = form.querySelector('[data-stream-body]');
      var counter = form.querySelector('[data-stream-counter]');
      var submit = form.querySelector('[data-stream-submit]');
      var submitButtons = form.querySelectorAll('[data-stream-action]');
      var scheduleToggle = form.querySelector('[data-stream-schedule-toggle]');
      var schedulePanel = form.querySelector('[data-stream-schedule-panel]');
      var scheduleInput = form.querySelector('[data-stream-scheduled-at]');
      var scheduleCancel = form.querySelector('[data-stream-schedule-cancel]');
      var submitActionInput = form.querySelector('[data-stream-submit-action]');
      var scheduleEnabledInput = form.querySelector('[data-stream-schedule-enabled]');
      var scheduledRunnerUrl = form.getAttribute('data-stream-scheduled-runner-url') || '';
      var scheduledRunnerCsrfInput = form.querySelector('input[name="csrf_token"]');
      var linkPreview = form.querySelector('[data-link-preview]');
      var linkPreviewEnabled = form.querySelector('[data-link-preview-enabled]');
      var linkPreviewFields = form.querySelectorAll('[data-link-preview-field]');
      var linkPreviewEndpoint = linkPreview ? linkPreview.getAttribute('data-link-preview-endpoint') || '' : '';
      var linkPreviewTimer = null;
      var linkPreviewLastUrl = '';
      var linkPreviewRemovedUrl = '';
      var linkPreviewRequestId = 0;
      var moreMenu = form.querySelector('[data-stream-more-menu]');
      var advancedToggle = form.querySelector('[data-stream-advanced-toggle]');
      var advancedPanel = form.querySelector('[data-stream-advanced-panel]');
      var advancedClose = form.querySelector('[data-stream-advanced-close]');

      function setAdvancedOpen(isOpen, shouldFocus) {
        if (!advancedPanel) {
          return;
        }
        advancedPanel.hidden = !isOpen;
        form.classList.toggle('has-advanced-options', isOpen);
        if (advancedToggle) {
          advancedToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
        if (moreMenu) {
          moreMenu.open = false;
        }
        if (shouldFocus) {
          if (isOpen) {
            var firstField = advancedPanel.querySelector('input, textarea, select');
            if (firstField) {
              firstField.focus();
            }
          } else if (textarea) {
            textarea.focus();
          }
        }
      }

      if (advancedToggle && advancedPanel) {
        advancedToggle.addEventListener('click', function (event) {
          event.preventDefault();
          setAdvancedOpen(advancedPanel.hidden, true);
        });
      }

      if (advancedClose && advancedPanel) {
        advancedClose.addEventListener('click', function (event) {
          event.preventDefault();
          setAdvancedOpen(false, true);
        });
      }

      document.addEventListener('click', function (event) {
        if (moreMenu && moreMenu.open && !moreMenu.contains(event.target)) {
          moreMenu.open = false;
        }
      });

      form.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          if (moreMenu && moreMenu.open) {
            moreMenu.open = false;
            var moreSummary = moreMenu.querySelector('summary');
            if (moreSummary) {
              moreSummary.focus();
            }
            return;
          }
          if (advancedPanel && !advancedPanel.hidden) {
            setAdvancedOpen(false, true);
          }
        }
      });

      function startScheduledRunnerHeartbeat() {
        if (!scheduledRunnerUrl || !scheduledRunnerCsrfInput || !scheduledRunnerCsrfInput.value || !window.fetch) {
          return;
        }
        var busy = false;
        var ping = function () {
          if (busy) {
            return;
          }
          busy = true;
          var payload = new URLSearchParams();
          payload.set('csrf_token', scheduledRunnerCsrfInput.value);
          window.fetch(scheduledRunnerUrl, {
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

      function updateCounter() {
        if (!textarea || !counter) {
          return;
        }
        var length = textarea.value.length;
        counter.textContent = length.toLocaleString() + ' / 5,000';
        counter.classList.toggle('near-limit', length >= 4500);
      }

      function autoGrow() {
        if (!textarea) {
          return;
        }
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 260) + 'px';
      }

      var selectedFiles = [];
      var previewObjectUrls = [];

      function revokePreviewObjectUrls() {
        if (!window.URL || !window.URL.revokeObjectURL) {
          previewObjectUrls = [];
          return;
        }
        previewObjectUrls.forEach(function (objectUrl) {
          window.URL.revokeObjectURL(objectUrl);
        });
        previewObjectUrls = [];
      }

      function clearPreview() {
        revokePreviewObjectUrls();
        selectedFiles = [];
        if (input) {
          input.value = '';
        }
        if (!preview) {
          return;
        }
        preview.innerHTML = '';
        preview.hidden = false;
        preview.classList.remove('is-visible', 'has-error');
      }

      function typeLabel(file) {
        var type = file && file.type ? file.type : '';
        if (type.indexOf('image/') === 0) {
          return 'Photo';
        }
        if (type.indexOf('video/') === 0) {
          return 'Video attachment';
        }
        if (type.indexOf('audio/') === 0) {
          return 'Audio attachment';
        }
        return 'Document attachment';
      }

      function syncInputFiles() {
        if (!input || typeof DataTransfer === 'undefined') {
          return false;
        }
        var transfer = new DataTransfer();
        selectedFiles.forEach(function (file) {
          transfer.items.add(file);
        });
        input.files = transfer.files;
        return true;
      }

      function previewMessage(message, isError) {
        revokePreviewObjectUrls();
        if (!preview) {
          return;
        }
        preview.innerHTML = '';
        var note = document.createElement('p');
        note.className = 'stream-compose-preview-message';
        note.textContent = message;
        preview.appendChild(note);
        preview.hidden = false;
        preview.classList.add('is-visible');
        preview.classList.toggle('has-error', !!isError);
      }

      function removeSelectedFile(index) {
        selectedFiles.splice(index, 1);
        if (!syncInputFiles()) {
          selectedFiles = [];
          if (input) input.value = '';
        }
        renderSelectedFiles();
      }

      function moveSelectedFile(index, direction) {
        var target = index + direction;
        if (target < 0 || target >= selectedFiles.length) {
          return;
        }
        var moved = selectedFiles.splice(index, 1)[0];
        selectedFiles.splice(target, 0, moved);
        syncInputFiles();
        renderSelectedFiles();
      }

      function buildPreviewNode(file, index) {
        var wrapper = document.createElement('div');
        wrapper.className = 'stream-compose-preview-item';

        if (file && file.type && file.type.indexOf('image/') === 0 && window.URL && window.URL.createObjectURL) {
          var thumb = document.createElement('div');
          thumb.className = 'stream-compose-preview-thumb';
          var img = document.createElement('img');
          img.alt = '';
          var objectUrl = window.URL.createObjectURL(file);
          previewObjectUrls.push(objectUrl);
          img.src = objectUrl;
          thumb.appendChild(img);
          wrapper.appendChild(thumb);
        } else {
          var icon = document.createElement('div');
          icon.className = 'stream-compose-preview-icon';
          icon.setAttribute('aria-hidden', 'true');
          icon.textContent = '📎';
          wrapper.appendChild(icon);
        }

        var metaWrap = document.createElement('div');
        metaWrap.className = 'stream-compose-preview-meta';
        var name = document.createElement('div');
        name.className = 'stream-compose-preview-name';
        name.textContent = file && file.name ? file.name : 'Attached media';
        metaWrap.appendChild(name);
        var meta = document.createElement('div');
        meta.className = 'stream-compose-preview-type';
        meta.textContent = typeLabel(file) + (selectedFiles.length > 1 ? ' ' + (index + 1) + ' of ' + selectedFiles.length : '');
        metaWrap.appendChild(meta);

        var actions = document.createElement('div');
        actions.className = 'stream-compose-preview-actions';
        if (selectedFiles.length > 1) {
          var left = document.createElement('button');
          left.type = 'button';
          left.className = 'stream-compose-preview-move';
          left.textContent = '←';
          left.disabled = index === 0;
          left.setAttribute('aria-label', 'Move photo left');
          left.addEventListener('click', function (event) {
            event.preventDefault();
            moveSelectedFile(index, -1);
          });
          actions.appendChild(left);

          var right = document.createElement('button');
          right.type = 'button';
          right.className = 'stream-compose-preview-move';
          right.textContent = '→';
          right.disabled = index === selectedFiles.length - 1;
          right.setAttribute('aria-label', 'Move photo right');
          right.addEventListener('click', function (event) {
            event.preventDefault();
            moveSelectedFile(index, 1);
          });
          actions.appendChild(right);
        }

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'stream-compose-preview-remove';
        remove.textContent = 'Remove';
        remove.setAttribute('aria-label', 'Remove ' + (file && file.name ? file.name : 'attachment'));
        remove.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          removeSelectedFile(index);
          if (input) input.focus();
        });
        actions.appendChild(remove);
        metaWrap.appendChild(actions);
        wrapper.appendChild(metaWrap);
        return wrapper;
      }

      function renderSelectedFiles() {
        revokePreviewObjectUrls();
        if (!preview) {
          return;
        }
        preview.innerHTML = '';
        preview.classList.remove('has-error');
        if (!selectedFiles.length) {
          preview.classList.remove('is-visible');
          return;
        }
        var wrapper = document.createElement('div');
        wrapper.className = 'stream-compose-preview-inner' + (selectedFiles.length > 1 ? ' is-gallery' : '');
        selectedFiles.forEach(function (file, index) {
          wrapper.appendChild(buildPreviewNode(file, index));
        });
        preview.appendChild(wrapper);
        preview.hidden = false;
        preview.classList.add('is-visible');
      }

      function setSelectedFiles(files) {
        var maxFiles = input ? parseInt(input.getAttribute('data-stream-max-files') || '4', 10) : 4;
        maxFiles = Math.max(1, Math.min(4, isNaN(maxFiles) ? 4 : maxFiles));
        var next = Array.prototype.slice.call(files || []);
        if (next.length > maxFiles) {
          selectedFiles = [];
          if (input) input.value = '';
          previewMessage('Choose no more than ' + maxFiles + ' photos.', true);
          return;
        }
        if (next.length > 1 && next.some(function (file) { return !file.type || file.type.indexOf('image/') !== 0; })) {
          selectedFiles = [];
          if (input) input.value = '';
          previewMessage('Multiple attachments must all be photos. Choose one file for audio, video, or documents.', true);
          return;
        }
        selectedFiles = next;
        syncInputFiles();
        renderSelectedFiles();
      }

      function firstUrl(text) {
        var match = String(text || '').match(/\bhttps?:\/\/[^\s<>()\[\]{}"']+/i);
        if (!match) {
          return '';
        }
        return match[0].replace(/[\.,;:!?]+$/g, '');
      }

      function setLinkPreviewFields(previewData, enabled) {
        if (linkPreviewEnabled) {
          linkPreviewEnabled.value = enabled ? '1' : '0';
        }
        var data = previewData || {};
        linkPreviewFields.forEach(function (field) {
          var key = field.getAttribute('data-link-preview-field') || '';
          field.value = enabled ? String(data[key] || '') : '';
        });
      }

      function clearLinkPreview(rememberUrl) {
        if (linkPreview) {
          linkPreview.innerHTML = '';
          linkPreview.hidden = true;
          linkPreview.classList.remove('is-visible', 'is-loading', 'has-error');
        }
        setLinkPreviewFields({}, false);
        if (rememberUrl && linkPreviewLastUrl) {
          linkPreviewRemovedUrl = linkPreviewLastUrl;
        }
      }

      function buildLinkPreviewNode(data) {
        var wrapper = document.createElement('div');
        wrapper.className = 'stream-compose-link-preview-inner';

        var dismiss = document.createElement('button');
        dismiss.type = 'button';
        dismiss.className = 'stream-compose-link-preview-dismiss';
        dismiss.textContent = '×';
        dismiss.setAttribute('aria-label', 'Remove link preview and post as a plain link');
        dismiss.setAttribute('title', 'Post as link only');
        dismiss.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          clearLinkPreview(true);
          if (textarea) {
            textarea.focus();
          }
        });
        wrapper.appendChild(dismiss);

        if (data.image) {
          wrapper.classList.add('has-image');
          var media = document.createElement('div');
          media.className = 'stream-compose-link-preview-image';
          var img = document.createElement('img');
          img.alt = '';
          img.src = data.image;
          img.addEventListener('error', function () {
            media.remove();
            wrapper.classList.remove('has-image');
            wrapper.classList.add('no-image');
          });
          media.appendChild(img);
          wrapper.appendChild(media);
        } else {
          wrapper.classList.add('no-image');
        }

        var body = document.createElement('div');
        body.className = 'stream-compose-link-preview-body';

        if (data.site_name) {
          var site = document.createElement('div');
          site.className = 'stream-compose-link-preview-site';
          site.textContent = data.site_name;
          body.appendChild(site);
        }

        var title = document.createElement('div');
        title.className = 'stream-compose-link-preview-title';
        title.textContent = data.title || data.url || 'Link preview';
        body.appendChild(title);

        if (data.description) {
          var description = document.createElement('div');
          description.className = 'stream-compose-link-preview-description';
          description.textContent = data.description;
          body.appendChild(description);
        }

        wrapper.appendChild(body);
        return wrapper;
      }

      function showLinkPreview(data) {
        if (!linkPreview || !data || !data.url) {
          return;
        }
        linkPreview.innerHTML = '';
        linkPreview.appendChild(buildLinkPreviewNode(data));
        linkPreview.hidden = false;
        linkPreview.classList.add('is-visible');
        linkPreview.classList.remove('is-loading', 'has-error');
        linkPreviewLastUrl = data.url;
        setLinkPreviewFields(data, true);
      }

      function fetchLinkPreview(url) {
        if (!linkPreview || !linkPreviewEndpoint || typeof fetch !== 'function') {
          return;
        }
        if (!url || url === linkPreviewRemovedUrl || url === linkPreviewLastUrl) {
          return;
        }
        var token = form.querySelector('input[name="csrf_token"]');
        var body = new URLSearchParams();
        body.set('url', url);
        if (token) {
          body.set('csrf_token', token.value || '');
        }
        var requestId = ++linkPreviewRequestId;
        linkPreview.hidden = false;
        linkPreview.innerHTML = '<div class="stream-compose-link-preview-loading">Loading link preview…</div>';
        linkPreview.classList.add('is-visible', 'is-loading');
        linkPreview.classList.remove('has-error');
        fetch(linkPreviewEndpoint, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString()
        }).then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok || !json || json.ok !== true) {
              throw new Error(json && json.message ? json.message : 'Preview unavailable.');
            }
            return json.preview || null;
          });
        }).then(function (previewData) {
          if (requestId !== linkPreviewRequestId) {
            return;
          }
          showLinkPreview(previewData);
        }).catch(function () {
          if (requestId !== linkPreviewRequestId) {
            return;
          }
          clearLinkPreview(false);
        });
      }

      function scheduleLinkPreview() {
        if (!textarea || !linkPreview) {
          return;
        }
        var url = firstUrl(textarea.value);
        if (!url) {
          linkPreviewLastUrl = '';
          linkPreviewRemovedUrl = '';
          clearLinkPreview(false);
          return;
        }
        if (url === linkPreviewRemovedUrl || url === linkPreviewLastUrl) {
          return;
        }
        if (linkPreviewTimer) {
          window.clearTimeout(linkPreviewTimer);
        }
        linkPreviewTimer = window.setTimeout(function () {
          fetchLinkPreview(url);
        }, 550);
      }

      function scheduleSubmitLabel(isScheduling) {
        if (!submit) {
          return;
        }
        var readyLabel = isScheduling
          ? (submit.getAttribute('data-schedule-label') || 'Schedule')
          : (submit.getAttribute('data-publish-label') || submit.getAttribute('data-ready-label') || 'Post');
        var busyLabel = isScheduling
          ? (submit.getAttribute('data-schedule-busy-label') || 'Scheduling...')
          : (submit.getAttribute('data-publish-busy-label') || submit.getAttribute('data-busy-label') || 'Posting...');
        if (submitActionInput) {
          submitActionInput.value = isScheduling ? 'schedule' : 'publish';
        }
        if (scheduleEnabledInput) {
          scheduleEnabledInput.value = isScheduling ? '1' : '0';
        }
        submit.textContent = readyLabel;
        submit.setAttribute('data-ready-label', readyLabel);
        submit.setAttribute('data-busy-label', busyLabel);
      }

      function setScheduleActive(isActive, shouldFocus) {
        form.classList.toggle('is-scheduling', isActive);
        if (schedulePanel) {
          schedulePanel.hidden = !isActive;
        }
        if (scheduleToggle) {
          scheduleToggle.classList.toggle('is-active', isActive);
          scheduleToggle.setAttribute('aria-expanded', isActive ? 'true' : 'false');
          scheduleToggle.setAttribute('aria-label', isActive ? 'Cancel scheduling' : 'Schedule post');
          scheduleToggle.setAttribute('title', isActive ? 'Cancel scheduling' : 'Schedule post');
        }
        if (scheduleInput) {
          if (isActive) {
            scheduleInput.setAttribute('required', 'required');
          } else {
            scheduleInput.removeAttribute('required');
            scheduleInput.value = '';
          }
        }
        scheduleSubmitLabel(isActive);
        if (isActive && shouldFocus && scheduleInput) {
          scheduleInput.focus();
        } else if (!isActive && shouldFocus && textarea) {
          textarea.focus();
        }
      }

      if (scheduleToggle && schedulePanel) {
        scheduleToggle.addEventListener('click', function (event) {
          event.preventDefault();
          setScheduleActive(!form.classList.contains('is-scheduling'), true);
        });
      }

      if (scheduleCancel) {
        scheduleCancel.addEventListener('click', function (event) {
          event.preventDefault();
          setScheduleActive(false, true);
        });
      }

      setScheduleActive(false, false);
      startScheduledRunnerHeartbeat();

      if (textarea) {
        textarea.addEventListener('input', function () {
          updateCounter();
          autoGrow();
          scheduleLinkPreview();
        });
        updateCounter();
        autoGrow();
        scheduleLinkPreview();
      }

      if (submitButtons.length) {
        form.addEventListener('submit', function (event) {
          var isScheduling = form.classList.contains('is-scheduling');
          var activeSubmit = event.submitter && event.submitter.getAttribute ? event.submitter : submit;
          if (!activeSubmit || !activeSubmit.getAttribute) {
            activeSubmit = submit;
          }
          var requestedAction = activeSubmit ? (activeSubmit.getAttribute('data-stream-action') || '') : '';
          if (activeSubmit && activeSubmit.hasAttribute('data-stream-primary-submit') && isScheduling) {
            requestedAction = 'schedule';
          }
          if (!requestedAction) {
            requestedAction = isScheduling ? 'schedule' : 'publish';
          }
          if (submitActionInput) {
            submitActionInput.value = requestedAction;
          }
          if (scheduleEnabledInput) {
            scheduleEnabledInput.value = requestedAction === 'schedule' ? '1' : '0';
          }
          if (requestedAction === 'schedule' && scheduleInput && !scheduleInput.value) {
            return;
          }
          if (activeSubmit) {
            activeSubmit.disabled = true;
            activeSubmit.textContent = activeSubmit.getAttribute('data-busy-label') || (requestedAction === 'schedule' ? 'Scheduling...' : (requestedAction === 'continue' ? 'Opening editor...' : 'Saving...'));
            activeSubmit.classList.add('is-busy');
          }
        });
      }

      if (input && preview) {
        input.addEventListener('change', function () {
          setSelectedFiles(input.files || []);
        });
      }


      if (window.location && /(?:^|[?&])compose=1(?:&|$)/.test(window.location.search || '')) {
        window.setTimeout(function () {
          if (form.scrollIntoView) {
            try {
              form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } catch (error) {
              form.scrollIntoView();
            }
          }
          if (textarea) {
            textarea.focus();
          }
        }, 80);
      }
    });
  }

  function loadComposerMounts() {
    var mounts = document.querySelectorAll('[data-stream-composer-mount]');
    if (!mounts.length || typeof fetch !== 'function') {
      return;
    }

    mounts.forEach(function (mount) {
      if (mount.dataset.streamComposerLoaded === '1') {
        return;
      }

      var endpoint = mount.getAttribute('data-stream-composer-endpoint') || '';
      if (!endpoint) {
        return;
      }

      mount.dataset.streamComposerLoaded = '1';
      var returnTo = window.location.pathname + window.location.search;
      var separator = endpoint.indexOf('?') === -1 ? '?' : '&';
      var url = endpoint + separator + 'return_to=' + encodeURIComponent(returnTo || '/');

      fetch(url, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (response) {
        if (response.status === 204 || !response.ok) {
          return '';
        }
        return response.text();
      }).then(function (html) {
        if (!html || !html.trim()) {
          return;
        }
        mount.innerHTML = html;
        setupComposer(mount);
        setupLikes(mount);
        setupLinkPreviewImages(mount);
      }).catch(function () {
        mount.dataset.streamComposerLoaded = '0';
      });
    });
  }


  var knownLikeEndpoint = '';

  function parseLikeCount(text) {
    var match = String(text || '').match(/([\d,]+)/);
    if (!match || !match[1]) {
      return 0;
    }
    var value = parseInt(match[1].replace(/,/g, ''), 10);
    return isNaN(value) ? 0 : value;
  }

  function streamAssetBase() {
    var streamScript = null;
    var scripts = document.querySelectorAll('script[src]');

    scripts.forEach(function (script) {
      var src = script.getAttribute('src') || '';
      if (!streamScript && src.indexOf('assets/stream.js') !== -1) {
        streamScript = src;
      }
    });

    if (!streamScript) {
      var stylesheet = document.querySelector('link[href*="assets/stream.css"], link[href*="assets/style.css"]');
      streamScript = stylesheet ? stylesheet.getAttribute('href') || '' : '';
    }

    if (streamScript) {
      try {
        var url = new URL(streamScript, window.location.href);
        url.search = '';
        url.hash = '';
        return url.href.replace(/assets\/(stream\.js|stream\.css|style\.css)$/i, '');
      } catch (error) {
        return '';
      }
    }

    return '';
  }

  function addUniqueEndpoint(list, endpoint) {
    endpoint = String(endpoint || '').trim();
    if (!endpoint) {
      return;
    }

    try {
      endpoint = new URL(endpoint, window.location.href).href;
    } catch (error) {
      return;
    }

    if (list.indexOf(endpoint) === -1) {
      list.push(endpoint);
    }
  }

  function likeEndpointCandidates(button) {
    var list = [];
    var assetBase = streamAssetBase();

    addUniqueEndpoint(list, knownLikeEndpoint);

    if (button) {
      addUniqueEndpoint(list, button.getAttribute('data-like-endpoint'));
      addUniqueEndpoint(list, button.getAttribute('data-like-endpoint-alt'));
    }

    if (assetBase) {
      addUniqueEndpoint(list, new URL('stream-like.php', assetBase).href);
      addUniqueEndpoint(list, new URL('admin/stream-like.php', assetBase).href);
    }

    addUniqueEndpoint(list, '/stream-like.php');
    addUniqueEndpoint(list, '/admin/stream-like.php');

    return list;
  }

  function endpointUrl(endpoint, params) {
    var url = new URL(endpoint, window.location.href);
    if (params) {
      Object.keys(params).forEach(function (key) {
        url.searchParams.set(key, params[key]);
      });
    }
    return url.href;
  }

  function jsonFetch(url, options) {
    options = options || {};
    options.credentials = 'same-origin';
    options.cache = 'no-store';
    options.headers = options.headers || {};
    options.headers['X-Requested-With'] = 'XMLHttpRequest';
    options.headers['Accept'] = 'application/json';

    return fetch(url, options).then(function (response) {
      var contentType = response.headers.get('content-type') || '';

      return response.text().then(function (text) {
        var trimmed = String(text || '').trim();
        var first = trimmed.charAt(0);
        var json = null;

        if (!trimmed) {
          var emptyError = new Error('Like endpoint returned an empty response.');
          emptyError.endpointRecoverable = true;
          emptyError.status = response.status;
          throw emptyError;
        }

        if (contentType.indexOf('application/json') === -1 && first !== '{' && first !== '[') {
          var htmlError = new Error('Like endpoint returned HTML instead of JSON.');
          htmlError.endpointRecoverable = true;
          htmlError.status = response.status;
          throw htmlError;
        }

        try {
          json = JSON.parse(trimmed);
        } catch (error) {
          var parseError = new Error('Like endpoint returned invalid JSON.');
          parseError.endpointRecoverable = true;
          parseError.status = response.status;
          throw parseError;
        }

        if (!response.ok || !json || json.ok !== true) {
          var message = json && json.message ? json.message : 'Like failed.';
          var appError = new Error(message);
          appError.status = response.status;
          appError.endpointRecoverable = response.status === 404 || response.status === 405 || response.status >= 500;
          throw appError;
        }

        return json;
      });
    });
  }

  function tryLikeEndpoints(candidates, urlFactory, optionsFactory) {
    var index = 0;
    var lastError = null;

    function next() {
      if (index >= candidates.length) {
        throw lastError || new Error('Like endpoint unavailable.');
      }

      var endpoint = candidates[index++];
      var url = urlFactory(endpoint);
      var options = optionsFactory ? optionsFactory(endpoint) : {};

      return jsonFetch(url, options).then(function (json) {
        knownLikeEndpoint = endpoint;
        return json;
      }).catch(function (error) {
        lastError = error;
        return next();
      });
    }

    return Promise.resolve().then(next);
  }

  function updateLikeButton(button, data) {
    if (!button || !data) {
      return;
    }

    var label = button.querySelector('.stream-like-text');
    var srText = button.querySelector('.stream-like-sr-text');
    var count = typeof data.count === 'number' ? data.count : parseLikeCount(data.label || (label ? label.textContent : ''));
    var liked = !!data.liked;
    var text = data.label || (count.toLocaleString() + ' ' + (count === 1 ? 'like' : 'likes'));
    var actionText = liked ? 'Post liked.' : 'Like this post.';

    button.dataset.likeCount = String(count);
    button.setAttribute('aria-pressed', liked ? 'true' : 'false');
    button.setAttribute('aria-label', actionText + ' ' + text);
    button.classList.toggle('is-liked', liked);
    button.classList.remove('has-like-error');

    if (label) {
      label.textContent = text;
    }
    if (srText) {
      srText.textContent = actionText;
    }
  }

  function cleanLikeErrorMessage(error) {
    var message = error && error.message ? error.message : '';
    if (!message || message.indexOf('Unexpected token') !== -1 || message.indexOf('<!DOCTYPE') !== -1 || message.indexOf('HTML instead of JSON') !== -1 || message.indexOf('invalid JSON') !== -1) {
      return 'Like endpoint unavailable';
    }
    return message;
  }

  function showLikeError(button, message, previousText) {
    var label = button ? button.querySelector('.stream-like-text') : null;
    if (!button || !label) {
      return;
    }

    button.classList.add('has-like-error');
    button.setAttribute('aria-label', message || 'Like failed.');
    label.textContent = message || 'Like failed';

    window.setTimeout(function () {
      if (label) {
        label.textContent = previousText || label.textContent;
      }
      button.classList.remove('has-like-error');
    }, 2200);
  }

  function hydrateLikes(root) {
    var scope = root || document;
    var buttons = Array.prototype.slice.call(scope.querySelectorAll('[data-stream-like]'));
    if (!buttons.length || typeof fetch !== 'function') {
      return;
    }

    var unique = [];
    var seen = {};
    buttons.forEach(function (button) {
      var slug = button.getAttribute('data-like-slug') || '';
      if (!slug || seen[slug]) {
        return;
      }
      seen[slug] = true;
      unique.push(slug);
    });

    if (!unique.length) {
      return;
    }

    var candidates = likeEndpointCandidates(buttons[0]);
    tryLikeEndpoints(candidates, function (endpoint) {
      return endpointUrl(endpoint, {
        slugs: unique.join(','),
        _: Date.now()
      });
    }, function () {
      return { method: 'GET' };
    }).then(function (json) {
      var data = json.data || {};
      buttons.forEach(function (button) {
        var slug = button.getAttribute('data-like-slug') || '';
        if (data[slug]) {
          updateLikeButton(button, data[slug]);
        }
      });
    }).catch(function () {
      // Like status hydration is progressive enhancement. If every endpoint check fails,
      // keep the baked-in count visible and avoid changing the public card layout.
    });
  }

  function setupLikes(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-stream-like]').forEach(function (button) {
      if (button.dataset.likeInitialized === '1') {
        return;
      }
      button.dataset.likeInitialized = '1';

      updateLikeButton(button, {
        liked: button.getAttribute('aria-pressed') === 'true',
        count: parseLikeCount(button.getAttribute('data-like-count') || (button.querySelector('.stream-like-text') || {}).textContent || ''),
        label: (button.querySelector('.stream-like-text') || {}).textContent || ''
      });

      button.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();

        var slug = button.getAttribute('data-like-slug') || '';
        var label = button.querySelector('.stream-like-text');
        if (!slug || button.disabled || typeof fetch !== 'function') {
          return;
        }

        if (button.classList.contains('is-liked') || button.getAttribute('aria-pressed') === 'true') {
          updateLikeButton(button, {
            liked: true,
            count: parseLikeCount(button.getAttribute('data-like-count') || (label ? label.textContent : '')),
            label: label ? label.textContent : ''
          });
          return;
        }

        var previousText = label ? label.textContent : '';
        button.disabled = true;
        button.classList.add('is-busy');
        if (label) {
          label.textContent = 'Liking...';
        }

        var candidates = likeEndpointCandidates(button);

        tryLikeEndpoints(candidates, function (endpoint) {
          return endpoint;
        }, function () {
          var body = new FormData();
          body.append('slug', slug);
          return {
            method: 'POST',
            body: body
          };
        }).then(function (json) {
          updateLikeButton(button, json.data || {});
        }).catch(function (error) {
          showLikeError(button, cleanLikeErrorMessage(error), previousText);
        }).finally(function () {
          button.disabled = false;
          button.classList.remove('is-busy');
        });
      });
    });

    hydrateLikes(scope);
  }

  function setupLoadMore() {
    var paginationContainer = document.querySelector('.pagination-load-more');
    var loadMoreLink = document.querySelector('.pagination-load-more .pagination-older a');
    var feed = document.querySelector('.stream-feed');
    var loadStatus = document.getElementById('stream-load-status');

    if (!paginationContainer || !loadMoreLink || !feed || typeof fetch !== 'function') {
      return;
    }

    var isLoading = false;
    var originalLabel = loadMoreLink.textContent;

    function setStatus(message) {
      if (loadStatus) {
        loadStatus.textContent = message;
      }
    }

    function disableLoadMore(message) {
      loadMoreLink.textContent = message || 'No more posts';
      loadMoreLink.classList.add('is-disabled');
      loadMoreLink.removeAttribute('href');
      loadMoreLink.setAttribute('aria-disabled', 'true');
      setStatus(message || 'No more posts');
    }

    loadMoreLink.addEventListener('click', function (event) {
      event.preventDefault();

      if (isLoading || loadMoreLink.classList.contains('is-disabled')) {
        return;
      }

      var url = loadMoreLink.getAttribute('data-load-more-url') || loadMoreLink.getAttribute('href') || '';
      if (!url) {
        return;
      }

      isLoading = true;
      feed.setAttribute('aria-busy', 'true');
      loadMoreLink.textContent = 'Loading...';
      setStatus('Loading...');

      fetch(url, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Load more request failed.');
          }
          return response.text();
        })
        .then(function (html) {
          var parser = new DOMParser();
          var doc = parser.parseFromString(html, 'text/html');
          var newPosts = Array.prototype.slice.call(doc.querySelectorAll('.stream-feed [data-stream-card], .stream-feed .stream-card'));

          var existingUrls = {};
          Array.prototype.slice.call(feed.querySelectorAll('[data-stream-card]')).forEach(function (post) {
            var url = post.getAttribute('data-stream-url') || '';
            if (url) {
              existingUrls[url] = true;
            }
          });

          newPosts = newPosts.filter(function (post) {
            var url = post.getAttribute('data-stream-url') || '';
            if (!url || !existingUrls[url]) {
              if (url) {
                existingUrls[url] = true;
              }
              return true;
            }
            return false;
          });

          if (!newPosts.length) {
            disableLoadMore('No more posts');
            return;
          }

          newPosts.forEach(function (post) {
            feed.appendChild(post);
          });

          setupCards(feed);
          setupQuickEdits(feed);
          setupStreamTrash(feed);
          setupComments(feed);
          setupLikes(feed);
          setupCopyLinks(feed);
          setupLinkPreviewImages(feed);

          var loadedMessage = newPosts.length + (newPosts.length === 1 ? ' more post loaded.' : ' more posts loaded.');
          setStatus(loadedMessage);

          var newMoreLink = doc.querySelector('.pagination-load-more .pagination-older a');

          if (newMoreLink && newMoreLink.getAttribute('href')) {
            loadMoreLink.setAttribute('href', newMoreLink.getAttribute('href'));
            loadMoreLink.setAttribute('data-load-more-url', newMoreLink.getAttribute('data-load-more-url') || newMoreLink.getAttribute('href'));
            loadMoreLink.textContent = originalLabel;
            loadMoreLink.classList.remove('is-disabled');
            loadMoreLink.removeAttribute('aria-disabled');
          } else {
            disableLoadMore('No more posts');
          }
        })
        .catch(function () {
          loadMoreLink.textContent = originalLabel;
          setStatus('Unable to load more posts right now.');
        })
        .finally(function () {
          isLoading = false;
          feed.removeAttribute('aria-busy');
        });
    });
  }

  function setupCopyLinks(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-copy-url]').forEach(function (button) {
      if (button.dataset.copyInitialized === '1') {
        return;
      }
      button.dataset.copyInitialized = '1';

      button.addEventListener('click', function () {
        var url = button.getAttribute('data-copy-url') || '';
        var label = button.getAttribute('data-copy-label') || 'Copy';
        var copied = button.getAttribute('data-copied-label') || 'Copied';
        var textNode = button.querySelector('span:last-child');

        function showCopied() {
          if (textNode) {
            textNode.textContent = copied;
            window.setTimeout(function () {
              textNode.textContent = label;
            }, 1800);
          }
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(showCopied).catch(function () {
            window.prompt('Copy this link:', url);
          });
        } else {
          window.prompt('Copy this link:', url);
        }
      });
    });
  }



  function setupComments(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-comments-mount]').forEach(function (mount) {
      if (mount.dataset.commentsInitialized === '1') {
        return;
      }
      mount.dataset.commentsInitialized = '1';
      var endpoint = mount.getAttribute('data-comments-endpoint') || '';
      var slug = mount.getAttribute('data-comments-slug') || '';
      if (!endpoint || !slug) {
        return;
      }

      function loadComments() {
        var url = endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'slug=' + encodeURIComponent(slug);
        fetch(url, { credentials: 'same-origin' })
          .then(function (response) { return response.text(); })
          .then(function (html) {
            mount.innerHTML = html;
            bindCommentForm();
          })
          .catch(function () {
            mount.innerHTML = '<p class="comment-note">Comments could not be loaded.</p>';
          });
      }

      function bindCommentForm() {
        var form = mount.querySelector('[data-comment-form]');
        if (!form || form.dataset.commentFormInitialized === '1') {
          return;
        }
        form.dataset.commentFormInitialized = '1';
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          var submit = form.querySelector('button[type="submit"]');
          var original = submit ? submit.textContent : '';
          if (submit) {
            submit.disabled = true;
            submit.textContent = 'Posting...';
          }
          fetch(form.getAttribute('action') || endpoint, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin'
          })
            .then(function (response) { return response.text(); })
            .then(function (html) {
              mount.innerHTML = html;
              bindCommentForm();
            })
            .catch(function () {
              var note = document.createElement('p');
              note.className = 'comment-notice';
              note.textContent = 'Comment could not be posted right now.';
              form.insertAdjacentElement('beforebegin', note);
            })
            .finally(function () {
              if (submit) {
                submit.disabled = false;
                submit.textContent = original || 'Post Comment';
              }
            });
        });
      }

      loadComments();
    });
  }

  function setupLinkPreviewImages(root) {
    var scope = root || document;
    scope.querySelectorAll('.stream-link-preview-image img, .stream-compose-link-preview-image img').forEach(function (img) {
      if (img.dataset.linkPreviewImageInitialized === '1') {
        return;
      }
      img.dataset.linkPreviewImageInitialized = '1';

      function removeBrokenImage() {
        var imageWrap = img.closest('.stream-link-preview-image, .stream-compose-link-preview-image');
        var card = img.closest('.stream-link-preview, .stream-compose-link-preview-inner');
        if (imageWrap) {
          imageWrap.remove();
        }
        if (card) {
          card.classList.remove('has-image');
          card.classList.add('no-image');
        }
      }

      img.addEventListener('error', removeBrokenImage);
      if (img.complete && img.naturalWidth === 0) {
        removeBrokenImage();
      }
    });
  }


  function setupMediaViewer() {
    var viewer = document.querySelector('[data-stream-media-viewer-modal]');
    if (viewer && viewer.dataset.mediaViewerControllerInitialized === '1') {
      return;
    }

    var viewerImage;
    var closeButton;
    var previousButton;
    var nextButton;
    var status;
    var currentItems = [];
    var currentIndex = 0;
    var previousFocus = null;

    if (!viewer) {
      viewer = document.createElement('div');
      viewer.className = 'stream-media-viewer';
      viewer.hidden = true;
      viewer.setAttribute('data-stream-media-viewer-modal', '');
      viewer.setAttribute('role', 'dialog');
      viewer.setAttribute('aria-modal', 'true');
      viewer.setAttribute('aria-label', 'Photo viewer');

      var stage = document.createElement('div');
      stage.className = 'stream-media-viewer-stage';

      viewerImage = document.createElement('img');
      viewerImage.className = 'stream-media-viewer-image';
      viewerImage.setAttribute('data-stream-media-viewer-image', '');
      viewerImage.alt = '';
      stage.appendChild(viewerImage);
      viewer.appendChild(stage);

      closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'stream-media-viewer-close';
      closeButton.setAttribute('data-stream-media-viewer-close', '');
      closeButton.setAttribute('aria-label', 'Close photo viewer');
      closeButton.textContent = '×';
      viewer.appendChild(closeButton);

      previousButton = document.createElement('button');
      previousButton.type = 'button';
      previousButton.className = 'stream-media-viewer-previous';
      previousButton.setAttribute('data-stream-media-viewer-previous', '');
      previousButton.setAttribute('aria-label', 'Previous photo');
      previousButton.textContent = '‹';
      viewer.appendChild(previousButton);

      nextButton = document.createElement('button');
      nextButton.type = 'button';
      nextButton.className = 'stream-media-viewer-next';
      nextButton.setAttribute('data-stream-media-viewer-next', '');
      nextButton.setAttribute('aria-label', 'Next photo');
      nextButton.textContent = '›';
      viewer.appendChild(nextButton);

      status = document.createElement('div');
      status.className = 'stream-media-viewer-status';
      status.setAttribute('data-stream-media-viewer-status', '');
      status.setAttribute('aria-live', 'polite');
      viewer.appendChild(status);

      document.body.appendChild(viewer);
    } else {
      viewerImage = viewer.querySelector('[data-stream-media-viewer-image]');
      closeButton = viewer.querySelector('[data-stream-media-viewer-close]');
      previousButton = viewer.querySelector('[data-stream-media-viewer-previous]');
      nextButton = viewer.querySelector('[data-stream-media-viewer-next]');
      status = viewer.querySelector('[data-stream-media-viewer-status]');
    }

    if (!viewerImage || !closeButton || !previousButton || !nextButton || !status) {
      return;
    }
    viewer.dataset.mediaViewerControllerInitialized = '1';

    function itemLinks(link) {
      var gallery = link.closest('.stream-media-gallery');
      if (!gallery) {
        return [link];
      }
      return Array.prototype.slice.call(gallery.querySelectorAll('[data-stream-media-viewer], .stream-media-gallery-item')).filter(function (item) {
        return !!(item && item.getAttribute('href') && item.querySelector('img'));
      });
    }

    function showItem(index) {
      if (!currentItems.length) {
        return;
      }
      if (index < 0) {
        index = currentItems.length - 1;
      }
      if (index >= currentItems.length) {
        index = 0;
      }
      currentIndex = index;
      var item = currentItems[currentIndex];
      var thumbnail = item.querySelector('img');
      viewerImage.src = item.href;
      viewerImage.alt = thumbnail && thumbnail.alt ? thumbnail.alt : 'Full-size photo';
      var hasMultiple = currentItems.length > 1;
      previousButton.hidden = !hasMultiple;
      nextButton.hidden = !hasMultiple;
      status.textContent = hasMultiple ? ('Photo ' + (currentIndex + 1) + ' of ' + currentItems.length) : 'Photo';
    }

    function openViewer(link) {
      currentItems = itemLinks(link);
      currentIndex = Math.max(0, currentItems.indexOf(link));
      previousFocus = document.activeElement;
      showItem(currentIndex);
      viewer.hidden = false;
      document.body.classList.add('stream-media-viewer-open');
      closeButton.focus();
    }

    function closeViewer() {
      if (viewer.hidden) {
        return;
      }
      viewer.hidden = true;
      viewerImage.removeAttribute('src');
      viewerImage.alt = '';
      document.body.classList.remove('stream-media-viewer-open');
      currentItems = [];
      currentIndex = 0;
      if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus();
      }
      previousFocus = null;
    }

    closeButton.addEventListener('click', closeViewer);
    previousButton.addEventListener('click', function () {
      showItem(currentIndex - 1);
    });
    nextButton.addEventListener('click', function () {
      showItem(currentIndex + 1);
    });
    viewer.addEventListener('click', function (event) {
      if (event.target === viewer || (event.target.classList && event.target.classList.contains('stream-media-viewer-stage'))) {
        closeViewer();
      }
    });
    viewerImage.addEventListener('click', function (event) {
      event.stopPropagation();
    });

    document.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('a') : null;
      if (!target || !target.matches('[data-stream-media-viewer], .stream-media-gallery-item, .stream-card-media > a') || !target.querySelector('img')) {
        return;
      }
      if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      openViewer(target);
    });

    document.addEventListener('keydown', function (event) {
      if (viewer.hidden) {
        return;
      }
      if (event.key === 'Escape') {
        event.preventDefault();
        closeViewer();
        return;
      }
      if (event.key === 'ArrowLeft' && currentItems.length > 1) {
        event.preventDefault();
        showItem(currentIndex - 1);
        return;
      }
      if (event.key === 'ArrowRight' && currentItems.length > 1) {
        event.preventDefault();
        showItem(currentIndex + 1);
        return;
      }
      if (event.key === 'Tab') {
        var focusable = [closeButton];
        if (!previousButton.hidden) {
          focusable.push(previousButton, nextButton);
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });
  }


  function setupQuickEdits(root) {
    var scope = root || document;
    var forms = scope.querySelectorAll('[data-stream-quick-edit-form]');
    if (forms.length && document.body) {
      document.body.classList.add('stream-quick-edit-enhanced');
    }
    forms.forEach(function (form) {
      if (form.dataset.quickEditInitialized === '1') {
        return;
      }
      form.dataset.quickEditInitialized = '1';

      var card = form.closest('[data-stream-card]');
      var content = card ? card.querySelector('[data-stream-quick-edit-content]') : null;
      var openButton = card ? card.querySelector('[data-stream-quick-edit-open]') : null;
      var cancelButton = form.querySelector('[data-stream-quick-edit-cancel]');
      var saveButton = form.querySelector('[data-stream-quick-edit-save]');
      var textarea = form.querySelector('[data-stream-quick-edit-textarea]');
      var status = form.querySelector('[data-stream-quick-edit-status]');
      var hashInput = form.querySelector('[data-stream-quick-edit-hash]');
      var currentBody = textarea ? textarea.value : '';

      if (!card || !content || !openButton || !textarea) {
        return;
      }

      function setStatus(message, isError) {
        if (!status) {
          return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
      }

      function sizeTextarea() {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(Math.max(textarea.scrollHeight, 112), Math.max(window.innerHeight * 0.65, 240)) + 'px';
      }

      function closeEditor(restoreValue) {
        if (restoreValue) {
          textarea.value = currentBody;
        }
        form.hidden = true;
        content.hidden = false;
        card.classList.remove('is-quick-editing');
        setStatus('', false);
      }

      function openEditor() {
        var menu = openButton.closest('details');
        if (menu) {
          menu.open = false;
        }
        content.hidden = true;
        form.hidden = false;
        card.classList.add('is-quick-editing');
        textarea.value = currentBody;
        sizeTextarea();
        window.setTimeout(function () {
          textarea.focus();
          textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        }, 0);
      }

      openButton.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        openEditor();
      });

      if (cancelButton) {
        cancelButton.addEventListener('click', function (event) {
          event.preventDefault();
          closeEditor(true);
          openButton.focus();
        });
      }

      textarea.addEventListener('input', sizeTextarea);
      textarea.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          event.preventDefault();
          closeEditor(true);
          openButton.focus();
        }
      });

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();
        if (typeof fetch !== 'function' || form.dataset.saving === '1') {
          return;
        }

        form.dataset.saving = '1';
        if (saveButton) {
          saveButton.disabled = true;
          saveButton.textContent = 'Saving…';
        }
        if (cancelButton) {
          cancelButton.disabled = true;
        }
        setStatus('Saving…', false);

        fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new FormData(form)
        }).then(function (response) {
          return response.json().catch(function () {
            return { ok: false, message: 'Quick edit returned an invalid response.' };
          }).then(function (json) {
            if (!response.ok || !json || json.ok !== true) {
              var error = new Error(json && json.message ? json.message : 'Quick edit could not be saved.');
              error.status = response.status;
              throw error;
            }
            return json;
          });
        }).then(function (json) {
          currentBody = typeof json.body === 'string' ? json.body : textarea.value;
          textarea.value = currentBody;
          content.innerHTML = typeof json.body_html === 'string' ? json.body_html : '';
          if (hashInput && json.content_hash) {
            hashInput.value = json.content_hash;
          }
          card.classList.toggle('has-no-text', content.innerHTML.trim() === '');
          closeEditor(false);
          openButton.focus();
        }).catch(function (error) {
          setStatus(error && error.message ? error.message : 'Quick edit could not be saved. Please try again.', true);
        }).finally(function () {
          form.dataset.saving = '0';
          if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = 'Save';
          }
          if (cancelButton) {
            cancelButton.disabled = false;
          }
        });
      });
    });
  }


  function setupStreamTrash(root) {
    var scope = root || document;
    var forms = scope.querySelectorAll('[data-stream-trash-form]');
    forms.forEach(function (form) {
      if (form.dataset.streamTrashInitialized === '1') {
        return;
      }
      form.dataset.streamTrashInitialized = '1';

      var card = form.closest('[data-stream-card]');
      var submitButton = form.querySelector('[data-stream-trash-submit]');
      var fileInput = form.querySelector('input[name="file"]');
      var filename = fileInput ? fileInput.value : '';

      form.addEventListener('submit', function (event) {
        if (!window.confirm('Move this post to Trash? You can restore it from Admin.')) {
          event.preventDefault();
          event.stopPropagation();
          return;
        }

        if (typeof fetch !== 'function' || form.dataset.trashing === '1') {
          return;
        }

        event.preventDefault();
        event.stopPropagation();
        form.dataset.trashing = '1';
        var originalLabel = submitButton ? submitButton.textContent : 'Move to trash';
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Moving…';
        }
        var menu = form.closest('details');
        if (menu) {
          menu.open = false;
        }

        fetch(form.action, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new FormData(form)
        }).then(function (response) {
          return response.json().catch(function () {
            return { ok: false, message: 'Move to Trash returned an invalid response.' };
          }).then(function (json) {
            if (!response.ok || !json || json.ok !== true) {
              var error = new Error(json && json.message ? json.message : 'Post could not be moved to Trash.');
              error.status = response.status;
              throw error;
            }
            return json;
          });
        }).then(function (json) {
          if (form.dataset.streamTrashSingle === '1') {
            window.location.href = json.redirect_url || form.querySelector('input[name="return_to"]').value || '/';
            return;
          }

          document.querySelectorAll('[data-stream-trash-form]').forEach(function (matchingForm) {
            var matchingFile = matchingForm.querySelector('input[name="file"]');
            if (!matchingFile || matchingFile.value !== filename) {
              return;
            }
            var matchingCard = matchingForm.closest('[data-stream-card]');
            if (matchingCard) {
              matchingCard.remove();
            }
          });

          var announcement = document.querySelector('[data-stream-trash-announcement]');
          if (!announcement) {
            announcement = document.createElement('p');
            announcement.className = 'screen-reader-text';
            announcement.setAttribute('role', 'status');
            announcement.setAttribute('aria-live', 'polite');
            announcement.setAttribute('data-stream-trash-announcement', '');
            document.body.appendChild(announcement);
          }
          announcement.textContent = 'Post moved to Trash.';
        }).catch(function (error) {
          window.alert(error && error.message ? error.message : 'Post could not be moved to Trash. Please try again.');
          form.dataset.trashing = '0';
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalLabel;
          }
        });
      });
    });
  }

  function setupCards(root) {
    var scope = root || document;
    scope.querySelectorAll('[data-stream-card]').forEach(function (card) {
      if (card.dataset.cardInitialized === '1') {
        return;
      }
      card.dataset.cardInitialized = '1';

      card.addEventListener('click', function (event) {
        if (event.defaultPrevented) {
          return;
        }
        if (event.target.closest('a, button, input, textarea, label, select, summary, details, [data-stream-actions-menu]')) {
          return;
        }
        var url = card.getAttribute('data-stream-url');
        if (url) {
          window.location.href = url;
        }
      });
    });
  }

  onReady(function () {
    setupMenu();
    setupComposer(document);
    loadComposerMounts();
    setupCopyLinks(document);
    setupLinkPreviewImages(document);
    setupMediaViewer(document);
    if (!isPreviewMode()) {
      setupLikes(document);
      setupCards(document);
      setupQuickEdits(document);
      setupStreamTrash(document);
      setupComments(document);
      setupLoadMore();
    }
  });
}());
