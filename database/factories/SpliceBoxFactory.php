<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\SpliceBox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpliceBox>
 */
class SpliceBoxFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'area_id' => Area::factory(),
            'type' => 'joint_box',
            'code' => 'JB-'.fake()->unique()->bothify('???-##'),
            'lat' => fake()->latitude(-6.25, -6.15),
            'lng' => fake()->longitude(106.75, 106.85),
            'status' => 'aktif',
        ];
    }
}
