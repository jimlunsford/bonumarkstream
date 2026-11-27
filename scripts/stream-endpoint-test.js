const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// stream.js reads several request endpoints from markup: Like buttons, the
// comments mount and its form, and the composer's link preview, Local Places
// picker, and scheduled-task heartbeat. Each request carries the visitor's
// cookies, and several carry the CSRF token. A loader must only ever request
// the same-origin core endpoint at its exact path below the Bonumark base,
// and must ignore markup inside rendered post, Page, Profile, comment, or
// Following content.

class FakeElement {
  constructor(tagName, attrs = {}, options = {}) {
    this.tagName = String(tagName).toUpperCase();
    this.attrs = Object.assign({}, attrs);
    this.dataset = {};
    this.style = {};
    this.listeners = {};
    this.children = options.children || {};
    this.lists = options.lists || {};
    this.parentElement = options.parent || null;
    this.hidden = false;
    this.disabled = false;
    this.value = options.value || '';
    this.textContent = '';
    this.isConnected = true;
    this.scrollHeight = 0;
    this.options = [];
    this.selectedIndex = 0;
    const classes = new Set(options.classes || []);
    this.classList = {
      add: (...names) => names.forEach((name) => classes.add(name)),
      remove: (...names) => names.forEach((name) => classes.delete(name)),
      contains: (name) => classes.has(name),
      toggle: (name, on) => { if (on === undefined ? !classes.has(name) : on) { classes.add(name); } else { classes.delete(name); } },
    };
    this.html = '';
  }
  set innerHTML(value) { this.html = String(value); if (this.onHtml) { this.onHtml(this.html); } }
  get innerHTML() { return this.html; }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
  setAttribute(name, value) { this.attrs[name] = String(value); }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); }
  removeAttribute(name) { delete this.attrs[name]; }
  querySelector(selector) { return this.children[selector] || null; }
  querySelectorAll(selector) { return this.lists[selector] || []; }
  addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
  fire(type, event = {}) {
    (this.listeners[type] || []).forEach((listener) => listener(Object.assign({preventDefault() {}, stopPropagation() {}, target: this}, event)));
  }
  matches(selector) {
    return selector.split(',').map((part) => part.trim()).some((part) => {
      const attr = part.match(/^\[([a-z-]+)\]$/);
      if (attr) { return this.hasAttribute(attr[1]); }
      const cls = part.match(/^\.([a-z-]+)$/);
      if (cls) { return this.classList.contains(cls[1]); }
      return /^[a-z]+$/.test(part) && this.tagName === part.toUpperCase();
    });
  }
  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matches(selector)) { return node; }
    }
    return null;
  }
  contains(other) { return other === this; }
  focus() {}
  appendChild(child) { return child; }
  insertAdjacentElement(position, element) { this.adjacent = element; }
}

// Run stream.js against a fake page and return its handlers plus every
// request it made. fetch rejects unless a scenario resolves it, so the Like
// loader walks every candidate endpoint it is willing to request.
function loadStream(scriptSrc, pageHref) {
  const requests = [];
  const timers = [];
  const document = {
    readyState: 'loading', hidden: false, activeElement: null,
    addEventListener() {},
    dispatchEvent() {},
    querySelector() { return null; },
    querySelectorAll(selector) { return selector === 'script[src]' ? [new FakeElement('script', {src: scriptSrc})] : []; },
    createElement(tag) { return new FakeElement(tag); },
  };
  const page = new URL(pageHref);
  const window = {
    location: {href: page.href, origin: page.origin, pathname: page.pathname, search: ''},
    addEventListener() {},
    setTimeout(callback) { timers.push(callback); return timers.length; },
    clearTimeout() {},
    setInterval() { return 0; },
    fetch: undefined,
  };
  const fetch = (url, options = {}) => {
    const request = {url: String(url), options};
    requests.push(request);
    return new Promise((resolve, reject) => { request.resolve = resolve; request.reject = reject; })
      .catch((error) => { throw error; });
  };
  window.fetch = fetch;
  const navigator = {geolocation: {getCurrentPosition(success) { success({coords: {latitude: 39.7684, longitude: -86.1581, accuracy: 12}}); }}};
  const context = {
    document, window, navigator, URL, URLSearchParams, Promise, Date, console, setImmediate,
    CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
    FormData: class { append() {} },
    fetch,
  };
  let source = fs.readFileSync(path.join(__dirname, '../assets/stream.js'), 'utf8');
  source = source.replace(/\}\(\)\);\s*$/, 'window.testHooks = {setupLikes, hydrateLikes, setupComments, setupComposer, setupLocalPlaces, coreEndpoint: typeof coreEndpoint === "function" ? coreEndpoint : null}; }());');
  vm.runInNewContext(source, context);
  const runTimers = () => { while (timers.length) { timers.shift()(); } };
  return {hooks: window.testHooks, requests, runTimers, document};
}

