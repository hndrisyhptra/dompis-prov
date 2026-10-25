<?php

namespace Tests\Unit;

use App\Services\Kml\KmlReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class KmlReaderTest extends TestCase
{
    private function read(string $body): array
    {
        return (new KmlReader)->read('<kml xmlns="http://www.opengis.net/kml/2.2"><Document>'.$body.'</Document></kml>');
    }

    public function test_points_lines_polygons_holes_multi_geometry_names_and_lon_lat_order(): void
    {
        $parsed = $this->read('<Placemark><name><![CDATA[<b>Lokasi</b><script>unsafe</script>]]></name><MultiGeometry><Point><coordinates>110,-7,100</coordinates></Point><LineString><coordinates>110,-7 111,-8</coordinates></LineString><Polygon><outerBoundaryIs><LinearRing><coordinates>110,-7 111,-7 111,-8 110,-7</coordinates></LinearRing></outerBoundaryIs><innerBoundaryIs><LinearRing><coordinates>110.1,-7.1 110.2,-7.1 110.2,-7.2 110.1,-7.1</coordinates></LinearRing></innerBoundaryIs></Polygon></MultiGeometry></Placemark>');
        $this->assertSame(1, $parsed['feature_count']);
        $this->assertSame(11, $parsed['vertex_count']);
        $feature = $parsed['geojson']['features'][0];
        $this->assertSame('Lokasiunsafe', $feature['properties']['name']);
        $this->assertSame([110.0, -7.0], $feature['geometry']['geometries'][0]['coordinates']);
        $this->assertCount(2, $feature['geometry']['geometries'][2]['coordinates']);
    }

    public function test_invalid_coordinates_xml_unsupported_geometry_and_partial_placemarks_are_rejected(): void
    {
        foreach (['', '<Placemark/>', '<Placemark><Point><coordinates>999,-7</coordinates></Point></Placemark>',
            '<Placemark><Point><coordinates>110,91</coordinates></Point></Placemark>',
            '<Placemark><Point><coordinates>NaN,-7</coordinates></Point></Placemark>',
            '<Placemark><LineString><coordinates>110,-7</coordinates></LineString></Placemark>',
            '<Placemark><Polygon><outerBoundaryIs><LinearRing><coordinates>1,2 2,3 3,4 4,5</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>',
            '<NetworkLink><Link><href>http://127.0.0.1/secret</href></Link></NetworkLink>',
            '<Placemark><Model/></Placemark>', '<Placemark><Point xmlns="urn:other"><coordinates>1,2</coordinates></Point></Placemark>',
            '<Placemark><Point><coordinates>110,-7</coordinates></Point></Placemark><Placemark><LineString/></Placemark>'] as $body) {
            try {
                $this->read($body);
                $this->fail('Invalid/partial KML must be rejected');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_external_entity_and_complexity_size_feature_coordinate_limits_are_enforced(): void
    {
        $point = '<Placemark><Point><coordinates>110,-7</coordinates></Point></Placemark>';
        foreach (['<!DOCTYPE kml [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><kml>&xxe;</kml>',
            '<kml>'.str_repeat(' ', KmlReader::MAX_BYTES).'</kml>',
            '<kml>'.str_repeat('<Folder>', 45).$point.str_repeat('</Folder>', 45).'</kml>',
            '<kml>'.str_repeat($point, 201).'</kml>',
            '<kml><Placemark><LineString><coordinates>'.str_repeat('110,-7 ', 10001).'</coordinates></LineString></Placemark></kml>'] as $xml) {
            try {
                (new KmlReader)->read($xml);
                $this->fail('Unsafe/oversized KML must be rejected');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_external_icons_and_html_description_are_never_included_in_map_data_and_state_resets(): void
    {
        $reader = new KmlReader;
        $xml = '<kml><Style><IconStyle><Icon><href>https://example.org/private</href></Icon></IconStyle></Style><Placemark><description><![CDATA[<img src="https://example.org/track">]]></description><Point><coordinates>110,-7</coordinates></Point></Placemark></kml>';
        $parsed = $reader->read($xml);
        $this->assertStringNotContainsString('example.org', json_encode($parsed));
        $this->assertSame(1, $reader->read($xml)['vertex_count']);
    }
}
