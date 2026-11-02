export const ROLES = ['Admin Konstruksi', 'PM Konstruksi', 'Manager Konstruksi', 'Finance', 'Superadmin'] as const;
export type Role = typeof ROLES[number];
export type Component = 'm' | 's';
export interface Khs { id: string; code: string; name: string; unit: string; package: string; m: number | null; s: number | null; version: number; active: boolean; history: { at: string; actor: Role; m: number | null; s: number | null }[] }
export interface BoqRow { id: string; khsId: string; qty: number; excelM: number | null; excelS: number | null; reviewed: boolean; note: string; m: number | null; s: number | null; version: number }
export interface Schedule { name: string; days: number; start: string; end: string }
export interface Project { id: string; no: string; name: string; unit: string; branch: string; projectCode: string; budget: number; date: string; status: 'Draft' | 'Sah'; parentId?: string; rootId: string; sourceBapp?: string; allocations: Record<string, { m: number; s: number }>; intro: string; team: { name: string; job: string }[]; schedule: Schedule[]; costs: { mandor: number; material: number; bop: number; permit: number }; risk: string; communication: string; quality: string; boqStatus: 'Belum diimpor' | 'Review' | 'Final'; boq: BoqRow[]; created: string }
export interface DealRow { rowId: string; m: boolean; s: boolean; qm: number; qs: number; pm: number; ps: number; sphM: number; sphS: number; offerM: number; offerS: number; capM: number | null; capS: number | null }
export interface Deal { id: string; projectId: string; no: string; mandor: string; status: 'Draft' | 'Terbit' | 'Sah'; rows: DealRow[]; place: string; location: string; witel: string; date: string; start: string; end: string; signer: string; fo: string; jointer: string; evidence: string }
export interface Spk { id: string; dealId: string; no: string; status: 'Draft' | 'Terbit' | 'Sah'; date: string; start: string; end: string; place: string; address: string; supervisor: string; phone: string; signer: string; position: string; evidence: string }
export interface Approval { role: Role; decision: 'Setujui' | 'Kembalikan' | 'Tolak'; note: string; at: string }
export interface Term { id: string; spkId: string; number: number; type: 'Parsial' | 'Final'; status: 'Draft' | 'Diajukan' | 'Dikembalikan' | 'Ditolak' | 'Disetujui'; actuals: Record<string, { m: number; s: number }>; approvals: Approval[]; history: Approval[]; submitted?: string; apm: string; apmStatus: 'Belum Terbit' | 'Valid' | 'Perlu Koreksi'; paid: number; paymentHistory: { amount: number; ref: string; at: string }[] }
export interface Bapp { id: string; termId: string; no: string; status: 'Draft' | 'Sah'; evidence: string; released: Record<string, { m: number; s: number }>; releaseHistory: { quantities: Record<string, { m: number; s: number }>; at: string; actor: Role }[] }
export interface Event { id: string; title: string; detail: string; route: string; actor: Role; at: string }
export interface User { id: string; name: string; email: string; role: Role; active: boolean }
export interface State { schema: number; generation: string; role: Role; scenario: string; projects: Project[]; khs: Khs[]; deals: Deal[]; spks: Spk[]; terms: Term[]; bapps: Bapp[]; events: Event[]; users: User[]; profile: { name: string; email: string }; lastSaved: string }
export const uid = (prefix = 'id') => `${prefix}-${globalThis.crypto.randomUUID()}`;
export const now = () => new Date().toISOString();
export const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta' }).format(new Date());
export const money = (v: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2, minimumFractionDigits: 0 }).format(v);
export const num = (v: number) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(v);
export const dateLabel = (v?: string) => v ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeZone: 'Asia/Jakarta' }).format(new Date(v)) : '—';
export const round = (v: number) => Math.round((v + Number.EPSILON) * 100) / 100;
export const validVolume = (v: number) => Number.isFinite(v) && v >= 0 && Math.abs(v * 1000 - Math.round(v * 1000)) < 0.000001;
export const volumeRound = (v: number) => Math.round((v + Number.EPSILON) * 1000) / 1000;
export const operational = (s: State) => s.role === 'Admin Konstruksi' || s.role === 'Superadmin';
export function requireAdmin(s: State) { if (!operational(s)) throw new Error('Hanya Admin Konstruksi atau Superadmin yang diotorisasi dapat menjalankan tindakan dokumen ini.'); }
export function requireFinance(s: State) { if (s.role !== 'Finance') throw new Error('Tindakan ini hanya tersedia untuk Finance.'); }
export function getProject(s: State, id: string) { const p = s.projects.find(p => p.id === id); if (!p) throw new Error('Project Plan tidak ditemukan.'); return p; }
export function getDeal(s: State, id: string) { const d = s.deals.find(d => d.id === id); if (!d) throw new Error('BA KH tidak ditemukan.'); return d; }
export function getSpk(s: State, id: string) { const v = s.spks.find(v => v.id === id); if (!v) throw new Error('SPK tidak ditemukan.'); return v; }
export function getTerm(s: State, id: string) { const t = s.terms.find(t => t.id === id); if (!t) throw new Error('Termin tidak ditemukan.'); return t; }
export const projectForSpk = (s: State, spk: Spk) => getProject(s, getDeal(s, spk.dealId).projectId);
export const dealTotal = (d: Deal) => round(d.rows.reduce((n, r) => n + round((r.m ? r.qm * r.pm : 0) + (r.s ? r.qs * r.ps : 0)), 0));
export function boqTotal(p: Project) { return round(p.boq.reduce((n, r) => n + round(r.qty * ((r.m ?? 0) + (r.s ?? 0))), 0)); }
export function termTotal(s: State, t: Term) { return round(getDeal(s, getSpk(s, t.spkId).dealId).rows.reduce((n, r) => n + round((r.m ? (t.actuals[r.rowId]?.m ?? 0) * r.pm : 0) + (r.s ? (t.actuals[r.rowId]?.s ?? 0) * r.ps : 0)), 0)); }
export function accepted(s: State, spkId: string, rowId: string, c: Component) { return s.terms.filter(t => t.spkId === spkId && t.status === 'Disetujui').reduce((n, t) => n + (t.actuals[rowId]?.[c] ?? 0), 0); }
export function released(s: State, spkId: string, rowId: string, c: Component) { return s.bapps.filter(b => getTerm(s, b.termId).spkId === spkId).reduce((n, b) => n + (b.released[rowId]?.[c] ?? 0), 0); }
export function remaining(s: State, spkId: string, row: DealRow, c: Component) { return volumeRound((c === 'm' ? row.m ? row.qm : 0 : row.s ? row.qs : 0) - accepted(s, spkId, row.rowId, c) - released(s, spkId, row.rowId, c)); }
export function ledger(s: State, rootId: string, rowId: string, c: Component, excludeDeal?: string) {
  const root = getProject(s, rootId); const row = root.boq.find(r => r.id === rowId)!;
  let reserved = 0, committed = 0, acceptedQty = 0;
  const projects = s.projects.filter(p => p.rootId === rootId);
  for (const p of projects) {
    const deals = s.deals.filter(d => d.projectId === p.id && d.id !== excludeDeal);
    let assigned = 0;
    for (const d of deals) {
      const r = d.rows.find(r => r.rowId === rowId); if (!r) continue;
      const q = c === 'm' ? r.m ? r.qm : 0 : r.s ? r.qs : 0; assigned += q;
      const spk = s.spks.find(v => v.dealId === d.id && v.status === 'Sah');
      if (!spk) reserved += q;
      else { const a = accepted(s, spk.id, rowId, c); acceptedQty += a; committed += q - a - released(s, spk.id, rowId, c); }
    }
    if (p.parentId) reserved += Math.max(0, (p.allocations[rowId]?.[c] ?? 0) - assigned);
  }
  const total = row && row[c] !== null ? row.qty : 0;
  return { total, reserved: volumeRound(reserved), committed: volumeRound(committed), accepted: volumeRound(acceptedQty), available: volumeRound(total - reserved - committed - acceptedQty) };
}
export function capacity(s: State, p: Project, rowId: string, c: Component, excludeDeal?: string) {
  if (!p.parentId) return ledger(s, p.rootId, rowId, c, excludeDeal).available;
  const used = s.deals.filter(d => d.projectId === p.id && d.id !== excludeDeal).reduce((n, d) => { const r = d.rows.find(r => r.rowId === rowId); return n + (r ? c === 'm' ? r.m ? r.qm : 0 : r.s ? r.qs : 0 : 0); }, 0);
  return volumeRound((p.allocations[rowId]?.[c] ?? 0) - used);
}
export function nextTermReason(s: State, spkId: string): string {
  if (getSpk(s, spkId).status !== 'Sah') return 'SPK harus disahkan terlebih dahulu.';
  const terms = s.terms.filter(t => t.spkId === spkId).sort((a, b) => a.number - b.number);
  if (terms.some(t => t.type === 'Final')) return 'Termin Final sudah dibuat. Termin berikutnya tidak tersedia.';
  if (terms.length >= 3) return 'Maksimal tiga termin per SPK.';
  const previous = terms.at(-1);
  if (previous && previous.status !== 'Disetujui') return `Termin ${previous.number} belum disetujui tiga level.`;
  if (previous && previous.apmStatus !== 'Valid') return `Termin ${previous.number + 1} belum dapat dibuat karena Nomor APM Termin ${previous.number} belum valid.`;
  return '';
}
export const approvalRole = (t: Term): Role | undefined => t.status === 'Diajukan' ? (['PM Konstruksi', 'Manager Konstruksi', 'Finance'] as Role[])[t.approvals.length] : undefined;
export function log(s: State, title: string, detail: string, route: string) { s.events.unshift({ id: uid('evt'), title, detail, route, actor: s.role, at: now() }); }
export function blankProject(): Project { const id = uid('pp'); return { id, no: '', name: '', unit: '', branch: '', projectCode: '', budget: 0, date: '', status: 'Draft', rootId: id, allocations: {}, intro: '', team: [], schedule: ['Persiapan', 'Material Delivery', 'Instalasi', 'Commissioning Test', 'Uji Terima'].map(name => ({ name, days: 1, start: '', end: '' })), costs: { mandor: 0, material: 0, bop: 0, permit: 0 }, risk: '', communication: '', quality: '', boqStatus: 'Belum diimpor', boq: [], created: today() }; }
export function projectErrors(s: State, p: Project): string[] {
  const e: string[] = [];
  for (const [k, label] of [['no', 'No PP'], ['name', 'Nama Kegiatan'], ['unit', 'Unit Kerja'], ['branch', 'Branch'], ['projectCode', 'ID Project'], ['date', 'Saat Penggunaan']] as const) if (!p[k]?.trim()) e.push(`${label} wajib diisi.`);
  if (s.projects.some(v => v.no.trim().toLowerCase() === p.no.trim().toLowerCase() && v.id !== p.id)) e.push('No PP sudah digunakan.');
  if (!Number.isFinite(p.budget) || p.budget <= 0) e.push('Total Nilai harus lebih dari nol.');
  if (p.schedule.some(v => !Number.isFinite(v.days) || v.days < 0 || (v.start && v.end && v.start > v.end))) e.push('HK dan rentang jadwal tidak valid.');
  return e;
}
export function saveProject(s: State, input: Project, attest = false) {
  requireAdmin(s); const p = structuredClone(input); const existing = s.projects.find(v => v.id === p.id);
  if (existing?.status === 'Sah') throw new Error('PP yang sudah sah tidak dapat diubah melalui form draft.');
  const errors = projectErrors(s, p); if (errors.length) throw new Error(errors.join('\n'));
  if (p.parentId && !existing) throw new Error('PP lanjutan harus dibuat dari pelepasan BA PP.');
  if (existing) { p.rootId = existing.rootId; p.parentId = existing.parentId; p.sourceBapp = existing.sourceBapp; p.allocations = existing.allocations; p.boq = existing.boq; p.boqStatus = existing.boqStatus; if (existing.parentId) p.budget = existing.budget; }
  if (attest && (!p.intro.trim() || !p.team.length || p.team.some(t => !t.name.trim() || !t.job.trim()) || !p.risk.trim() || !p.communication.trim() || !p.quality.trim() || p.schedule.some(v => !v.start || !v.end))) throw new Error('Lengkapi pendahuluan, tim, tanggal jadwal, risiko, komunikasi dan kualitas sebelum pengesahan.');
  p.no = p.no.trim(); p.status = attest ? 'Sah' : 'Draft';
  if (existing) s.projects[s.projects.indexOf(existing)] = p; else s.projects.unshift(p);
  log(s, attest ? 'PP disahkan' : existing ? 'Draft PP disimpan' : 'PP dibuat', p.no, `/project-plan/${p.id}`); return p.id;
}
export function importDemo(s: State, projectId: string) {
  requireAdmin(s); const p = getProject(s, projectId); if (p.boqStatus === 'Final' || p.parentId) throw new Error('BOQ final atau alokasi lanjutan tidak dapat diimpor ulang.');
  p.boq = s.khs.filter(k => k.active).slice(0, 6).map((k, i) => ({ id: `${p.id}-row-${i}`, khsId: k.id, qty: [1200, 800, 12, 4, 8, 6][i], excelM: k.m === null ? null : k.m + (i === 1 ? 500 : 0), excelS: k.s, m: k.m, s: k.s, version: k.version, reviewed: i !== 1, note: '' })); p.boqStatus = 'Review'; log(s, 'Simulasi BOQ dimuat', p.no, `/boq/${p.id}`);
}
export function reviewBoq(s: State, projectId: string, rowId: string, note: string) { requireAdmin(s); const p = getProject(s, projectId); if (p.boqStatus !== 'Review') throw new Error('BOQ bukan draft review.'); const r = p.boq.find(r => r.id === rowId)!; const k = s.khs.find(k => k.id === r.khsId); if (!k?.active) throw new Error('Designator aktif tidak ditemukan.'); if (!note.trim()) throw new Error('Catatan review wajib diisi.'); Object.assign(r, { reviewed: true, note: note.trim(), m: k.m, s: k.s, version: k.version }); log(s, 'Selisih BOQ direview', `${p.no} · ${k.code}`, `/boq/${p.id}`); }
export function finalizeBoq(s: State, projectId: string) { requireAdmin(s); const p = getProject(s, projectId); if (p.boqStatus !== 'Review' || !p.boq.length) throw new Error('Muat BOQ terlebih dahulu.'); if (p.boq.some(r => { const k = s.khs.find(k => k.id === r.khsId); return !k?.active || !r.reviewed || r.version !== k.version || !Number.isFinite(r.qty) || r.qty <= 0; })) throw new Error('Finalisasi diblokir: selesaikan seluruh review dan revalidasi Master KHS.'); p.boqStatus = 'Final'; log(s, 'BOQ difinalisasi', `${p.no} · ${money(boqTotal(p))}`, `/boq/${p.id}`); }
export function blankDeal(s: State, projectId: string): Deal {
  const p = getProject(s, projectId); const root = getProject(s, p.rootId);
  return { id: uid('bakh'), projectId, no: '', mandor: '', status: 'Draft', rows: root.boq.map(r => ({ rowId: r.id, m: false, s: false, qm: 0, qs: 0, pm: round((r.m ?? 0) * .85), ps: round((r.s ?? 0) * .85), sphM: round((r.m ?? 0) * .8), sphS: round((r.s ?? 0) * .8), offerM: round((r.m ?? 0) * .9), offerS: round((r.s ?? 0) * .9), capM: r.m, capS: r.s })), place: '', location: '', witel: '', date: today(), start: '', end: '', signer: '', fo: '', jointer: '', evidence: '' };
}
export function dealErrors(s: State, d: Deal, formal = false) {
  const e: string[] = [], p = getProject(s, d.projectId);
  if (p.boqStatus !== 'Final' || p.status !== 'Sah') e.push('PP harus sah dan BOQ harus final.');
  if (!d.no.trim() || !d.mandor.trim()) e.push('Nomor BA KH dan Mandor wajib diisi.');
  if (s.deals.some(v => v.id !== d.id && v.no === d.no.trim())) e.push('Nomor BA KH sudah digunakan.');
  if (!d.rows.some(r => r.m || r.s)) e.push('Pilih minimal satu komponen pekerjaan.');
  for (const r of d.rows) for (const c of ['m', 's'] as Component[]) if (r[c]) {
    const q = c === 'm' ? r.qm : r.qs, price = c === 'm' ? r.pm : r.ps, cap = c === 'm' ? r.capM : r.capS;
    if (cap === null || !validVolume(q) || q <= 0 || q > capacity(s, p, r.rowId, c, d.id)) e.push(`${r.rowId.split('-row-').at(-1)} · Volume ${c === 'm' ? 'Material' : 'Jasa'} melebihi ketersediaan atau tidak valid.`);
    if (!Number.isFinite(price) || price < 0 || price > (cap ?? 0)) e.push(`Harga ${c === 'm' ? 'Material' : 'Jasa'} harus 0 sampai harga KHS komponen tersebut.`);
    for (const proposal of c === 'm' ? [r.sphM, r.offerM] : [r.sphS, r.offerS]) if (!Number.isFinite(proposal) || proposal < 0) e.push('Harga SPH/penawaran harus angka nonnegatif.');
  }
  if (formal) {
    if (!d.place || !d.location || !d.witel || !d.date || !d.start || !d.end || !d.signer || !d.fo || !d.jointer) e.push('Lengkapi informasi dokumen, periode, penandatangan serta tim FO/Jointer.');
    const dates = p.schedule.flatMap(v => [v.start, v.end]).filter(Boolean).sort(); const duration = p.schedule.reduce((n, v) => n + v.days, 0);
    const workDays = (Date.parse(d.end) - Date.parse(d.start)) / 86400000 + 1;
    if (d.start > d.end || workDays > duration || !dates.length || d.start < dates[0] || d.end > dates.at(-1)!) e.push('Periode mandor harus di dalam jadwal PP dan tidak melebihi total HK.');
  }
  return [...new Set(e)];
}
export function saveDeal(s: State, input: Deal, action: 'save' | 'generate' | 'attest' = 'save') {
  requireAdmin(s); const d = structuredClone(input), old = s.deals.find(v => v.id === d.id); if (old?.status === 'Sah') throw new Error('Snapshot BA KH sah tidak dapat diedit.');
  if (old?.status === 'Terbit') { if (action !== 'attest') throw new Error('BA KH terbit terkunci; hanya pengesahan bukti dokumen tersedia.'); Object.assign(d, structuredClone(old), { evidence: input.evidence }); }
  const e = dealErrors(s, d, action !== 'save'); if (e.length) throw new Error(e.join('\n'));
  if (action === 'attest' && !d.evidence.trim()) throw new Error('Masukkan referensi bukti dokumen bertanda tangan. Tanda tangan tidak dibuat otomatis.');
  d.status = action === 'attest' ? 'Sah' : action === 'generate' ? 'Terbit' : 'Draft';
  if (old) s.deals[s.deals.indexOf(old)] = d; else s.deals.push(d); log(s, action === 'attest' ? 'BA KH disahkan' : action === 'generate' ? 'BA KH diterbitkan' : 'Negosiasi disimpan', d.no, `/ba-kh/${d.id}`); return d.id;
}
export function blankSpk(s: State, dealId: string): Spk { const d = getDeal(s, dealId); return { id: uid('spk'), dealId, no: '', status: 'Draft', date: today(), start: d.start, end: d.end, place: d.place, address: '', supervisor: '', phone: '', signer: d.signer, position: '', evidence: '' }; }
export function saveSpk(s: State, input: Spk, action: 'save' | 'generate' | 'attest' = 'save') {
  requireAdmin(s); const v = structuredClone(input), d = getDeal(s, v.dealId); const old = s.spks.find(x => x.id === v.id);
  if (old?.status === 'Sah') throw new Error('SPK sah tidak dapat diubah.'); if (old?.status === 'Terbit') { if (action !== 'attest') throw new Error('SPK terbit terkunci; hanya pengesahan bukti dokumen tersedia.'); Object.assign(v, structuredClone(old), { evidence: input.evidence }); } if (d.status !== 'Sah') throw new Error('SPK hanya dari BA KH sah.');
  if (s.spks.some(x => x.id !== v.id && (x.dealId === v.dealId || x.no === v.no.trim()))) throw new Error('BA KH sudah memiliki SPK atau nomor SPK duplikat.');
  if (!v.no.trim()) throw new Error('Nomor SPK wajib diisi.');
  if (action !== 'save' && (!v.place || !v.date || !v.start || !v.end || !v.address || !v.supervisor || !v.phone || !v.signer || !v.position)) throw new Error('Lengkapi seluruh informasi SPK sebelum penerbitan.');
  if (v.start > v.end || v.start < d.start || v.end > d.end) throw new Error('Periode SPK harus berada dalam periode BA KH.');
  if (action === 'attest' && !v.evidence.trim()) throw new Error('Referensi bukti tanda tangan SPK wajib diisi.');
  const errs = dealErrors(s, d, true); if (errs.length) throw new Error(errs.join('\n'));
  v.status = action === 'attest' ? 'Sah' : action === 'generate' ? 'Terbit' : 'Draft'; if (old) s.spks[s.spks.indexOf(old)] = v; else s.spks.push(v);
  log(s, action === 'attest' ? 'SPK disahkan · volume committed' : action === 'generate' ? 'SPK diterbitkan' : 'Draft SPK disimpan', v.no, `/spk/${v.id}`); return v.id;
}
export function createTerm(s: State, spkId: string) { requireAdmin(s); const reason = nextTermReason(s, spkId); if (reason) throw new Error(reason); const number = s.terms.filter(t => t.spkId === spkId).length + 1; const t: Term = { id: uid('termin'), spkId, number, type: number === 3 ? 'Final' : 'Parsial', status: 'Draft', actuals: {}, approvals: [], history: [], apm: '', apmStatus: 'Belum Terbit', paid: 0, paymentHistory: [] }; s.terms.push(t); log(s, 'Draft termin dibuat', `${getSpk(s, spkId).no} · Termin ${number}`, `/termin/${t.id}`); return t.id; }
export function termErrors(s: State, t: Term) {
  const d = getDeal(s, getSpk(s, t.spkId).dealId), e: string[] = [];
  if (t.number === 3 && t.type !== 'Final') e.push('Termin ketiga wajib Final.');
  if (t.number > 3) e.push('Maksimal tiga termin.');
  for (const r of d.rows) for (const c of ['m', 's'] as Component[]) { const q = t.actuals[r.rowId]?.[c] ?? 0; if (!validVolume(q) || (!r[c] && q !== 0) || q > remaining(s, t.spkId, r, c)) e.push(`Volume aktual ${c === 'm' ? 'Material' : 'Jasa'} melebihi sisa komitmen atau tidak valid.`); }
  if (termTotal(s, t) <= 0) e.push('Masukkan minimal satu volume aktual dengan nilai tagihan positif.'); return [...new Set(e)];
}
export function saveTerm(s: State, input: Term, submit = false) {
  requireAdmin(s); const old = getTerm(s, input.id); if (!['Draft', 'Dikembalikan'].includes(old.status)) throw new Error('Termin terkunci setelah diajukan atau ditolak.');
  const t = structuredClone(old); t.type = input.type; t.actuals = structuredClone(input.actuals);
  const e = termErrors(s, t); if (e.length) throw new Error(e.join('\n'));
  if (submit) { t.status = 'Diajukan'; t.approvals = []; t.submitted = now(); }
  s.terms[s.terms.indexOf(old)] = t; log(s, submit ? 'Termin diajukan ke PM Konstruksi' : 'Draft termin disimpan', `${getSpk(s, t.spkId).no} · Termin ${t.number} · ${money(termTotal(s, t))}`, `/termin/${t.id}`);
}
export function decide(s: State, termId: string, decision: Approval['decision'], note: string) {
  const t = getTerm(s, termId); if (approvalRole(t) !== s.role) throw new Error('Approval harus berurutan oleh role yang ditugaskan. Superadmin tidak menggantikan approver.');
  if (decision !== 'Setujui' && !note.trim()) throw new Error('Alasan wajib untuk pengembalian atau penolakan.');
  const approval: Approval = { role: s.role, decision, note: note.trim(), at: now() }; t.history.push(approval);
  if (decision === 'Setujui') { t.approvals.push(approval); if (t.approvals.length === 3) { t.status = 'Disetujui'; if (t.type === 'Final') s.bapps.push({ id: uid('bapp'), termId: t.id, no: `BAPP/${getSpk(s, t.spkId).no}`, status: 'Draft', evidence: '', released: {}, releaseHistory: [] }); } }
  else { t.status = decision === 'Kembalikan' ? 'Dikembalikan' : 'Ditolak'; t.approvals = []; }
  log(s, decision === 'Setujui' ? `Approval ${s.role} selesai` : `Termin ${decision.toLowerCase()}`, `${getSpk(s, t.spkId).no} · Termin ${t.number}${note ? ` · ${note}` : ''}`, `/termin/${t.id}`);
}
export function recordApm(s: State, termId: string, apm: string, valid = true) { requireFinance(s); const t = getTerm(s, termId); if (t.status !== 'Disetujui' || t.approvals.length !== 3) throw new Error('Tiga approval wajib selesai.'); if (!/^[A-Za-z0-9][A-Za-z0-9/._-]{4,59}$/.test(apm.trim())) throw new Error('Nomor APM 5–60 karakter: huruf, angka, / . _ atau -.'); if (valid && s.terms.some(x => x.id !== t.id && x.apmStatus === 'Valid' && x.apm === apm.trim())) throw new Error('Nomor APM sudah digunakan.'); if (!valid && s.terms.some(x => x.spkId === t.spkId && x.number > t.number)) throw new Error('APM tidak dapat dibatalkan setelah termin berikutnya dibuat.'); t.apm = apm.trim(); t.apmStatus = valid ? 'Valid' : 'Perlu Koreksi'; log(s, valid ? 'APM dicatat' : 'APM perlu koreksi', t.apm, `/termin/${t.id}`); }
export function recordPayment(s: State, termId: string, amount: number, ref: string) { requireFinance(s); const t = getTerm(s, termId); if (t.status !== 'Disetujui' || t.apmStatus !== 'Valid') throw new Error('Pembayaran membutuhkan termin approved dan APM valid.'); if (!Number.isFinite(amount) || round(amount) <= 0 || Math.abs(amount * 100 - Math.round(amount * 100)) > 0.000001 || round(t.paid + amount) > termTotal(s, t) || !ref.trim()) throw new Error('Nilai pembayaran harus positif, tidak melebihi outstanding, dan memiliki referensi.'); t.paid = round(t.paid + amount); t.paymentHistory.push({ amount: round(amount), ref: ref.trim(), at: now() }); log(s, 'Pembayaran dicatat', `${ref} · ${money(amount)}`, `/termin/${t.id}`); }
export function attestBapp(s: State, id: string, no: string, evidence: string) { requireAdmin(s); const b = s.bapps.find(b => b.id === id)!; if (b.status === 'Sah') throw new Error('BA PP sudah disahkan.'); if (getTerm(s, b.termId).status !== 'Disetujui') throw new Error('Termin Final belum approved.'); if (!no.trim() || !evidence.trim()) throw new Error('Nomor BA PP dan referensi bukti tanda tangan wajib.'); b.no = no.trim(); b.evidence = evidence.trim(); b.status = 'Sah'; log(s, 'BA PP difinalisasi', b.no, `/ba-pp/${b.id}`); }
export function releaseVolume(s: State, id: string, quantities: Record<string, { m: number; s: number }>) {
  requireAdmin(s); const b = s.bapps.find(b => b.id === id)!; const t = getTerm(s, b.termId); if (b.status !== 'Sah' || t.type !== 'Final' || t.status !== 'Disetujui' || t.approvals.length !== 3) throw new Error('BA PP sah dan tiga approval Final wajib sebelum pelepasan.');
  const d = getDeal(s, getSpk(s, t.spkId).dealId); let total = 0;
  for (const [rowId, q] of Object.entries(quantities)) { const r = d.rows.find(r => r.rowId === rowId); if (!r) throw new Error('Item pelepasan tidak dikenal.'); for (const c of ['m', 's'] as Component[]) { if (!validVolume(q[c]) || q[c] > remaining(s, t.spkId, r, c)) throw new Error('Pelepasan melebihi sisa volume yang belum accepted.'); total += q[c]; } }
  if (total <= 0) throw new Error('Pilih volume positif untuk dilepas.');
  for (const [rowId, q] of Object.entries(quantities)) { const old = b.released[rowId] ?? { m: 0, s: 0 }; b.released[rowId] = { m: volumeRound(old.m + q.m), s: volumeRound(old.s + q.s) }; } b.releaseHistory.push({ quantities: structuredClone(quantities), at: now(), actor: s.role }); log(s, 'Volume dilepas oleh Admin', b.no, `/ba-pp/${b.id}`);
}
export function continuationAvailable(s: State, b: Bapp, rowId: string, c: Component) { const allocated = s.projects.filter(p => p.sourceBapp === b.id).reduce((n, p) => n + (p.allocations[rowId]?.[c] ?? 0), 0); return volumeRound((b.released[rowId]?.[c] ?? 0) - allocated); }
export function createContinuation(s: State, bappId: string, no: string, allocations: Project['allocations'], notes: string) {
  requireAdmin(s); const b = s.bapps.find(b => b.id === bappId)!; const t = getTerm(s, b.termId); if (b.status !== 'Sah' || t.status !== 'Disetujui' || t.approvals.length !== 3 || !b.releaseHistory.length) throw new Error('BA PP sah, Final approved dan otorisasi pelepasan wajib.');
  if (!no.trim() || s.projects.some(p => p.no.toLowerCase() === no.trim().toLowerCase())) throw new Error('No PP Baru wajib unik dan diisi manual.');
  const parent = projectForSpk(s, getSpk(s, t.spkId)), root = getProject(s, parent.rootId); let budget = 0, qTotal = 0;
  for (const [id, q] of Object.entries(allocations)) { const row = root.boq.find(r => r.id === id); if (!row) throw new Error('Item alokasi tidak dikenal.'); for (const c of ['m', 's'] as Component[]) { if (!validVolume(q[c]) || q[c] > continuationAvailable(s, b, id, c) || q[c] > ledger(s, root.id, id, c).available) throw new Error('Volume lanjutan melebihi volume dilepas yang masih tersedia.'); qTotal += q[c]; budget += q[c] * (row[c] ?? 0); } }
  if (qTotal <= 0) throw new Error('Pilih minimal satu volume untuk PP lanjutan.');
  const p = blankProject(); Object.assign(p, { no: no.trim(), name: `${parent.name} — Lanjutan`, unit: parent.unit, branch: parent.branch, projectCode: parent.projectCode, budget: round(budget), date: today(), rootId: root.id, parentId: parent.id, sourceBapp: b.id, allocations: structuredClone(allocations), intro: notes, boqStatus: 'Final', boq: [], schedule: structuredClone(parent.schedule) }); s.projects.push(p); log(s, 'PP lanjutan dibuat · volume reserved', `${p.no} ← ${parent.no}`, `/project-plan/${p.id}`); return p.id;
}
export function updateKhs(s: State, input: Khs, remove = false) {
  requireAdmin(s); const old = s.khs.find(k => k.id === input.id);
  if ((remove || !old) && s.role !== 'Superadmin') throw new Error('Tambah/hapus Master KHS hanya Superadmin.');
  if (remove) { if (s.projects.some(p => p.boq.some(r => r.khsId === input.id))) throw new Error('KHS sudah digunakan; nonaktifkan agar snapshot tetap utuh.'); s.khs = s.khs.filter(k => k.id !== input.id); return; }
  if (!input.code.trim() || !input.name.trim() || !input.unit.trim() || !input.package.trim()) throw new Error('Identitas KHS wajib lengkap.');
  if (s.khs.some(k => k.id !== input.id && k.code === input.code && k.package === input.package)) throw new Error('Designator/paket sudah ada.');
  if ([input.m, input.s].some(n => n !== null && (!Number.isFinite(n) || n < 0)) || (input.m === null && input.s === null)) throw new Error('Minimal satu harga komponen nonnegatif wajib tersedia.');
  const k = structuredClone(input); k.version = (old?.version ?? 0) + 1; k.history = [...(old?.history ?? []), { at: now(), actor: s.role, m: k.m, s: k.s }];
  if (old) s.khs[s.khs.indexOf(old)] = k; else s.khs.push(k);
  for (const p of s.projects.filter(p => p.boqStatus === 'Review')) for (const r of p.boq.filter(r => r.khsId === k.id)) { r.reviewed = false; r.m = k.m; r.s = k.s; r.version = k.version; }
  log(s, 'Master KHS diperbarui', `${k.code} · versi ${k.version}; snapshot terbit tetap`, '/master-khs');
}
export function saveUser(s: State, user: User, remove = false) {
  if (s.role !== 'Superadmin') throw new Error('Manajemen pengguna hanya Superadmin.');
  if (!user.name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(user.email) || !ROLES.includes(user.role)) throw new Error('Nama, email valid, dan role wajib diisi.');
  if (s.users.some(u => u.id !== user.id && u.email.toLowerCase() === user.email.toLowerCase())) throw new Error('Email pengguna sudah digunakan.');
  if (remove) s.users = s.users.filter(u => u.id !== user.id); else { const i = s.users.findIndex(u => u.id === user.id); if (i < 0) s.users.push(structuredClone(user)); else s.users[i] = structuredClone(user); }
  log(s, remove ? 'Pengguna demo dihapus' : 'Pengguna demo disimpan', user.name, '/pengaturan?tab=users');
}
export function saveProfile(s: State, name: string, email: string) { if (!name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw new Error('Nama dan email profil wajib valid.'); s.profile = { name: name.trim(), email: email.trim() }; log(s, 'Profil diperbarui', name, '/pengaturan'); }
