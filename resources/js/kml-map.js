/** Dependencies are injected so error, retry, and safe popup behavior can be tested without a browser. */
export async function setupKmlMap(root, { leaflet, fetcher = fetch, documentRef = document, timeoutMs = 15000 }) {
    const host = root.querySelector('[data-kml-map]');
    const status = root.querySelector('[data-kml-status]');
    const retry = root.querySelector('[data-kml-retry]');
    const fit = root.querySelector('[data-kml-fit]');
    let map;
    let layer;
    let loading = false;
    const fitAll = () => { if (layer) map.fitBounds(layer.getBounds(), { padding: [24, 24], maxZoom: 17 }); };
    fit.addEventListener('click', fitAll);
    async function load() {
        if (loading) return;
        loading = true;
        retry.hidden = true;
        fit.disabled = true;
        host.setAttribute('aria-busy', 'true');
        status.textContent = 'Memuat data peta…';
        status.classList.remove('danger');
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetcher(root.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });
            if (!response.ok) throw new Error('Data peta tidak tersedia. Muat ulang halaman untuk memeriksa akses atau coba lagi.');
            const data = await response.json();
            if (data.type !== 'FeatureCollection' || !Array.isArray(data.features) || !data.features.length) throw new Error('KML belum memiliki objek untuk ditampilkan.');
            if (!map) map = leaflet.map(host, { scrollWheelZoom: false });
            if (layer) layer.remove();
            layer = leaflet.geoJSON(data, {
                style: { color: '#315B8C', weight: 4, fillOpacity: 0.15 },
                pointToLayer: (_feature, latlng) => leaflet.circleMarker(latlng, { radius: 7, color: '#315B8C', fillColor: '#729bc9', fillOpacity: 0.9, weight: 2 }),
                onEachFeature: (feature, item) => {
                    const label = documentRef.createElement('span');
                    label.textContent = feature.properties?.name || 'Objek KML';
                    item.bindPopup(label);
                },
            }).addTo(map);
            if (!layer.getBounds().isValid()) throw new Error('Batas koordinat KML tidak dapat ditampilkan.');
            fitAll();
            fit.disabled = false;
            status.textContent = `${data.features.length} objek KML ditampilkan.`;
            // The provider is trusted deployment configuration; no KML URL/style is requested.
            if (!root.dataset.tilesLoaded && root.dataset.tileUrl) {
                leaflet.tileLayer(root.dataset.tileUrl, { attribution: root.dataset.attribution, maxZoom: 19 }).on('tileerror', () => {
                    status.textContent = 'Peta dasar belum dapat dimuat. Objek KML tetap tampil; periksa koneksi internet.';
                }).addTo(map);
                root.dataset.tilesLoaded = '1';
            }
        } catch (error) {
            status.textContent = error.name === 'AbortError' ? 'Waktu pemuatan peta habis. Periksa koneksi lalu coba lagi.' : error.message || 'Preview peta gagal dimuat.';
            status.classList.add('danger');
            retry.hidden = false;
        } finally {
            clearTimeout(timer);
            host.setAttribute('aria-busy', 'false');
            loading = false;
        }
    }
    retry.addEventListener('click', load);
    await load();
}
