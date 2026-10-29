<?php

namespace Database\Seeders;

use App\Enums\CableType;
use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'admin@example.com',
            'role' => Role::SuperAdmin,
        ]);

        $areas = [
            ['code' => 'BDG', 'name' => 'Bandung', 'center_lat' => -6.9175, 'center_lng' => 107.6191],
            ['code' => 'BKS', 'name' => 'Bekasi', 'center_lat' => -6.2383, 'center_lng' => 106.9756],
        ];

        foreach ($areas as $data) {
            $area = Area::create($data);

            User::factory()->create([
                'name' => 'Teknisi '.$area->name,
                'email' => 'teknisi.'.strtolower($area->code).'@example.com',
                'role' => Role::Teknisi,
                'area_id' => $area->id,
            ]);

            $this->seedNetwork($area);
        }
    }

    /**
     * Two ODCs per area, each feeding a handful of ODPs with some connected customers.
     */
    private function seedNetwork(Area $area): void
    {
        foreach (range(1, 2) as $o) {
            $odc = Odc::create([
                'area_id' => $area->id,
                'code' => sprintf('ODC-%s-%02d', $area->code, $o),
                'name' => "ODC {$area->name} {$o}",
                'lat' => $area->center_lat + ($o - 1.5) * 0.01,
                'lng' => $area->center_lng + ($o - 1.5) * 0.01,
                'capacity' => 48,
                'splitter_ratio' => '1:8',
            ]);

            foreach (range(1, 6) as $p) {
                $angle = deg2rad($p * 60);
                $odp = Odp::create([
                    'area_id' => $area->id,
                    'odc_id' => $odc->id,
                    'odc_port' => $p,
                    'code' => sprintf('ODP-%s-%02d/%02d', $area->code, $o, $p),
                    'lat' => $odc->lat + 0.003 * sin($angle),
                    'lng' => $odc->lng + 0.003 * cos($angle),
                    'capacity' => $p % 3 === 0 ? 16 : 8,
                    'splitter_ratio' => $p % 3 === 0 ? '1:16' : '1:8',
                    'pole_number' => 'T-'.($o * 100 + $p),
                ]);

                // Distribution cable with a bend, as if it follows the street.
                Cable::create([
                    'area_id' => $area->id,
                    'type' => CableType::Distribusi,
                    'cores' => 12,
                    'from_type' => 'odc',
                    'from_id' => $odc->id,
                    'to_type' => 'odp',
                    'to_id' => $odp->id,
                    'path' => [[$odc->lat, $odc->lng], [$odp->lat, $odc->lng], [$odp->lat, $odp->lng]],
                ]);

                // Spread occupancy so the map shows green, yellow and red ODPs.
                $used = [1 => 1, 2 => 3, 3 => 9, 4 => 6, 5 => 8, 6 => 0][$p];
                foreach ($odp->ports()->take($used)->get() as $port) {
                    $customer = Customer::create([
                        'area_id' => $area->id,
                        'name' => fake('id_ID')->name(),
                        'phone' => fake('id_ID')->phoneNumber(),
                        'address' => fake('id_ID')->streetAddress(),
                    ]);
                    $port->update(['status' => PortStatus::Terpakai, 'customer_id' => $customer->id]);
                }
            }
        }
    }
}
