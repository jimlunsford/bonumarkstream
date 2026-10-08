import assert from 'node:assert/strict';

// Inspect rendered documents only. Never serialize inputs, cookies, protocol
// URLs, screenshots, or storage state. Browser evaluation is test code only.
export async function presentationChecks(page, kind) {
  await page.waitForLoadState('load');
  assert.equal(await page.locator('link[rel=stylesheet]').count(), 1);
  assert.equal(await page.locator('link[rel=stylesheet]').getAttribute('href'), '/assets/connect.css');
  assert.equal(await page.locator('script, style, [style]').count(), 0);
  assert.equal(await page.locator('.masthead .brand').textContent(), 'Bonumark Connect');
  assert.equal(await page.locator('main').count(), 1); assert.equal(await page.locator('h1').count(), 1);
  const resources = await page.evaluate(() => ({
    cssLoaded: [...document.styleSheets].some(sheet => sheet.href === location.origin + '/assets/connect.css' && sheet.cssRules.length > 0),
    external: performance.getEntriesByType('resource').some(entry => new URL(entry.name).origin !== location.origin),
    background: getComputedStyle(document.documentElement).backgroundColor
  }));
  assert.ok(resources.cssLoaded, 'Same-origin stylesheet loaded and parsed');
  assert.equal(resources.external, false, 'No external resources on Connect documents');
  assert.equal(resources.background, 'rgb(10, 13, 18)', 'Stream Admin background is rendered');
  for (const viewport of [{ width: 1280, height: 900 }, { width: 768, height: 1024 }, { width: 390, height: 844 }, { width: 360, height: 800 }]) {
    await page.setViewportSize(viewport);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${kind}: no horizontal overflow at ${viewport.width}`);
    const metrics = await page.evaluate(() => {
      const rgb = value => value.match(/[\d.]+/g).map(Number);
      const luminance = values => values.slice(0, 3).map(v => v / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4).reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
      const contrast = (a, b) => { const x = luminance(a); const y = luminance(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
      const background = el => { for (let node = el; node; node = node.parentElement) { const color = rgb(getComputedStyle(node).backgroundColor); if (color.length === 3 || color[3] === 1) return color; } return [10, 13, 18]; };
      const text = [...document.querySelectorAll('h1,h2,h3,h4,p,label,code,.button,.state-pill,.brand,.brand span,.footer')];
      const controls = [...document.querySelectorAll('input:not([type=hidden]),.button')];
      return {
        textContrast: text.every(el => contrast(rgb(getComputedStyle(el).color), background(el)) >= 4.5),
        controlContrast: controls.every(el => contrast(rgb(getComputedStyle(el).borderTopColor), background(el)) >= 3 || contrast(background(el), background(el.parentElement)) >= 3),
        targets: controls.every(el => el.getBoundingClientRect().height >= 44),
        labels: [...document.querySelectorAll('input:not([type=hidden])')].every(el => el.labels.length > 0),
        inBounds: controls.every(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; })
      };
    });
    assert.ok(metrics.textContrast, `${kind}: text contrast >= 4.5:1`);
    assert.ok(metrics.controlContrast, `${kind}: control boundaries >= 3:1`);
    assert.ok(metrics.targets && metrics.labels && metrics.inBounds, `${kind}: labeled, usable controls`);
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    await page.keyboard.press('Tab');
    assert.ok(await page.locator('.skip-link').evaluate(el => document.activeElement === el), 'Keyboard reaches skip link');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Tab');
    assert.ok(await page.evaluate(() => {
      const el = document.activeElement; const style = getComputedStyle(el);
      return el.matches('input, button, a') && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2;
    }), 'Keyboard reaches controls with visible focus');
  }
  await page.setViewportSize({ width: 1280, height: 900 });
}
