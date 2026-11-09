// Browser UI checks (development tooling only; not part of the production runtime).
// Usage: node tests/ui/ui-test.js http://127.0.0.1:8080 <demo-password>
const { chromium } = require('playwright');
const base = process.argv[2]; const pw = process.argv[3];
const out = __dirname + '/../output/ui';
const viewports = { desktop: { width: 1366, height: 900 }, tablet: { width: 820, height: 1180 }, mobile: { width: 390, height: 844 } };
const accounts = { owner: 'primary.admin@example.test', admin: 'org.admin@example.test', supervisor: 'supervisor@example.test', volunteer: 'volunteer@example.test' };
const pages = { owner: ['/dashboard', '/admin/applications', '/volunteers', '/projects', '/activities', '/hours/review', '/reports', '/admin/audit', '/admin/users', '/admin/settings', '/certificates', '/admin/training-types'],
  admin: ['/dashboard', '/volunteers'], supervisor: ['/dashboard', '/projects', '/activities', '/hours/review', '/reports'], volunteer: ['/dashboard', '/me', '/my/hours', '/activities', '/certificates'] };
const results = []; const rec = (name, ok, detail = '') => { results.push({ name, ok, detail }); console.log((ok ? '  PASS ' : '  FAIL ') + name + (detail ? ' :: ' + detail : '')); };

async function audit(page, label) {
  const r = await page.evaluate(() => {
    const unlabeled = [...document.querySelectorAll('input:not([type=hidden]):not([disabled]), select, textarea')].filter(el => {
      if (el.closest('.hp') || el.closest('[hidden]')) return false;
      if (el.getAttribute('aria-label')) return false;
      if (el.id && document.querySelector(`label[for="${el.id}"]`)) return false;
      return !el.closest('label');
    }).map(el => el.name || el.id);
    const overflow = document.documentElement.scrollWidth - window.innerWidth;
    const imgsNoAlt = [...document.images].filter(i => !i.hasAttribute('alt')).length;
    const h1 = document.querySelectorAll('h1').length;
    const php = /Fatal error|Warning:|Notice:|Stack trace/.test(document.body.innerText);
    return { unlabeled, overflow, imgsNoAlt, h1, php, lang: document.documentElement.lang };
  });
  rec(`${label}: no PHP error text`, !r.php);
  rec(`${label}: every form control has a label`, r.unlabeled.length === 0, r.unlabeled.join(','));
  rec(`${label}: no horizontal page overflow`, r.overflow <= 1, 'overflow ' + r.overflow + 'px');
  rec(`${label}: images have alt text, one h1, lang set`, r.imgsNoAlt === 0 && r.h1 >= 1 && r.lang === 'en', JSON.stringify({ alt: r.imgsNoAlt, h1: r.h1, lang: r.lang }));
}

(async () => {
  const browser = await chromium.launch();
  for (const [vp, size] of Object.entries(viewports)) {
    const ctx = await browser.newContext({ viewport: size });
    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    let r = await page.goto(base + '/apply');
    rec(`[${vp}] /apply status 200`, r.status() === 200);
    await audit(page, `[${vp}] /apply`);
    await page.screenshot({ path: `${out}/${vp}-apply.png`, fullPage: true });
    if (vp === 'desktop') {
      await page.fill('#full_name', 'UI Test Applicant');
      await page.fill('#email', `ui.${Date.now()}@example.test`);
      await page.selectOption('#expertise', 'Data Analysis');
      await page.selectOption('#availability_type', 'specific_days');
      rec('[desktop] day checkboxes appear for "Specific days"', await page.isVisible('#days-field'));
      await page.check('input[name="available_days[]"][value="2"]');
      await page.check('input[name="privacy_consent"]');
      await page.click('button[type=submit]');
      rec('[desktop] application submitted through the form', page.url().endsWith('/apply/thank-you'));
    }
    for (const [role, email] of Object.entries(accounts)) {
      if (vp !== 'desktop' && role === 'admin') continue;
      await ctx.clearCookies();
      await page.goto(base + '/login');
      await page.fill('#email', email); await page.fill('#password', pw);
      await page.click('button[type=submit]');
      rec(`[${vp}] ${role} signs in`, page.url().endsWith('/dashboard'), page.url());
      for (const p of (vp === 'desktop' ? pages[role] : pages[role].slice(0, 2))) {
        r = await page.goto(base + p);
        rec(`[${vp}] ${role} ${p} status 200`, r.status() === 200, String(r.status()));
        await audit(page, `[${vp}] ${role} ${p}`);
        if (p === '/dashboard' || vp === 'desktop') await page.screenshot({ path: `${out}/${vp}-${role}${p.replace(/\//g, '_')}.png`, fullPage: true });
      }
      await page.click('form[action$="/logout"] button');
      rec(`[${vp}] ${role} signs out`, page.url().includes('/login'));
    }
    rec(`[${vp}] no browser console errors (incl. CSP violations)`, consoleErrors.length === 0, consoleErrors.slice(0, 3).join(' | '));
    await ctx.close();
  }
  await browser.close();
  require('fs').writeFileSync(out + '/ui-results.json', JSON.stringify(results, null, 2));
  const failed = results.filter(x => !x.ok).length;
  console.log(`\n${results.length} UI checks, ${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
