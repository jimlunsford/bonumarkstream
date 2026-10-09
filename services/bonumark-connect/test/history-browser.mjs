import assert from 'node:assert/strict';
import { presentationChecks } from './presentation-browser.mjs';

// Called with JavaScript disabled, production HTTP rendering and disposable SQL.
export async function historyBrowserChecks(t, page, relay, origin) {
  const originalList = relay.list;
  let rows = []; let requests = 0;
  page.on('request', () => requests++);
  const row = (state, id) => Object.freeze({
    connection_id: `00000000-0000-4000-8000-${String(id).padStart(12, '0')}`,
    canonical_origin: 'https://site.example.com', base_path: '', state,
    scopes: Object.freeze(['status:read', `fixture:${id}`])
  });
  const open = async records => {
    rows = Object.freeze(records); relay.list = async () => rows;
    const response = await page.goto(origin + '/');
    assert.equal(response.status(), 200); await page.waitForLoadState('load');
    assert.equal(response.headers()['cache-control'], 'no-store');
    assert.equal(response.headers()['content-security-policy'], "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; style-src 'self'");
  };
  const summary = page.locator('.connection-history > summary');
  const history = page.locator('.connection-history');
  const states = selector => page.locator(selector + ' .connection-card').evaluateAll(cards => cards.map(card => card.dataset.state));
  try {
    await t.test('same-site active connection stays prominent; native history disclosure has no side effects', async () => {
      await open([row('abandoned', 1), row('active', 2), row('disconnected', 3)]);
      assert.deepEqual(await states('.connections'), ['active']);
      assert.deepEqual(await states('.connection-history'), ['abandoned', 'disconnected']);
      assert.equal(await summary.textContent(), 'Connection history (2)');
      assert.equal(await history.getAttribute('open'), null);
      assert.equal(await history.locator('button, form, a, input').count(), 0);
      assert.deepEqual(await page.locator('.connections button').allTextContents(), ['Check connection', 'Disconnect']);
      const snapshot = JSON.stringify(rows);
      const database = await relay.db.query('SELECT * FROM connections ORDER BY connection_id');
      const before = requests;
      for (const viewport of [{ width: 1280, height: 900 }, { width: 768, height: 1024 }, { width: 390, height: 844 }, { width: 360, height: 800 }]) {
        await page.setViewportSize(viewport);
        assert.equal(await history.locator('.state-pill').first().isVisible(), false);
        assert.doesNotMatch(await page.locator('main').ariaSnapshot(), /Abandoned|Disconnected/);
        // Tab from the last active action reaches the native summary, not hidden content.
        await page.getByRole('button', { name: 'Disconnect', exact: true }).focus();
        await page.keyboard.press('Tab');
        assert.ok(await summary.evaluate(el => document.activeElement === el && getComputedStyle(el).outlineStyle !== 'none' && parseFloat(getComputedStyle(el).outlineWidth) >= 2));
        assert.ok((await summary.boundingBox()).height >= 44);
        assert.match(await summary.ariaSnapshot(), /Connection history \(2\)/);
        await page.keyboard.press('Enter');
        assert.equal(await history.evaluate(el => el.open), true);
        assert.deepEqual(await history.locator('.state-pill').allTextContents(), ['Abandoned', 'Disconnected']);
        assert.match(await page.locator('main').ariaSnapshot(), /Abandoned/);
        assert.equal(await history.locator('.scope-label').count(), 2);
        assert.equal(await page.locator('.connection-card').count(), 3);
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.keyboard.press('Space');
        assert.equal(await history.evaluate(el => el.open), false);
        await page.keyboard.press('Tab');
        assert.ok(await page.evaluate(() => !document.activeElement.closest('.history-records')));
      }
      assert.equal(requests, before, 'Disclosure interaction makes no network request or authentication call');
      assert.equal(JSON.stringify(rows), snapshot, 'All records and scopes unchanged');
      assert.deepEqual(await relay.db.query('SELECT * FROM connections ORDER BY connection_id'), database, 'No persisted connection change');
      await presentationChecks(page, 'current connections and collapsed history');
      await summary.click();
      await presentationChecks(page, 'current connections and expanded history');
    });
    await t.test('all actionable, nonterminal and unknown states remain visible with original actions and order', async () => {
      const expected = {
        pending: ['Disconnect'], awaiting_confirmation: ['Confirm connection', 'Disconnect'],
        exchanging: [], suspended: ['Disconnect'], revoked_or_expired: ['Disconnect'],
        disconnect_pending: [], active: ['Check connection', 'Disconnect']
      };
      const current = Object.keys(expected);
      const unknown = '<future & "state">';
      await open([row('failed', 1), ...current.map((state, i) => row(state, i + 2)), row(unknown, 20), row('denied', 21), row('active', 22), row('constructor', 23)]);
      assert.deepEqual(await states('.connections'), [...current, unknown, 'active', 'constructor']);
      assert.deepEqual(await states('.connection-history'), ['failed', 'denied']);
      for (const [i, state] of current.entries()) {
        const card = page.locator('.connections .connection-card').nth(i);
        assert.ok(await card.isVisible());
        assert.deepEqual(await card.locator('button').allTextContents(), expected[state]);
      }
      const unknownCard = page.locator('.connections .connection-card').nth(current.length);
      assert.equal(await unknownCard.locator('.state-pill').textContent(), unknown);
      assert.equal(await unknownCard.locator('button').count(), 0);
      assert.equal(await unknownCard.locator('future, script').count(), 0);
      assert.equal(await page.locator('.owner-review').count(), 1);
      assert.equal(await page.locator('.connections [data-state=active]').count(), 2);
      assert.equal(await page.locator('.connection-card').count(), rows.length);
      await summary.click();
      assert.equal(await history.locator('button').count(), 0);
      await presentationChecks(page, 'all connection states');
    });
    await t.test('history-only and no-record empty states are truthful', async () => {
      await open([row('disconnected', 1), row('abandoned', 2), row('denied', 3), row('failed', 4)]);
      assert.equal(await page.getByRole('heading', { name: 'No current connections', exact: true }).count(), 1);
      assert.equal(await page.getByText('No sites connected yet', { exact: true }).count(), 0);
      assert.equal(await summary.textContent(), 'Connection history (4)');
      assert.equal(await history.getAttribute('open'), null);
      assert.ok(await page.getByRole('button', { name: 'Connect site', exact: true }).isVisible());
      await presentationChecks(page, 'history only');
      await open([]);
      assert.equal(await page.getByRole('heading', { name: 'No sites connected yet', exact: true }).count(), 1);
      assert.equal(await history.count(), 0);
      await presentationChecks(page, 'no records');
    });
    await t.test('readable permissions retain exact safely escaped technical values and wrap in both groups', async () => {
      const scope = '<img src=x onerror="fixture">&' + 'long:'.repeat(35);
      const record = state => Object.freeze({ ...row(state, state === 'active' ? 1 : 2), canonical_origin: 'https://' + 'long'.repeat(14) + '.example.com', base_path: '/' + 'path'.repeat(35), scopes: Object.freeze(['status:read', scope]) });
      await open([record('active'), record('failed')]);
      await summary.click();
      for (const card of await page.locator('.connection-card').all()) {
        assert.equal(await card.locator('.scope-label').textContent(), 'Read site status');
        assert.deepEqual(await card.locator('code').allTextContents(), ['status:read', scope]);
        assert.equal(await card.locator('img, script, [onerror]').count(), 0);
        assert.ok(await card.locator('.scope-label').evaluate(el => parseFloat(getComputedStyle(el).fontSize) > parseFloat(getComputedStyle(el.nextElementSibling).fontSize)));
      }
      await presentationChecks(page, 'long current and historical addresses and permissions');
    });
  } finally { relay.list = originalList; }
}
