<?php

namespace Tests\Unit;

use App\Services\Material\MaterialReportParser;
use App\Support\MaterialReportFields;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MaterialReportParserTest extends TestCase
{
    public function test_45_fields_preserve_order_nullable_values_text_identifiers_quantities_and_coordinates(): void
    {
        $this->assertCount(45, MaterialReportFields::all());
        $keys = array_keys(MaterialReportFields::all());
        $this->assertSame(['tiang', 'tiang_galvanis', 'koord_tiang', 'total_dc'], array_slice($keys, 17, 4));
        $text = str_replace(['NO TQ/SC/ID: ', 'No Voice: -', 'SN_ONT: -', 'Nik Teknisi: -', 'Total DC: -', 'LONGITUDE_odp: -', 'LATITUDE_ODP: -'],
            ['NO TQ/SC/ID: 000123', 'No Voice: 0012345', 'SN_ONT: 000ABC', 'Nik Teknisi: 00112233', 'Total DC: 12.125', 'LONGITUDE_odp: 112.700100', 'LATITUDE_ODP: -7.12345678'], MaterialReportFields::template());
        $data = (new MaterialReportParser)->parse($text)['fields'];
        $this->assertSame('000123', $data['no_tq_sc_id']);
        $this->assertSame('0012345', $data['no_voice']);
        $this->assertSame('000ABC', $data['sn_ont']);
        $this->assertSame('00112233', $data['nik_teknisi']);
        $this->assertSame('12.125', $data['total_dc']);
        $this->assertSame('112.70010000', $data['longitude_odp']);
        $this->assertSame('-7.12345678', $data['latitude_odp']);
        $this->assertNull($data['sn_stb']);
        $this->assertNull($data['tiang']);
        $this->assertSame('0.000', (new MaterialReportParser)->parse("/material\nNO TQ/SC/ID: 000123\nTiang: 0")['fields']['tiang']);
    }

    public function test_missing_keys_duplicate_labels_unknown_fields_negative_precision_and_bad_coordinates_fail(): void
    {
        foreach ([MaterialReportFields::template(), "/material\nNO TQ/SC/ID: X\nTiang: -1", "/material\nNO TQ/SC/ID: X\nTotal DC: 1,200",
            "/material\nNO TQ/SC/ID: X\nTiang: 0.0001", "/material\nNO TQ/SC/ID: X\nLATITUDE_ONT: -7315385", "/material\nNO TQ/SC/ID: X\nLONGITUDE_ONT: 181",
            "/material\nNO TQ/SC/ID: X\nNIK Teknisi: A\nNik Teknisi: B", "/material\nNO TQ/SC/ID: X\nBRANCH: OTHER", "/material\nNO TQ/SC/ID: X\nAlamat tanpa titik dua",
            "/material\nNO TQ/SC/ID: X\nAlamat: ".str_repeat('a', 4100)] as $text) {
            try {
                (new MaterialReportParser)->parse($text);
                $this->fail('Invalid material report must not be silently accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
