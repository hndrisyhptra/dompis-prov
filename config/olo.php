<?php

return [
    'inbound' => env('WO_ORDER_SOURCE', 'excel'),
    'sheets' => [
        // Unconfigured is deliberately the default. Fake is only allowed outside production.
        'driver' => env('SHEETS_DRIVER', 'google'),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID', ''),
        'credentials_path' => env('GOOGLE_APPLICATION_CREDENTIALS', ''),
        'fake_path' => env('SHEETS_FAKE_PATH', storage_path('app/private/bima-demo.json')),
        'max_rows' => 10000,
    ],
];
