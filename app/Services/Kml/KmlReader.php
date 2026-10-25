<?php

namespace App\Services\Kml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

class KmlReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private int $vertices = 0;

    private string $namespace = '';

    public function read(string $xml): array
    {
        $this->vertices = 0;
        if (strlen($xml) > self::MAX_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) || str_contains($xml, "\0")) {
            throw new InvalidArgumentException('Gunakan XML KML tanpa DTD/entity, maksimal 5 MB. KMZ belum didukung.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || libxml_get_errors()) {
                throw new InvalidArgumentException('Struktur XML KML tidak valid.');
            }
            $root = $doc->documentElement;
            $this->namespace = $root->namespaceURI ?? '';
            if ($root->localName !== 'kml' || ! in_array($this->namespace, ['', 'http://www.opengis.net/kml/2.2', 'http://earth.google.com/kml/2.1'], true)) {
                throw new InvalidArgumentException('File harus berupa KML 2.1/2.2.');
            }
            $nodes = 0;
            $this->checkTree($root, 0, $nodes);
            $xpath = new DOMXPath($doc);
            $placemarks = $xpath->query('//*[local-name()="Placemark"]');
            if ($placemarks->length > 200) {
                throw new InvalidArgumentException('Maksimal 200 objek Placemark per file.');
            }
            $features = [];
            foreach ($placemarks as $placemark) {
                $this->assertNamespace($placemark);
                $geometries = [];
                $name = '';
                foreach ($placemark->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    if ($child->localName === 'name') {
                        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($child->textContent))), 0, 160);
                    }
                    if (in_array($child->localName, ['Point', 'LineString', 'Polygon', 'MultiGeometry'], true)) {
                        $geometries[] = $this->geometry($child);
                    }
                }
                if (count($geometries) !== 1) {
                    throw new InvalidArgumentException('Setiap Placemark harus memiliki satu Point, LineString, Polygon, atau MultiGeometry.');
                }
                $features[] = ['type' => 'Feature', 'properties' => ['name' => $name ?: 'Objek '.(count($features) + 1)], 'geometry' => $geometries[0]];
            }
            if (! $features) {
                throw new InvalidArgumentException('KML belum memiliki titik, jalur, atau area yang bisa ditampilkan.');
            }

            return ['geojson' => ['type' => 'FeatureCollection', 'features' => $features], 'feature_count' => count($features), 'vertex_count' => $this->vertices];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function checkTree(DOMElement $element, int $depth, int &$nodes): void
    {
        if ($depth > 40 || ++$nodes > 30000) {
            throw new InvalidArgumentException('Struktur KML terlalu kompleks.');
        }
        if (in_array($element->localName, ['NetworkLink', 'NetworkLinkControl', 'GroundOverlay', 'ScreenOverlay', 'PhotoOverlay', 'Model', 'Track', 'MultiTrack'], true)) {
            throw new InvalidArgumentException('KML berisi tautan eksternal/overlay atau geometri yang belum didukung. Ekspor titik, jalur, atau area sebagai KML.');
        }
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->checkTree($child, $depth + 1, $nodes);
            }
        }
    }

    private function assertNamespace(DOMElement $element): void
    {
        if (($element->namespaceURI ?? '') !== $this->namespace) {
            throw new InvalidArgumentException('Namespace geometri KML tidak valid.');
        }
    }

    private function children(DOMElement $parent, string $name): array
    {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $this->assertNamespace($child);
                $result[] = $child;
            }
        }

        return $result;
    }

    private function geometry(DOMElement $element): array
    {
        $this->assertNamespace($element);
        if ($element->localName === 'MultiGeometry') {
            $geometries = [];
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    if (! in_array($child->localName, ['Point', 'LineString', 'Polygon', 'MultiGeometry'], true)) {
                        throw new InvalidArgumentException('MultiGeometry berisi geometri yang belum didukung.');
                    }
                    $geometries[] = $this->geometry($child);
                }
            }
            if (! $geometries) {
                throw new InvalidArgumentException('MultiGeometry kosong.');
            }

            return ['type' => 'GeometryCollection', 'geometries' => $geometries];
        }
        if ($element->localName === 'Polygon') {
            $outer = $this->children($element, 'outerBoundaryIs');
            if (count($outer) !== 1) {
                throw new InvalidArgumentException('Polygon wajib memiliki satu batas luar.');
            }
            $rings = [];
            foreach (array_merge($outer, $this->children($element, 'innerBoundaryIs')) as $boundary) {
                $ring = $this->children($boundary, 'LinearRing');
                if (count($ring) !== 1) {
                    throw new InvalidArgumentException('Batas Polygon harus berupa LinearRing.');
                }
                $points = $this->coordinates($ring[0]);
                if (count($points) < 4 || $points[0] !== $points[array_key_last($points)]) {
                    throw new InvalidArgumentException('Polygon harus memiliki minimal empat koordinat dengan awal dan akhir yang sama.');
                }
                $rings[] = $points;
            }

            return ['type' => 'Polygon', 'coordinates' => $rings];
        }
        $points = $this->coordinates($element);
        if (($element->localName === 'Point' && count($points) !== 1) || ($element->localName === 'LineString' && count($points) < 2)) {
            throw new InvalidArgumentException('Point memerlukan satu koordinat; LineString minimal dua koordinat.');
        }

        return ['type' => $element->localName, 'coordinates' => $element->localName === 'Point' ? $points[0] : $points];
    }

    private function coordinates(DOMElement $element): array
    {
        $coordinates = $this->children($element, 'coordinates');
        if (count($coordinates) !== 1) {
            throw new InvalidArgumentException('Geometri harus memiliki satu elemen coordinates.');
        }
        $points = [];
        foreach (preg_split('/\s+/', trim($coordinates[0]->textContent), flags: PREG_SPLIT_NO_EMPTY) as $tuple) {
            $parts = explode(',', $tuple);
            if (count($parts) < 2 || count($parts) > 3 || count(array_filter($parts, fn ($v) => is_numeric($v) && is_finite((float) $v))) !== count($parts)) {
                throw new InvalidArgumentException('Koordinat harus longitude,latitude[,altitude] yang valid.');
            }
            $lon = (float) $parts[0];
            $lat = (float) $parts[1];
            if (abs($lon) > 180 || abs($lat) > 90) {
                throw new InvalidArgumentException('Longitude harus −180 sampai 180 dan latitude −90 sampai 90.');
            }
            if (++$this->vertices > 10000) {
                throw new InvalidArgumentException('Maksimal 10.000 koordinat per file.');
            }
            $points[] = [$lon, $lat]; // Altitude does not affect the 2D map. Never infer missing coordinates.
        }
        if (! $points) {
            throw new InvalidArgumentException('Koordinat geometri kosong.');
        }

        return $points;
    }
}
