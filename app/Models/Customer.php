<?php

namespace App\Models;

use App\Models\Concerns\BelongsToArea;
use App\Models\Concerns\HasPhotos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    use BelongsToArea, HasPhotos;

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
}
