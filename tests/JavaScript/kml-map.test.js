import test from 'node:test';
import assert from 'node:assert/strict';
import { setupKmlMap } from '../../resources/js/kml-map.js';

function fixture(fetcher) {
    const events = {};
    const element = () => ({ classList: { add() {}, remove() {} }, setAttribute(key, value) { this[key] = value; }, addEventListener(name, callback) { this[name] = callback; } });
    const host = element(), status = element(), retry = element(), fit = element();
    const root = { dataset: { url: '/scoped/data', tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: 'OSM' }, querySelector(key) { return key.includes('status') ? status : key.includes('retry') ? retry : key.includes('fit') ? fit : host; } };
    const map = { fitBounds(_bounds, options) { events.fit = options; } };
    const layer = { addTo() { return this; }, getBounds() { return { isValid: () => true }; }, remove() {} };
    const leaflet = {
        map: () => map,
        geoJSON(data, options) { events.options = options; events.data = data; return layer; },
        circleMarker: () => ({}),
        tileLayer(url) { events.tileUrl = url; return { on(_name, handler) { events.tileError = handler; return this; }, addTo() {} }; },
    };
    return { root, host, status, retry, fit, events, run: () => setupKmlMap(root, { leaflet, fetcher, documentRef: { createElement: () => ({}) } }) };
}
const data = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { name: '<img src=x onerror=alert(1)>' }, geometry: { type: 'Point', coordinates: [110, -7] } }] };
test('map fits all geometry, uses scoped JSON, and displays popup text without HTML injection', async () => {
    const f = fixture(async (url, options) => {
        assert.equal(url, '/scoped/data');
        assert.equal(options.credentials, 'same-origin');
        return { ok: true, json: async () => data };
    });
    await f.run();
    assert.equal(f.host['aria-busy'], 'false');
    assert.equal(f.retry.hidden, true);
    assert.equal(f.fit.disabled, false);
    assert.equal(f.events.fit.maxZoom, 17);
    let popup;
    f.events.options.onEachFeature(data.features[0], { bindPopup(value) { popup = value; } });
    assert.equal(popup.textContent, data.features[0].properties.name);
    assert.equal(popup.innerHTML, undefined);
    f.events.tileError();
    assert.match(f.status.textContent, /Objek KML tetap tampil/);
});
test('failed fetch offers retry and successful retry restores map actions', async () => {
    let calls = 0;
    const f = fixture(async () => ++calls === 1 ? { ok: false } : { ok: true, json: async () => data });
    await f.run();
    assert.equal(f.retry.hidden, false);
    assert.equal(f.fit.disabled, true);
    assert.match(f.status.textContent, /akses/);
    await f.retry.click();
    assert.equal(f.retry.hidden, true);
    assert.equal(f.fit.disabled, false);
});
test('empty geometry and expired requests show specific real error states', async () => {
    const empty = fixture(async () => ({ ok: true, json: async () => ({ type: 'FeatureCollection', features: [] }) }));
    await empty.run();
    assert.match(empty.status.textContent, /belum memiliki objek/);
    const timedOut = fixture(async () => { const error = new Error(); error.name = 'AbortError'; throw error; });
    await timedOut.run();
    assert.match(timedOut.status.textContent, /Waktu pemuatan/);
    assert.equal(timedOut.host['aria-busy'], 'false');
});
