'use strict';
// RampWise front end: talks to the PHP JSON endpoints with fetch + async/await.
// User-controlled text is only ever inserted with textContent (never innerHTML).

const $ = (sel, root = document) => root.querySelector(sel);
const CSRF = $('meta[name="csrf-token"]').content;
const searchForm = $('#search-form');
const routeForm = $('#route-form');

class ApiError extends Error {
  constructor(message, status, fields) { super(message); this.status = status; this.fields = fields || {}; }
}

async function api(path, { method = 'GET', body } = {}) {
  const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
  if (method !== 'GET') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = CSRF;           // checked on the server for every write
  }
  if (body !== undefined) opts.body = JSON.stringify(body);
  let res;
  try { res = await fetch(path, opts); }
  catch { throw new ApiError('Cannot reach the server. Check your connection.', 0); }
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON response */ }
  if (!res.ok || !data || data.ok === false) {
    throw new ApiError(data?.error?.message || `Request failed (${res.status}).`, res.status, data?.error?.fields);
  }
  return data;
}

// ---------- small UI helpers ----------
function el(tag, { cls, text } = {}, children = []) {
  const n = document.createElement(tag);
  if (cls) n.className = cls;
  if (text !== undefined) n.textContent = text;
  children.forEach(c => n.append(c));
  return n;
}
function say(msg, kind = 'ok') { const s = $('#status'); s.textContent = msg; s.className = 'status ' + kind; }
function clearErrors(form) { form.querySelectorAll('[data-err]').forEach(s => (s.textContent = '')); }
function showErrors(form, fields) {
  Object.entries(fields).forEach(([k, v]) => { const s = form.querySelector(`[data-err="${k}"]`); if (s) s.textContent = v; });
}
function setView(name) {   // idle | loading | empty | error | list
  ['idle', 'loading', 'empty', 'error'].forEach(id => ($('#' + id).hidden = id !== name));
  $('#list').hidden = name !== 'list';
}

// ---------- buildings ----------
async function loadBuildings() {
  const { buildings } = await api('api/buildings.php');
  ['#from', '#to', '#rf-from', '#rf-to'].forEach(sel => {
    const s = $(sel);
    buildings.forEach(b => s.append(new Option(b.name, b.id)));   // Option sets text safely
  });
}

// ---------- search / read ----------
let lastSearch = null;
async function search() {
  clearErrors(searchForm);
  const f = new FormData(searchForm);
  const p = new URLSearchParams({ from: f.get('from') || '', to: f.get('to') || '', pace: f.get('pace'), sort: f.get('sort') });
  ['step_free', 'covered', 'avoid_steep'].forEach(k => f.get(k) && p.set(k, '1'));
  if (f.get('max_m')) p.set('max_m', f.get('max_m'));
  lastSearch = p;
  setView('loading'); say('');
  try {
    const { routes } = await api('api/routes.php?' + p);
    render(routes);
  } catch (e) {
    if (e.status === 422) { showErrors(searchForm, e.fields); setView('idle'); say(e.message, 'bad'); }
    else { $('#error-msg').textContent = e.message; setView('error'); }
  }
}

function badge(text, good) { return el('li', { cls: 'badge ' + (good ? 'good' : 'bad'), text }); }

function render(routes) {
  const list = $('#list');
  list.replaceChildren();
  if (!routes.length) { setView('empty'); return; }
  routes.forEach(r => list.append(card(r)));
  setView('list');
  say(`${routes.length} route${routes.length === 1 ? '' : 's'} found.`);
}

function card(r) {
  const stepFree = r.steps === 0 || r.has_lift;
  const art = el('article', { cls: 'card' + (r.status === 'closed' ? ' closed' : '') }, [
    el('h3', { text: `${r.from_name} ↔ ${r.to_name}` }),
    el('p', { cls: 'time', text: `${r.minutes} min` }),
    el('p', { text: `${r.distance_m} m` }),
    el('ul', { cls: 'badges' }, [
      badge(stepFree ? 'Step-free' : `${r.steps} steps`, stepFree),
      badge(r.covered ? 'Covered' : 'Uncovered', r.covered),
      badge(`Slope: ${r.slope}`, r.slope !== 'steep'),
      badge(r.has_lift ? 'Lift available' : 'No lift', r.has_lift),
    ]),
  ]);
  if (r.status === 'closed') {
    art.append(el('p', { cls: 'warning', text: `⚠ Closed: ${r.closed_reason ?? 'no reason given'}` }));
  }
  const actions = el('div', { cls: 'row' });
  if (r.status === 'open') actions.append(btn('Report closed', () => toggleReport(art, r)));
  if (r.status === 'closed' && r.can_reopen) actions.append(btn('Reopen', () => reopen(r)));
  if (r.mine) {
    actions.append(btn('Edit', () => startEdit(r)));
    actions.append(btn('Delete', () => remove(r), 'danger'));
  }
  art.append(actions);
  return art;
}

