(function () {
  'use strict';

  function pwaMeta(name) {
    var tag = document.querySelector('meta[name="' + name + '"]');
    return tag ? tag.getAttribute('content') || '' : '';
  }

  function clearPwaCaches() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(function (registrations) {
        registrations.forEach(function (registration) {
          if (registration.active) {
            registration.active.postMessage({ type: 'BMS_CLEAR_PWA_CACHE' });
          }
        });
      });
    }
    if ('caches' in window) {
      caches.keys().then(function (keys) {
        keys.forEach(function (key) {
          if (key.indexOf('bonumark-stream-static-') === 0) {
            caches.delete(key);
          }
        });
      });
    }
  }

  function registerServiceWorker() {
    var workerUrl = pwaMeta('bonumark-service-worker');
    var scopeUrl = pwaMeta('bonumark-service-worker-scope');
    if (!workerUrl || !('serviceWorker' in navigator) || !window.isSecureContext) {
      return;
    }
    navigator.serviceWorker.register(workerUrl, scopeUrl ? { scope: scopeUrl } : undefined).catch(function () {});
  }

  try {
    var params = new URLSearchParams(window.location.search || '');
    if (params.has('bms-pwa-clear')) {
      clearPwaCaches();
    }
  } catch (error) {}

  if (document.readyState === 'loading') {
    window.addEventListener('load', registerServiceWorker);
  } else {
    registerServiceWorker();
  }
}());
