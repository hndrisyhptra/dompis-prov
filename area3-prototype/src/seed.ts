import { type State, type Project, type Role, type Term, ROLES, blankProject, importDemo, reviewBoq, finalizeBoq, blankDeal, saveDeal, blankSpk, saveSpk, createTerm, saveTerm, decide, recordApm, recordPayment, attestBapp, releaseVolume, createContinuation, now, today, uid } from './domain';
export const SCENARIOS = [
  { id: 'overview', label: 'Ringkasan operasional', description: 'Beberapa proyek terhubung, satu approval menunggu dan satu PP lanjutan.' },
  { id: 'A', label: 'A · Validasi BOQ', description: 'Buka BOQ PP/026/AREA3 → review selisih → finalisasi.' },
  { id: 'B', label: 'B · Negosiasi BA KH', description: 'PP sah + BOQ final → pilih komponen → SPH → penawaran → kesepakatan.' },
  { id: 'C', label: 'C · Penerbitan SPK', description: 'BA KH sah → lengkapi SPK → preview → pengesahan dan commitment.' },
  { id: 'D', label: 'D · Termin parsial', description: 'SPK sah → volume aktual → ajukan → ganti role PM, Manager, Finance → APM.' },
  { id: 'E', label: 'E · Final & PP lanjutan', description: 'Final disetujui → sahkan BA PP → lepaskan sisa volume → masukkan No PP Baru.' },
] as const;
export function seed(scenario = 'overview'): State {
  const s: State = { schema: 1, generation: uid('demo'), role: 'Admin Konstruksi', scenario, projects: [], khs: [], deals: [], spks: [], terms: [], bapps: [], events: [], users: ROLES.map((role, i) => ({ id: `user-${i}`, name: ['Aditya Pratama', 'Rizky Nugraha', 'Dian Puspita', 'Nadia Rahma', 'Administrator'][i], email: `demo${i + 1}@area3.example`, role, active: true })), profile: { name: 'Aditya Pratama', email: 'aditya@area3.example' }, lastSaved: now() };
  const masters: [string, string, string, number | null, number | null][] = [
    ['DEMO-FO-24', 'Kabel fiber optik aerial 24 core', 'm', 18500, 12500],
    ['DEMO-FO-12', 'Kabel fiber optik duct 12 core', 'm', 14250, 16500],
    ['DEMO-TIANG', 'Tiang besi 7 meter', 'batang', 1850000, 450000],
    ['DEMO-ODC', 'ODC outdoor 144 core', 'unit', 8750000, 1250000],
    ['DEMO-SPLICE', 'Penyambungan fiber optik', 'titik', null, 185000],
    ['DEMO-OTDR', 'Pengujian OTDR & commissioning', 'link', null, 650000],
  ];
  s.khs = masters.map(([code, name, unit, m, service], i) => ({ id: `khs-${i}`, code, name, unit, package: 'Paket 10 · Demo', m, s: service, version: 1, active: true, history: [{ at: now(), actor: 'Admin Konstruksi', m, s: service }] }));
  function makeProject(no: string, name: string, branch: string): Project {
    const p = blankProject(); p.id = `pp-${s.projects.length + 1}`; p.rootId = p.id; Object.assign(p, { no, name, branch, unit: 'Konstruksi Area 3', projectCode: `PRJ-2026-${s.projects.length + 1}`, budget: 240000000, date: '2026-11-02', status: 'Sah', intro: 'Pembangunan dan penguatan jaringan akses fiber optik untuk peningkatan kapasitas layanan pelanggan.', team: [{ name: 'Aditya Pratama', job: 'Admin Konstruksi' }, { name: 'Rizky Nugraha', job: 'PM Konstruksi' }], schedule: p.schedule.map((v, i) => ({ ...v, days: [3, 5, 15, 4, 3][i], start: '2026-11-02', end: '2026-12-01' })), risk: 'Mitigasi keterlambatan izin dengan koordinasi pemangku wilayah sebelum mobilisasi.', communication: 'Laporan harian melalui koordinasi proyek dan dokumentasi progres pekerjaan.', quality: 'Inspeksi material, pengujian OTDR dan uji terima sesuai spesifikasi.', costs: { mandor: 130000000, material: 6000000, bop: 4500000, permit: 2000000 } });
    s.projects.push(p); importDemo(s, p.id); return p;
  }
  function finalBoq(p: Project) { p.boq.filter(r => !r.reviewed).forEach(r => reviewBoq(s, p.id, r.id, 'Harga resmi Master KHS digunakan pada skenario demo.')); finalizeBoq(s, p.id); }
  function contract(p: Project, attest = true) { const d = blankDeal(s, p.id); Object.assign(d, { no: `BAKH/${p.no}`, mandor: 'Budi Santoso', place: 'Surabaya', location: p.branch, witel: p.branch, date: '2026-11-02', start: '2026-11-02', end: '2026-11-21', signer: 'Pejabat TA · Demo', fo: 'Tim FO 01 · Demo', jointer: 'Tim Jointer 01 · Demo', evidence: 'Bukti skenario demo — bukan tanda tangan nyata' }); d.rows.forEach((r, i) => { r.m = r.capM !== null; r.s = r.capS !== null; r.qm = r.m ? p.boq[i].qty : 0; r.qs = r.s ? p.boq[i].qty : 0; }); saveDeal(s, d, attest ? 'attest' : 'save'); return d; }
  function spkFor(d: ReturnType<typeof blankDeal>) { const spk = blankSpk(s, d.id); Object.assign(spk, { no: `SPK/${getNo(d.projectId)}`, date: '2026-11-02', address: 'Alamat mandor · data demonstrasi', supervisor: 'Arif Wijaya', phone: '080000000000', position: 'Manager Konstruksi', evidence: 'Bukti skenario demo — bukan tanda tangan nyata' }); saveSpk(s, spk, 'attest'); return spk; }
  function getNo(id: string) { return s.projects.find(p => p.id === id)!.no; }
  function submit(spkId: string, ratio: number, final = false) { s.role = 'Admin Konstruksi'; const id = createTerm(s, spkId); const t = s.terms.find(t => t.id === id)!; t.type = final ? 'Final' : 'Parsial'; const d = s.deals.find(d => d.id === s.spks.find(v => v.id === spkId)!.dealId)!; d.rows.forEach(r => t.actuals[r.rowId] = { m: r.m ? r.qm * ratio : 0, s: r.s ? r.qs * ratio : 0 }); saveTerm(s, t, true); return t; }
  function approve(t: Term, apm = false) { (['PM Konstruksi', 'Manager Konstruksi', 'Finance'] as Role[]).forEach(role => { s.role = role; decide(s, t.id, 'Setujui', 'Diperiksa pada skenario demo.'); }); if (apm) recordApm(s, t.id, `APM/2026/${s.terms.length.toString().padStart(3, '0')}`); s.role = 'Admin Konstruksi'; }
  const main = makeProject('PP/026/AREA3', 'Modernisasi Jaringan Akses Surabaya Timur', 'Surabaya');
  if (scenario !== 'A') finalBoq(main);
  if (['C', 'D', 'E', 'overview'].includes(scenario)) {
    const d = contract(main);
    if (scenario !== 'C') {
      const spk = spkFor(d);
      if (scenario === 'E') { const t1 = submit(spk.id, .4); approve(t1, true); const t2 = submit(spk.id, .35, true); approve(t2); }
      if (scenario === 'overview') { const t1 = submit(spk.id, .4); approve(t1, true); s.role = 'Finance'; recordPayment(s, t1.id, 10000000, 'TRF/DEMO/001'); s.role = 'Admin Konstruksi'; submit(spk.id, .2); }
    }
  }
  if (scenario === 'overview') {
    const complete = makeProject('PP/018/AREA3', 'Pembangunan FTTH Kawasan Sidoarjo', 'Sidoarjo'); finalBoq(complete); const d = contract(complete); const spk = spkFor(d); const t = submit(spk.id, .75, true); approve(t, true); const b = s.bapps.find(b => b.termId === t.id)!; attestBapp(s, b.id, 'BAPP/018/2026', 'Arsip demo dokumen bertanda tangan'); const qs = Object.fromEntries(d.rows.map(r => [r.rowId, { m: r.m ? r.qm * .25 : 0, s: r.s ? r.qs * .25 : 0 }])); releaseVolume(s, b.id, qs); createContinuation(s, b.id, 'PP/031/AREA3', qs, 'Penyelesaian sisa pekerjaan dari BA PP/018.');
    const p3 = makeProject('PP/029/AREA3', 'Penguatan Backbone Fiber Malang Selatan', 'Malang'); finalBoq(p3);
    const p4 = makeProject('PP/030/AREA3', 'Relokasi Jaringan Akses Denpasar', 'Denpasar'); p4.status = 'Draft';
  }
  s.role = 'Admin Konstruksi'; return s;
}
