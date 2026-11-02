import test from 'node:test';
import assert from 'node:assert/strict';
import { serializeCsv } from '../src/exports';
test('CSV preserves identifiers, numeric values, quotes, commas and line breaks', () => {
  const csv = serializeCsv(['No PP','Nilai','Catatan'],[['001/AREA3',2422500,'Catatan "ditinjau", hari ini\nBaris kedua']]);
  assert.equal(csv, '\ufeff"No PP","Nilai","Catatan"\r\n"001/AREA3","2422500","Catatan ""ditinjau"", hari ini\nBaris kedua"');
});
test('CSV neutralizes formula-leading text while leaving numeric values numeric', () => {
  const csv = serializeCsv(['Teks','Angka'],[['=1+1',-10],['+cmd',0],['@FORMULA',12.5]]);
  assert.ok(csv.includes('"\'=1+1","-10"'));
  assert.ok(csv.includes('"\'+cmd","0"'));
  assert.ok(csv.includes('"\'@FORMULA","12.5"'));
});
