<?php

return [
    'enabled' => (bool) env('WEB_PERFORMANCE_ENABLED', false),
    'slow_ms' => max(0, (int) env('WEB_PERFORMANCE_SLOW_MS', 1000)),
];
