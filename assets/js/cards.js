'use strict';
/* Visitation card: on-screen HTML, print sheets (HTML) and a vector PDF (drawn with jsPDF, so text stays razor sharp at any zoom).
   Reference design is 150 x 64 mm (four fit on one A4 page, top to bottom). Other sizes scale the whole layout. */
const esc = s =>
  String(s ?? '').replace(
    /[&<>"']/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
  );
const REF = { W: 150, H: 64 };
const NOT_VALID = 'CARD NOT VALID WITHOUT THE OFFICIAL STAMP';
const scaleOf = t => Math.min(t.card_w / REF.W, t.card_h / REF.H);
const QR_MM = 19; // printed size of the QR code on the reference card
const BAND = 'STUDENT VISITATION CARD';

/** QR matrix for a URL (qrcode-generator, vendored in assets/vendor/qrcode.js - works offline). Level M survives a smudge or a stamp edge. */
function qrMatrix(url) {
  const make = typeof qrcode !== 'undefined' ? qrcode : require('../vendor/qrcode.js');
  const q = make(0, 'M');
  q.addData(String(url));
  q.make();
  const n = q.getModuleCount(),
    rows = [];
  for (let r = 0; r < n; r++) {
    const row = [];
    for (let c = 0; c < n; c++) row.push(q.isDark(r, c));
    rows.push(row);
  }
  return rows;
}
/** Horizontal runs of dark modules: [row, startCol, length]. Far fewer shapes than one square per module. */
function qrRuns(m) {
  const runs = [];
  m.forEach((row, r) => {
    let c = 0;
    while (c < row.length) {
      if (!row[c]) {
        c++;
        continue;
      }
      let e = c;
      while (e < row.length && row[e]) e++;
      runs.push([r, c, e - c]);
      c = e;
    }
  });
  return runs;
}
function qrSvg(url) {
  if (!url) return '<div class="qx">QR</div>';
  const m = qrMatrix(url),
    n = m.length,
    Q = 1;
  const d = qrRuns(m)
    .map(([r, c, l]) => `M${c + Q} ${r + Q}h${l}v1h-${l}z`)
    .join('');
  return `<svg viewBox="0 0 ${n + 2 * Q} ${n + 2 * Q}" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#fff"/><path d="${d}" fill="#000"/></svg>`;
}

/** How many cards fit on an A4 page and where the gaps go (equal spacing all round). */
function sheetGrid(t) {
  const cols = Math.max(1, Math.floor((210 - 16) / (t.card_w + 4))),
    rows = Math.max(1, Math.floor((297 - 16) / (t.card_h + 4)));
  return {
    cols,
    rows,
    per: cols * rows,
    gx: (210 - cols * t.card_w) / (cols + 1),
    gy: (297 - rows * t.card_h) / (rows + 1),
  };
}

/* ---------------- on-screen / print card ---------------- */
function cardHTML(s, t) {
  const logo = t.logo ? `<img class="lg" src="${esc(t.logo)}" alt="">` : '<span></span>';
  return `<div class="vc" style="--c:${esc(t.color)};--ff:${esc(t.font)};width:${t.card_w}mm;height:${t.card_h}mm;--k:${scaleOf(t).toFixed(3)}">
<div class="wm">${esc(t.watermark)}</div>
<div class="h">${logo}<div class="ht"><b>${esc(t.name)}</b><i>${esc(t.motto)}</i><small>${esc(t.address)}</small>${t.contact ? `<small>Tel: ${esc(t.contact)}</small>` : ''}</div>${logo}</div>
<div class="tt">${BAND}</div>
<div class="b">${s.photo ? `<img class="ph" src="${esc(s.photo)}" alt="">` : '<div class="ph x">NO PHOTO</div>'}
<div class="d"><div class="nm">${esc(s.first)} ${esc(s.last)}</div>
<div><span>Adm No:</span> ${esc(s.adm)}</div><div><span>Class:</span> ${esc(s.cls)} ${esc(s.stream)}</div>
<div><span>Origin:</span> ${esc(s.origin)}</div><div><span>Tel:</span> ${esc(s.pc)}</div>
<div class="w"><span>${esc(s.rel)}:</span> ${esc(s.pf)} ${esc(s.pl)}</div><div class="w"><span>Address:</span> ${esc(s.pa)}</div></div>
<div class="qr">${qrSvg(s.vurl)}<small>Scan to verify</small></div>
<div class="stamp">OFFICIAL<br>STAMP</div></div>
<div class="ft"><div class="vn">${NOT_VALID}</div><div class="fr"><span>No: ${esc(s.cardNo)}</span><span>${esc(t.signer)}: ________</span></div></div></div>`;
}

/** Print sheets: A4 pages with the cards in equal-spaced slots, exactly like the PDF. */
function sheetsHTML(list, t) {
  const g = sheetGrid(t);
  let out = '';
  for (let i = 0; i < list.length; i += g.per) {
    out +=
      `<div class="sheet" style="grid-template-columns:repeat(${g.cols},${t.card_w}mm);grid-template-rows:repeat(${g.rows},${t.card_h}mm);gap:${g.gy.toFixed(2)}mm ${g.gx.toFixed(2)}mm;padding:${g.gy.toFixed(2)}mm ${g.gx.toFixed(2)}mm">` +
      list
        .slice(i, i + g.per)
        .map(s => cardHTML(s, t))
        .join('') +
      '</div>';
  }
  return out;
}
const imagesReady = root =>
  Promise.race([
    // never wait more than 4 s for a broken image
    Promise.all(
      [...root.querySelectorAll('img')].map(i =>
        i.complete
          ? 1
          : new Promise(r => {
              i.onload = i.onerror = r;
            })
      )
    ),
    new Promise(r => setTimeout(r, 4000)),
  ]);
/** Open the print dialog for some HTML (card sheets or a report). onDone runs after the dialog closes. */
async function printHTML(html, onDone) {
  const area = document.getElementById('printArea');
  area.innerHTML = html;
  await imagesReady(area);
  const end = () => {
    removeEventListener('afterprint', end);
    area.innerHTML = '';
    if (onDone) onDone();
  };
  addEventListener('afterprint', end);
  setTimeout(() => print(), 50);
}

/* ---------------- vector PDF ---------------- */
const hex2rgb = h => [1, 3, 5].map(i => parseInt(h.slice(i, i + 2), 16) || 0);
const pdfFont = f => (/georgia|times/i.test(f) ? 'times' : 'helvetica');

async function loadImage(url) {
  // browser only: fetch an image and read its size
  try {
    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) return null;
    const blob = await r.blob();
    const data = await new Promise((ok, no) => {
      const fr = new FileReader();
      fr.onload = () => ok(fr.result);
      fr.onerror = no;
      fr.readAsDataURL(blob);
    });
    const dim = await new Promise(ok => {
      const i = new Image();
      i.onload = () => ok([i.naturalWidth, i.naturalHeight]);
      i.onerror = () => ok(null);
      i.src = data;
    });
    return dim ? { data, w: dim[0], h: dim[1], fmt: /png/i.test(blob.type) ? 'PNG' : 'JPEG' } : null;
  } catch (e) {
    return null;
  }
}

