'use strict';
/* Application logic: talks to api/index.php and renders every screen.
   Nothing here loads "the whole school": lists are fetched one page at a time and searched on the server. */
const $ = id => document.getElementById(id);
const State = {
  user: null,
  csrf: '',
  set: {},
  brand: {},
  streams: {},
  editing: null,
  report: { kind: '', page: 1 },
  picked: new Map(),
  seq: {},
  stu: { page: 1, size: 25, total: 0, pages: 1 },
  cards: { page: 1, size: 24, total: 0, pages: 1, rows: [] },
  promo: { rows: [], ticked: new Set() },
  perm: {},
};
const can = p => !!State.user && State.user.perms.includes(p);
const canAny = list => list.split(' ').some(can);
const CLASSES = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6'];
const TITLES = {
  dash: 'Dashboard',
  reg: 'Register students',
  stu: 'Students',
  photos: 'Photos',
  cards: 'Cards and printing',
  promo: 'Promotion',
  streams: 'Classes and streams',
  des: 'Card design',
  rep: 'Reports',
  usr: 'Users',
  bak: 'Backups',
};

/* ---------- server calls ---------- */
const qs = o =>
  Object.entries(o || {})
    .filter(([, v]) => v !== '' && v != null)
    .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
    .join('&');
async function api(action, { method = 'GET', json, form, query, signal } = {}) {
  const o = { method, credentials: 'same-origin', headers: {}, signal };
  if (method === 'POST') {
    o.headers['X-CSRF-Token'] = State.csrf;
    if (form) o.body = form;
    else {
      o.headers['Content-Type'] = 'application/json';
      o.body = JSON.stringify(json || {});
    }
  }
  let res, data;
  try {
    res = await fetch('api/index.php?action=' + action + (query ? '&' + qs(query) : ''), o);
    data = await res.json();
  } catch (e) {
    if (e.name === 'AbortError') throw Object.assign(new Error('Cancelled.'), { aborted: true, details: [] });
    throw Object.assign(
      new Error('Cannot reach the server. Check that Apache and MySQL are running in XAMPP, then reload.'),
      { details: [] }
    );
  }
  if (!res.ok || data.ok === false) {
    if (res.status === 401 && State.user) {
      State.user = null;
      showLogin();
    }
    if (data.must_change && State.user) {
      State.user.must_change = true;
      forcePassword();
    }
    throw Object.assign(new Error(data.error || 'Request failed.'), {
      details: data.errors || [],
      status: res.status,
      retry: data.retry_after || 0,
      data,
    });
  }
  return data;
}
const debounce = (fn, ms) => {
  let t;
  return (...a) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...a), ms);
  };
};
/** Start a request of a kind (for example the student list). The previous one of the same kind is cancelled,
 *  so a fast typist does not leave the server working on answers nobody will see. ok() is true only for the newest. */
const requests = {};
function latest(key) {
  if (requests[key]) requests[key].abort();
  const ctl = (requests[key] = new AbortController());
  return { signal: ctl.signal, ok: () => requests[key] === ctl };
}
const newest = key => {
  const n = (State.seq[key] = (State.seq[key] || 0) + 1);
  return () => n === State.seq[key];
}; // ignore slow, out-of-date answers

