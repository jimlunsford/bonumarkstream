// Presentation only. State labels and visual tones never select authority paths.
const escape = value => String(value).replace(/[&<>"']/g, x => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[x]);
const states = Object.freeze({
  active: ['Active', 'success'], awaiting_confirmation: ['Awaiting confirmation', 'warning'],
  pending: ['Pending', 'neutral'], exchanging: ['Exchanging', 'neutral'],
  suspended: ['Suspended', 'warning'], revoked_or_expired: ['Revoked or expired', 'warning'],
  disconnect_pending: ['Disconnect pending', 'warning'], disconnected: ['Disconnected', 'neutral'],
  denied: ['Denied', 'neutral'], failed: ['Failed', 'danger'], abandoned: ['Abandoned', 'neutral']
});
const noticeTones = Object.freeze({ confirmed: 'success', healthy: 'success', disconnected: 'success', review: 'warning', confirm_failed: 'danger', health_failed: 'warning', disconnect_failed: 'warning' });
const page = (title, body, narrow = false) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${escape(title)} | Bonumark Connect</title><link rel="stylesheet" href="/assets/connect.css"></head>
<body><a class="skip-link" href="#main">Skip to content</a>
<header class="masthead"><div class="brand">Bonumark <span>Connect</span></div><p>Secure connections for Bonumark Stream</p></header>
<main id="main" class="app${narrow ? ' app-narrow' : ''}" tabindex="-1">${body}</main>
<footer class="footer">Bonumark Connect <span aria-hidden="true">/</span> Development service</footer></body></html>`;

export const loginPage = proof => page('Sign in', `<section class="panel auth-card" aria-labelledby="page-title"><p class="eyebrow">Owner access</p><h1 id="page-title">Sign in</h1><p class="lede" id="credential-help">Use your separately provisioned development account credential. This is not your Bonumark site password.</p><form class="stack-form" method="post" action="/session"><input type="hidden" name="login_csrf" value="${escape(proof)}"><label for="credential">Development account credential</label><input id="credential" name="credential" type="password" autocomplete="current-password" aria-describedby="credential-help" required><button class="button primary">Sign in</button></form></section>`, true);

export function connectedPage(rows, field, noticeKey, notice, actions, labels) {
  const cards = rows.map(r => {
    const [label, tone] = Object.hasOwn(states, r.state) ? states[r.state] : [r.state, 'neutral'];
    return `<article class="connection-card panel" data-state="${escape(r.state)}"><div class="connection-heading"><h3 class="site-address">${escape(r.canonical_origin + r.base_path)}</h3><span class="state-pill tone-${tone}">${escape(label)}</span></div><div class="permissions"><h4>Granted permissions</h4>${r.scopes.length ? `<ul class="scope-list">${r.scopes.map(scope => `<li><code>${escape(scope)}</code></li>`).join('')}</ul>` : '<p class="meta">No permissions granted.</p>'}</div>${r.state === 'disconnect_pending' ? '<p class="owner-review">Owner review required. Routing is disabled; site revocation is not confirmed.</p>' : ''}<div class="connection-actions">${(Object.hasOwn(actions, r.state) ? actions[r.state] : []).map(action => `<form method="post" action="/connections/${escape(r.connection_id)}/${action}">${field}<button class="button ${action === 'disconnect' ? 'secondary danger' : action === 'confirm' ? 'primary' : 'secondary'}">${labels[action]}</button></form>`).join('')}</div></article>`;
  }).join('');
  return page('Connected sites', `<div class="page-heading"><p class="eyebrow">Your connections</p><h1>Connected sites</h1><p class="lede">Review your Bonumark Stream connections, check their health, and manage access.</p></div>${notice ? `<p class="notice tone-${noticeTones[noticeKey]}" role="status">${notice}</p>` : ''}
<section class="panel connect-panel" aria-labelledby="connect-title"><h2 id="connect-title">Connect a site</h2><p class="meta" id="site-help">Enter your site address. You will approve access on your Bonumark site before confirming here.</p><form method="post" action="/connections/start">${field}<label for="site">Bonumark site URL</label><div class="connect-fields"><input id="site" type="url" name="site" placeholder="https://your-site.example" aria-describedby="site-help" required><button class="button primary">Connect site</button></div></form></section>
<section class="connections" aria-labelledby="connections-title"><h2 id="connections-title">Site connections</h2>${cards || '<div class="panel empty-state"><h3>No sites connected yet</h3><p class="meta">Your Bonumark Stream sites will appear here after you authorize a connection. Start with the site address above.</p></div>'}</section>`);
}

export const approvalPage = result => page('Approve on your Bonumark site', `<section class="panel approval-card" aria-labelledby="page-title"><p class="eyebrow">Site authorization</p><h1 id="page-title">Approve on your Bonumark site</h1><p class="lede">Continue to your site to sign in and approve access.</p><p class="approval-site">${escape(result.site)}</p><p class="meta">Your site password stays on your Bonumark site. Return here to confirm the connection afterward.</p><a class="button primary" href="${escape(result.authorization_url)}" rel="noreferrer">Open site approval</a></section>`, true);
