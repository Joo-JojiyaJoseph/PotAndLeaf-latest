import { createPortal } from 'react-dom';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { MagnifyingGlassIcon, QuestionMarkCircleIcon, XMarkIcon, ArrowUpIcon } from '@heroicons/react/24/outline';
import { Card, Spinner } from '../../components/ui';

/*
 * Help & User Guide. The guide is a Markdown file shipped with the app
 * (public/help/guide.md) with its screenshots in public/help/img/, so it opens
 * offline and for every user. Only the small part of Markdown the guide uses is
 * understood: headings, paragraphs, lists, tick lists, tables, quotes, pictures,
 * code blocks, **bold**, *italic*, `code` and [links](url).
 */

const BASE = `${import.meta.env.BASE_URL ?? '/'}help/`;

function slug(text) {
  return String(text).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function decode(s) {
  return s.replace(/&#91;/g, '[').replace(/&#93;/g, ']').replace(/&amp;/g, '&').replace(/\\([[\]()*_`#>|-])/g, '$1');
}

/** Inline: **bold**, *italic*, `code`, [text](url). */
function Inline({ text, mark }) {
  const out = [];
  const re = /(\*\*([^*]+)\*\*|`([^`]+)`|\[([^\]]+)\]\(([^)\s]+)\)|\*([^*\s][^*]*)\*)/g;
  let last = 0; let m; let k = 0;
  const plain = (t) => {
    if (!mark) return t;
    const parts = t.split(new RegExp(`(${mark.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig'));
    return parts.map((p, i) => (p.toLowerCase() === mark.toLowerCase() ? <mark key={i} className="rounded bg-amber-soft px-0.5 text-ink">{p}</mark> : p));
  };
  while ((m = re.exec(text))) {
    if (m.index > last) out.push(<Fragment key={k++}>{plain(decode(text.slice(last, m.index)))}</Fragment>);
    if (m[2]) out.push(<strong key={k++} className="font-semibold text-ink">{plain(decode(m[2]))}</strong>);
    else if (m[3]) out.push(<code key={k++} className="rounded bg-paper px-1 py-0.5 text-[0.85em]">{decode(m[3])}</code>);
    else if (m[4]) {
      const href = m[5];
      const internal = href.startsWith('#');
      out.push(<a key={k++} href={href} target={internal ? undefined : '_blank'} rel="noreferrer" className="font-medium text-leaf-hover underline underline-offset-2">{plain(decode(m[4]))}</a>);
    } else if (m[6]) out.push(<em key={k++}>{plain(decode(m[6]))}</em>);
    last = re.lastIndex;
  }
  if (last < text.length) out.push(<Fragment key={k++}>{plain(decode(text.slice(last)))}</Fragment>);
  return <>{out}</>;
}

/** Turn the Markdown into a flat list of blocks. */
function parse(md) {
  const lines = md.replace(/\r/g, '').split('\n');
  const blocks = [];
  let i = 0;
  const isList = (l) => /^\s*([-*]|\d+\.)\s+/.test(l);
  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) { i++; continue; }
    let m;
    if ((m = line.match(/^(#{1,4})\s+(.*)$/))) { blocks.push({ type: 'h', level: m[1].length, text: m[2].trim() }); i++; continue; }
    if (line.startsWith('```')) {
      const body = []; i++;
      while (i < lines.length && !lines[i].startsWith('```')) body.push(lines[i++]);
      i++; blocks.push({ type: 'code', text: body.join('\n') }); continue;
    }
    if ((m = line.match(/^!\[([^\]]*)\]\(([^)]+)\)\s*$/))) { blocks.push({ type: 'img', alt: m[1], src: m[2] }); i++; continue; }
    if (line.trim().startsWith('|')) {
      const rows = [];
      while (i < lines.length && lines[i].trim().startsWith('|')) {
        const cells = lines[i].trim().replace(/^\||\|$/g, '').split(/(?<!\\)\|/).map((c) => c.trim());
        if (!cells.every((c) => /^:?-{2,}:?$/.test(c))) rows.push(cells);
        i++;
      }
      blocks.push({ type: 'table', head: rows[0] ?? [], rows: rows.slice(1) }); continue;
    }
    if (line.startsWith('>')) {
      const body = [];
      while (i < lines.length && lines[i].startsWith('>')) body.push(lines[i++].replace(/^>\s?/, ''));
      // A quote can hold its own paragraphs, lists and tables (worked examples).
      blocks.push({ type: 'quote', children: parse(body.join('\n')) }); continue;
    }
    if (isList(line)) {
      const items = [];
      while (i < lines.length && (isList(lines[i]) || (lines[i].startsWith('    ') && lines[i].trim()))) {
        const l = lines[i];
        const indent = l.match(/^\s*/)[0].length;
        const mm = l.match(/^\s*([-*]|\d+\.)\s+(\[( |x|X)\]\s+)?(.*)$/);
        if (mm) items.push({ depth: indent >= 2 ? 1 : 0, ordered: /\d/.test(mm[1]), check: mm[2] ? mm[3].toLowerCase() === 'x' : null, text: mm[4] });
        else if (items.length) items[items.length - 1].text += ' ' + l.trim();
        i++;
      }
      blocks.push({ type: 'list', items }); continue;
    }
    const para = [];
    while (i < lines.length && lines[i].trim() && !/^(#{1,4}\s|```|>|\s*\||!\[)/.test(lines[i]) && !isList(lines[i])) para.push(lines[i++].trim());
    blocks.push({ type: 'p', text: para.join(' ') });
  }
  return blocks;
}

/** Group blocks into chapters (## headings) so search can show whole chapters. */
function chapters(blocks) {
  const out = [];
  let cur = { id: 'intro', title: 'Introduction', blocks: [] };
  for (const b of blocks) {
    if (b.type === 'h' && b.level === 1) continue;
    if (b.type === 'h' && b.level === 2) { if (cur.blocks.length) out.push(cur); cur = { id: slug(b.text), title: b.text, blocks: [b] }; continue; }
    cur.blocks.push(b);
  }
  if (cur.blocks.length) out.push(cur);
  return out;
}

function blockText(b) {
  if (b.type === 'table') return [...b.head, ...b.rows.flat()].join(' ');
  if (b.type === 'list') return b.items.map((x) => x.text).join(' ');
  if (b.type === 'img') return b.alt;
  if (b.type === 'quote') return b.children.map(blockText).join(' ');
  return b.text ?? '';
}

function List({ items, mark }) {
  const ordered = items[0]?.ordered;
  const Tag = ordered ? 'ol' : 'ul';
  const top = [];
  items.forEach((it) => { if (it.depth === 0 || !top.length) top.push({ ...it, kids: [] }); else top[top.length - 1].kids.push(it); });
  return (
    <Tag className={(ordered ? 'list-decimal' : top[0]?.check !== null ? 'list-none' : 'list-disc') + ' my-2.5 space-y-1 pl-6 text-[13.5px] leading-relaxed'}>
      {top.map((it, n) => (
        <li key={n} className="pl-1 marker:text-muted">
          {it.check !== null && <input type="checkbox" readOnly checked={it.check} className="mr-2 align-middle" aria-hidden />}
          <Inline text={it.text} mark={mark} />
          {it.kids.length > 0 && <List items={it.kids.map((k) => ({ ...k, depth: 0 }))} mark={mark} />}
        </li>
      ))}
    </Tag>
  );
}

function Block({ b, mark, onZoom }) {
  switch (b.type) {
    case 'h': {
      const cls = b.level === 2
        ? 'scroll-mt-24 border-t border-line pt-7 text-lg font-bold tracking-tight text-ink first:border-0 first:pt-0'
        : 'scroll-mt-24 mt-5 text-[15px] font-semibold text-ink';
      const Tag = b.level === 2 ? 'h2' : 'h3';
      return <Tag id={slug(b.text)} className={cls}><Inline text={b.text} mark={mark} /></Tag>;
    }
    case 'p': return <p className="my-2.5 text-[13.5px] leading-relaxed text-ink/90"><Inline text={b.text} mark={mark} /></p>;
    case 'quote': return (
      <blockquote className="my-3 rounded-xl border-l-4 border-leaf bg-leaf-soft/50 px-4 py-1 text-[13.5px] leading-relaxed [&>*]:my-2">
        {b.children.map((c, n) => <Block key={n} b={c} mark={mark} onZoom={onZoom} />)}
      </blockquote>
    );
    case 'code': return <pre className="my-3 overflow-x-auto rounded-xl bg-paper px-4 py-3 text-sm">{b.text}</pre>;
    case 'list': return <List items={b.items} mark={mark} />;
    case 'img': {
      // "img/x.jpg#1600x1000" carries the picture's size, so space is kept
      // for it before it loads and jumping to a chapter lands in the right place.
      const [path, size] = b.src.split('#');
      const [w, h] = (size ?? '').split('x').map(Number);
      const src = /^https?:|^\//.test(path) ? path : BASE + path;
      return (
        <figure className="mx-auto my-4 max-w-[680px]">
          <button type="button" onClick={() => onZoom({ src, alt: b.alt })} className="block w-full overflow-hidden rounded-xl border border-line bg-white shadow-soft" title="Click to see it bigger">
            <img src={src} alt={b.alt} loading="lazy" width={w || undefined} height={h || undefined} style={w && h ? { aspectRatio: `${w} / ${h}` } : undefined} className="h-auto w-full" />
          </button>
          <figcaption className="mt-1.5 text-center text-xs text-muted">{b.alt}</figcaption>
        </figure>
      );
    }
    case 'table': return (
      <div className="my-4 overflow-x-auto rounded-xl border border-line">
        <table className="w-full text-[13px]">
          <thead><tr className="border-b border-line text-left">{b.head.map((h, n) => <th key={n} className="px-3 py-2 font-semibold whitespace-normal"><Inline text={h} mark={mark} /></th>)}</tr></thead>
          <tbody>{b.rows.map((r, n) => <tr key={n} className="border-b border-line/60 last:border-0 align-top">{r.map((c, j) => <td key={j} className="px-3 py-2 whitespace-normal"><Inline text={c} mark={mark} /></td>)}</tr>)}</tbody>
        </table>
      </div>
    );
    default: return null;
  }
}

export default function HelpPage() {
  const [md, setMd] = useState(null);
  const [failed, setFailed] = useState(false);
  const [search, setSearch] = useState('');
  const [zoom, setZoom] = useState(null);
  const topRef = useRef(null);

  useEffect(() => {
    fetch(`${BASE}guide.md`, { cache: 'no-cache' })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error('missing'))))
      .then(setMd)
      .catch(() => setFailed(true));
  }, []);
  useEffect(() => {
    if (!zoom) return undefined;
    const onKey = (e) => e.key === 'Escape' && setZoom(null);
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [zoom]);

  const parsed = useMemo(() => (md ? parse(md) : []), [md]);
  const title = parsed.find((b) => b.type === 'h' && b.level === 1)?.text ?? 'User Guide';
  const all = useMemo(() => chapters(parsed), [parsed]);
  const q = search.trim();
  const shown = useMemo(() => {
    if (!q) return all;
    const needle = q.toLowerCase();
    return all.filter((c) => c.blocks.some((b) => blockText(b).toLowerCase().includes(needle)));
  }, [all, q]);

  function jump(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  return (
    <div className="space-y-5 p-4 sm:p-6" ref={topRef}>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-2xl bg-leaf-soft text-leaf-hover"><QuestionMarkCircleIcon className="size-6" /></span>
          <div>
            <h1 className="page-title">Help &amp; User Guide</h1>
            <p className="text-sm text-muted">Every screen, step by step, with pictures and small sums you can check. Search below or pick a chapter.</p>
          </div>
        </div>
      </div>

      <div className="relative">
        <MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search the guide — e.g. transfer, barcode, GST, commission…"
          className="h-11 w-full rounded-xl border border-line bg-surface pl-9 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-leaf/25" aria-label="Search the guide" />
        {search && <button type="button" onClick={() => setSearch('')} className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-muted hover:text-ink" aria-label="Clear search"><XMarkIcon className="size-4" /></button>}
      </div>

      {failed ? (
        <Card className="p-10 text-center text-sm text-muted">The guide could not be loaded. Check that <code>public/help/guide.md</code> was copied with the app.</Card>
      ) : !md ? (
        <div className="flex justify-center py-16"><Spinner className="size-6" /></div>
      ) : (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
          <nav className="lg:sticky lg:top-4 lg:self-start" aria-label="Chapters">
            <Card className="p-2">
              <p className="microlabel px-2 pb-1 pt-1 font-semibold text-faint">{q ? `${shown.length} chapter${shown.length === 1 ? '' : 's'} match` : 'Chapters'}</p>
              <div className="flex gap-1 overflow-x-auto lg:block lg:max-h-[70vh] lg:overflow-y-auto no-scrollbar">
                {shown.map((c) => (
                  <button key={c.id} type="button" onClick={() => jump(c.id)}
                    className="block shrink-0 rounded-lg px-2.5 py-1.5 text-left text-[13px] text-ink/80 hover:bg-leaf-soft hover:text-leaf-hover lg:w-full">
                    {c.title}
                  </button>
                ))}
              </div>
            </Card>
          </nav>

          <Card className="min-w-0 px-4 py-5 sm:px-8 sm:py-7">
            <h2 className="mb-1 text-xl font-bold tracking-tight">{title}</h2>
            {shown.length === 0 && <p className="py-10 text-center text-sm text-muted">Nothing in the guide matches “{q}”. Try a shorter word.</p>}
            {shown.map((c) => (
              <section key={c.id} className="mb-6">
                {c.blocks.map((b, n) => <Block key={n} b={b} mark={q || null} onZoom={setZoom} />)}
              </section>
            ))}
            <div className="mt-8 flex justify-end">
              <button type="button" onClick={() => topRef.current?.scrollIntoView({ behavior: 'smooth' })} className="inline-flex items-center gap-1 text-sm text-muted hover:text-ink">
                <ArrowUpIcon className="size-4" /> Back to top
              </button>
            </div>
          </Card>
        </div>
      )}

      {zoom && createPortal(
        <div className="fixed inset-0 z-[90] flex items-center justify-center bg-black/70 p-4" onClick={() => setZoom(null)} role="dialog" aria-label={zoom.alt}>
          <img src={zoom.src} alt={zoom.alt} className="max-h-full max-w-full rounded-xl bg-white shadow-pop" />
          <button type="button" className="absolute right-4 top-4 rounded-full bg-white/90 p-2 text-ink" aria-label="Close"><XMarkIcon className="size-5" /></button>
        </div>,
        document.body,
      )}
    </div>
  );
}
