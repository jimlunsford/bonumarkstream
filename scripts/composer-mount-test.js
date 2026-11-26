const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// The composer mount loader fetches HTML with the owner's cookies and inserts
// it into the page. It must only act on the core-rendered mount, never on
// markup inside rendered post, Page, Profile, comment, or Following content,
// and it must only request the same-origin core composer endpoint.

class Element {
  constructor(tagName, attrs = {}, classes = [], parent = null) {
    this.tagName = tagName.toUpperCase();
    this.attrs = attrs;
    this.dataset = {};
    this.parentElement = parent;
    const set = new Set(classes);
    this.classList = {contains: (name) => set.has(name), add: (name) => set.add(name), remove: (name) => set.delete(name)};
    this.innerHTML = '';
  }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; }
  hasAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name); }
  matchesSimple(selector) {
    // Supports the class and attribute selectors used by stream.js here.
    return selector.split(',').map((part) => part.trim()).some((part) => {
      const attr = part.match(/^\[([a-z-]+)\]$/);
      if (attr) { return this.hasAttribute(attr[1]); }
      const cls = part.match(/^\.([a-z-]+)$/);
      if (cls) { return this.classList.contains(cls[1]); }
      return false;
    });
  }
  closest(selector) {
    for (let node = this; node; node = node.parentElement) {
      if (node.matchesSimple(selector)) { return node; }
    }
    return null;
  }
}

function runScenario(elements, scriptSrc) {
  const requests = [];
  const document = {
    readyState: 'loading',
    addEventListener() {},
    querySelector() { return null; },
    querySelectorAll(selector) {
      if (selector === 'script[src]') {
        return [new Element('script', {src: scriptSrc})];
      }
      if (selector === '[data-stream-composer-mount]') {
        return elements.filter((el) => el.hasAttribute('data-stream-composer-mount'));
      }
      if (selector === 'section.stream-composer-mount[data-stream-composer-mount]') {
        return elements.filter((el) => el.tagName === 'SECTION' && el.classList.contains('stream-composer-mount') && el.hasAttribute('data-stream-composer-mount'));
      }
      return [];
    },
  };
  const window = {location: {href: 'https://example.test/stream/', origin: 'https://example.test', pathname: '/stream/', search: ''}, addEventListener() {}, setTimeout() {}, setInterval() {}};
  const context = {document, window, URL, Promise, console,
    fetch(url, options) { requests.push({url: String(url), options}); return new Promise(() => {}); }};
  let source = fs.readFileSync(path.join(__dirname, '../assets/stream.js'), 'utf8');
  source = source.replace(/\}\(\)\);\s*$/, 'window.testHooks = {loadComposerMounts}; }());');
  vm.runInNewContext(source, context);
  requests.length = 0;
  window.testHooks.loadComposerMounts();
  return requests;
}

const coreEndpoint = 'https://example.test/admin/stream-composer.php';
const script = '/assets/stream.js?v=0.8.5';

// Markup an attacker could place in rendered user content.
const card = new Element('div', {'data-stream-quick-edit-content': ''}, ['stream-card-content']);
const injectedLink = new Element('a', {'href': '/x', 'data-stream-composer-mount': '', 'data-stream-composer-endpoint': 'https://evil.example/collect'}, [], card);
const injectedSection = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': '/admin/stream-composer.php'}, ['stream-composer-mount'], card);
const profile = new Element('div', {}, ['profile-about-content']);
const profileMount = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': '/admin/stream-composer.php'}, ['stream-composer-mount'], profile);

// The real core mount, and core-shaped mounts that point elsewhere.
const main = new Element('main', {});
const coreMount = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': '/admin/stream-composer.php', 'aria-live': 'polite'}, ['stream-composer-mount'], main);
const crossOrigin = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': 'https://evil.example/admin/stream-composer.php'}, ['stream-composer-mount'], main);
const otherPath = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': '/admin/users.php?delete=1'}, ['stream-composer-mount'], main);

let requests = runScenario([injectedLink], script);
assert.equal(requests.length, 0, 'A composer mount attribute on a link inside post content must not load anything.');

requests = runScenario([injectedSection, profileMount], script);
assert.equal(requests.length, 0, 'A composer mount inside rendered post or Profile content must not load anything.');

requests = runScenario([crossOrigin], script);
assert.equal(requests.length, 0, 'A composer mount pointing to another origin must not load.');

requests = runScenario([otherPath], script);
assert.equal(requests.length, 0, 'A composer mount pointing to another same-origin path must not load.');

requests = runScenario([injectedLink, injectedSection, profileMount, crossOrigin, otherPath, coreMount], script);
assert.equal(requests.length, 1, 'Only the core composer mount loads.');
assert.ok(requests[0].url.startsWith(coreEndpoint + '?return_to='), 'The core composer mount requests the core composer endpoint: ' + requests[0].url);
assert.equal(requests[0].options.credentials, 'same-origin');

// A subdirectory install keeps working.
const subMount = new Element('section', {'data-stream-composer-mount': '', 'data-stream-composer-endpoint': '/blog/admin/stream-composer.php'}, ['stream-composer-mount'], main);
requests = runScenario([subMount], '/blog/assets/stream.js?v=0.8.5');
assert.equal(requests.length, 1, 'A subdirectory install loads its core composer.');
assert.ok(requests[0].url.startsWith('https://example.test/blog/admin/stream-composer.php?return_to='), requests[0].url);

console.log('PASS composer mount: ignores user-content and foreign-endpoint mounts; loads only the core same-origin composer');
