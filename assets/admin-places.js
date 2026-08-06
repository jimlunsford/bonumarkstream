(function () {
  'use strict';

  function setFeedback(element, message, isError) {
    if (!element) {
      return;
    }
    element.classList.toggle('is-error', Boolean(isError));
    element.innerHTML = '';
    var paragraph = document.createElement('p');
    paragraph.textContent = message || '';
    element.appendChild(paragraph);
  }

  function locationLine(place) {
    var parts = [];
    if (place.locality) {
      parts.push(place.locality);
    }
    if (place.region && parts.indexOf(place.region) === -1) {
      parts.push(place.region);
    }
    if (!parts.length && place.country) {
      parts.push(place.country);
    }
    return parts.join(', ');
  }

  function publicLabels(place, mode) {
    mode = mode || place.default_display_mode || 'exact';
    var primary = '';
    var secondary = '';

    if (mode === 'city') {
      primary = place.locality || place.region || place.country || place.name || '';
      secondary = place.locality && place.region
        ? place.region
        : ((place.country || '') !== primary ? (place.country || '') : '');
    } else if (mode === 'area') {
      primary = place.area_label || place.locality || place.name || '';
      var parts = [];
      if (place.locality && place.locality !== primary) {
        parts.push(place.locality);
      }
      if (place.region && parts.indexOf(place.region) === -1) {
        parts.push(place.region);
      }
      secondary = parts.join(', ');
      if (!secondary && place.country && place.country !== primary) {
        secondary = place.country;
      }
    } else {
      primary = place.name || '';
      secondary = locationLine(place);
    }

    return {
      primary: primary,
      secondary: secondary,
      mode: mode
    };
  }

  function requestDeviceLocation() {
    return new Promise(function (resolve, reject) {
      if (!window.isSecureContext) {
        reject(new Error('Location access requires HTTPS.'));
        return;
      }
      if (!navigator.geolocation) {
        reject(new Error('This browser does not provide location access.'));
        return;
      }

      navigator.geolocation.getCurrentPosition(function (position) {
        resolve({
          latitude: Number(position.coords.latitude),
          longitude: Number(position.coords.longitude),
          accuracy: Number(position.coords.accuracy || 0)
        });
      }, function (error) {
        var message = 'The device could not provide a location.';
        if (error && error.code === 1) {
          message = 'Location permission was denied. Allow location access for this site, then try again.';
        } else if (error && error.code === 2) {
          message = 'Your current location is unavailable. Check the device location setting, then try again.';
        } else if (error && error.code === 3) {
          message = 'The location request timed out. Try again.';
        }
        reject(new Error(message));
      }, {
        enableHighAccuracy: false,
        timeout: 10000,
        maximumAge: 60000
      });
    });
  }

  function fetchNearby(endpoint, csrf, position) {
    var body = new URLSearchParams();
    body.set('csrf_token', csrf || '');
    body.set('latitude', String(position.latitude));
    body.set('longitude', String(position.longitude));
    body.set('accuracy', String(position.accuracy || 0));

    return window.fetch(endpoint, {
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
          throw new Error(json && json.message ? json.message : 'Nearby places could not be loaded.');
        }
        return {
          places: Array.isArray(json.places) ? json.places : [],
          radius: Number(json.radius_meters || 0)
        };
      });
    });
  }

  function createNearbyResult(place, editBase, compact) {
    var article = document.createElement('article');
    article.className = 'places-nearby-result';

    var copy = document.createElement('div');
    var name = document.createElement('strong');
    name.textContent = place.name || 'Saved place';
    copy.appendChild(name);

    var details = [];
    var location = locationLine(place);
    if (location) {
      details.push(location);
    }
    if (place.area_label) {
      details.push(place.area_label);
    }
    var detail = document.createElement('span');
    detail.textContent = details.join(' · ') || 'Private saved place';
    copy.appendChild(detail);
    article.appendChild(copy);

    var distance = document.createElement('span');
    distance.className = 'places-nearby-distance';
    distance.textContent = typeof place.distance_meters === 'number'
      ? String(place.distance_meters) + ' m away'
      : 'Nearby';
    article.appendChild(distance);

    if (editBase && place.id) {
      var link = document.createElement('a');
      link.className = compact ? 'button-link secondary' : 'button-link secondary';
      link.href = editBase + encodeURIComponent(String(place.id));
      link.textContent = 'Edit';
      article.appendChild(link);
    }

    return article;
  }

  function attachDirectoryNearby() {
    var panel = document.querySelector('[data-places-directory-nearby]');
    if (!panel || panel.getAttribute('data-places-directory-nearby-bound') === '1') {
      return;
    }
    panel.setAttribute('data-places-directory-nearby-bound', '1');

    var button = panel.querySelector('[data-places-nearby-find]');
    var feedback = panel.querySelector('[data-places-nearby-feedback]');
    var results = panel.querySelector('[data-places-nearby-results]');
    var csrfInput = panel.querySelector('[data-places-nearby-csrf]');
    var endpoint = panel.getAttribute('data-nearby-endpoint') || '';
    var editBase = panel.getAttribute('data-edit-base') || '';

    if (!button || !feedback || !results || !endpoint || !window.fetch) {
      return;
    }

    button.addEventListener('click', function () {
      button.disabled = true;
      panel.setAttribute('aria-busy', 'true');
      results.hidden = true;
      results.innerHTML = '';
      setFeedback(feedback, 'Finding your current location…', false);

      requestDeviceLocation()
        .then(function (position) {
          setFeedback(feedback, 'Checking saved places nearby…', false);
          return fetchNearby(endpoint, csrfInput ? csrfInput.value : '', position);
        })
        .then(function (payload) {
          results.innerHTML = '';
          if (!payload.places.length) {
            results.hidden = true;
            setFeedback(feedback, 'No saved places were found nearby. Add a place if this location should be reusable.', false);
            return;
          }

          payload.places.forEach(function (place) {
            results.appendChild(createNearbyResult(place, editBase, false));
          });
          results.hidden = false;
          var radiusLabel = payload.radius > 0 ? ' within ' + String(payload.radius) + ' meters' : '';
          setFeedback(feedback, String(payload.places.length) + ' saved place' + (payload.places.length === 1 ? '' : 's') + ' found' + radiusLabel + '.', false);
        })
        .catch(function (error) {
          results.hidden = true;
          setFeedback(feedback, error && error.message ? error.message : 'Nearby places could not be loaded.', true);
        })
        .finally(function () {
          panel.removeAttribute('aria-busy');
          button.disabled = false;
        });
    });
  }

  function attachPlaceEditor() {
    var form = document.querySelector('[data-place-editor]');
    if (!form || form.getAttribute('data-place-editor-bound') === '1') {
      return;
    }
    form.setAttribute('data-place-editor-bound', '1');

    var nameInput = form.querySelector('[data-place-preview-name]');
    var areaInput = form.querySelector('[data-place-preview-area]');
    var localityInput = form.querySelector('[data-place-preview-locality]');
    var regionInput = form.querySelector('[data-place-preview-region]');
    var countryInput = form.querySelector('[data-place-preview-country]');
    var categorySelect = form.querySelector('#place_category');
    var modeSelect = form.querySelector('[data-place-preview-mode]');
    var latitudeInput = form.querySelector('[data-place-admin-latitude]');
    var longitudeInput = form.querySelector('[data-place-admin-longitude]');
    var previewContainer = form.querySelector('[data-place-preview-container]');
    var previewMarker = form.querySelector('[data-place-preview-marker]');
    var previewPrimary = form.querySelector('[data-place-preview-primary]');
    var previewSecondary = form.querySelector('[data-place-preview-secondary]');
    var categoryFact = form.querySelector('[data-place-preview-category-fact]');
    var locationFact = form.querySelector('[data-place-preview-location-fact]');
    var modeFact = form.querySelector('[data-place-preview-mode-fact]');
    var coordinatePill = form.querySelector('[data-place-coordinate-status]');
    var nearbyButton = form.querySelector('[data-place-editor-nearby]');
    var nearbyResults = form.querySelector('[data-place-editor-nearby-results]');
    var status = form.querySelector('[data-place-admin-status]');
    var endpoint = form.getAttribute('data-nearby-endpoint') || '';
    var editBase = form.getAttribute('data-edit-base') || '';
    var currentPlaceId = parseInt(form.getAttribute('data-current-place-id') || '0', 10) || 0;
    var csrfInput = form.querySelector('input[name="csrf_token"]');

    function currentPlace() {
      return {
        name: nameInput ? nameInput.value.trim() : '',
        area_label: areaInput ? areaInput.value.trim() : '',
        locality: localityInput ? localityInput.value.trim() : '',
        region: regionInput ? regionInput.value.trim() : '',
        country: countryInput ? countryInput.value.trim() : '',
        default_display_mode: modeSelect ? modeSelect.value : 'exact'
      };
    }

    function updatePreview() {
      var place = currentPlace();
      var labels = publicLabels(place, place.default_display_mode);
      var hasPrimary = Boolean(labels.primary);
      var hasSecondary = hasPrimary && Boolean(labels.secondary);
      if (previewContainer) {
        previewContainer.classList.toggle('is-empty', !hasPrimary);
      }
      if (previewMarker) {
        previewMarker.hidden = !hasPrimary;
      }
      if (previewPrimary) {
        previewPrimary.textContent = hasPrimary
          ? labels.primary
          : 'Enter a place name to preview the public label';
        previewPrimary.classList.toggle('is-placeholder', !hasPrimary);
      }
      if (previewSecondary) {
        previewSecondary.textContent = hasSecondary ? labels.secondary : '';
        previewSecondary.hidden = !hasSecondary;
      }
      if (categoryFact && categorySelect) {
        var option = categorySelect.options[categorySelect.selectedIndex];
        categoryFact.textContent = option ? option.textContent : 'Other';
      }
      if (locationFact) {
        locationFact.textContent = locationLine(place) || 'Not set';
      }
      if (modeFact && modeSelect) {
        var modeOption = modeSelect.options[modeSelect.selectedIndex];
        modeFact.textContent = modeOption ? modeOption.textContent : 'Place name and city';
      }
    }

    function updateCoordinateStatus() {
      if (!coordinatePill || !latitudeInput || !longitudeInput) {
        return;
      }
      var latitude = Number(latitudeInput.value);
      var longitude = Number(longitudeInput.value);
      var valid = latitudeInput.value !== '' && longitudeInput.value !== '' &&
        Number.isFinite(latitude) && Number.isFinite(longitude) &&
        latitude >= -90 && latitude <= 90 && longitude >= -180 && longitude <= 180;
      coordinatePill.className = 'status-pill ' + (valid ? 'published' : 'draft');
      coordinatePill.textContent = valid ? 'COORDINATES READY' : 'COORDINATES NEEDED';
    }

    [nameInput, areaInput, localityInput, regionInput, countryInput, categorySelect, modeSelect].forEach(function (control) {
      if (!control) {
        return;
      }
      control.addEventListener('input', updatePreview);
      control.addEventListener('change', updatePreview);
    });
    [latitudeInput, longitudeInput].forEach(function (control) {
      if (!control) {
        return;
      }
      control.addEventListener('input', updateCoordinateStatus);
      control.addEventListener('change', updateCoordinateStatus);
    });

    updatePreview();
    updateCoordinateStatus();

    if (!nearbyButton || !nearbyResults || !endpoint || !window.fetch) {
      return;
    }

    nearbyButton.addEventListener('click', function () {
      nearbyButton.disabled = true;
      nearbyResults.innerHTML = '';
      nearbyResults.hidden = true;
      if (status) {
        status.classList.remove('is-error');
        status.textContent = 'Checking nearby saved places…';
      }

      var latitude = latitudeInput ? Number(latitudeInput.value) : NaN;
      var longitude = longitudeInput ? Number(longitudeInput.value) : NaN;
      var hasCoordinates = latitudeInput && longitudeInput && latitudeInput.value !== '' && longitudeInput.value !== '' &&
        Number.isFinite(latitude) && Number.isFinite(longitude);

      var positionPromise = hasCoordinates
        ? Promise.resolve({ latitude: latitude, longitude: longitude, accuracy: 0 })
        : requestDeviceLocation().then(function (position) {
            if (latitudeInput) {
              latitudeInput.value = position.latitude.toFixed(7);
              latitudeInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (longitudeInput) {
              longitudeInput.value = position.longitude.toFixed(7);
              longitudeInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return position;
          });

      positionPromise
        .then(function (position) {
          return fetchNearby(endpoint, csrfInput ? csrfInput.value : '', position);
        })
        .then(function (payload) {
          var matches = payload.places.filter(function (place) {
            return !currentPlaceId || Number(place.id) !== currentPlaceId;
          });

          nearbyResults.innerHTML = '';
          if (!matches.length) {
            nearbyResults.hidden = true;
            if (status) {
              status.classList.remove('is-error');
              status.textContent = currentPlaceId
                ? 'No other saved places were found near these coordinates.'
                : 'No saved places were found near these coordinates.';
            }
            return;
          }

          matches.forEach(function (place) {
            nearbyResults.appendChild(createNearbyResult(place, editBase, true));
          });
          nearbyResults.hidden = false;
          if (status) {
            status.classList.remove('is-error');
            status.textContent = 'Review nearby records before saving to avoid creating a duplicate place.';
          }
        })
        .catch(function (error) {
          nearbyResults.hidden = true;
          if (status) {
            status.classList.add('is-error');
            status.textContent = error && error.message ? error.message : 'Nearby places could not be loaded.';
          }
        })
        .finally(function () {
          nearbyButton.disabled = false;
        });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    attachDirectoryNearby();
    attachPlaceEditor();
  });
}());
