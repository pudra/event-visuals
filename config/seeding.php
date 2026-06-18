<?php

return [
    /*
     * Number of events EventSeeder generates. Defaults to the full 1.25M dataset
     * the challenge ships; override per run with SEED_ROWS, e.g.
     * SEED_ROWS=20000 php artisan db:seed
     */
    'rows' => (int) env('SEED_ROWS', 1_250_000),
];
