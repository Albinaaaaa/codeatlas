<?php

return [
    'local_source_enabled' => env('CODEATLAS_LOCAL_SOURCE_ENABLED', false),
    'local_source_root' => env('CODEATLAS_LOCAL_SOURCE_ROOT'),

    'repository_scan' => [
        'max_file_size' => (int) env('CODEATLAS_SCAN_MAX_FILE_SIZE', 1_048_576),
        'max_files' => (int) env('CODEATLAS_SCAN_MAX_FILES', 20_000),
        'max_entries' => (int) env('CODEATLAS_SCAN_MAX_ENTRIES', 100_000),
        'max_total_size' => (int) env('CODEATLAS_SCAN_MAX_TOTAL_SIZE', 104_857_600),
    ],
];
