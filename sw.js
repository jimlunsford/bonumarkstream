const BMS_CACHE_NAME = 'bonumark-stream-static-v0.5.30';
const BMS_CACHE_PREFIX = 'bonumark-stream-static-';
// Site Identity PWA icons use versioned dynamic URLs and are intentionally not cached here.
const BMS_STATIC_ASSETS = [
  'assets/style.css',
  'assets/stream.css',
  'assets/admin.css',
  'assets/stream.js',
  'assets/admin.js',
  'assets/editor.js',
  'assets/pwa.js'
];

function bmsScopePath() {
  try {
    return new URL(self.registration.scope).pathname;
  } catch (error) {
    return '/';
  }
}

function bmsWithinScope(url) {
  const scopePath = bmsScopePath();
  return url.pathname === scopePath || url.pathname.indexOf(scopePath) === 0;
}

function bmsBlockedPrivatePath(url) {
  const scopePath = bmsScopePath().replace(/\/$/, '/');
  const relative = url.pathname.indexOf(scopePath) === 0 ? url.pathname.slice(scopePath.length) : url.pathname.replace(/^\//, '');
  return relative.indexOf('admin/') === 0 ||
    relative.indexOf('api/') === 0 ||
    relative === 'account.php' ||
    relative === 'profile.php' ||
    relative === 'install.php' ||
    relative.indexOf('_bonumark_stream/') === 0;
}

function bmsSafeStaticAsset(url) {
  if (!bmsWithinScope(url) || bmsBlockedPrivatePath(url)) {
    return false;
  }
  const scopePath = bmsScopePath().replace(/\/$/, '/');
  const relative = url.pathname.indexOf(scopePath) === 0 ? url.pathname.slice(scopePath.length) : url.pathname.replace(/^\//, '');
  if (relative.indexOf('assets/') !== 0 || relative.indexOf('assets/media/') === 0) {
    return false;
  }
  return /\.(css|js|png|svg|webp|ico)$/i.test(relative);
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(BMS_CACHE_NAME).then((cache) => cache.addAll(
      BMS_STATIC_ASSETS.map((path) => new URL(path, self.registration.scope).toString())
    )).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((key) => {
      if (key.indexOf(BMS_CACHE_PREFIX) === 0 && key !== BMS_CACHE_NAME) {
        return caches.delete(key);
      }
      return Promise.resolve(false);
    }))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || !bmsSafeStaticAsset(url)) {
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => cached || fetch(request).then((response) => {
      if (!response || response.status !== 200 || response.type !== 'basic') {
        return response;
      }
      const copy = response.clone();
      caches.open(BMS_CACHE_NAME).then((cache) => cache.put(request, copy));
      return response;
    }).catch(() => caches.match(new URL(url.pathname, self.location.origin).toString())))
  );
});

self.addEventListener('message', (event) => {
  if (!event.data || event.data.type !== 'BMS_CLEAR_PWA_CACHE') {
    return;
  }
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((key) => {
    if (key.indexOf(BMS_CACHE_PREFIX) === 0) {
      return caches.delete(key);
    }
    return Promise.resolve(false);
  }))));
});
