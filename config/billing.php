<?php

return [

    'archive' => [
        'retention_days' => (int) env('BALANCE_ARCHIVE_RETENTION_DAYS', 90),
        'batch_size' => (int) env('BALANCE_ARCHIVE_BATCH_SIZE', 5000),
    ],

];
