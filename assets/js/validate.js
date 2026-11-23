'use strict';
/* Client-side checks that mirror api/lib.php. They give instant feedback;
   the server repeats every check (including uniqueness, which needs the whole database) and is the final authority.
   The admission number is assigned by the server, so it is not entered or checked here. */
const RX = {
  name: /^[\p{L}][\p{L}' .-]*$/u,
  place: /^[\p{L}][\p{L}' .,-]*$/u,
  adm: /^[A-Za-z0-9][A-Za-z0-9\/-]*$/,
  phone: /^\d{10,12}$/,
  stream: /^[A-Za-z0-9][A-Za-z0-9 ]*$/,
};
const LIVE_FILTERS = {
  // characters removed while typing
  first: /[^\p{L}' .-]/gu,
  last: /[^\p{L}' .-]/gu,
  pf: /[^\p{L}' .-]/gu,
  pl: /[^\p{L}' .-]/gu,
  origin: /[^\p{L}' .,-]/gu,
  pc: /\D/g,
};
const tidy = v =>
  String(v ?? '')
    .trim()
    .replace(/\s+/g, ' ');

/** @returns {{f:string,m:string}[]} */
function validateStudent(d, file, streams) {
  const er = [],
    E = (f, m) => er.push({ f, m });
  for (const k of ['first', 'last', 'cls', 'stream', 'origin', 'pf', 'pl', 'pc', 'pa', 'rel'])
    d[k] = tidy(d[k]);
  d.pc = d.pc.replace(/\s+/g, '');
  {
    const m = /^256(\d{9})$/.exec(d.pc);
    if (m) d.pc = '0' + m[1];
  } // same number, local form
  [
    ['first', 'First name'],
    ['last', 'Last name'],
    ['pf', 'Guardian first name'],
    ['pl', 'Guardian last name'],
  ].forEach(([k, label]) => {
    if (!d[k]) E(k, label + ' is required.');
    else if (!RX.name.test(d[k])) E(k, label + ' must contain letters only (no digits or symbols).');
  });
  if (!/^S[1-6]$/.test(d.cls)) E('cls', 'Choose a class (S1 to S6).');
  if (!d.stream) E('stream', 'Choose a stream.');
  else if (streams && !(streams[d.cls] || []).includes(d.stream))
    E('stream', `Stream "${d.stream}" is not set up for ${d.cls}.`);
  if (d.origin && !RX.place.test(d.origin)) E('origin', 'Place of origin must contain letters only.');
  if (!d.pc) E('pc', 'Contact number is required.');
  else if (!RX.phone.test(d.pc))
    E('pc', 'Contact must be digits only, 10 to 12 digits (for example 0772856765).');
  if (!['Father', 'Mother', 'Guardian', 'Other'].includes(d.rel))
    E('rel', 'Choose the relationship to the student.');
  if (file && file.size) {
    if (!/^image\//.test(file.type)) E('photo', 'The photo must be an image file.');
    else if (file.size > 10 * 1048576) E('photo', 'The photo is larger than 10 MB.');
  }
  return er;
}

/** Strip illegal characters as the user types. */
function attachLiveFilters(form) {
  form.addEventListener('input', e => {
    const rx = LIVE_FILTERS[e.target.name];
    if (rx) {
      const v = e.target.value.replace(rx, '');
      if (v !== e.target.value) e.target.value = v;
    }
    e.target.classList.remove('bad');
  });
}
