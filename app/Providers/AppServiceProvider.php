<?php

namespace App\Providers;

use App\Models\Odc;
use App\Models\Odp;
use App\Models\Olt;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short names stored in cables.from_type / to_type and sent to the map.
        Relation::morphMap([
            'olt' => Olt::class,
            'odc' => Odc::class,
            'odp' => Odp::class,
        ]);
    }
}
