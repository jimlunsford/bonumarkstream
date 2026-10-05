import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Real browser against the disposable local site, never an external account.
// No traces, videos, form snapshots or network archives containing secrets.
export async function browserChecks(siteBase, cookie, approvalPath, label) {
  const browser = await chromium.launch({ executablePath: process.env.BMC_BROWSER_EXECUTABLE || undefined, args: ['--no-sandbox'] });
  try {
    const context = await browser.newContext();
    const split = cookie.indexOf('=');
    await context.addCookies([{ name: cookie.slice(0, split), value: cookie.slice(split + 1), url: siteBase }]);
    const page = await context.newPage();
    const unexpected = [];
    await page.route('**/*', route => {
      if (!route.request().url().startsWith(siteBase + '/')) { unexpected.push('external resource'); return route.abort(); }
      return route.continue();
    });
    const routes = approvalPath ? [['approval', approvalPath], ['applications', '/admin/connected-applications.php'], ['error', '/admin/connect-authorize.php?request=00000000-0000-4000-8000-000000000000']] : [['applications', '/admin/connected-applications.php']];
    for (const width of [1440, 768, 390]) {
      await page.setViewportSize({ width, height: 960 });
      for (const [name, route] of routes) {
        await page.goto(siteBase + route, { waitUntil: 'networkidle' });
        assert.equal(await page.locator('h1').count(), 1, 'One named page heading');
        assert.equal(await page.locator('main').count(), 1, 'One main landmark');
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `No horizontal overflow at ${width}px on ${name}`);
        if (name === 'approval') {
          const approve = page.getByRole('button', { name: 'Approve connection', exact: true });
          assert.equal(await approve.count(), 1); await approve.focus();
          assert.ok(await approve.evaluate(e => document.activeElement === e), 'Approval is keyboard focusable');
          assert.equal(await page.getByLabel('Access expires after').count(), 1);
          assert.equal(await page.getByRole('group', { name: 'Requested permissions' }).count(), 1);
        } else if (name === 'error') {
          assert.equal(await page.getByRole('heading', { name: 'Connection could not be approved' }).count(), 1);
          assert.equal(await page.getByRole('button', { name: 'Approve connection', exact: true }).count(), 0);
        } else {
          assert.equal(await page.getByLabel('Type REVOKE to confirm').count(), 1);
          const recovery = page.getByLabel('Action', { exact: true }); await recovery.focus();
          assert.ok(await recovery.evaluate(e => document.activeElement === e), 'Recovery is keyboard focusable');
        }
        if (process.env.BMC_UI_ARTIFACT_DIR) {
          await mkdir(process.env.BMC_UI_ARTIFACT_DIR, { recursive: true });
          await page.evaluate(() => scrollTo(0, 0));
          await page.screenshot({ path: `${process.env.BMC_UI_ARTIFACT_DIR}/${label}-${name}-${width}.png`, fullPage: true });
        }
      }
    }
    assert.deepEqual(unexpected, [], 'Sensitive screens load only local resources');
  } finally { await browser.close(); }
}
