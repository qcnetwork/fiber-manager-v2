<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Odp;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Odp>
 */
class OdpFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'code' => 'ODP-'.fake()->unique()->bothify('???-##/##'),
            'lat' => fake()->latitude(-6.25, -6.15),
            'lng' => fake()->longitude(106.75, 106.85),
            'capacity' => 8,
            'splitter_ratio' => '1:8',
            'status' => 'aktif',
        ];
    }
}
