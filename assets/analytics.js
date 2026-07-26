(function () {
  'use strict';

  var currentScript = document.currentScript;
  var endpoint = currentScript && currentScript.getAttribute('data-bonumark-analytics-endpoint');
  if (!endpoint || !window.location || !window.navigator) {
    return;
  }

  var params = new URLSearchParams(window.location.search || '');
  var cleanPath = window.location.pathname || '/';
  var payload = {
    path: cleanPath,
    referrer: document.referrer || '',
    utm_source: params.get('utm_source') || '',
    utm_medium: params.get('utm_medium') || '',
    utm_campaign: params.get('utm_campaign') || ''
  };
  var body = JSON.stringify(payload);

  try {
    if (navigator.sendBeacon) {
      var blob = new Blob([body], { type: 'application/json' });
      if (navigator.sendBeacon(endpoint, blob)) {
        return;
      }
    }

    if (window.fetch) {
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: body
      }).catch(function () {});
    }
  } catch (error) {
    // Analytics is optional and must never interfere with the page.
  }
}());
