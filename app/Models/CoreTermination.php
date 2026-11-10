<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * A core ending at an ODP or ODC: on a splitter input (kind "input", port = input number) or straight on an output
 * port (kind "output", port = port number). An ODP's output port may have a customer, an ODC's an ODP.
 */
class CoreTermination extends Model
{
    public const KINDS = ['input', 'output'];

    /** Assets (morph aliases) cores can end on. */
    public const LOCATIONS = ['odp', 'odc'];

    /** Splitter inputs an ODP/ODC can have. */
    public const MAX_INPUTS = 4;

    protected $fillable = ['core_id', 'side', 'location_type', 'location_id', 'kind', 'port', 'notes'];

    protected function casts(): array
    {
        return ['port' => 'integer', 'location_id' => 'integer'];
    }

    public function core(): BelongsTo
    {
        return $this->belongsTo(CableCore::class, 'core_id');
    }

    public function location(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Terminations on the given ODP/ODC.
     */
    public function scopeAt(Builder $query, Model $location): void
    {
        $query->where('location_type', $location->getMorphClass())->where('location_id', $location->getKey());
    }

    /**
     * The cores ending on an ODP/ODC, for its panel: which input/port and which cable core.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function listFor(Model $location): Collection
    {
        return static::at($location)->with('core.cable:id,code,type,cores,tube_size')->orderBy('kind')->orderBy('port')->get()
            ->map(fn (CoreTermination $t) => [
                'kind' => $t->kind,
                'port' => $t->port,
                'core_id' => $t->core->id,
                'number' => $t->core->number,
                'cable' => $t->core->cable?->only('id', 'code', 'type', 'cores', 'tube_size'),
            ]);
    }

    /**
     * Where the core ends, for traces: the ODP/ODC, input/port, and what that output port feeds
     * (a customer on an ODP, an ODP on an ODC).
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $location = match ($this->location_type) {
            'odp' => Odp::withoutGlobalScopes()->find($this->location_id),
            'odc' => Odc::withoutGlobalScopes()->find($this->location_id),
            default => null,
        };

        $customer = null;
        $odp = null;
        if ($this->kind === 'output' && $this->location_type === 'odp') {
            $port = OdpPort::where('odp_id', $this->location_id)->where('number', $this->port)->with('customer:id,name')->first();
            $customer = $port?->customer ? ['id' => $port->customer->id, 'name' => $port->customer->name] : null;
        } elseif ($this->kind === 'output' && $this->location_type === 'odc') {
            $fed = Odp::withoutGlobalScopes()->where('odc_id', $this->location_id)->where('odc_port', $this->port)->first(['id', 'code']);
            $odp = $fed ? ['id' => $fed->id, 'code' => $fed->code] : null;
        }

        return [
            'termination_id' => $this->id,
            'core_id' => (int) $this->core_id,
            'location' => $location ? [
                'kind' => $this->location_type,
                'id' => $location->id,
                'code' => $location->code,
                'splitter_ratio' => $location->splitter_ratio,
            ] : null,
            'kind' => $this->kind,
            'port' => $this->port,
            'customer' => $customer,
            'odp' => $odp,
            'notes' => $this->notes,
        ];
    }
}
