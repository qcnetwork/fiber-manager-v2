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

    /*
    |--------------------------------------------------------------------------
    | Road routing for the coverage check
    |--------------------------------------------------------------------------
    |
    | OSRM server used to measure the distance to an ODP along the roads
    | instead of in a straight line. Defaults to the public FOSSGIS foot
    | server (light use only, about one request per second). Leave the URL
    | empty to always use straight-line distances.
    |
    */

    'routing' => [
        'url' => env('ROUTING_URL', 'https://routing.openstreetmap.de/routed-foot'),
        // The public server can take several seconds to answer.
        'timeout' => (int) env('ROUTING_TIMEOUT', 10),
        'max_candidates' => 10,
    ],

];
