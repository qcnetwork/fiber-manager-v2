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

    /*
    |--------------------------------------------------------------------------
    | Cable connect radius
    |--------------------------------------------------------------------------
    |
    | When adding or moving an ODC/ODP, cables passing within this many meters
    | are offered for connecting the asset to.
    |
    */

    'cable_connect_radius' => (int) env('CABLE_CONNECT_RADIUS', 30),

];