/** Draw one card with its top-left corner at (X, Y) millimetres. img = { logo, photo } preloaded images (or null). */
function drawCard(doc, s, t, X, Y, img) {
  const k = scaleOf(t),
    W = t.card_w / k,
    H = t.card_h / k,
    w = t.card_w,
    h = t.card_h;
  const px = v => X + v * k,
    py = v => Y + v * k,
    P = v => v * k;
  const [r, g, b] = hex2rgb(t.color),
    F = pdfFont(t.font);
  const txt = (str, x, y, size, style, color, opt) => {
    doc.setFont(F, style);
    doc.setFontSize(size * k);
    doc.setTextColor(...color);
    doc.text(String(str), px(x), py(y), opt);
  };
  const fit = (str, size, style, maxW) => {
    doc.setFont(F, style);
    doc.setFontSize(size * k);
    str = String(str);
    if (doc.getTextWidth(str) <= P(maxW)) return str;
    while (str.length > 1 && doc.getTextWidth(str + '...') > P(maxW)) str = str.slice(0, -1);
    return str + '...';
  };

  doc.setFillColor(255, 255, 255);
  doc.roundedRect(X, Y, w, h, P(2), P(2), 'F');
  doc.saveGraphicsState();
  doc.rect(X, Y, w, h, null);
  doc.clip();
  doc.discardPath();

  // watermark
  if (t.watermark) {
    doc.saveGraphicsState();
    doc.setGState(new doc.GState({ opacity: 0.06 }));
    const fs = 30 * k;
    doc.setFont(F, 'bold');
    doc.setFontSize(fs);
    doc.setTextColor(0, 0, 0);
    const tw = doc.getTextWidth(t.watermark),
      a = (20 * Math.PI) / 180;
    doc.text(
      t.watermark,
      X + w / 2 - (tw / 2) * Math.cos(a),
      Y + h / 2 + (tw / 2) * Math.sin(a) + fs * 0.12,
      { angle: 20 }
    );
    doc.restoreGraphicsState();
  }

  // header: crest - centred school block - crest
  if (img.logo) {
    const lh = 14,
      lw = Math.min(18, (lh * img.logo.w) / img.logo.h),
      lhh = (lw * img.logo.h) / img.logo.w;
    doc.addImage(img.logo.data, img.logo.fmt, px(4), py((19 - lhh) / 2), P(lw), P(lhh), 'logo', 'FAST');
    doc.addImage(
      img.logo.data,
      img.logo.fmt,
      px(W - 4 - lw),
      py((19 - lhh) / 2),
      P(lw),
      P(lhh),
      'logo',
      'FAST'
    );
  }
  let ns = 13;
  doc.setFont(F, 'bold');
  doc.setFontSize(ns * k);
  while (ns > 7 && doc.getTextWidth(t.name) > P(W - 48)) {
    ns -= 0.5;
    doc.setFontSize(ns * k);
  }
  txt(t.name, W / 2, 6.4, ns, 'bold', [r, g, b], { align: 'center' });
  if (t.motto) txt(t.motto, W / 2, 10.4, 7.5, 'bolditalic', [185, 28, 28], { align: 'center' });
  txt(fit(t.address, 6.5, 'normal', W - 48), W / 2, 13.6, 6.5, 'normal', [85, 85, 85], { align: 'center' });
  if (t.contact)
    txt(fit('Tel: ' + t.contact, 6.5, 'normal', W - 48), W / 2, 16.6, 6.5, 'normal', [85, 85, 85], {
      align: 'center',
    });
  doc.setDrawColor(245, 196, 0);
  doc.setLineWidth(P(0.6));
  doc.line(X, py(19), X + w, py(19));

  // title band
  doc.setFillColor(r, g, b);
  doc.rect(X, py(19.3), w, P(5), 'F');
  doc.setFont(F, 'bold');
  doc.setFontSize(7.5 * k);
  doc.setTextColor(245, 196, 0);
  doc.text(BAND, px(W / 2), py(19.3 + 3.45), { align: 'center', charSpace: P(0.4) });

  // body
  const top = 24.3,
    bot = H - 8.4,
    cy = top + (bot - top) / 2;
  const pw = 23,
    ph = 28,
    p0 = 4,
    py0 = cy - ph / 2;
  doc.setFillColor(238, 238, 238);
  doc.rect(px(p0), py(py0), P(pw), P(ph), 'F');
  if (img.photo) {
    const sc = Math.max(pw / img.photo.w, ph / img.photo.h),
      dw = img.photo.w * sc,
      dh = img.photo.h * sc;
    doc.saveGraphicsState();
    doc.rect(px(p0), py(py0), P(pw), P(ph), null);
    doc.clip();
    doc.discardPath();
    doc.addImage(
      img.photo.data,
      img.photo.fmt,
      px(p0 + (pw - dw) / 2),
      py(py0 + (ph - dh) / 2),
      P(dw),
      P(dh)
    );
    doc.restoreGraphicsState();
  } else txt('NO PHOTO', p0 + pw / 2, cy + 1, 6, 'normal', [136, 136, 136], { align: 'center' });
  doc.setDrawColor(r, g, b);
  doc.setLineWidth(P(0.25));
  doc.rect(px(p0), py(py0), P(pw), P(ph), 'S');

  const sr = 10.5,
    scx = W - 4 - sr - 1; // stamp circle
  doc.setDrawColor(150, 150, 150);
  doc.setLineWidth(P(0.3));
  doc.setLineDashPattern([P(1.2), P(1.2)], 0);
  doc.circle(px(scx), py(cy), P(sr), 'S');
  doc.setLineDashPattern([], 0);
  txt('OFFICIAL', scx, cy - 0.4, 5.5, 'bold', [170, 170, 170], { align: 'center' });
  txt('STAMP', scx, cy + 2, 5.5, 'bold', [170, 170, 170], { align: 'center' });

  const qx = scx - sr - 3 - QR_MM,
    qy = cy - (QR_MM + 3) / 2; // QR code between the details and the stamp
  if (s.vurl) {
    const m = qrMatrix(s.vurl),
      n = m.length + 2,
      u = QR_MM / n;
    doc.setFillColor(255, 255, 255);
    doc.rect(px(qx), py(qy), P(QR_MM), P(QR_MM), 'F');
    doc.setFillColor(0, 0, 0);
    qrRuns(m).forEach(([rr, cc, l]) =>
      doc.rect(
        px(qx + (cc + 1) * u - 0.002),
        py(qy + (rr + 1) * u - 0.002),
        P(l * u + 0.004),
        P(u + 0.004),
        'F'
      )
    );
    txt('Scan to verify', qx + QR_MM / 2, qy + QR_MM + 2.4, 5, 'normal', [90, 90, 90], { align: 'center' });
  }
  const x0 = p0 + pw + 4,
    dw = qx - 4 - x0,
    xB = x0 + dw * 0.58; // details
  const field = (label, value, x, y, maxW, lines = 1) => {
    doc.setFont(F, 'normal');
    doc.setFontSize(6.8 * k);
    doc.setTextColor(102, 102, 102);
    doc.text(label, px(x), py(y));
    const lw = doc.getTextWidth(label) / k + 1;
    doc.setFont(F, 'normal');
    doc.setFontSize(8 * k);
    doc.setTextColor(17, 17, 17);
    const parts = doc.splitTextToSize(String(value || '-'), P(maxW - lw)).slice(0, lines);
    parts.forEach((ln, i) => doc.text(ln, px(x + lw), py(y + i * 3.1)));
  };
  txt(fit(s.first + ' ' + s.last, 10.5, 'bold', dw), x0, cy - 10, 10.5, 'bold', [r, g, b]);
  field('Adm No:', s.adm, x0, cy - 4.2, xB - x0 - 1);
  field('Class:', s.cls + ' ' + s.stream, xB, cy - 4.2, x0 + dw - xB);
  field('Origin:', s.origin, x0, cy + 1, xB - x0 - 1);
  field('Tel:', s.pc, xB, cy + 1, x0 + dw - xB);
  field(s.rel + ':', s.pf + ' ' + s.pl, x0, cy + 6.2, dw);
  field('Address:', s.pa, x0, cy + 11.4, dw, 2);

  // footer: validity line, card number, signature
  doc.setFillColor(238, 240, 244);
  doc.rect(X, py(H - 8.4), w, P(8.4), 'F');
  doc.setDrawColor(204, 204, 204);
  doc.setLineWidth(P(0.2));
  doc.line(X, py(H - 8.4), X + w, py(H - 8.4));
  doc.setFont(F, 'bold');
  doc.setFontSize(6.8 * k);
  doc.setTextColor(185, 28, 28);
  doc.text(NOT_VALID, px(W / 2), py(H - 4.8), { align: 'center', charSpace: P(0.1) });
  txt('No: ' + s.cardNo, 4, H - 1.6, 6.2, 'bold', [40, 40, 40]);
  txt(`${t.signer}: ________`, W - 4, H - 1.6, 6.2, 'normal', [40, 40, 40], { align: 'right' });
  doc.restoreGraphicsState();

  doc.setDrawColor(r, g, b);
  doc.setLineWidth(P(0.45));
  doc.roundedRect(X, Y, w, h, P(2), P(2), 'S');
}