const tick = () => new Promise((resolve) => setImmediate(resolve));
const scope = (selector, elements) => ({querySelectorAll: (requested) => (requested === selector ? elements : [])});
const rejectAll = async (requests) => {
  for (let round = 0; round < 20; round++) {
    const pending = requests.filter((request) => !request.settled);
    if (!pending.length) { return; }
    pending.forEach((request) => { request.settled = true; request.reject(new Error('unreachable')); });
    await tick();
  }
};

function likeButton(attrs, parent = null) {
  const button = new FakeElement('button', Object.assign({'data-stream-like': '', 'data-like-slug': 'post', 'data-like-count': '0'}, attrs), {parent});
  button.children = {'.stream-like-text': new FakeElement('span'), '.stream-like-sr-text': new FakeElement('span')};
  return button;
}

function commentsMount(attrs, parent = null) {
  return new FakeElement('section', Object.assign({'data-comments-mount': '', 'data-comments-slug': 'post'}, attrs), {classes: ['stream-comments-mount'], parent});
}

function composerForm(endpoints, parent = null) {
  const form = new FakeElement('form', {'data-stream-form': '', 'data-stream-scheduled-runner-url': endpoints.runner || ''}, {parent});
  const textarea = new FakeElement('textarea', {'data-stream-body': ''}, {value: 'Worth reading https://example.com/article', parent: form});
  const csrf = new FakeElement('input', {name: 'csrf_token'}, {value: 'csrf-secret', parent: form});
  const preview = new FakeElement('div', {'data-link-preview': '', 'data-link-preview-endpoint': endpoints.preview || ''}, {parent: form});
  const picker = new FakeElement('div', {'data-local-places': '', 'data-nearby-endpoint': endpoints.nearby || '', 'data-save-endpoint': endpoints.save || ''}, {parent: form});
  const nearby = new FakeElement('button', {'data-place-nearby': ''}, {parent: picker});
  const save = new FakeElement('button', {'data-place-save': ''}, {parent: picker});
  picker.children = {
    '[data-place-nearby]': nearby, '[data-place-save]': save,
    '[data-place-new-name]': new FakeElement('input', {}, {value: 'Corner cafe'}),
    '[data-place-new-latitude]': new FakeElement('input', {}, {value: '39.7684000'}),
    '[data-place-new-longitude]': new FakeElement('input', {}, {value: '-86.1581000'}),
    '[data-place-status]': new FakeElement('p'), '[data-place-create-status]': new FakeElement('p'),
  };
  form.children = {'[data-stream-body]': textarea, 'input[name="csrf_token"]': csrf, '[data-link-preview]': preview};
  form.lists = {'[data-local-places]': [picker]};
  return {form, nearby, save};
}

// Exercise the composer: link preview, Nearby, Save place, and the heartbeat.
function composerRequests(endpoints, options = {}) {
  const page = loadStream(options.script || '/assets/stream.js?v=1', options.href || 'https://example.test/stream/');
  const {form, nearby, save} = composerForm(endpoints, options.parent || null);
  page.hooks.setupComposer(scope('[data-stream-form]', [form]));
  page.runTimers();
  nearby.fire('click');
  save.fire('click');
  return page.requests.map((request) => ({url: request.url, body: String(request.options.body || '')}));
}

