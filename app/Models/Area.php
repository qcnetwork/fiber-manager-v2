<?php

namespace App\Models;

use App\Models\Concerns\ConnectsCables;
use App\Models\Concerns\HasPhotos;
use Database\Factories\AreaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A branch area, which is also a POP: its center is the POP location where its OLTs sit.
 */
class Area extends Model
{
    /** @use HasFactory<AreaFactory> */
    use ConnectsCables, HasFactory, HasPhotos;

    protected $fillable = ['code', 'name', 'center_lat', 'center_lng', 'address', 'notes'];

    protected function casts(): array
    {
        return [
            'center_lat' => 'float',
            'center_lng' => 'float',
        ];
    }

    /**
     * The POP location under the names cables and the map use for every asset.
     */
    protected function lat(): Attribute
    {
        return Attribute::get(fn () => $this->center_lat);
    }

    protected function lng(): Attribute
    {
        return Attribute::get(fn () => $this->center_lng);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function olts(): HasMany
    {
        return $this->hasMany(Olt::class);
    }

    public function odcs(): HasMany
    {
        return $this->hasMany(Odc::class);
    }

    public function odps(): HasMany
    {
        return $this->hasMany(Odp::class);
    }
}