/** Run fn over items, a few at a time. */
async function inParallel(items, limit, fn) {
  let next = 0;
  const worker = async () => {
    while (next < items.length) await fn(items[next++]);
  };
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, worker));
}

/** Build the PDF document. getImage(url) -> {data,w,h,fmt}|null (injected so this can run anywhere). */
async function buildPdf(JsPDF, list, t, getImage, progress) {
  const doc = new JsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
  doc.setProperties({
    title: (t.name || 'School') + ' - visitation cards',
    subject: 'Student visitation cards',
    creator: 'Visitation Card System',
  });
  const g = sheetGrid(t),
    logo = t.logo ? await getImage(t.logo) : null;
  await inParallel(
    list.filter(s => s.photo),
    6,
    s => getImage(s.photo)
  ); // fetch the photos together, then draw
  for (let i = 0; i < list.length; i++) {
    progress && progress(i + 1, list.length);
    const slot = i % g.per;
    if (i && slot === 0) doc.addPage();
    const photo = list[i].photo ? await getImage(list[i].photo) : null;
    drawCard(
      doc,
      list[i],
      t,
      g.gx + (slot % g.cols) * (t.card_w + g.gx),
      g.gy + Math.floor(slot / g.cols) * (t.card_h + g.gy),
      { logo, photo }
    );
  }
  return { doc, perPage: g.per };
}
/** Load a script once, when it is first needed. */
const scriptLoads = {};
function loadScript(src) {
  if (!scriptLoads[src]) {
    scriptLoads[src] = new Promise((resolve, reject) => {
      const el = document.createElement('script');
      el.src = src;
      el.onload = resolve;
      el.onerror = () => {
        delete scriptLoads[src];
        reject(new Error('Could not load ' + src));
      };
      document.head.appendChild(el);
    });
  }
  return scriptLoads[src];
}

/** Browser: build and download the PDF. The PDF library (the largest file in the system) is loaded only now. */
async function cardsToPdf(list, t, filename, progress) {
  if (!(window.jspdf && window.jspdf.jsPDF)) await loadScript('assets/vendor/jspdf.umd.min.js?v=3.1');
  if (!(window.jspdf && window.jspdf.jsPDF))
    throw new Error('The PDF library did not load (assets/vendor/jspdf.umd.min.js).');
  const cache = new Map();
  const getImage = async u => {
    if (!cache.has(u)) cache.set(u, await loadImage(u));
    return cache.get(u);
  };
  const { doc, perPage } = await buildPdf(window.jspdf.jsPDF, list, t, getImage, progress);
  doc.save(filename);
  return perPage;
}
if (typeof module !== 'undefined')
  module.exports = { buildPdf, drawCard, sheetGrid, cardHTML, sheetsHTML, scaleOf, qrMatrix, qrSvg };
