<?php
return [
    'host' => env('MEILISEARCH_HOST', 'http://meilisearch:7700'),
    'key' => env('MEILISEARCH_KEY'),
    'index' => env('MEILISEARCH_INDEX', 'karyalay_media_files'),
    'timeout' => (int) env('MEILISEARCH_TIMEOUT', 3),
];