/* ---------- small UI helpers ---------- */
function modal(html, mount, wide) {
  const o = document.createElement('div');
  o.className = 'overlay';
  o.innerHTML = '<div class="card modal' + (wide ? ' wide' : '') + '">' + html + '</div>';
  document.body.appendChild(o);
  mount(o, () => o.remove());
  return o;
}
function ask(msg, onYes, yes = 'Delete') {
  modal(
    `<p style="margin:0 0 14px;white-space:pre-wrap">${esc(msg)}</p><div class="row end" style="margin:0"><button class="btn" data-no>${onYes ? 'Cancel' : 'OK'}</button>${onYes ? `<button class="btn pri" data-yes>${esc(yes)}</button>` : ''}</div>`,
    (o, close) => {
      o.querySelector('[data-no]').onclick = close;
      const y = o.querySelector('[data-yes]');
      if (y)
        y.onclick = () => {
          close();
          onYes();
        };
    }
  );
}
/** One-time password shown to the administrator (never stored, never shown again). */
function showSecret(title, who, pw) {
  modal(
    `<h3>${esc(title)}</h3><p>Give this one-time password to <b>${esc(who)}</b>. They will be asked to choose their own password the first time they sign in.</p><div class="secret">${esc(pw)}</div><p class="muted small">It is shown only now. If it is lost, use "Reset password" again.</p><div class="row end"><button class="btn" data-copy>Copy</button><button class="btn pri" data-no>Done</button></div>`,
    (o, close) => {
      o.querySelector('[data-no]').onclick = close;
      o.querySelector('[data-copy]').onclick = e => {
        navigator.clipboard &&
          navigator.clipboard.writeText(pw).then(() => (e.target.textContent = 'Copied'));
      };
    }
  );
}
function showErrors(form, msgEl, errors) {
  form.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
  errors.forEach(e => {
    const el = e.f && form.elements[e.f];
    if (el && el.classList) el.classList.add('bad');
  });
  msgEl.className = 'msg';
  msgEl.textContent = 'Not saved. Please fix:\n- ' + errors.map(e => e.m).join('\n- ');
  const first = errors[0] && errors[0].f && form.elements[errors[0].f];
  if (first && first.focus) first.focus();
}
const say = (el, text, ok) => {
  el.className = 'msg' + (ok ? ' ok' : '');
  el.textContent = text;
};
const fail = (el, err) => (err.details && err.details.length ? null : say(el, err.message));
const csvCell = v => {
  v = String(v ?? '');
  if (/^[=+\-@]/.test(v)) v = "'" + v;
  return '"' + v.replace(/"/g, '""') + '"';
};
const avatar = s =>
  s.thumb || s.photo
    ? `<img class="thumb" src="${esc(s.thumb || s.photo)}" alt="">`
    : '<span class="thumb"></span>';
const fmtDate = t =>
  t
    ? new Date(t.replace(' ', 'T')).toLocaleString([], {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : '';
const mb = n => (n / 1048576).toFixed(n < 1048576 ? 2 : 1) + ' MB';
const opts = (list, sel) =>
  list.map(v => `<option${v === sel ? ' selected' : ''}>${esc(v)}</option>`).join('');
const activeTab = () => {
  const b = document.querySelector('#nav button.on');
  return b ? b.dataset.tab : '';
};

function pager(el, st, key, unit) {
  el.innerHTML =
    `<span class="muted">${st.total} ${unit}</span><span class="pg"><button class="btn sm" data-pg="${key}" data-to="${st.page - 1}"${st.page <= 1 ? ' disabled' : ''}>Previous</button><span>Page ${st.page} of ${st.pages}</span><button class="btn sm" data-pg="${key}" data-to="${st.page + 1}"${st.page >= st.pages ? ' disabled' : ''}>Next</button></span>` +
    (key === 'rep'
      ? ''
      : `<label>Per page<select data-size="${key}">${[24, 25, 50, 100, 200]
          .filter(n => (key === 'cards' ? n !== 25 : n !== 24))
          .map(n => `<option${n === st.size ? ' selected' : ''}>${n}</option>`)
          .join('')}</select></label>`);
}
const PAGE_LOADERS = { stu: () => loadStu(), cards: () => loadCards(), rep: () => loadReport() };
document.addEventListener('click', e => {
  const b = e.target.closest('[data-pg]');
  if (b && !b.disabled) {
    (State[b.dataset.pg] || State.stu).page = +b.dataset.to;
    PAGE_LOADERS[b.dataset.pg]();
  }
});
document.addEventListener('change', e => {
  const s = e.target.closest('[data-size]');
  if (s) {
    const st = State[s.dataset.size];
    st.size = +s.value;
    st.page = 1;
    PAGE_LOADERS[s.dataset.size]();
  }
});

$('menuBtn').onclick = () => $('side').classList.toggle('open');

/* ---------- sign in / out, forced password change ---------- */
let lockTimer = null;
function showLogin() {
  $('app').classList.add('hidden');
  $('login').classList.remove('hidden');
  $('loginForm').elements.password.value = '';
  applyBrand();
}
/** Sign-in screen: the product, and the school's own name and logo once the school has been set up. */
function applyBrand() {
  const b = State.brand || {},
    product = b.product || 'Visitation Cards Management System',
    note = $('loginNote');
  $('loginLogo').src = b.logo || 'assets/img/vcms-logo.png';
  $('loginTitle').textContent = b.school || product;
  $('loginSub').textContent = b.school ? product : 'Sign in to continue';
  document.title = b.school ? b.school + ' - ' + product : product;
  if (b.installed === false) {
    note.classList.remove('hidden');
    note.innerHTML =
      b.problem === 'db'
        ? 'The system cannot reach its database. Start <b>MySQL</b> in the XAMPP Control Panel, check <code>api/config.php</code>, then reload this page.'
        : 'The system is not installed yet. Open <a href="install.php">install.php</a> to create the administrator account.';
    $('loginBtn').disabled = true;
  } else {
    note.classList.add('hidden');
    if (!lockTimer) $('loginBtn').disabled = false;
  }
}
function startCountdown(sec) {
  clearInterval(lockTimer);
  const end = Date.now() + sec * 1000,
    btn = $('loginBtn');
  btn.disabled = true;
  const tick = () => {
    const left = Math.max(0, Math.round((end - Date.now()) / 1000));
    if (!left) {
      clearInterval(lockTimer);
      btn.disabled = false;
      say($('loginMsg'), '');
      return;
    }
    say(
      $('loginMsg'),
      `Too many wrong entries. Sign-in is blocked.\nTry again in ${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}.`
    );
  };
  tick();
  lockTimer = setInterval(tick, 1000);
}
let forcing = false;
function forcePassword() {
  if (forcing) return;
  forcing = true;
  modal(
    `<h3>Choose your own password</h3><p class="muted">Your account uses a temporary password. Choose a new one to continue. Use at least 8 characters with letters and numbers.</p>
    <label>Temporary password<input type="password" id="fp0" autocomplete="current-password"></label><label style="margin-top:8px">New password<input type="password" id="fp1" autocomplete="new-password"></label>
    <label style="margin-top:8px">Repeat new password<input type="password" id="fp2" autocomplete="new-password"></label><div class="msg" id="fpMsg"></div>
    <div class="row end"><button class="btn" data-out>Sign out</button><button class="btn pri" data-yes>Save and continue</button></div>`,
    (o, close) => {
      o.querySelector('[data-out]').onclick = async () => {
        close();
        forcing = false;
        try {
          const r = await api('logout', { method: 'POST' });
          State.csrf = r.csrf;
        } catch (e) {}
        State.user = null;
        showLogin();
      };
      o.querySelector('[data-yes]').onclick = async () => {
        const m = o.querySelector('#fpMsg');
        if (o.querySelector('#fp1').value !== o.querySelector('#fp2').value)
          return say(m, 'The new passwords do not match.');
        try {
          await api('change_password', {
            method: 'POST',
            json: { current: o.querySelector('#fp0').value, new: o.querySelector('#fp1').value },
          });
          close();
          forcing = false;
          await startApp(await api('session'));
        } catch (err) {
          say(m, err.message);
        }
      };
    }
  );
}
async function startApp(session) {
  State.user = session.user;
  State.set = session.settings;
  State.brand = session.brand || State.brand;
  State.streams = session.streams || {};
  State.csrf = session.csrf || State.csrf;
  State.picked.clear();
  $('login').classList.add('hidden');
  $('app').classList.remove('hidden');
  $('meName').textContent = State.user.full_name;
  $('meRole').textContent = State.user.role_name;
  applyNav();
  brand();
  if (State.user.must_change) {
    forcePassword();
    return;
  }
  const want = location.hash.slice(1),
    first = [...document.querySelectorAll('#nav button')].find(b => !b.classList.contains('hidden'));
  const b = document.querySelector(`#nav button[data-tab="${want}"]:not(.hidden)`) || first;
  if (b) b.click();
}
/** Menus follow the person's permissions; until the school is set up only the Dashboard (with the setup form) is available. */
function applyNav() {
  const ready = !!State.set.setup_done;
  document.querySelectorAll('[data-perm]').forEach(el => {
    const inNav = !!el.closest('#nav');
    el.classList.toggle('hidden', !canAny(el.dataset.perm) || (inNav && !ready && el.dataset.tab !== 'dash'));
  });
  document
    .querySelectorAll('#designForm input,#designForm select')
    .forEach(el => (el.disabled = !can('design.manage')));
}
const admExample = () => (State.set.setup_done && State.set.code ? State.set.code : 'ABC') + '/0001';
function brand() {
  const ready = !!State.set.setup_done,
    product = State.set.product || 'Visitation Cards Management System';
  $('brandName').textContent = ready ? State.set.name : 'Visitation Cards';
  document.querySelector('.brand small').textContent = ready ? product : 'Management System';
  $('brandLogo').src = State.set.logo || 'assets/img/vcms-logo.png';
  document.title = ready ? State.set.name + ' - ' + product : product;
  State.brand = { ...State.brand, school: ready ? State.set.name : '', logo: State.set.logo || null, installed: true };
  document.querySelectorAll('.admEx').forEach(e => (e.textContent = admExample()));
  document.querySelectorAll('.admExF').forEach(e => (e.textContent = admExample().replace('/', '_') + '.jpg'));
}
$('loginForm').onsubmit = async e => {
  e.preventDefault();
  const f = e.target;
  $('loginBtn').disabled = true;
  try {
    const r = await api('login', {
      method: 'POST',
      json: { username: f.elements.username.value, password: f.elements.password.value, dash: 1 },
    });
    State.csrf = r.csrf;
    if (r.dash) dashSeed = { data: r.dash, at: Date.now() };
    say($('loginMsg'), '');
    await startApp(r);
  } catch (err) {
    err.retry ? startCountdown(err.retry) : say($('loginMsg'), err.message);
  } finally {
    if (!lockTimer || !$('loginBtn').disabled) $('loginBtn').disabled = false;
    if (State.user) $('loginBtn').disabled = false;
  }
};
$('logoutBtn').onclick = async () => {
  try {
    const r = await api('logout', { method: 'POST' });
    State.csrf = r.csrf;
  } catch (e) {}
  State.user = null;
  showLogin();
};
$('pwBtn').onclick = () =>
  modal(
    `<h3>Change password</h3><label>Current password<input type="password" id="pw0"></label><label style="margin-top:8px">New password (8+ characters, letters and numbers)<input type="password" id="pw1"></label><label style="margin-top:8px">Repeat new password<input type="password" id="pw2"></label><div class="msg" id="pwMsg"></div><div class="row end"><button class="btn" data-no>Cancel</button><button class="btn pri" data-yes>Change</button></div>`,
    (o, close) => {
      o.querySelector('[data-no]').onclick = close;
      o.querySelector('[data-yes]').onclick = async () => {
        const m = o.querySelector('#pwMsg');
        if (o.querySelector('#pw1').value !== o.querySelector('#pw2').value)
          return say(m, 'The new passwords do not match.');
        try {
          await api('change_password', {
            method: 'POST',
            json: { current: o.querySelector('#pw0').value, new: o.querySelector('#pw1').value },
          });
          close();
          ask('Password changed.');
        } catch (err) {
          say(m, err.message);
        }
      };
    }
  );

/* ---------- navigation ---------- */
function goTab(tab, sub) {
  const b = document.querySelector(`#nav button[data-tab="${tab}"]:not(.hidden)`);
  if (b) b.click();
  if (sub) setSub(sub);
}
document.querySelectorAll('#nav button').forEach(
  b =>
    (b.onclick = () => {
      document.querySelectorAll('#nav button').forEach(x => x.classList.remove('on'));
      document.querySelectorAll('main section').forEach(x => x.classList.remove('on'));
      b.classList.add('on');
      $(b.dataset.tab).classList.add('on');
      $('side').classList.remove('open');
      $('pageTitle').textContent = TITLES[b.dataset.tab];
      history.replaceState(null, '', '#' + b.dataset.tab);
      renderAll();
    })
);
function renderAll() {
  brand();
  const go = {
    dash: loadDash,
    reg: renderRegisterForm,
    stu: loadStu,
    photos: initPhotos,
    cards: loadCards,
    promo: renderPromo,
    streams: renderStreams,
    des: renderDesign,
    rep: initReports,
    usr: loadUsers,
    bak: loadBackups,
  }[activeTab()];
  if (go)
    Promise.resolve(go()).catch(err => {
      if (err.status !== 401 && !err.aborted) console.error(err);
    });
}
/* ---------- dashboard (all numbers are computed by the server) ---------- */
let dashSeed = null; // the first dashboard numbers arrive with the sign-in reply, so the dashboard needs no request of its own
async function loadDash() {
  const ok = newest('dash'),
    seed = dashSeed && Date.now() - dashSeed.at < 15000 ? dashSeed.data : null;
  dashSeed = null;
  const ready = !!State.set.setup_done;
  $('dash').classList.toggle('setup-mode', !ready);
  $('setupBox').classList.toggle('hidden', ready || !can('design.manage'));
  $('setupWait').classList.toggle('hidden', ready || can('design.manage'));
  if (!ready) {
    // first run: the administrator describes the school before anything else can be done
    $('schoolHead').classList.add('hidden');
    $('dashAlert').innerHTML = '';
    $('gettingStarted').innerHTML = '';
    if (can('design.manage')) initSetupForm();
    return;
  }
  renderSchoolHead();
  if (!can('students.view')) {
    $('kpis').innerHTML = '';
    $('heat').innerHTML = '';
    $('recent').innerHTML = '';
    return;
  }
  const d = seed || (await api('stats'));
  if (!ok()) return;
  renderGettingStarted(d);
  let alert = '';
  if (d.backup && !d.backup.ok)
    alert = `<div class="banner er"><b>Backup warning.</b> ${d.backup.never ? 'No backup has been made yet.' : d.backup.error ? 'The last backup failed: ' + esc(d.backup.error) : 'The last good backup is ' + Math.round(d.backup.age_hours) + ' hours old.'} Open <a href="#bak" data-go="bak">Backups</a> and make one now.</div>`;
  if (State.set.verify_local && can('design.manage'))
    alert += `<div class="banner wa"><b>The QR codes only work on this computer.</b> They point to ${esc(State.set.verify_base)}. Set the network address under <a href="#des" data-go="des">Card design</a> before printing cards.</div>`;
  $('dashAlert').innerHTML = alert;
  $('kpis').innerHTML = [
    ['Students', d.total],
    ['Cards printed', d.printed],
    ['Waiting to print', d.total - d.printed],
    ['Without photo', d.nophoto],
  ]
    .map(x => `<div class="kpi"><b>${x[1]}</b><span>${x[0]}</span></div>`)
    .join('');
  const cnt = {};
  d.heat.forEach(x => (cnt[x.cls + '|' + x.stream] = +x.n));
  CLASSES.forEach(c =>
    (State.streams[c] || []).forEach(n => {
      cnt[c + '|' + n] = cnt[c + '|' + n] || 0;
    })
  );
  const rows = Object.keys(cnt)
    .map(k => k.split('|'))
    .sort((a, b) => CLASSES.indexOf(a[0]) - CLASSES.indexOf(b[0]) || a[1].localeCompare(b[1]));
  $('heat').innerHTML = rows.length
    ? '<table><tr><th>Class</th><th>Stream</th><th>Students</th></tr>' +
      rows
        .map(r => `<tr><td>${esc(r[0])}</td><td>${esc(r[1])}</td><td>${cnt[r[0] + '|' + r[1]]}</td></tr>`)
        .join('') +
      '</table>'
    : '<p class="muted">No streams set up yet.</p>';
  $('recent').innerHTML = d.recent.length
    ? '<table><tr><th></th><th>Name</th><th>Class</th><th>Adm No</th><th>Date</th></tr>' +
      d.recent
        .map(
          s =>
            `<tr><td>${avatar(s)}</td><td>${esc(s.first)} ${esc(s.last)}</td><td>${esc(s.cls)} ${esc(s.stream)}</td><td>${esc(s.adm)}</td><td>${esc(s.reg)}</td></tr>`
        )
        .join('') +
      '</table>'
    : '<p class="muted">No students registered yet.</p>';
}
function renderSchoolHead() {
  const s = State.set,
    el = $('schoolHead'),
    line = [s.address, s.contact && 'Tel: ' + s.contact].filter(Boolean).map(esc).join(' &middot; ');
  el.classList.remove('hidden');
  el.innerHTML = `<img src="${esc(s.logo || 'assets/img/vcms-logo.png')}" alt="">
    <div class="grow"><h2>${esc(s.name)}</h2>${s.motto ? `<p class="motto">${esc(s.motto)}</p>` : ''}${line ? `<p class="muted">${line}</p>` : ''}</div>
    <div class="chip">Admission numbers<br><b>${esc(admExample())}</b></div>`;
}
/** Shown to the administrator until the first student exists: what is left to do before the system is in use. */
function renderGettingStarted(d) {
  const el = $('gettingStarted');
  if (!can('design.manage') || d.total > 0) {
    el.innerHTML = '';
    return;
  }
  const items = [
    [true, 'School information saved', ''],
    [Object.values(State.streams).some(l => l.length), 'Set up the classes and streams', 'streams'],
    [!State.set.verify_local, 'Set the QR verification address, so phones can check cards', 'des'],
    [!(d.backup && d.backup.never), 'Make the first backup', 'bak'],
    [false, 'Register the first students', 'reg'],
  ];
  el.innerHTML =
    '<div class="card"><h3>Getting started</h3><ul class="checklist">' +
    items
      .map(
        ([done, label, go]) =>
          `<li class="${done ? 'done' : ''}"><span>${done ? '&#10003;' : '&#9633;'}</span>${go && !done ? `<a href="#${go}" data-go="${go}">${esc(label)}</a>` : esc(label)}</li>`
      )
      .join('') +
    '</ul></div>';
}
document.addEventListener('click', e => {
  const b = e.target.closest('[data-go]');
  if (b) {
    e.preventDefault();
    goTab(b.dataset.go, b.dataset.sub);
  }
});

/* ---------- register: manual form ---------- */
const sf = $('studentForm');
attachLiveFilters(sf);
function setSub(sub) {
  document.querySelectorAll('#regSeg button').forEach(b => b.classList.toggle('on', b.dataset.sub === sub));
  $('subManual').classList.toggle('hidden', sub !== 'manual');
  $('subExcel').classList.toggle('hidden', sub !== 'excel');
}
$('regSeg').addEventListener('click', e => {
  if (e.target.dataset.sub) setSub(e.target.dataset.sub);
});
function fillStreamSelect(selected) {
  const cls = sf.elements.cls.value;
  let list = State.streams[cls] || [];
  if (selected && !list.includes(selected)) list = [...list, selected];
  sf.elements.stream.innerHTML = list.length
    ? opts(list, selected)
    : '<option value="">(no streams set up)</option>';
}
function renderRegisterForm() {
  const classes = CLASSES.filter(c => (State.streams[c] || []).length),
    cur = sf.elements.cls.value;
  sf.elements.cls.innerHTML = opts(classes, classes.includes(cur) ? cur : classes[0]);
  fillStreamSelect(sf.elements.stream.value);
  $('noStreams').classList.toggle('hidden', classes.length > 0);
  $('saveBtn').disabled = !classes.length;
}
sf.elements.cls.addEventListener('change', () => fillStreamSelect(''));
function resetForm(keepMsg) {
  State.editing = null;
  sf.reset();
  sf.elements.rev.value = '';
  sf.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
  $('admNote').classList.remove('hidden');
  $('admShow').classList.add('hidden');
  $('regTitle').textContent = 'Register a student';
  $('saveBtn').textContent = 'Save student';
  if (!keepMsg) say($('regMsg'), '');
  renderRegisterForm();
}
$('clearBtn').onclick = () => resetForm();
sf.onsubmit = async e => {
  e.preventDefault();
  const d = Object.fromEntries(new FormData(sf)),
    file = sf.elements.photo.files[0];
  const er = validateStudent(d, file, State.streams);
  if (er.length) return showErrors(sf, $('regMsg'), er);
  const fd = new FormData(sf);
  if (State.editing) fd.append('student_id', State.editing.id);
  $('saveBtn').disabled = true;
  try {
    const r = await api('student_save', { method: 'POST', form: fd });
    const name = d.first + ' ' + d.last,
      noPhoto = !file && !(State.editing && State.editing.photo),
      edited = !!State.editing;
    if (!edited) State.set.code_locked = true; // the initials are part of admission numbers from now on
    resetForm(true);
    say(
      $('regMsg'),
      `Saved ${name}. ${edited ? '' : 'Admission number: ' + r.adm + '. '}` +
        (r.photo_error ? 'The details were saved but the photo was not: ' + r.photo_error + ' ' : '') +
        (noPhoto && !r.photo_error ? "No photo yet: add it under Photos or on the student's row." : ''),
      !r.photo_error
    );
  } catch (err) {
    err.details.length ? showErrors(sf, $('regMsg'), err.details) : fail($('regMsg'), err);
  } finally {
    $('saveBtn').disabled = false;
  }
};
async function editStudent(id) {
  let s;
  try {
    s = (await api('student_get', { query: { id } })).student;
  } catch (err) {
    return ask(err.message);
  }
  goTab('reg', 'manual');
  State.editing = s;
  renderRegisterForm();
  for (const k of ['first', 'last', 'origin', 'pf', 'pl', 'pc', 'pa', 'rel'])
    sf.elements[k].value = s[k] ?? '';
  sf.elements.rev.value = s.rev;
  sf.elements.cls.innerHTML = opts(
    [...new Set([...CLASSES.filter(c => (State.streams[c] || []).length), s.cls])].sort(),
    s.cls
  );
  fillStreamSelect(s.stream);
  $('admNote').classList.add('hidden');
  $('admShow').classList.remove('hidden');
  $('admVal').textContent = s.adm;
  $('regTitle').textContent = 'Edit ' + s.first + ' ' + s.last + ' (the card will be marked to print again)';
  $('saveBtn').textContent = 'Save changes';
}

/* ---------- register: Excel upload (check first, then import) ---------- */
function showImport(r) {
  const head = `<p><b>${r.total}</b> row(s) read: <span class="tag ok">${r.valid} ready</span> <span class="tag ${r.rejected ? 'er' : ''}">${r.rejected} rejected</span>${r.rows.length < r.total ? ` <span class="muted small">(first ${r.rows.length} rows shown)</span>` : ''}</p>`;
  $('xlTable').innerHTML =
    head +
    '<table><tr><th>Row</th><th>Admission</th><th>Name</th><th>Class</th><th>Result</th></tr>' +
    r.rows
      .map(
        x =>
          `<tr><td>${x.row}</td><td>${esc(x.adm)}</td><td>${esc(x.name)}</td><td>${esc((x.cls + ' ' + x.stream).trim())}</td><td>${x.ok ? '<span class="tag ok">' + (r.commit ? 'imported' : 'ready') + '</span>' : '<span class="tag er">rejected</span> ' + esc(x.msg)}</td></tr>`
      )
      .join('') +
    '</table>';
}
$('xlFile').onchange = () => {
  $('xlImport').classList.add('hidden');
  $('xlTable').innerHTML = '';
  say($('xlMsg'), '');
};
$('xlCheck').onclick = async () => {
  const f = $('xlFile').files[0];
  if (!f) return say($('xlMsg'), 'Choose the filled Excel (.xlsx) or CSV file first.');
  const fd = new FormData();
  fd.append('file', f);
  say($('xlMsg'), 'Checking every row...');
  try {
    const r = await api('import_file', { method: 'POST', form: fd });
    showImport(r);
    say(
      $('xlMsg'),
      r.valid
        ? 'Nothing is saved yet. Review the table, then import. Admission numbers are assigned when you import.'
        : 'No valid rows to import. Fix the file and check again.',
      !!r.valid
    );
    $('xlImport').classList.toggle('hidden', !r.valid);
    $('xlImport').textContent = `Import ${r.valid} valid student(s)`;
  } catch (err) {
    $('xlTable').innerHTML = '';
    $('xlImport').classList.add('hidden');
    say($('xlMsg'), err.message);
  }
};
$('xlImport').onclick = async () => {
  const f = $('xlFile').files[0];
  if (!f) return;
  const fd = new FormData();
  fd.append('file', f);
  fd.append('commit', '1');
  $('xlImport').disabled = true;
  say($('xlMsg'), 'Importing...', true);
  try {
    const r = await api('import_file', { method: 'POST', form: fd });
    showImport(r);
    $('xlImport').classList.add('hidden');
    say(
      $('xlMsg'),
      `Imported ${r.valid} student(s)` +
        (r.rejected ? `; ${r.rejected} row(s) were rejected and not saved.` : '.') +
        ' Next: add their photos under Photos.',
      true
    );
  } catch (err) {
    say($('xlMsg'), err.message);
  } finally {
    $('xlImport').disabled = false;
  }
};

/* ---------- student table: one page at a time, search and filters on the server ---------- */
function fillClassStream(cSel, sSel, allLabel) {
  const c = cSel.value;
  cSel.innerHTML = `<option value="">${allLabel}</option>` + opts(CLASSES, c);
  cSel.value = c;
  const names = c
      ? State.streams[c] || []
      : [...new Set(CLASSES.flatMap(x => State.streams[x] || []))].sort(),
    s = sSel.value;
  sSel.innerHTML = '<option value="">All streams</option>' + opts(names, s);
  sSel.value = names.includes(s) ? s : '';
}
const stuFilters = () => ({
  q: $('q').value.trim(),
  cls: $('sfc').value,
  stream: $('sfs').value,
  photo: $('sfp').value,
  status: $('sfst').value,
});
async function loadStu() {
  fillClassStream($('sfc'), $('sfs'), 'All classes');
  const req = latest('stu'),
    f = stuFilters();
  $('expBtn').href = 'api/index.php?action=export_csv&' + qs(f);
  $('namesBtn').href = 'api/index.php?action=names_csv&' + qs({ cls: f.cls, stream: f.stream });
  let r;
  try {
    r = await api('students', {
      query: { ...f, page: State.stu.page, size: State.stu.size, thumbs: 1 },
      signal: req.signal,
    });
  } catch (err) {
    return err.aborted ? null : say($('stuMsg'), err.message);
  }
  if (!req.ok()) return;
  Object.assign(State.stu, { page: r.page, pages: r.pages, total: r.total });
  const del = can('students.delete'),
    wr = can('students.write'),
    ph = can('photos.upload');
  $('stuTbl').innerHTML =
    '<table><tr>' +
    (del ? '<th><input type="checkbox" id="selAll"></th>' : '') +
    '<th></th><th>Adm</th><th>Name</th><th>Class</th><th>Guardian</th><th>Card no</th><th>Status</th><th></th></tr>' +
    r.students
      .map(
        s => `<tr>${del ? `<td><input type="checkbox" class="sel" value="${s.id}"></td>` : ''}<td>${avatar(s)}</td><td>${esc(s.adm)}</td><td>${esc(s.first)} ${esc(s.last)}</td><td>${esc(s.cls)} ${esc(s.stream)}${s.status !== 'active' ? ` <span class="tag mu">${esc(s.status)}</span>` : ''}</td><td>${esc(s.pf)} ${esc(s.pl)} (${esc(s.pc)})</td><td>${esc(s.cardNo || '')}</td>
<td><span class="tag ${s.printed ? 'ok' : 'wa'}">${s.printed ? 'Printed x' + s.prints : 'Unprinted'}</span> ${s.photo ? '' : '<span class="tag er">No photo</span>'}</td>
<td>${wr ? `<button class="btn sm" data-edit="${s.id}">Edit</button> ` : ''}${ph ? `<button class="btn sm" data-photo="${s.id}">Photo</button> ` : ''}${del ? `<button class="btn sm dng" data-del="${s.id}" data-label="${esc(s.adm)} (${esc(s.first)} ${esc(s.last)})">Delete</button>` : ''}</td></tr>`
      )
      .join('') +
    '</table>' +
    (r.students.length ? '' : '<p class="muted">No students found.</p>');
  pager($('stuPager'), State.stu, 'stu', 'student(s)');
}
const reloadStu = () => {
  State.stu.page = 1;
  loadStu();
};
$('q').oninput = debounce(reloadStu, 250);
['sfc', 'sfs', 'sfp', 'sfst'].forEach(id => $(id).addEventListener('change', reloadStu));
let photoTarget = null;
$('stuTbl').addEventListener('click', e => {
  const t = e.target;
  if (t.id === 'selAll') document.querySelectorAll('.sel').forEach(c => (c.checked = t.checked));
  if (t.dataset.edit) editStudent(+t.dataset.edit);
  if (t.dataset.photo) {
    photoTarget = +t.dataset.photo;
    $('rowPhoto').value = '';
    $('rowPhoto').click();
  }
  if (t.dataset.del)
    deleteStudents([+t.dataset.del], `Delete ${t.dataset.label} and all their cards? This cannot be undone.`);
});
$('rowPhoto').onchange = async () => {
  const f = $('rowPhoto').files[0];
  if (!f || !photoTarget) return;
  if (!/^image\//.test(f.type)) return say($('stuMsg'), 'That file is not an image.');
  const fd = new FormData();
  fd.append('student_id', photoTarget);
  fd.append('photo', f);
  try {
    await api('student_photo', { method: 'POST', form: fd });
    say($('stuMsg'), 'Photo saved.', true);
    loadStu();
  } catch (err) {
    say($('stuMsg'), err.message);
  }
};
$('delSelBtn').onclick = () => {
  const ids = [...document.querySelectorAll('.sel:checked')].map(c => +c.value);
  if (!ids.length) return ask('Tick the students you want to delete first.');
  deleteStudents(ids, `Delete ${ids.length} selected student(s) and their cards? This cannot be undone.`);
};
function deleteStudents(ids, msg) {
  ask(msg, async () => {
    try {
      await api('student_delete', { method: 'POST', json: { ids } });
      ids.forEach(i => State.picked.delete(i));
      loadStu();
    } catch (err) {
      ask(err.message);
    }
  });
}

/* ---------- photos: bulk upload, one file per request, matched by admission number or by name ---------- */
function initPhotos() {
  fillClassStream($('phCls'), $('phStream'), 'All classes');
  $('phNames').href =
    'api/index.php?action=names_csv&' + qs({ cls: $('phCls').value, stream: $('phStream').value });
}
$('phCls').addEventListener('change', initPhotos);
$('phStream').addEventListener('change', initPhotos);
const PH_TAG = {
  saved: ['ok', 'saved'],
  skipped: ['wa', 'skipped'],
  no_match: ['er', 'no match'],
  ambiguous: ['er', 'ambiguous'],
  invalid: ['er', 'rejected'],
  error: ['er', 'failed'],
};
$('phGo').onclick = async () => {
  const files = [...$('phFiles').files];
  if (!files.length) return say($('phMsg'), 'Choose the photos first.');
  const replace = $('phReplace').checked ? '1' : '0',
    rows = [],
    cnt = { saved: 0, skipped: 0, no_match: 0, ambiguous: 0, invalid: 0, error: 0 };
  let done = 0,
    next = 0;
  $('phGo').disabled = true;
  $('phBarWrap').classList.remove('hidden');
  $('phTable').innerHTML = '';
  const draw = () => {
    $('phBar').style.width = Math.round((done / files.length) * 100) + '%';
    say(
      $('phMsg'),
      `${done} of ${files.length} done: ${cnt.saved} saved, ${cnt.skipped} skipped, ${cnt.no_match + cnt.ambiguous} not matched, ${cnt.invalid + cnt.error} rejected.`,
      true
    );
    $('phTable').innerHTML =
      '<table><tr><th>File</th><th>Result</th><th>Student</th><th>Note</th></tr>' +
      rows
        .slice(-400)
        .reverse()
        .map(
          x =>
            `<tr><td>${esc(x.file)}</td><td><span class="tag ${PH_TAG[x.status][0]}">${PH_TAG[x.status][1]}</span></td><td>${x.student ? esc(x.student.adm + ' ' + x.student.name) : ''}</td><td class="wrap">${esc(x.msg)}</td></tr>`
        )
        .join('') +
      '</table>';
  };
  const worker = async () => {
    while (next < files.length) {
      const f = files[next++],
        fd = new FormData();
      fd.append('file', f);
      fd.append('name', f.name);
      fd.append('replace', replace);
      let r;
      try {
        r = await api('photos_bulk', { method: 'POST', form: fd });
      } catch (err) {
        r = { status: 'error', file: f.name, msg: err.message };
      }
      cnt[r.status] = (cnt[r.status] || 0) + 1;
      rows.push(r);
      done++;
      if (done % 3 === 0 || done === files.length) draw();
    }
  };
  await Promise.all([worker(), worker(), worker()]);
  draw();
  say(
    $('phMsg'),
    `Finished: ${cnt.saved} photo(s) saved to the right students` +
      (files.length - cnt.saved ? `; ${files.length - cnt.saved} not saved (see the Result column).` : '.'),
    cnt.saved === files.length
  );
  $('phGo').disabled = false;
};

/* ---------- cards and printing ---------- */
const cardFilters = () => ({
  cls: $('fc').value,
  stream: $('fs').value,
  printed: $('fst').value,
  q: $('fq').value.trim(),
});
async function loadCards() {
  fillClassStream($('fc'), $('fs'), 'All classes');
  const w = $('qrWarn');
  w.classList.toggle('hidden', !State.set.verify_local);
  if (State.set.verify_local)
    w.innerHTML = `<b>The QR codes on these cards point to ${esc(State.set.verify_base)}</b>, which a phone cannot reach. ${can('design.manage') ? 'Set the network address in <a href="#des" data-go="des">Card design</a> first.' : 'Ask the administrator to set the verification address before printing.'}`;
  const req = latest('cards');
  let r;
  try {
    r = await api('students', {
      query: { ...cardFilters(), page: State.cards.page, size: State.cards.size, qr: 1 },
      signal: req.signal,
    });
  } catch (err) {
    return err.aborted ? null : say($('pmsg'), err.message);
  }
  if (!req.ok()) return;
  Object.assign(State.cards, { page: r.page, pages: r.pages, total: r.total, rows: r.students });
  const picked = State.picked.size;
  $('cnt').textContent = r.total + ' card(s) match' + (picked ? ', ' + picked + ' selected' : '');
  $('printBtn').innerHTML = picked ? `Print ${picked} selected` : `Print all ${r.total} matching`;
  $('pdfBtn').textContent = picked ? `Save PDF (${picked} selected)` : `Save PDF (all ${r.total})`;
  $('printBtn').disabled = $('pdfBtn').disabled = !r.total;
  $('cardList').innerHTML =
    r.students
      .map(
        s =>
          `<div class="cw">${cardHTML(s, State.set)}<div class="pickrow"><label style="flex-direction:row;align-items:center;gap:6px;color:var(--tx)"><input type="checkbox" class="pick" data-id="${s.id}"${State.picked.has(s.id) ? ' checked' : ''}> Select</label><button class="btn sm" data-card="${s.id}" data-do="print">Print this card</button><button class="btn sm" data-card="${s.id}" data-do="pdf">PDF</button>${s.printed ? '<span class="tag ok">printed</span>' : ''}</div></div>`
      )
      .join('') || '<p class="muted">No cards match.</p>';
  pager($('cardPager'), State.cards, 'cards', 'card(s)');
}
const reloadCards = () => {
  State.cards.page = 1;
  loadCards();
};
$('fq').oninput = debounce(reloadCards, 250);
['fc', 'fs', 'fst'].forEach(id => $(id).addEventListener('change', reloadCards));
/** Everything the filters match (not just the visible page), fetched 200 at a time. */
async function fetchAllCards() {
  const out = [];
  let page = 1,
    pages = 1;
  do {
    const r = await api('students', { query: { ...cardFilters(), page, size: 200, qr: 1 } });
    out.push(...r.students);
    pages = r.pages;
    page++;
    say($('pmsg'), `Loading cards: ${out.length} of ${r.total}...`, true);
  } while (page <= pages);
  return out;
}
const targetCards = async () => (State.picked.size ? [...State.picked.values()] : fetchAllCards());
async function markPrinted(list, action) {
  try {
    for (let i = 0; i < list.length; i += 500)
      await api('mark_printed', {
        method: 'POST',
        json: { ids: list.slice(i, i + 500).map(s => s.id), action },
      });
    State.picked.clear();
    loadCards();
  } catch (err) {
    say($('pmsg'), 'Done, but the printed status could not be saved: ' + err.message);
  }
}
async function doPrint(getList) {
  let list;
  try {
    list = await getList();
  } catch (err) {
    return say($('pmsg'), err.message);
  }
  if (!list.length) return say($('pmsg'), 'Nothing selected.');
  const go = () => {
    const miss = list.filter(s => !s.photo).length;
    say(
      $('pmsg'),
      `Opening the print dialog for ${list.length} card(s), 4 per A4 page.` +
        (miss ? ` ${miss} have no photo and print a placeholder.` : ''),
      true
    );
    printHTML(sheetsHTML(list, State.set), () => markPrinted(list, 'print'));
  };
  list.length > 600
    ? ask(
        `${list.length} cards is a lot for the browser's print dialog. "Save PDF" is faster and safer for large batches. Print anyway?`,
        go,
        'Print anyway'
      )
    : go();
}
async function doPdf(getList, name) {
  let list;
  try {
    list = await getList();
  } catch (err) {
    return say($('pmsg'), err.message);
  }
  if (!list.length) return say($('pmsg'), 'Nothing selected.');
  try {
    const per = await cardsToPdf(list, State.set, name, (i, n) =>
      say($('pmsg'), `Building PDF: card ${i} of ${n}...`, true)
    );
    say($('pmsg'), `PDF saved: ${list.length} card(s), ${per} per A4 page.`, true);
    markPrinted(list, 'pdf');
  } catch (err) {
    say($('pmsg'), 'Could not create the PDF: ' + err.message);
  }
}
$('printBtn').onclick = () => doPrint(targetCards);
$('pdfBtn').onclick = () => doPdf(targetCards, 'visitation-cards.pdf');
$('pickAll').onclick = () => {
  State.cards.rows.forEach(s => State.picked.set(s.id, s));
  loadCards();
};
$('pickNone').onclick = () => {
  State.picked.clear();
  loadCards();
};
$('cardList').addEventListener('change', e => {
  if (!e.target.classList.contains('pick')) return;
  const id = +e.target.dataset.id;
  e.target.checked
    ? State.picked.set(
        id,
        State.cards.rows.find(s => s.id === id)
      )
    : State.picked.delete(id);
  const n = State.picked.size;
  $('cnt').textContent = State.cards.total + ' card(s) match' + (n ? ', ' + n + ' selected' : '');
  $('printBtn').innerHTML = n ? `Print ${n} selected` : `Print all ${State.cards.total} matching`;
  $('pdfBtn').textContent = n ? `Save PDF (${n} selected)` : `Save PDF (all ${State.cards.total})`;
});
$('cardList').addEventListener('click', e => {
  const id = +e.target.dataset.card;
  if (!id) return;
  const s = State.cards.rows.find(x => x.id === id);
  e.target.dataset.do === 'print'
    ? doPrint(async () => [s])
    : doPdf(async () => [s], 'card-' + s.adm.replace(/[^\w-]/g, '_') + '.pdf');
});

/* ---------- promotion ---------- */
const nextClass = c => CLASSES[CLASSES.indexOf(c) + 1] || 'graduate';
function promoPayload() {
  const to = $('pTo').value,
    map = {};
  document.querySelectorAll('#pMap select').forEach(s => (map[s.dataset.from] = s.value));
  return { from_cls: $('pFrom').value, to, map, ids: [...State.promo.ticked] };
}
const PTO = { graduate: 'Graduated (finished school)', left: 'Left school' };
function renderPromo() {
  const from = $('pFrom').value || 'S1';
  $('pFrom').innerHTML = opts(CLASSES, from);
  const cur = $('pTo').value;
  $('pTo').innerHTML = [
    ...CLASSES.filter(c => c !== from).map(c => `<option value="${c}">${c}</option>`),
    ...Object.entries(PTO).map(([k, v]) => `<option value="${k}">${v}</option>`),
  ].join('');
  $('pTo').value = [...$('pTo').options].some(o => o.value === cur) && cur !== from ? cur : nextClass(from);
  loadPromoList();
  loadPromoHist();
}
async function loadPromoList() {
  const from = $('pFrom').value,
    ok = newest('promo');
  say($('pMsg'), '');
  $('pOut').innerHTML = '';
  const rows = [];
  let page = 1,
    pages = 1;
  try {
    do {
      const r = await api('students', { query: { cls: from, size: 200, page, sort: '' } });
      rows.push(...r.students);
      pages = r.pages;
      page++;
    } while (page <= pages);
  } catch (err) {
    return say($('pMsg'), err.message);
  }
  if (!ok()) return;
  State.promo.rows = rows;
  State.promo.ticked = new Set(rows.map(s => s.id));
  renderPromoMap();
  renderPromoList();
}
function renderPromoMap() {
  const to = $('pTo').value,
    leaving = !!PTO[to];
  if (leaving) {
    $('pMap').innerHTML =
      `<p class="muted">Students keep their class and stream on record but are marked <b>${esc(PTO[to].toLowerCase())}</b>; their cards stop being valid.</p>`;
    return;
  }
  const targets = State.streams[to] || [],
    src = [
      ...new Set([...(State.streams[$('pFrom').value] || []), ...State.promo.rows.map(s => s.stream)]),
    ].sort();
  $('pMap').innerHTML = targets.length
    ? '<div class="stepbox"><b>Streams</b>: where does each current stream go in ' +
      esc(to) +
      '?' +
      src
        .map(
          n =>
            `<div class="row" style="align-items:center;margin:4px 0"><span style="width:110px">${esc($('pFrom').value)} ${esc(n)} goes to</span><select data-from="${esc(n)}" style="max-width:180px">${opts(targets, targets.includes(n) ? n : targets[0])}</select></div>`
        )
        .join('') +
      '</div>'
    : `<div class="notice">${esc(to)} has no streams yet. Add them under <b>Classes &amp; streams</b> first.</div>`;
}
function renderPromoList() {
  const f = $('pFind').value.trim().toLowerCase(),
    rows = State.promo.rows.filter(
      s => !f || (s.first + ' ' + s.last + ' ' + s.adm).toLowerCase().includes(f)
    );
  $('pList').innerHTML =
    '<table><tr><th></th><th>Adm</th><th>Name</th><th>Stream</th></tr>' +
    rows
      .map(
        s =>
          `<tr><td><input type="checkbox" class="pk" value="${s.id}"${State.promo.ticked.has(s.id) ? ' checked' : ''}></td><td>${esc(s.adm)}</td><td>${esc(s.first)} ${esc(s.last)}</td><td>${esc(s.stream)}</td></tr>`
      )
      .join('') +
    '</table>' +
    (State.promo.rows.length ? '' : '<p class="muted">No enrolled students in this class.</p>');
  $('pCount').textContent = State.promo.ticked.size + ' of ' + State.promo.rows.length + ' ticked';
}
$('pFrom').onchange = () => {
  renderPromo();
};
$('pTo').onchange = renderPromoMap;
$('pFind').oninput = renderPromoList;
$('pAll').onclick = () => {
  State.promo.rows.forEach(s => State.promo.ticked.add(s.id));
  renderPromoList();
};
$('pNone').onclick = () => {
  State.promo.ticked.clear();
  renderPromoList();
};
$('pList').addEventListener('change', e => {
  if (e.target.classList.contains('pk')) {
    const id = +e.target.value;
    e.target.checked ? State.promo.ticked.add(id) : State.promo.ticked.delete(id);
    $('pCount').textContent = State.promo.ticked.size + ' of ' + State.promo.rows.length + ' ticked';
  }
});
$('pPreview').onclick = async () => {
  try {
    const r = await api('promote_preview', { method: 'POST', json: promoPayload() });
    $('pOut').innerHTML = `<div class="stepbox"><b>${r.count} student(s) would move:</b><ul>${Object.entries(
      r.groups
    )
      .map(([k, v]) => `<li>${esc(k)}: ${v}</li>`)
      .join(
        ''
      )}</ul>${r.target_occupied ? `<p class="muted">${esc($('pTo').value)} already has ${r.target_occupied} enrolled student(s); the promoted students join them.</p>` : ''}${r.problems.map(p => `<p style="color:var(--er)">${esc(p)}</p>`).join('')}</div>`;
    say($('pMsg'), '');
  } catch (err) {
    say($('pMsg'), err.message);
  }
};
$('pGo').onclick = () => {
  const p = promoPayload();
  if (!p.ids.length) return say($('pMsg'), 'Tick at least one student.');
  ask(
    `Move ${p.ids.length} student(s) from ${p.from_cls} to ${PTO[p.to] || p.to}? Their admission numbers and photos stay the same. You can undo this afterwards.`,
    async () => {
      try {
        const r = await api('promote', { method: 'POST', json: p });
        say($('pMsg'), `${r.moved} student(s) moved. Their cards are marked to be printed again.`, true);
        await loadPromoList();
        loadPromoHist();
      } catch (err) {
        say($('pMsg'), err.message);
      }
    },
    'Promote'
  );
};
$('yrPreview').onclick = async () => {
  try {
    const r = await api('promote_all', { method: 'POST', json: { preview: true, s6: $('s6mode').value } });
    $('yrOut').innerHTML =
      '<table><tr><th>Move</th><th>Students</th></tr>' +
      r.steps
        .map(
          x =>
            `<tr><td>${x.from} to ${x.to === 'graduate' ? 'Graduated' : x.to === 'left' ? 'Left school' : x.to}</td><td>${x.count}</td></tr>`
        )
        .join('') +
      '</table>' +
      (r.problems.length
        ? r.problems.map(p => `<p style="color:var(--er)">${esc(p)}</p>`).join('') +
          '<p class="muted">Add the missing streams under Classes and streams, then preview again. Nothing has been changed.</p>'
        : `<div class="row"><button class="btn pri" id="yrRun">Promote whole school (${r.steps.reduce((a, x) => a + x.count, 0)} students)</button></div>`);
    const run = $('yrRun');
    if (run)
      run.onclick = () =>
        ask(
          'Promote the whole school now? All moves happen together or not at all. You can undo it from the history below.',
          async () => {
            try {
              const x = await api('promote_all', { method: 'POST', json: { s6: $('s6mode').value } });
              $('yrOut').innerHTML =
                `<div class="banner ok">Done: ${x.moved} student(s) moved. Cards of moved students are marked to be printed again.</div>`;
              loadPromoHist();
              renderPromo();
            } catch (err) {
              $('yrOut').innerHTML = `<div class="banner er">${esc(err.message)} Nothing was changed.</div>`;
            }
          },
          'Promote everyone'
        );
  } catch (err) {
    $('yrOut').innerHTML = `<div class="banner er">${esc(err.message)}</div>`;
  }
};
async function loadPromoHist() {
  const r = await api('promotions');
  $('promoHist').innerHTML = r.batches.length
    ? '<table><tr><th>When</th><th>By</th><th>What</th><th>Students</th><th></th></tr>' +
      r.batches
        .map(
          b =>
            `<tr><td>${esc(fmtDate(b.at))}</td><td>${esc(b.by || '')}</td><td>${esc(b.label)}</td><td>${b.moved}</td><td>${b.undone ? '<span class="tag mu">undone</span>' : `<button class="btn sm" data-undo="${b.id}">Undo</button>`}</td></tr>`
        )
        .join('') +
      '</table>'
    : '<p class="muted">No promotions yet.</p>';
}
$('promoHist').addEventListener('click', e => {
  const id = +e.target.dataset.undo;
  if (!id) return;
  ask(
    'Put every student of this promotion back in their old class and stream? Students who were moved again since then are left where they are.',
    async () => {
      try {
        const r = await api('promote_undo', { method: 'POST', json: { id } });
        ask(
          `${r.restored} student(s) restored` +
            (r.skipped ? `; ${r.skipped} were changed since and were left alone.` : '.')
        );
        loadPromoHist();
        renderPromo();
      } catch (err) {
        ask(err.message);
      }
    },
    'Undo'
  );
});

/* ---------- classes and streams ---------- */
async function renderStreams() {
  if (!can('streams.manage')) return;
  const r = await api('streams');
  State.streams = r.streams;
  $('streamRows').innerHTML = CLASSES.map(
    c =>
      `<div class="row" style="align-items:center;flex-wrap:nowrap"><b style="width:44px">${c}</b><input data-cls="${c}" value="${esc((State.streams[c] || []).join(', '))}" placeholder="e.g. East, West, North" maxlength="120"></div>`
  ).join('');
}
$('streamSave').onclick = async () => {
  const streams = {};
  document.querySelectorAll('#streamRows input').forEach(i => (streams[i.dataset.cls] = i.value));
  try {
    const r = await api('streams_save', { method: 'POST', json: { streams } });
    State.streams = r.streams;
    say($('streamMsg'), 'All streams saved. Staff can now choose them when registering students.', true);
  } catch (err) {
    say(
      $('streamMsg'),
      err.details.length ? 'Not saved:\n- ' + err.details.map(e => e.m).join('\n- ') : err.message
    );
  }
};

/* ---------- design ---------- */
const df = $('designForm');
function designValues() {
  const v = { ...State.set };
  for (const k of ['name', 'motto', 'address', 'contact', 'color', 'font', 'watermark', 'signer'])
    v[k] = df.elements[k].value;
  v.card_w = +df.elements.card_w.value || 150;
  v.card_h = +df.elements.card_h.value || 64;
  if (df.elements.logo.files[0]) v.logo = URL.createObjectURL(df.elements.logo.files[0]);
  return v;
}
async function renderDesign() {
  try {
    State.set = (await api('settings')).settings; // fresh: another person may have registered students (this locks the initials)
  } catch (e) {}
  for (const k of [
    'name',
    'code',
    'motto',
    'address',
    'contact',
    'verify_url',
    'color',
    'font',
    'card_w',
    'card_h',
    'watermark',
    'signer',
  ])
    df.elements[k].value = State.set[k] ?? '';
  df.elements.code.disabled = !!State.set.code_locked || !can('design.manage');
  $('codeNote').textContent = State.set.code_locked
    ? 'The initials are locked: students have been registered and the initials are part of every admission number.'
    : 'The initials can still be changed, because no student has been registered yet.';
  const w = $('desWarn');
  w.classList.toggle('hidden', !State.set.verify_local);
  w.innerHTML = `QR codes currently point to <b>${esc(State.set.verify_base)}</b>, which only works on this computer. Type the address staff phones use to reach this computer (for example <code>http://192.168.1.10/vcms</code>) in "Verification address", then save. To find it: open a Command Prompt on this computer and run <code>ipconfig</code>; use the "IPv4 Address".`;
  drawPreview();
}
function drawPreview() {
  const base = (df.elements.verify_url.value.trim() || State.set.verify_base || '').replace(/\/$/, '');
  const s = {
    adm: admExample(),
    first: 'Sample',
    last: 'Student',
    cls: 'S1',
    stream: 'East',
    origin: 'Sample Town',
    rel: 'Father',
    pf: 'John',
    pl: 'Doe',
    pc: '0772856765',
    pa: 'Sample Address',
    photo: null,
    cardNo: 'VC-' + (State.set.code || 'ABC') + '-SAMPLE',
    vurl: base + '/verify.php?c=SAMPLE&k=000000000000',
  };
  $('prev').innerHTML = cardHTML(s, designValues());
}
df.elements.code.addEventListener('input', e => (e.target.value = e.target.value.toUpperCase().replace(/[^A-Z]/g, '')));
df.addEventListener('input', drawPreview);
df.onsubmit = async e => {
  e.preventDefault();
  try {
    const r = await api('settings_save', { method: 'POST', form: new FormData(df) });
    State.set = r.settings;
    df.elements.logo.value = '';
    df.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
    brand();
    renderDesign();
    say($('desMsg'), 'Design saved.', true);
  } catch (err) {
    err.details.length ? showErrors(df, $('desMsg'), err.details) : fail($('desMsg'), err);
  }
};

/* ---------- reports (server side, paged) ---------- */
function initReports() {
  if (State.report.kind) loadReport();
}
async function loadReport() {
  const k = State.report.kind;
  if (!k) return;
  const ok = newest('rep');
  let r;
  try {
    r = await api('report', { query: { kind: k, page: State.report.page, size: 50 } });
  } catch (err) {
    return ($('repOut').innerHTML = `<p class="msg">${esc(err.message)}</p>`);
  }
  if (!ok()) return;
  Object.assign(State.report, { page: r.page, pages: r.pages, total: r.total, size: 50 });
  $('repOut').innerHTML =
    `<table><tr>` +
    r.head.map(x => `<th>${esc(x)}</th>`).join('') +
    '</tr>' +
    r.rows.map(row => '<tr>' + row.map(x => `<td>${esc(x)}</td>`).join('') + '</tr>').join('') +
    '</table>' +
    (r.rows.length ? '' : '<p class="muted">Nothing to report.</p>');
  $('repCsv').href = 'api/index.php?action=report_csv&kind=' + k;
  pager($('repPager'), State.report, 'rep', 'row(s)');
}
$('repBtns').addEventListener('click', e => {
  if (e.target.dataset.rep) {
    State.report = { kind: e.target.dataset.rep, page: 1 };
    loadReport();
  }
});
$('repPrint').onclick = () =>
  State.report.kind &&
  printHTML(
    `<div style="padding:12mm;font:12px Arial;color:#000"><h2>${esc(State.set.name)}</h2>${$('repOut').innerHTML}</div>`
  );

/* ---------- users, permissions, passwords ---------- */
const uf = $('userForm');
let editingUser = null,
  userData = null;
function renderPermBox(selected) {
  const g = new Set(userData.grantable),
    sel = new Set(selected);
  $('permBox').innerHTML = Object.entries(userData.catalog)
    .map(
      ([k, label]) =>
        `<label><input type="checkbox" value="${k}"${sel.has(k) ? ' checked' : ''}${g.has(k) ? '' : ' disabled'}> ${esc(label)}</label>`
    )
    .join('');
}
function roleDefaults() {
  return userData.defaults[uf.elements.role.value] || [];
}
function resetUser() {
  editingUser = null;
  uf.reset();
  $('userTitle').textContent = 'Add user';
  $('pwWrap').classList.remove('hidden');
  $('permBox').classList.add('hidden');
  uf.elements.username.disabled = false;
  uf.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
  if (userData) renderPermBox(roleDefaults());
}
$('userClear').onclick = () => {
  resetUser();
  say($('userMsg'), '');
};
$('customPerms').onchange = () => {
  $('permBox').classList.toggle('hidden', !$('customPerms').checked);
  if ($('customPerms').checked && !editingUser) renderPermBox(roleDefaults());
};
uf.elements.role.onchange = () => {
  if (!$('customPerms').checked) renderPermBox(roleDefaults());
  else renderPermBox(roleDefaults());
};
async function loadUsers() {
  userData = await api('users');
  uf.elements.role.innerHTML = Object.entries(userData.roles)
    .filter(([k]) => k !== 'system_admin' || State.user.role === 'system_admin')
    .map(([k, v]) => `<option value="${k}">${esc(v)}</option>`)
    .join('');
  if (!editingUser) renderPermBox(roleDefaults());
  $('userTbl').innerHTML =
    '<table><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th></th></tr>' +
    userData.users
      .map(u => {
        const me = u.id === State.user.id;
        return `<tr><td>${esc(u.username)}</td><td>${esc(u.full_name)}</td><td>${esc(userData.roles[u.role])}${u.custom ? ' <span class="tag ac">custom permissions</span>' : ''}</td>
<td>${u.active ? '' : '<span class="tag er">disabled</span> '}${u.locked ? '<span class="tag er">locked</span> ' : ''}${u.must_change ? '<span class="tag wa">must change password</span>' : ''}</td>
<td><button class="btn sm" data-ue="${u.id}">Edit</button> ${me ? '' : `<button class="btn sm" data-ur="${u.id}">Reset password</button> ${u.locked ? `<button class="btn sm" data-ul="${u.id}">Unlock</button> ` : ''}<button class="btn sm" data-ut="${u.id}" data-on="${u.active ? 0 : 1}">${u.active ? 'Disable' : 'Enable'}</button> <button class="btn sm dng" data-ud="${u.id}">Delete</button>`}</td></tr>`;
      })
      .join('') +
    '</table>';
  const a = (await api('audit')).log;
  $('auditTbl').innerHTML =
    '<table><tr><th>When</th><th>User</th><th>Action</th><th>Detail</th></tr>' +
    a
      .map(
        x =>
          `<tr><td>${esc(x.created_at)}</td><td>${esc(x.username || '')}</td><td><span class="tag ${/fail|lock/.test(x.action) ? 'er' : ''}">${esc(x.action)}</span></td><td>${esc(x.detail)}</td></tr>`
      )
      .join('') +
    '</table>';
}
uf.onsubmit = async e => {
  e.preventDefault();
  const json = {
    full_name: uf.elements.full_name.value,
    username: uf.elements.username.value,
    role: uf.elements.role.value,
    password: uf.elements.password.value,
    perms: $('customPerms').checked
      ? [...$('permBox').querySelectorAll('input:checked')].map(i => i.value)
      : null,
  };
  if (editingUser) json.id = editingUser;
  try {
    const r = await api('user_save', { method: 'POST', json });
    resetUser();
    say($('userMsg'), 'User saved.', true);
    loadUsers();
    if (r.temp_password) showSecret('Account created', r.username, r.temp_password);
  } catch (err) {
    err.details.length ? showErrors(uf, $('userMsg'), err.details) : fail($('userMsg'), err);
  }
};
$('userTbl').addEventListener('click', async e => {
  const t = e.target,
    id = +(t.dataset.ue || t.dataset.ur || t.dataset.ul || t.dataset.ut || t.dataset.ud || 0);
  if (!id) return;
  const u = userData.users.find(x => x.id === id);
  const run = async (action, json, done) => {
    try {
      const r = await api(action, { method: 'POST', json });
      if (done) done(r);
      loadUsers();
    } catch (err) {
      ask(err.message);
    }
  };
  if (t.dataset.ue) {
    editingUser = id;
    uf.elements.full_name.value = u.full_name;
    uf.elements.username.value = u.username;
    uf.elements.role.value = u.role;
    $('pwWrap').classList.add('hidden');
    $('customPerms').checked = u.custom;
    $('permBox').classList.toggle('hidden', !u.custom);
    renderPermBox(u.perm_list);
    $('userTitle').textContent =
      'Edit ' +
      u.username +
      (id === State.user.id ? ' (you cannot change your own role or permissions)' : '');
    uf.scrollIntoView({ behavior: 'smooth' });
  }
  if (t.dataset.ur)
    ask(
      `Reset the password of ${u.username}? They are signed out of nothing, but must use the new one-time password and then choose their own.`,
      () => run('user_reset', { id }, r => showSecret('Password reset', r.username, r.temp_password)),
      'Reset'
    );
  if (t.dataset.ul) run('user_unlock', { id });
  if (t.dataset.ut) run('user_toggle', { id, active: t.dataset.on === '1' });
  if (t.dataset.ud) ask(`Delete the account of ${u.username}?`, () => run('user_delete', { id }));
});

/* ---------- backups ---------- */
async function loadBackups() {
  const r = await api('backups'),
    st = r.status || {},
    age = st.ok && st.time ? (Date.now() - new Date(st.time).getTime()) / 3600000 : null;
  const good = st.ok && age !== null && age <= r.warn_hours;
  const banner = !r.zip
    ? `<div class="banner er"><b>Backups cannot run:</b> the PHP zip extension is off. In <code>C:\\xampp\\php\\php.ini</code> remove the ";" before <code>extension=zip</code> and restart Apache.</div>`
    : good
      ? `<div class="banner ok"><b>Backups are working.</b> Last good backup: ${esc(fmtDate(st.time))} (${Math.round(age)} hour(s) ago), ${st.students ?? '?'} students, ${esc(mb(st.size))}.${(st.warnings || []).map(w => '<br>' + esc(w)).join('')}</div>`
      : st.ok
        ? `<div class="banner er"><b>The last backup is ${Math.round(age)} hours old.</b> The scheduled task may not be running. Make one now and check the schedule below.</div>`
        : st.error
          ? `<div class="banner er"><b>The last backup FAILED:</b> ${esc(st.error)}</div>`
          : `<div class="banner er"><b>No backup has been made yet.</b></div>`;
  $('bakBody').innerHTML =
    banner +
    `<div class="row"><button class="btn pri" id="bakRun">Back up now</button><span class="muted small">Safe to run while people are working.</span></div><div class="msg" id="bakMsg"></div>
  <h3 style="margin-top:16px">Saved backups</h3>` +
    (r.files.length
      ? '<div class="tw"><table><tr><th>File</th><th>Made</th><th>Size</th><th></th></tr>' +
        r.files
          .map(
            f =>
              `<tr><td>${esc(f.name)} ${f.kind === 'prerestore' ? '<span class="tag wa">safety copy before a restore</span>' : ''}</td><td>${esc(fmtDate(new Date(f.time * 1000).toISOString().slice(0, 19).replace('T', ' ')))}</td><td>${esc(mb(f.size))}</td><td><a class="btn sm" href="api/index.php?action=backup_download&f=${encodeURIComponent(f.name)}">Download</a></td></tr>`
          )
          .join('') +
        '</table></div>'
      : '<p class="muted">None yet.</p>') +
    `<h3 style="margin-top:16px">How it is set up</h3><ul class="muted">
    <li>Each backup is one ZIP with the whole database and every photo. It is re-opened and checked after it is written.</li>
    <li>Saved in <code>${esc(r.dir)}</code>${r.copy_dir ? ` and copied to <code>${esc(r.copy_dir)}</code>` : ' <b>(no second copy: set BACKUP_COPY_DIR in api/config.php to a USB drive or other disk)</b>'}.</li>
    <li>Kept: the newest ${r.keep_last} backups plus one per month for ${r.keep_months} months.</li>
    <li>Run automatically by Windows Task Scheduler: right-click <code>tools\\install-backup-task.bat</code> and choose "Run as administrator" once.</li>
    <li><b>A backup on the same disk does not protect you from a failed disk.</b> Copy the newest ZIP to a USB drive or another computer every week.</li>
    <li>To restore after data loss follow <code>docs\\RECOVERY.md</code> (command: <code>php tools\\restore.php latest</code>).</li></ul>`;
  $('bakRun').onclick = async () => {
    $('bakRun').disabled = true;
    say($('bakMsg'), 'Backing up... this can take a minute for a big school.', true);
    try {
      const x = await api('backup_run', { method: 'POST' });
      await loadBackups();
      say(
        $('bakMsg'),
        `Backup saved: ${x.file} (${mb(x.size)}, ${x.tables.students ?? '?'} students).`,
        true
      );
    } catch (err) {
      $('bakRun').disabled = false;
      say($('bakMsg'), err.message);
    }
  };
}

/* ---------- first-run school setup ---------- */
const setupForm = $('setupForm');
let setupReady = false,
  codeTouched = false,
  setupLogoFile = null,
  setupLogoUrl = null;
const SKIP_WORDS = ['OF', 'THE', 'AND', 'FOR', 'IN', 'AT', 'ON', 'A', 'AN', 'DE', 'LA', 'LE', 'DU', 'DES', 'ET', 'DA', 'DI'];
/** "Green Hills Secondary School" -> GHSS. The same rules as initials_from_name() in api/core.php. */
function suggestInitials(name) {
  const t = name
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/['\u2019`]/g, '');
  const words = (t.match(/[A-Z]+/g) || []).filter(w => !SKIP_WORDS.includes(w));
  if (!words.length) return '';
  let code = words.map(w => w[0]).join('');
  if (code.length < 2) code = words[0].slice(0, 3);
  return code.slice(0, 6);
}
function setupPreview() {
  const f = setupForm.elements,
    code = f.code.value || 'ABC',
    file = f.logo.files[0] || null;
  if (file !== setupLogoFile) {
    if (setupLogoUrl) URL.revokeObjectURL(setupLogoUrl);
    setupLogoFile = file;
    setupLogoUrl = file ? URL.createObjectURL(file) : null;
  }
  $('setupExample').innerHTML = f.code.value
    ? `Admission numbers will look like <b>${esc(code)}/0001</b>`
    : 'The initials are suggested from the school name. You can change them.';
  const t = {
    ...State.set,
    name: f.name.value.trim() || 'Your School Name',
    motto: f.motto.value,
    address: f.address.value || 'Your school address',
    contact: f.contact.value,
    color: f.color.value,
    font: 'Arial',
    card_w: 150,
    card_h: 64,
    watermark: f.code.value,
    signer: f.signer.value || 'Headteacher',
    logo: setupLogoUrl,
  };
  const s = {
    adm: code + '/0001',
    first: 'Sample',
    last: 'Student',
    cls: 'S1',
    stream: 'East',
    origin: 'Sample Town',
    rel: 'Father',
    pf: 'John',
    pl: 'Doe',
    pc: '0700000000',
    pa: 'Sample Address',
    photo: null,
    cardNo: 'VC-' + code + '-00001',
    vurl: '',
  };
  $('setupPrev').innerHTML = cardHTML(s, t);
}
function initSetupForm() {
  if (!setupReady) {
    setupReady = true;
    const f = setupForm.elements;
    f.name.addEventListener('input', () => {
      if (!codeTouched) f.code.value = suggestInitials(f.name.value);
    });
    f.code.addEventListener('input', () => {
      codeTouched = true;
      f.code.value = f.code.value.toUpperCase().replace(/[^A-Z]/g, '').slice(0, 6);
    });
    setupForm.addEventListener('input', setupPreview);
    setupForm.addEventListener('change', setupPreview);
    setupForm.onsubmit = async e => {
      e.preventDefault();
      const er = [];
      if (f.name.value.trim().length < 3) er.push({ f: 'name', m: 'Enter the full name of the school (at least 3 characters).' });
      if (!/^[A-Z]{2,6}$/.test(f.code.value))
        er.push({ f: 'code', m: 'School initials: 2 to 6 capital letters, for example ABC. They start every admission number.' });
      if (!/^[0-9+\/\s-]*$/.test(f.contact.value))
        er.push({ f: 'contact', m: 'Contact may contain only digits, spaces, "+", "/" and "-".' });
      if (er.length) return showErrors(setupForm, $('setupMsg'), er);
      $('setupBtn').disabled = true;
      try {
        const r = await api('school_setup', { method: 'POST', form: new FormData(setupForm) });
        State.set = r.settings;
        setupForm.querySelectorAll('.bad').forEach(x => x.classList.remove('bad'));
        say($('setupMsg'), '');
        applyNav();
        brand();
        await loadDash(); // the normal dashboard now appears, with the school's own header
      } catch (err) {
        err.details.length ? showErrors(setupForm, $('setupMsg'), err.details) : fail($('setupMsg'), err);
      } finally {
        $('setupBtn').disabled = false;
      }
    };
  }
  setupPreview();
}

/* ---------- start ---------- */
(async function init() {
  try {
    const wantsDash = !location.hash || location.hash === '#dash';
    const s = await api('session', { query: { dash: wantsDash ? 1 : '' } });
    State.csrf = s.csrf;
    State.brand = s.brand || {};
    if (s.dash) dashSeed = { data: s.dash, at: Date.now() };
    if (s.user) await startApp(s);
    else showLogin();
  } catch (err) {
    showLogin();
    say($('loginMsg'), err.message);
  }
})();
