/* Hastra Labs — document text extraction in the browser (no uploads).

     .txt   decoded as UTF-8 (BOM stripped)
     .docx  a ZIP container: a minimal reader walks the central directory,
            inflates word/document.xml with the native DecompressionStream,
            and rebuilds paragraphs (w:p), tabs, breaks and list bullets
     .pdf   pdf.js (pinned via the import map): text items are regrouped into
            visual lines by baseline, then ordered left to right

   Returns { text, lines[], kind, pages, warnings[] }. */

export const MAX_DOC_BYTES = 25 * 1024 * 1024;

export async function extractText(file) {
  if (file.size > MAX_DOC_BYTES) throw new Error(`${file.name} is larger than 25 MB.`);
  const name = file.name.toLowerCase();
  const kind = name.endsWith('.pdf') || file.type === 'application/pdf' ? 'pdf'
    : name.endsWith('.docx') || file.type.includes('wordprocessingml') ? 'docx'
    : name.endsWith('.txt') || name.endsWith('.md') || file.type.startsWith('text/') ? 'txt'
    : name.endsWith('.doc') ? 'doc' : 'unknown';
  if (kind === 'doc') throw new Error(`${file.name}: legacy .doc files are not supported. Save it as .docx or PDF.`);
  if (kind === 'unknown') throw new Error(`${file.name}: unsupported type. Use PDF, DOCX or TXT.`);
  const bytes = new Uint8Array(await file.arrayBuffer());
  let lines, pages = 1;
  const warnings = [];
  if (kind === 'txt') lines = decodeText(bytes).split(/\r?\n/);
  else if (kind === 'docx') lines = await docxLines(bytes);
  else ({ lines, pages } = await pdfLines(bytes));
  lines = lines.map(l => l.replace(/ /g, ' ').replace(/[ \t]+/g, ' ').trim());
  const text = lines.join('\n');
  if (text.replace(/\s/g, '').length < 20) warnings.push(kind === 'pdf'
    ? `${file.name} has almost no text layer; it is probably a scanned image. Run OCR first, or paste the syllabus as text.`
    : `${file.name} contains almost no text.`);
  return { text, lines, kind, pages, warnings, name: file.name };
}

function decodeText(bytes) {
  let s = new TextDecoder('utf-8').decode(bytes);
  if (s.charCodeAt(0) === 0xfeff) s = s.slice(1);
  return s;
}

// ── ZIP (just enough for Office Open XML) ───────────────────────────────────
async function inflateRaw(data) {
  const stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
  return new Uint8Array(await new Response(stream).arrayBuffer());
}
export async function unzipEntry(bytes, wanted) {
  const dv = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  let eocd = -1;
  for (let i = bytes.length - 22; i >= Math.max(0, bytes.length - 65557); i--) {
    if (dv.getUint32(i, true) === 0x06054b50) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('This is not a valid .docx (ZIP) file.');
  const entries = dv.getUint16(eocd + 10, true);
  let p = dv.getUint32(eocd + 16, true);
  const td = new TextDecoder();
  for (let n = 0; n < entries && p + 46 <= bytes.length; n++) {
    if (dv.getUint32(p, true) !== 0x02014b50) break;
    const method = dv.getUint16(p + 10, true);
    const csize = dv.getUint32(p + 20, true), usize = dv.getUint32(p + 24, true);
    const nlen = dv.getUint16(p + 28, true), xlen = dv.getUint16(p + 30, true), clen = dv.getUint16(p + 32, true);
    const local = dv.getUint32(p + 42, true);
    const fname = td.decode(bytes.subarray(p + 46, p + 46 + nlen));
    if (fname === wanted) {
      if (usize > 60 * 1024 * 1024) throw new Error('The document body is too large.');
      if (dv.getUint32(local, true) !== 0x04034b50) throw new Error('Corrupt ZIP entry.');
      const start = local + 30 + dv.getUint16(local + 26, true) + dv.getUint16(local + 28, true);
      const data = bytes.subarray(start, start + csize);
      if (method === 0) return data;
      if (method === 8) return inflateRaw(data);
      throw new Error('Unsupported ZIP compression method ' + method + '.');
    }
    p += 46 + nlen + xlen + clen;
  }
  throw new Error(`${wanted} was not found — is this really a Word document?`);
}

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
async function docxLines(bytes) {
  const xml = new TextDecoder().decode(await unzipEntry(bytes, 'word/document.xml'));
  const doc = new DOMParser().parseFromString(xml, 'application/xml');
  if (doc.getElementsByTagName('parsererror').length) throw new Error('The document XML could not be parsed.');
  const lines = [];
  for (const p of doc.getElementsByTagNameNS(W, 'p')) {
    let s = '';
    const walk = node => {
      for (const c of node.childNodes) {
        if (c.nodeType !== 1) continue;
        if (c.namespaceURI === W) {
          if (c.localName === 't') s += c.textContent;
          else if (c.localName === 'tab') s += '\t';
          else if (c.localName === 'br' || c.localName === 'cr') s += '\n';
          else if (c.localName === 'p') continue;          // nested paragraphs are visited on their own
          else walk(c);
        } else walk(c);
      }
    };
    walk(p);
    const isList = p.getElementsByTagNameNS(W, 'numPr').length > 0;
    for (const part of s.split('\n')) if (part.trim()) lines.push((isList ? '• ' : '') + part);
    if (!s.trim()) lines.push('');
  }
  return lines;
}

// ── PDF ─────────────────────────────────────────────────────────────────────
let pdfjs;
async function loadPdfJs() {
  if (pdfjs) return pdfjs;
  pdfjs = await import('pdfjs');
  pdfjs.GlobalWorkerOptions.workerSrc = import.meta.resolve('pdfjs-worker');
  return pdfjs;
}
async function pdfLines(bytes) {
  const lib = await loadPdfJs();
  const base = import.meta.resolve('pdfjs').replace(/build\/pdf\.min\.mjs$/, '');
  const task = lib.getDocument({ data: bytes, isEvalSupported: false, cMapUrl: base + 'cmaps/', cMapPacked: true,
                                 standardFontDataUrl: base + 'standard_fonts/' });
  const pdf = await task.promise;
  const lines = [];
  const pages = Math.min(pdf.numPages, 300);
  for (let n = 1; n <= pages; n++) {
    const page = await pdf.getPage(n);
    const content = await page.getTextContent();
    const rows = [];
    for (const it of content.items) {
      if (!('str' in it) || !it.str) continue;
      const x = it.transform[4], y = it.transform[5], h = Math.abs(it.transform[3]) || it.height || 10;
      let row = rows.find(r => Math.abs(r.y - y) <= Math.max(2, h * 0.35));
      if (!row) { row = { y, items: [] }; rows.push(row); }
      row.items.push({ x, w: it.width, s: it.str, h });
    }
    rows.sort((a, b) => b.y - a.y);
    for (const r of rows) {
      r.items.sort((a, b) => a.x - b.x);
      let s = '', end = null;
      for (const it of r.items) {
        if (end !== null && it.x - end > it.h * 0.18 && !s.endsWith(' ') && !it.s.startsWith(' ')) s += ' ';
        s += it.s;
        end = it.x + it.w;
      }
      lines.push(s);
    }
    lines.push('');
    page.cleanup();
  }
  const numPages = pdf.numPages;
  await (task.destroy?.() ?? pdf.destroy?.());   // pdf.js ≥ 5 destroys through the loading task
  return { lines, pages: numPages };
}
