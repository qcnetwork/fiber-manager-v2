<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Enums\PortStatus;
use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\ConnectsCables;
use App\Models\Concerns\HasPhotos;
use Database\Factories\OdpFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Odp extends Model
{
    /** @use HasFactory<OdpFactory> */
    use BelongsToArea, ConnectsCables, HasFactory, HasPhotos;

    protected $fillable = [
        'area_id', 'odc_id', 'odc_port', 'code', 'name', 'lat', 'lng', 'address', 'pole_number',
        'capacity', 'splitter_ratio', 'status', 'installed_at', 'notes',
    ];

    protected $attributes = [
        'capacity' => 8,
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'status' => AssetStatus::class,
            'installed_at' => 'date:Y-m-d',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (Odp $odp) => $odp->syncPorts());
    }

    public function odc(): BelongsTo
    {
        return $this->belongsTo(Odc::class);
    }

    public function ports(): HasMany
    {
        return $this->hasMany(OdpPort::class)->orderBy('number');
    }

    /**
     * Adds the used/free port counts as `used_ports` and `free_ports`.
     */
    public function scopeWithPortUsage(Builder $query): void
    {
        $query->withCount([
            'ports as used_ports' => fn (Builder $q) => $q->where('status', '!=', PortStatus::Kosong),
            'ports as free_ports' => fn (Builder $q) => $q->where('status', PortStatus::Kosong),
        ]);
    }

    /**
     * Makes the port rows match the capacity. Returns false when shrinking would drop ports that are not empty.
     */
    public function syncPorts(): bool
    {
        $existing = $this->ports()->get()->keyBy('number');

        $toRemove = $existing->filter(fn (OdpPort $port) => $port->number > $this->capacity);
        if ($toRemove->contains(fn (OdpPort $port) => $port->status !== PortStatus::Kosong)) {
            return false;
        }

        $this->ports()->whereKey($toRemove->modelKeys())->delete();

        $missing = collect(range(1, $this->capacity))->diff($existing->keys());
        $this->ports()->createMany($missing->map(fn (int $number) => ['number' => $number])->all());

        return true;
    }
}
