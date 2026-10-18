<?php

namespace Tests\Feature;

use App\Services\Sheets\GoogleSheetsGateway;
use App\Services\Sheets\SheetException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleGatewayTest extends TestCase
{
    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->keyPath = tempnam(sys_get_temp_dir(), 'olo-fake-key-');
        file_put_contents($this->keyPath, json_encode(['client_email' => 'synthetic@example.invalid', 'private_key' => 'synthetic-key-for-http-fake-only']));
        config(['olo.sheets.spreadsheet_id' => 'synthetic-spreadsheet-id', 'olo.sheets.credentials_path' => $this->keyPath]);
        Cache::put('google-token:'.hash_file('sha256', $this->keyPath), 'test-token', 3000);
    }

    protected function tearDown(): void
    {
        unlink($this->keyPath);
        parent::tearDown();
    }

    private function metadata(int $rows, int $columns): array
    {
        $payload = [];
        $payload['sheets'][0]['properties'] = ['title' => 'BIMA_RAW', 'gridProperties' => ['rowCount' => $rows, 'columnCount' => $columns]];

        return $payload;
    }

    public function test_reads_only_bima_with_formatted_text_and_guard_column(): void
    {
        $payload = [];
        $payload['sheets'][0]['data'][0]['rowData'] = [
            ['values' => [['formattedValue' => 'NO']]],
            ['values' => [['formattedValue' => '0001', 'userEnteredValue' => ['stringValue' => '0001']]]],
            ['values' => [['formattedValue' => 'computed', 'userEnteredValue' => ['formulaValue' => '=A1']]]],
        ];
        $payload['sheets'][0]['data'][0]['rowData'][0]['values'] = array_pad($payload['sheets'][0]['data'][0]['rowData'][0]['values'], 33, []);
        $payload['sheets'][0]['data'][0]['rowData'][] = ['values' => array_fill(0, 33, [])];
        Http::fakeSequence()->push($this->metadata(12000, 33))->push($payload);
        $this->assertSame([['NO'], ['0001'], ['=A1']], (new GoogleSheetsGateway)->readBima());
        Http::assertSent(fn ($request) => $request->method() === 'GET' && ($request['ranges'] ?? '') === "'BIMA_RAW'!A1:AG10002" && str_contains($request['fields'], 'userEnteredValue,formattedValue'));
    }

    public function test_monitoring_atomic_batch_uses_strings_clears_stale_rows_and_verifies(): void
    {
        $rows = [array_fill(0, 20, 'Header'), array_pad(['0001', '=not-a-formula'], 20, '')];
        Http::fakeSequence()
            ->push(['sheets' => [['properties' => ['sheetId' => 42, 'title' => 'MONITORING_OLO', 'gridProperties' => ['rowCount' => 100, 'columnCount' => 26]]]]])
            ->push(['replies' => [[]]])
            ->push(['values' => [$rows[0], ['0001', '=not-a-formula']]]);
        (new GoogleSheetsGateway)->publishMonitoring($rows);
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $update = $request['requests'][0]['updateCells'];
            $this->assertSame(42, $update['range']['sheetId']);
            $this->assertArrayNotHasKey('endRowIndex', $update['range']);
            $this->assertSame('userEnteredValue', $update['fields']);
            $this->assertSame('=not-a-formula', $update['rows'][1]['values'][1]['userEnteredValue']['stringValue']);

            return true;
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'BIMA_RAW'));
    }

    public function test_mismatched_readback_is_failed_instead_of_claiming_success(): void
    {
        Http::fakeSequence()->push(['sheets' => [['properties' => ['sheetId' => 7, 'title' => 'MONITORING_OLO', 'gridProperties' => ['rowCount' => 100, 'columnCount' => 26]]]]])->push([])->push(['values' => [['wrong']]]);
        $this->expectException(SheetException::class);
        $this->expectExceptionMessage('Verifikasi snapshot');
        (new GoogleSheetsGateway)->publishMonitoring([array_fill(0, 20, 'Header')]);
    }

    public function test_missing_credentials_fail_without_external_request(): void
    {
        config(['olo.sheets.credentials_path' => '/missing/credential-file']);
        try {
            (new GoogleSheetsGateway)->readBima();
            $this->fail('Expected unavailable credential');
        } catch (SheetException $exception) {
            $this->assertStringContainsString('belum tersedia', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_google_error_body_is_never_exposed(): void
    {
        Http::fake(['*' => Http::response(['error' => 'SENSITIVE-PROVIDER-PAYLOAD'], 403)]);
        try {
            (new GoogleSheetsGateway)->readBima();
            $this->fail('Expected provider failure');
        } catch (SheetException $exception) {
            $this->assertStringNotContainsString('SENSITIVE', $exception->getMessage());
            $this->assertStringContainsString('403', $exception->getMessage());
        }
    }

    public function test_service_account_jwt_token_exchange_uses_fake_http_only(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        file_put_contents($this->keyPath, json_encode(['client_email' => 'synthetic@example.invalid', 'private_key' => $private]));
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'synthetic-access-token']), 'sheets.googleapis.com/*' => Http::response($this->metadata(1, 32))]);
        $this->assertSame([], (new GoogleSheetsGateway)->readBima());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token') && count(explode('.', $request['assertion'])) === 3);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-access-token'));
    }

    public function test_read_range_is_clamped_to_real_grid_dimensions(): void
    {
        Http::fakeSequence()->push($this->metadata(5, 32))->push(['sheets' => []]);
        $this->assertSame([], (new GoogleSheetsGateway)->readBima());
        Http::assertSent(fn ($request) => ($request['ranges'] ?? '') === "'BIMA_RAW'!A1:AF5");
    }
}
