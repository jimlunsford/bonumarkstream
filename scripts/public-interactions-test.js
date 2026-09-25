const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

// A small DOM adapter exercises the shipped asynchronous handlers, without a browser dependency.
class Element {
  constructor(attrs = {}) {
    this.attrs = attrs; this.dataset = {}; this.listeners = {}; this.disabled = false;
    this.textContent = ''; this.isConnected = true; this.children = {};
    const classes = new Set();
    this.classList = {add: x => classes.add(x), remove: x => classes.delete(x),
      contains: x => classes.has(x), toggle: (x, on) => on ? classes.add(x) : classes.delete(x)};
  }
  getAttribute(k) { return this.attrs[k] || null; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  querySelector(k) { return this.children[k] || null; }
  addEventListener(k, f) { this.listeners[k] = f; }
  contains(x) { return x === this; }
  insertAdjacentElement() {}
  fire(k) { this.listeners[k]({preventDefault() {}, stopPropagation() {}}); }
}
function button(slug) {
  const e = new Element({'data-like-slug': slug, 'data-like-count': '0'});
  e.children['.stream-like-text'] = new Element(); e.children['.stream-like-sr-text'] = new Element();
  return e;
}
function commentLink(slug) {
  const e = new Element({'data-public-comments': slug});
  e.children['[data-public-comment-label]'] = new Element(); return e;
}
class CommentMount extends Element {
  set innerHTML(html) {
    this.html = html;
    const match = html.match(/data-public-comment-count="(\d+)"/);
    this.children = {};
    if (!match) return;
    this.panel = new Element({'data-public-comment-count': match[1]});
    this.heading = new Element(); this.heading.textContent = match[1] + ' Comments';
    this.list = new Element(); this.list.innerHTML = 'comments:' + match[1];
    this.form = new Element({action: '/stream-comments.php'});
    this.textarea = new Element(); this.textarea.value = '';
    this.submit = new Element(); this.submit.textContent = 'Post Comment';
    this.form.children = {'textarea': this.textarea, 'button[type="submit"]': this.submit};
    this.children = {'[data-public-comment-count]': this.panel, '[data-public-comment-heading]': this.heading,
      '[data-public-comment-list]': this.list, '[data-comment-form]': this.form, textarea: this.textarea};
  }
  get innerHTML() { return this.html; }
}
const buttons = [button('post'), button('post'), button('other')];
const links = [commentLink('post'), commentLink('post'), commentLink('other')];
const mount = new CommentMount({'data-comments-endpoint': '/stream-comments.php', 'data-comments-slug': 'post'});
const events = {}; const windowEvents = {}; const requests = []; let interval;
const document = {
  readyState: 'loading', hidden: false, activeElement: null,
  addEventListener(k, f) { (events[k] ||= []).push(f); },
  dispatchEvent(e) { (events[e.type] || []).forEach(f => f(e)); },
  querySelector() { return null; },
  querySelectorAll(k) { return ({'[data-stream-like]': buttons, '[data-public-comments]': links,
    '[data-comments-mount]': [mount], 'script[src]': []})[k] || []; },
  createElement() { return new CommentMount(); }
};
const window = {location: {href: 'https://example.test/'},
  addEventListener(k, f) { windowEvents[k] = f; },
  setInterval(f, ms) { assert.equal(ms, 30000); interval = f; }, setTimeout() {}};
const context = {document, window, URL, Promise, Date, console,
  CustomEvent: class {constructor(type, init) {this.type = type; this.detail = init.detail;}},
  FormData: class {append() {}},
  fetch(url, options) { return new Promise(resolve => requests.push({url, options, resolve})); }
};
let source = fs.readFileSync(path.join(__dirname, '../assets/stream.js'), 'utf8');
source = source.replace(/\}\(\)\);\s*$/, 'window.testHooks = {hydrateLikes, setupLikes, setupComments, setupPublicInteractionRefresh}; }());');
vm.runInNewContext(source, context);
const api = window.testHooks;
const tick = () => new Promise(resolve => setImmediate(resolve));
function respond(req, value, ok = true) {
  req.resolve({ok, status: ok ? 200 : 422, headers: {get: () => 'application/json'},
    text: () => Promise.resolve(typeof value === 'string' ? value : JSON.stringify(value))});
}
const state = (count, comments, liked = false) => ({ok: true, data: {post: {count, comments, liked}}});
const html = count => '<section data-public-comment-count="' + count + '"></section>';
function counts(likes, comments) {
  for (const e of buttons.slice(0, 2)) assert.equal(e.dataset.likeCount, String(likes));
  for (const e of links.slice(0, 2)) assert.equal(e.children['[data-public-comment-label]'].textContent,
    comments + (comments === 1 ? ' Comment' : ' Comments'));
}
(async () => {
  const first = api.hydrateLikes(document); await tick();
  assert.equal(new URL(requests[0].url).searchParams.get('slugs'), 'post,other');
  assert.equal(requests[0].options.cache, 'no-store');
  respond(requests.shift(), state(2, 3)); await first; counts(2, 3);
  assert.equal(buttons[2].dataset.likeCount, undefined);

  const old = api.hydrateLikes(document); await tick(); const oldRequest = requests.shift();
  const recent = api.hydrateLikes(document); await tick();
  respond(requests.shift(), state(1, 2)); await recent;
  respond(oldRequest, state(9, 9)); await old; counts(1, 2);

  api.setupLikes(document); await tick(); const beforeClick = requests.shift();
  buttons[0].fire('click'); await tick();
  assert.equal(requests[0].options.method, 'POST');
  respond(requests.shift(), {ok: true, data: {count: 2, comments: 2, liked: true}});
  await tick(); counts(2, 2);
  respond(beforeClick, state(0, 0)); await tick(); counts(2, 2);
  assert.equal(buttons[0].disabled, false);
  assert.equal(buttons[1].getAttribute('aria-pressed'), 'true');

  api.setupComments(document);
  respond(requests.shift(), html(2)); await tick();
  mount.textarea.value = 'Keep my unsent draft';
  const preservedForm = mount.form;
  document.dispatchEvent({type: 'bms:public-interactions', detail: {slug: 'post', comments: 3}});
  respond(requests.shift(), html(3)); await tick();
  assert.equal(mount.form, preservedForm);
  assert.equal(mount.textarea.value, 'Keep my unsent draft');
  counts(2, 3);
  assert.equal(mount.panel.getAttribute('data-public-comment-count'), '3');

  mount.form.fire('submit');
  assert.equal(mount.submit.disabled, true);
  mount.form.fire('submit'); assert.equal(requests.length, 1);
  respond(requests.shift(), html(4)); await tick();
  assert.equal(mount.textarea.value, '');
  counts(2, 4);
  respond(requests.shift(), state(2, 4, true)); await tick(); counts(2, 4);

  mount.textarea.value = 'Retain rejected comment';
  mount.form.fire('submit'); respond(requests.shift(), html(4), false); await tick();
  assert.equal(mount.textarea.value, 'Retain rejected comment');
  respond(requests.shift(), state(2, 4, true)); await tick();

  api.setupPublicInteractionRefresh();
  document.hidden = true; interval(); await tick(); assert.equal(requests.length, 0);
  document.hidden = false; windowEvents.focus(); windowEvents.focus(); await tick();
  assert.equal(requests.length, 1);
  respond(requests.shift(), state(1, 3, true)); await tick();
  assert.equal(requests.length, 1); // Remote removal updates the visible conversation without replacing its form.
  respond(requests.shift(), html(3)); await tick(); counts(1, 3);
  assert.equal(mount.textarea.value, 'Retain rejected comment');
  console.log('PASS public interactions: initial/duplicate surfaces, ordering, local Like, async comment, draft retention, remote removal and visible-only polling');
})().catch(error => {console.error(error); process.exitCode = 1;});
