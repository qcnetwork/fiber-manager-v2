<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Services\GeoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Cables drawn before pass-through points existed (or migrated from fiber-manager) often already have a bend
 * exactly on an ODC/ODP. This records those as pass-through points, so the cable's route shows them.
 */
class DetectCableStops extends Command
{
    protected $signature = 'kabel:deteksi-sambungan
        {--jarak=3 : Jarak maksimal (meter) titik jalur ke POP/ODC/ODP agar dianggap tersambung}
        {--simpan : Simpan hasilnya; tanpa ini hanya menampilkan apa yang akan ditambahkan}';

    protected $description = 'Deteksi POP/ODC/ODP yang dilewati kabel (titik jalur tepat di aset) dan catat sebagai titik sambung';

    public function handle(): int
    {
        $maxMeters = (float) $this->option('jarak');
        $save = (bool) $this->option('simpan');

        // Every locatable asset, grouped by area: a cable only connects to assets in its own area.
        $assets = collect()
            ->concat(Odc::withoutGlobalScopes()->whereNotNull('lat')->get())
            ->concat(Odp::withoutGlobalScopes()->whereNotNull('lat')->get())
            ->concat(Area::all()->filter(fn (Area $pop) => $pop->lat !== null))
            ->groupBy(fn (Model $asset) => $asset instanceof Area ? $asset->id : $asset->area_id);

        $rows = [];
        $found = 0;
        $cables = Cable::withoutGlobalScopes()->with('stops')->orderBy('id')->get();

        foreach ($cables as $cable) {
            $path = $cable->path;
            $last = count($path) - 1;
            $known = $cable->stops->keyBy('vertex');
            $new = [];

            for ($vertex = 1; $vertex < $last; $vertex++) {
                if ($known->has($vertex)) {
                    continue;
                }
                $nearest = null;
                $nearestDist = INF;
                foreach ($assets->get($cable->area_id, []) as $asset) {
                    $dist = GeoService::pathLength([$path[$vertex], [$asset->lat, $asset->lng]]);
                    if ($dist <= $maxMeters && $dist < $nearestDist) {
                        [$nearest, $nearestDist] = [$asset, $dist];
                    }
                }
                if ($nearest) {
                    $new[] = ['vertex' => $vertex, 'type' => $nearest->getMorphClass(), 'id' => $nearest->id];
                    $rows[] = [
                        $cable->code ?: "#{$cable->id}",
                        $vertex,
                        strtoupper($nearest->getMorphClass()).' '.($nearest instanceof Area ? $nearest->name : $nearest->code),
                        round($nearestDist, 1).' m',
                    ];
                }
            }

            if ($new && $save) {
                DB::transaction(function () use ($cable, $new) {
                    foreach ($new as $stop) {
                        $cable->stops()->create(['vertex' => $stop['vertex'], 'asset_type' => $stop['type'], 'asset_id' => $stop['id']]);
                    }
                });
            }
            $found += count($new);
        }

        if ($rows) {
            $this->table(['Kabel', 'Titik ke-', 'Aset', 'Jarak'], $rows);
        }

        $cableCount = count(array_unique(array_column($rows, 0)));
        if ($save) {
            $this->info("{$found} titik sambung disimpan pada {$cableCount} kabel.");
        } else {
            $this->info("{$found} titik sambung ditemukan pada {$cableCount} kabel. Jalankan lagi dengan --simpan untuk menyimpan.");
        }

        return self::SUCCESS;
    }
}
