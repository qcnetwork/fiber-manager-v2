<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Coverage radius
    |--------------------------------------------------------------------------
    |
    | Maximum straight-line distance in meters between an ODP and a new
    | customer for the location to count as covered (drop cable reach).
    |
    */

    'coverage_radius' => (int) env('COVERAGE_RADIUS', 250),

];