function btn(label, onClick, extra = '') {
  const b = el('button', { cls: `btn small ${extra}`.trim(), text: label });
  b.type = 'button';
  b.addEventListener('click', onClick);
  return b;
}

// ---------- report closed (update) ----------
function toggleReport(art, r) {
  const existing = $('.report-form', art);
  if (existing) { existing.remove(); return; }
  const ta = el('textarea');
  ta.id = `reason-${r.id}`; ta.rows = 2; ta.maxLength = 200;
  const lab = el('label', { text: 'What is blocking this route?' }); lab.htmlFor = ta.id;
  const err = el('span', { cls: 'err' });
  const form = el('form', { cls: 'report-form' }, [lab, ta, err, el('button', { cls: 'btn small', text: 'Submit report' })]);
  form.noValidate = true;
  form.addEventListener('submit', async ev => {
    ev.preventDefault(); err.textContent = '';
    try {
      await api('api/report.php', { method: 'POST', body: { route_id: r.id, reason: ta.value } });
      await search(); say('Route reported as closed.');
    } catch (e) { err.textContent = e.fields.reason || e.message; }
  });
  art.append(form); ta.focus();
}

async function reopen(r) {
  try { await api(`api/report.php?route_id=${r.id}`, { method: 'DELETE' }); await search(); say('Route reopened.'); }
  catch (e) { say(e.message, 'bad'); }
}

// ---------- create / update / delete ----------
function resetRouteForm() {
  routeForm.reset(); $('#rf-id').value = '';
  $('#route-form-title').textContent = 'Add a route'; $('#rf-cancel').hidden = true; clearErrors(routeForm);
}

function startEdit(r) {
  $('#rf-id').value = r.id;
  $('#rf-from').value = r.from_id; $('#rf-to').value = r.to_id;
  $('#rf-distance').value = r.distance_m; $('#rf-steps').value = r.steps; $('#rf-slope').value = r.slope;
  routeForm.has_lift.checked = r.has_lift; routeForm.covered.checked = r.covered;
  $('#route-form-title').textContent = 'Edit your route'; $('#rf-cancel').hidden = false;
  routeForm.scrollIntoView({ behavior: 'smooth' });
}

routeForm.addEventListener('submit', async ev => {
  ev.preventDefault(); clearErrors(routeForm);
  const f = new FormData(routeForm);
  const body = {
    from_id: f.get('from_id'), to_id: f.get('to_id'), distance_m: f.get('distance_m'),
    steps: f.get('steps'), slope: f.get('slope'),
    has_lift: routeForm.has_lift.checked, covered: routeForm.covered.checked,
  };
  const id = f.get('id');
  const submit = $('#rf-submit'); submit.disabled = true;
  try {
    await api(id ? `api/routes.php?id=${encodeURIComponent(id)}` : 'api/routes.php', { method: id ? 'PUT' : 'POST', body });
    say(id ? 'Route updated.' : 'Route added.');
    resetRouteForm();
    if (searchForm.from.value && searchForm.to.value) await search(); say(id ? 'Route updated.' : 'Route added.');
  } catch (e) {
    if (e.status === 422) { showErrors(routeForm, e.fields); say(e.message, 'bad'); } else say(e.message, 'bad');
  } finally { submit.disabled = false; }
});
$('#rf-cancel').addEventListener('click', resetRouteForm);

async function remove(r) {
  if (!confirm(`Delete the route ${r.from_name} ↔ ${r.to_name}?`)) return;
  try { await api(`api/routes.php?id=${r.id}`, { method: 'DELETE' }); await search(); say('Route deleted.'); }
  catch (e) { say(e.message, 'bad'); }
}

// ---------- events, theme preference (the only thing kept in localStorage) ----------
searchForm.addEventListener('submit', ev => { ev.preventDefault(); search(); });
searchForm.addEventListener('change', () => { if (searchForm.from.value && searchForm.to.value) search(); });
$('#retry').addEventListener('click', () => (lastSearch ? search() : setView('idle')));

try { const t = localStorage.getItem('rampwise-theme'); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch { /* storage blocked */ }
$('#theme-toggle').addEventListener('click', () => {
  const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
  document.documentElement.dataset.theme = next;
  try { localStorage.setItem('rampwise-theme', next); } catch { /* ignore */ }
});

loadBuildings().catch(e => say(e.message, 'bad'));
