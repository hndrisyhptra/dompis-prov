<?php

namespace App\Services\Sheets;

use App\Contracts\GoogleSheetGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleSheetsGateway implements GoogleSheetGateway
{
    private function spreadsheet(): string
    {
        $id = config('olo.sheets.spreadsheet_id');
        if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{10,200}$/', $id)) {
            throw new SheetException('Spreadsheet ID belum dikonfigurasi dengan benar.');
        }

        return $id;
    }

    private function token(): string
    {
        $path = config('olo.sheets.credentials_path');
        if (! $path || ! is_readable($path) || ! is_file($path)) {
            throw new SheetException('File kredensial service account belum tersedia.');
        }

        return Cache::remember('google-token:'.hash_file('sha256', $path), 3000, function () use ($path) {
            $key = json_decode(file_get_contents($path), true);
            if (! isset($key['client_email'], $key['private_key'])) {
                throw new SheetException('Format service account tidak valid.');
            }
            $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => $key['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600,
            ]));
            if (! openssl_sign($jwt, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new SheetException('Service account gagal menandatangani token.');
            }
            $response = Http::asForm()->connectTimeout(5)->timeout(15)->retry([250, 1000], throw: false)
                ->post('https://oauth2.googleapis.com/token', ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt.'.'.$encode($signature)]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new SheetException('Otorisasi Google gagal. Periksa kredensial dan izin spreadsheet.');
            }

            return $response->json('access_token');
        });
    }

    private function request(string $method, string $suffix, array $data = []): array
    {
        $id = $this->spreadsheet();
        $response = Http::withToken($this->token())->acceptJson()->connectTimeout(5)->timeout(20)
            ->retry([250, 1000], throw: false)->{$method}("https://sheets.googleapis.com/v4/spreadsheets/{$id}{$suffix}", $data);
        if (! $response->successful()) {
            throw new SheetException('Google Sheets tidak dapat diakses (HTTP '.$response->status().'). Periksa izin atau coba lagi.');
        }

        return $response->json() ?? [];
    }

    public function readBima(): array
    {
        $metadata = $this->request('get', '', ['fields' => 'sheets.properties']);
        $sheet = collect($metadata['sheets'] ?? [])->first(fn ($item) => ($item['properties']['title'] ?? null) === 'BIMA_RAW');
        if (! $sheet) {
            throw new SheetException('Tab BIMA_RAW belum tersedia.');
        }
        $grid = $sheet['properties']['gridProperties'] ?? [];
        if (($grid['columnCount'] ?? 0) < 32) {
            throw new \InvalidArgumentException('BIMA_RAW harus menyediakan 32 kolom A:AF sesuai template.');
        }
        // Never request cells outside the tab's actual grid. Include AG as a guard when present.
        $endColumn = $grid['columnCount'] >= 33 ? 'AG' : 'AF';
        $endRow = max(1, min($grid['rowCount'] ?? 1, config('olo.sheets.max_rows') + 2));
        // Read displayed IDs and actual formula input together in one remote snapshot.
        // A values-only API read would conceal formulas behind their calculated result.
        $result = $this->request('get', '', [
            'ranges' => "'BIMA_RAW'!A1:{$endColumn}{$endRow}",
            'fields' => 'sheets(data(rowData(values(userEnteredValue,formattedValue))))',
        ]);
        $rows = $result['sheets'][0]['data'][0]['rowData'] ?? [];

        $matrix = array_map(fn ($row) => array_map(
            fn ($cell) => $cell['userEnteredValue']['formulaValue'] ?? $cell['formattedValue'] ?? (string) ($cell['userEnteredValue']['stringValue'] ?? $cell['userEnteredValue']['numberValue'] ?? ''),
            $row['values'] ?? [],
        ), $rows);
        // Grid formatting can leave trailing empty CellData objects. They are not extra headers/data.
        foreach ($matrix as &$row) {
            while ($row !== [] && end($row) === '') {
                array_pop($row);
            }
        }
        unset($row);
        while ($matrix !== [] && end($matrix) === []) {
            array_pop($matrix);
        }

        return $matrix;
    }

    public function publishMonitoring(array $rows): void
    {
        // A single atomic batchUpdate overwrites cells AND removes stale managed rows.
        // The full managed output is keyed/sorted in MySQL; physical row numbers are never identities.
        $meta = $this->request('get', '', ['fields' => 'sheets.properties']);
        $sheet = collect($meta['sheets'] ?? [])->first(fn ($item) => ($item['properties']['title'] ?? null) === 'MONITORING_OLO');
        if (! $sheet) {
            throw new SheetException('Tab MONITORING_OLO belum dibuat.');
        }
        $properties = $sheet['properties'];
        $requests = [];
        if (($properties['gridProperties']['rowCount'] ?? 0) < count($rows)) {
            $requests[] = ['appendDimension' => ['sheetId' => $properties['sheetId'], 'dimension' => 'ROWS', 'length' => count($rows) - $properties['gridProperties']['rowCount']]];
        }
        if (($properties['gridProperties']['columnCount'] ?? 0) < 20) {
            $requests[] = ['appendDimension' => ['sheetId' => $properties['sheetId'], 'dimension' => 'COLUMNS', 'length' => 20 - $properties['gridProperties']['columnCount']]];
        }
        $requests[] = ['updateCells' => [
            'range' => ['sheetId' => $properties['sheetId'], 'startRowIndex' => 0, 'startColumnIndex' => 0, 'endColumnIndex' => 20],
            'rows' => array_map(fn ($row) => ['values' => array_map(fn ($cell) => ['userEnteredValue' => ['stringValue' => (string) $cell]], $row)], $rows),
            'fields' => 'userEnteredValue',
        ]];
        $this->request('post', ':batchUpdate', ['requests' => $requests]);
        $actual = $this->request('get', '/values/'.rawurlencode("'MONITORING_OLO'!A:T"), ['valueRenderOption' => 'UNFORMATTED_VALUE'])['values'] ?? [];
        $normalize = fn ($matrix) => array_map(fn ($row) => array_pad(array_map('strval', $row), 20, ''), $matrix);
        if ($normalize($actual) !== $normalize($rows)) {
            throw new SheetException('Verifikasi snapshot monitoring gagal. Jalankan retry sebelum menganggap push berhasil.');
        }
    }
}
