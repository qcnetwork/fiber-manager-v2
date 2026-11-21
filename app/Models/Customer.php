<?php

namespace App\Models;

use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\ConnectsCables;
use App\Models\Concerns\HasPhotos;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use BelongsToArea, ConnectsCables, HasPhotos, LogsActivity;

    protected $fillable = [
        'area_id', 'customer_code', 'name', 'phone', 'address', 'lat', 'lng', 'package', 'ont_sn', 'status',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function port(): HasOne
    {
        return $this->hasOne(OdpPort::class);
    }

    /**
     * How a cable route names the customer at its end (other cable ends show their code).
     */
    protected function code(): Attribute
    {
        return Attribute::get(fn () => $this->activityLabel());
    }

    public function activityLabel(): string
    {
        return $this->customer_code ? "{$this->name} ({$this->customer_code})" : $this->name;
    }
}
