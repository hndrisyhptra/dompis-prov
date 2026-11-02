import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../src/seed';
import { blankProject, getTerm, getSpk, getDeal } from '../src/domain';
import { DocumentPreview } from '../src/ui';
test('unsaved manually entered PP can be previewed before it exists in the store', () => {
  const s = seed('B'), p = blankProject(); p.no = 'PP/NEW-PREVIEW'; p.name = 'Proyek baru belum disimpan';
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'Project Plan', project:p, onClose:()=>{} }));
  assert.ok(html.includes('PP/NEW-PREVIEW'));
  assert.ok(html.includes('Proyek baru belum disimpan'));
});
test('BA PP document total uses all approved terms, not only the last Final', () => {
  const s = seed('E'), bapp = s.bapps[0], term = getTerm(s,bapp.termId), spk = getSpk(s,term.spkId), deal = getDeal(s,spk.dealId);
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'BA Penyelesaian Pekerjaan', bapp, term, spk, deal, onClose:()=>{} }));
  const summary = html.match(/class="doc-total"[\s\S]*?<\/div>/)?.[0] ?? '';
  assert.match(summary,/85\.922\.250/);
  assert.match(html,/900/);
});
test('continuation PP preview totals its allocated quantities rather than full root BOQ', () => {
  const s = seed(), project = s.projects.find(p=>p.parentId)!;
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'Project Plan', project, onClose:()=>{} }));
  const summary = html.match(/class="doc-total"[\s\S]*?<\/div>/)?.[0] ?? '';
  assert.match(summary,/33\.695\.000/);
  assert.doesNotMatch(summary,/134\.780\.000/);
});
