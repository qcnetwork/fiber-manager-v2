<?php

namespace App\Models;

use App\Models\Concerns\BelongsToArea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Olt extends Model
{
    use BelongsToArea;

    protected $fillable = ['area_id', 'code', 'name', 'brand', 'pon_ports', 'lat', 'lng', 'address', 'notes'];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function odcs(): HasMany
    {
        return $this->hasMany(Odc::class);
    }
}
