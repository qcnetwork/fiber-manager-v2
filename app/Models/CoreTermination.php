<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A core ending at an ODP: on a splitter input (kind "input", port = input number) or straight on an output port
 * (kind "output", port = the ODP port number, which may have a customer).
 */
class CoreTermination extends Model
{
    public const KINDS = ['input', 'output'];

    /** Splitter inputs an ODP can have. */
    public const MAX_INPUTS = 4;

    protected $fillable = ['core_id', 'odp_id', 'kind', 'port', 'notes'];

    protected function casts(): array
    {
        return ['port' => 'integer'];
    }

    public function core(): BelongsTo
    {
        return $this->belongsTo(CableCore::class, 'core_id');
    }

    public function odp(): BelongsTo
    {
        return $this->belongsTo(Odp::class);
    }

    /**
     * Where the core ends, for traces: the ODP, input/port and (for an output) the customer on that port.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $odp = Odp::withoutGlobalScopes()->find($this->odp_id);
        $port = $this->kind === 'output'
            ? OdpPort::where('odp_id', $this->odp_id)->where('number', $this->port)->with('customer:id,name')->first()
            : null;

        return [
            'termination_id' => $this->id,
            'core_id' => (int) $this->core_id,
            'odp' => $odp ? ['id' => $odp->id, 'code' => $odp->code, 'splitter_ratio' => $odp->splitter_ratio] : null,
            'kind' => $this->kind,
            'port' => $this->port,
            'customer' => $port?->customer ? ['id' => $port->customer->id, 'name' => $port->customer->name] : null,
            'notes' => $this->notes,
        ];
    }
}
