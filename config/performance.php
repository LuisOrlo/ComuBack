<?php

return [
    'enabled' => (bool) env('PERFORMANCE_LOGGING', false),
    'channel' => env('PERFORMANCE_LOG_CHANNEL', 'performance'),
];
