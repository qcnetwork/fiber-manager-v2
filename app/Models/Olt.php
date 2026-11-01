<?php

namespace App\Models;

use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OLT at a POP (its area). PON ports are grouped by card slot.
 */
class Olt extends Model
{
    use BelongsToArea, LogsActivity;

    protected $fillable = ['area_id', 'code', 'name', 'brand', 'ip_address', 'pon_ports', 'address', 'notes'];

    public function ports(): HasMany
    {
        return $this->hasMany(OltPort::class)->orderBy('slot')->orderBy('port');
    }

    /**
     * Makes the ports match the cards, given as [slot => number of ports]. Returns false, changing nothing,
     * when that would remove a port an ODC is still connected to.
     *
     * @param  array<int, int>  $cards
     */
    public function syncPorts(array $cards): bool
    {
        $wanted = collect($cards)->flatMap(fn (int $count, int $slot) => array_map(fn ($port) => "{$slot}/{$port}", range(1, $count)));
        // A base collection: Eloquent's except() filters by model id, not by these "slot/port" keys.
        $existing = $this->ports()->withCount('odcs')->get()->toBase()->keyBy(fn (OltPort $p) => "{$p->slot}/{$p->port}");

        $toRemove = $existing->except($wanted->all());
        if ($toRemove->contains(fn (OltPort $p) => $p->odcs_count > 0)) {
            return false;
        }

        OltPort::whereKey($toRemove->pluck('id')->all())->delete();
        // New ports show up as the OLT's port count change; no entry per port.
        ActivityLog::muted(fn () => $this->ports()->createMany($wanted->diff($existing->keys())->map(function (string $key) {
            [$slot, $port] = explode('/', $key);

            return ['slot' => (int) $slot, 'port' => (int) $port];
        })->values()->all()));

        $this->update(['pon_ports' => $wanted->count()]);

        return true;
    }

    /**
     * Cards as [slot => number of ports], from the ports that exist.
     *
     * @return array<int, int>
     */
    public function cards(): array
    {
        return $this->ports->groupBy('slot')->map(fn ($ports) => $ports->max('port'))->all();
    }
}