(async () => {
  const root = 'https://example.test/';

  // Like buttons: a foreign or non-core endpoint is never requested; the core
  // endpoints are.
  let page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  page.hooks.hydrateLikes(scope('[data-stream-like]', [likeButton({'data-like-endpoint': 'https://evil.example/collect', 'data-like-endpoint-alt': '/admin/users.php?delete=1'})]));
  await tick(); await rejectAll(page.requests);
  let urls = page.requests.map((request) => new URL(request.url));
  assert.ok(urls.length > 0, 'Like hydration still requests the core endpoints.');
  for (const url of urls) {
    assert.equal(url.origin, 'https://example.test', 'A Like request left the site origin: ' + url.href);
    assert.ok(['/stream-like.php', '/admin/stream-like.php'].includes(url.pathname), 'A Like request used a non-core path: ' + url.href);
  }
  assert.deepEqual([...new Set(urls.map((url) => url.pathname))], ['/stream-like.php', '/admin/stream-like.php']);

  page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  const clicked = likeButton({'data-like-endpoint': 'https://evil.example/collect'});
  page.hooks.setupLikes(scope('[data-stream-like]', [clicked]));
  await tick(); await rejectAll(page.requests);
  page.requests.length = 0;
  clicked.fire('click');
  await tick(); await rejectAll(page.requests);
  assert.ok(page.requests.length > 0 && page.requests.every((request) => request.url.startsWith(root) && /\/(admin\/)?stream-like\.php$/.test(new URL(request.url).pathname)), 'A Like click posts only to the core endpoints.');
  assert.ok(page.requests.every((request) => request.options.method === 'POST'));

  const card = new FakeElement('div', {'data-stream-quick-edit-content': ''}, {classes: ['stream-card-content']});
  page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  const injected = likeButton({'data-like-endpoint': '/stream-like.php'}, card);
  page.hooks.setupLikes(scope('[data-stream-like]', [injected]));
  await tick();
  injected.fire('click');
  await tick();
  assert.equal(page.requests.length, 0, 'A Like button inside post content is ignored.');

  // Comments mount and comment form.
  for (const declared of ['https://evil.example/comments.php', '/admin/users.php', '/comments.php.evil', '//evil.example/comments.php']) {
    page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
    page.hooks.setupComments(scope('[data-comments-mount]', [commentsMount({'data-comments-endpoint': declared})]));
    assert.equal(page.requests.length, 0, 'A comments mount pointing to ' + declared + ' loads nothing.');
  }
  page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  page.hooks.setupComments(scope('[data-comments-mount]', [commentsMount({'data-comments-endpoint': '/comments.php'}, card)]));
  assert.equal(page.requests.length, 0, 'A comments mount inside post content loads nothing.');

  page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  const mount = commentsMount({'data-comments-endpoint': '/comments.php?x=1#frag'});
  const commentForm = new FakeElement('form', {'data-comment-form': '', action: 'https://evil.example/steal'}, {parent: mount});
  commentForm.children = {textarea: new FakeElement('textarea', {}, {value: 'Hello'}), 'button[type="submit"]': new FakeElement('button')};
  mount.onHtml = (html) => { mount.children = html.includes('panel') ? {'[data-comment-form]': commentForm} : {}; };
  page.hooks.setupComments(scope('[data-comments-mount]', [mount]));
  assert.equal(page.requests.length, 1);
  assert.equal(page.requests[0].url, 'https://example.test/comments.php?slug=post', 'The comments mount loads the core endpoint without the declared query or fragment.');
  page.requests[0].resolve({ok: true, status: 200, text: () => Promise.resolve('<section class="panel"></section>')});
  await tick(); await tick();
  commentForm.fire('submit');
  assert.equal(page.requests.length, 2);
  assert.equal(page.requests[1].url, 'https://example.test/comments.php', 'A comment form never posts to a foreign action.');

  // Composer: link preview, Local Places, and the scheduled-task heartbeat.
  const core = {preview: '/admin/link-preview.php', nearby: '/admin/places-nearby.php', save: '/admin/places-save.php', runner: '/admin/scheduled-runner.php'};
  let made = composerRequests(core);
  assert.deepEqual(made.map((request) => request.url).sort(), [
    'https://example.test/admin/link-preview.php',
    'https://example.test/admin/places-nearby.php',
    'https://example.test/admin/places-save.php',
    'https://example.test/admin/scheduled-runner.php',
  ], 'The composer reaches each core endpoint.');
  assert.ok(made.every((request) => request.body.includes('csrf_token=csrf-secret')), 'Each composer request carries the CSRF token.');

  for (const [label, foreign] of [
    ['another origin', {preview: 'https://evil.example/admin/link-preview.php', nearby: 'https://evil.example/admin/places-nearby.php', save: 'https://evil.example/admin/places-save.php', runner: 'https://evil.example/admin/scheduled-runner.php'}],
    ['another core path', {preview: '/admin/users.php', nearby: '/admin/places-save.php', save: '/admin/places-nearby.php', runner: '/admin/link-preview.php'}],
    ['a lookalike path', {preview: '/admin/link-preview.php/x', nearby: '/x/admin/places-nearby.php', save: '/admin/places-save.phps', runner: '/admin/scheduled-runner.php.bak'}],
  ]) {
    made = composerRequests(foreign);
    assert.deepEqual(made, [], 'Composer endpoints on ' + label + ' are never requested, so the CSRF token never leaves: ' + JSON.stringify(made));
  }

  made = composerRequests(core, {parent: card});
  assert.deepEqual(made, [], 'A composer form inside post content is ignored.');

  // A subdirectory install keeps working with its own core paths only.
  made = composerRequests({preview: '/blog/admin/link-preview.php', nearby: '/blog/admin/places-nearby.php', save: '/blog/admin/places-save.php', runner: '/blog/admin/scheduled-runner.php'}, {script: '/blog/assets/stream.js?v=1', href: 'https://example.test/blog/'});
  assert.equal(made.length, 4, 'A subdirectory install reaches its core composer endpoints.');
  assert.ok(made.every((request) => request.url.startsWith('https://example.test/blog/admin/')));
  made = composerRequests(core, {script: '/blog/assets/stream.js?v=1', href: 'https://example.test/blog/'});
  assert.deepEqual(made, [], 'A subdirectory install never requests root paths outside its base.');

  page = loadStream('/blog/assets/stream.js?v=1', 'https://example.test/blog/');
  page.hooks.setupComments(scope('[data-comments-mount]', [commentsMount({'data-comments-endpoint': '/blog/comments.php'})]));
  assert.equal(page.requests.length, 1);
  assert.equal(page.requests[0].url, 'https://example.test/blog/comments.php?slug=post');

  // The shared check, directly.
  page = loadStream('/assets/stream.js?v=1', 'https://example.test/stream/');
  assert.equal(page.hooks.coreEndpoint('/comments.php', ['comments.php']), 'https://example.test/comments.php');
  assert.equal(page.hooks.coreEndpoint('https://example.test/comments.php', ['comments.php']), 'https://example.test/comments.php');
  assert.equal(page.hooks.coreEndpoint('http://example.test/comments.php', ['comments.php']), '', 'A scheme change is another origin.');
  assert.equal(page.hooks.coreEndpoint('https://example.test:8443/comments.php', ['comments.php']), '', 'A port change is another origin.');
  assert.equal(page.hooks.coreEndpoint('javascript:alert(1)', ['comments.php']), '');
  assert.equal(page.hooks.coreEndpoint('', ['comments.php']), '');

  console.log('PASS stream endpoints: Like, comments, link preview, Local Places, and heartbeat loaders request only same-origin core endpoints and ignore user content');
})().catch((error) => { console.error(error); process.exitCode = 1; });
