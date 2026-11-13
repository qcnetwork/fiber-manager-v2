<?php

namespace App\Services\Transfer;

use App\Enums\CoreStatus;
use App\Enums\PortStatus;
use App\Models\Area;
use App\Models\Cable;
use App\Models\CableCore;
use App\Models\CoreSplice;
use App\Models\CoreTermination;
use App\Models\Customer;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
use App\Models\OltPort;
use App\Models\SpliceBox;
use Illuminate\Database\Eloquent\Model;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Writes the network of one area (or every area the user can see) as an .xlsx workbook.
 *
 * The ODC and ODP sheets use the import columns first, so the file can be edited and imported back;
 * columns after those are informational and ignored on import.
 */
class ExcelExporter
{
    /** TIA-598 fiber/tube colors, same order as resources/js/lib/cores.ts. */
    private const FIBER_COLORS = ['Biru', 'Oranye', 'Hijau', 'Coklat', 'Abu-abu', 'Putih', 'Merah', 'Hitam', 'Kuning', 'Ungu', 'Pink', 'Tosca'];

    private const LOCATION_LABELS = ['pop' => 'POP', 'odc' => 'ODC', 'odp' => 'ODP', 'joint' => 'Joint/Roset'];

    public function export(?int $areaId, string $outputPath): void
    {
        $writer = $this->open($outputPath);
        $inArea = fn ($query) => $query->when($areaId, fn ($q) => $q->where('area_id', $areaId));

        $this->sheet($writer, 'ODC', [...Columns::ODC, 'area', 'jumlah_odp'], [16, 24, 12, 12, 30, 10, 10, 10, 14, 30, 18, 9, 9, 14, 12], first: true);
        $inArea(Odc::query())->with(['area:id,code', 'oltPort.olt:id,code'])->withCount('odps')->orderBy('code')->lazy()->each(
            fn (Odc $odc) => $writer->addRow($this->row([
                $odc->code, $odc->name, $odc->lat, $odc->lng, $odc->address, $odc->capacity, $odc->splitter_ratio,
                $odc->status->value, $odc->installed_at?->format('Y-m-d'), $odc->notes,
                $odc->oltPort?->olt?->code, $odc->oltPort?->slot, $odc->oltPort?->port,
                $odc->area?->code, $odc->odps_count,
            ])),
        );

        // Informational only: every PON port and what it feeds.
        $this->sheet($writer, 'POP-OLT', ['pop', 'olt', 'merk', 'ip', 'slot_pon', 'port_pon', 'status_port', 'odc'], [22, 20, 14, 16, 9, 9, 11, 40]);
        OltPort::query()
            ->whereHas('olt', $inArea) // through Olt, so the area scope applies too
            ->with(['olt:id,area_id,code,brand,ip_address', 'olt.area:id,name', 'odcs:id,olt_port_id,code'])
            ->join('olts', 'olts.id', '=', 'olt_ports.olt_id')
            ->orderBy('olts.code')->orderBy('olt_ports.slot')->orderBy('olt_ports.port')
            ->select('olt_ports.*')
            ->lazy()
            ->each(fn (OltPort $port) => $writer->addRow($this->row([
                $port->olt->area?->name, $port->olt->code, $port->olt->brand, $port->olt->ip_address, $port->slot, $port->port,
                $port->status, $port->odcs->pluck('code')->implode(', ') ?: null,
            ])));

        // Informational only. Areas have no area scope; users tied to one area only get their own POP.
        $this->sheet($writer, 'POP', ['kode', 'nama', 'latitude', 'longitude', 'alamat', 'olt', 'jumlah_odc', 'catatan'], [12, 22, 12, 12, 30, 30, 11, 30]);
        Area::query()
            ->when($areaId, fn ($q) => $q->whereKey($areaId))
            ->when(auth()->user()?->seesAllAreas() === false, fn ($q) => $q->whereKey(auth()->user()->area_id))
            ->with('olts:id,area_id,code')
            ->orderBy('name')
            ->get()
            ->each(fn (Area $pop) => $writer->addRow($this->row([
                $pop->code, $pop->name, $pop->lat, $pop->lng, $pop->address, $pop->olts->pluck('code')->implode(', ') ?: null,
                Odc::query()->where('area_id', $pop->id)->count(), $pop->notes,
            ])));

        $this->sheet($writer, 'ODP', [...Columns::ODP,'area', 'port_terpakai', 'port_kosong'], [18, 20, 16, 9, 12, 12, 30, 10, 10, 10, 10, 14, 30, 8, 13, 12]);
        $inArea(Odp::query())->with(['area:id,code', 'odc:id,code'])->withPortUsage()->orderBy('code')->lazy()->each(
            fn (Odp $odp) => $writer->addRow($this->row([
                $odp->code, $odp->name, $odp->odc?->code, $odp->odc_port, $odp->lat, $odp->lng, $odp->address, $odp->pole_number,
                $odp->capacity, $odp->splitter_ratio, $odp->status->value, $odp->installed_at?->format('Y-m-d'), $odp->notes,
                $odp->area?->code, $odp->used_ports, $odp->free_ports,
            ])),
        );

        $this->sheet($writer, 'Joint-Roset', ['kode', 'jenis', 'nama', 'latitude', 'longitude', 'alamat', 'no_tiang', 'kapasitas', 'status', 'tanggal_pasang', 'catatan', 'area'], [16, 11, 20, 12, 12, 30, 10, 10, 10, 14, 30, 12]);
        $inArea(SpliceBox::query())->with('area:id,code')->orderBy('code')->lazy()->each(
            fn (SpliceBox $box) => $writer->addRow($this->row([
                $box->code, $box->type->label(), $box->name, $box->lat, $box->lng, $box->address, $box->pole_number, $box->capacity,
                $box->status->value, $box->installed_at?->format('Y-m-d'), $box->notes, $box->area?->code,
            ])),
        );

        $this->sheet($writer, 'Pelanggan', ['kode_odp', 'port', 'status_port', 'nama', 'id_pelanggan', 'hp', 'alamat', 'paket', 'sn_ont', 'catatan_port', 'latitude', 'longitude', 'status_pelanggan', 'area'], [18, 6, 12, 24, 14, 16, 30, 14, 18, 24, 12, 12, 15, 12]);
        OdpPort::query()
            ->whereHas('odp', $inArea) // through Odp, so the area scope applies too
            ->where('odp_ports.status', '!=', PortStatus::Kosong)
            ->with(['odp:id,code,area_id', 'odp.area:id,code', 'customer'])
            ->join('odps', 'odps.id', '=', 'odp_ports.odp_id')
            ->orderBy('odps.code')->orderBy('odp_ports.number')
            ->select('odp_ports.*')
            ->lazy()
            ->each(fn (OdpPort $port) => $writer->addRow($this->row([
                $port->odp->code, $port->number, $port->status->value, $port->customer?->name, $port->customer?->customer_code,
                $port->customer?->phone, $port->customer?->address, $port->customer?->package, $port->customer?->ont_sn, $port->notes,
                $port->customer?->lat, $port->customer?->lng, $port->customer?->status, $port->odp->area?->code,
            ])));
        // Customers not (or no longer) on an ODP port.
        $inArea(Customer::query())->whereDoesntHave('port')->with('area:id,code')->orderBy('name')->lazy()->each(
            fn (Customer $customer) => $writer->addRow($this->row([
                null, null, null, $customer->name, $customer->customer_code, $customer->phone, $customer->address, $customer->package,
                $customer->ont_sn, null, $customer->lat, $customer->lng, $customer->status, $customer->area?->code,
            ])),
        );

        $this->sheet($writer, 'Kabel', ['id', 'kode', 'jenis', 'core', 'core_per_tube', 'status', 'dari', 'ke', 'panjang_m', 'jumlah_titik', 'core_terpakai', 'catatan', 'area'], [7, 18, 12, 8, 13, 10, 18, 18, 11, 12, 13, 30, 12]);
        $inArea(Cable::query())
            ->with(['from:id,code,name', 'to:id,code,name', 'area:id,code'])
            ->withCount(['coreRows as used_cores' => fn ($q) => $q->where('status', CoreStatus::Terpakai)])
            ->orderBy('type')->orderBy('code')->lazy()->each(
                fn (Cable $cable) => $writer->addRow($this->row([
                    '#'.$cable->id, $cable->code, $cable->type->value, $cable->cores, $cable->tube_size, $cable->status->value,
                    $this->assetCode($cable->from), $this->assetCode($cable->to), $cable->length_m, count($cable->path),
                    $cable->used_cores, $cable->notes, $cable->area?->code,
                ])),
            );

        $this->cores($writer, $inArea);

        $writer->close();
    }

    /**
     * Core-level sheets: every core with its colors and what it connects to, every splice, and every core ending
     * on an ODP/ODC. Splices and terminations have no area of their own; they go with the cables of the area.
     */
    private function cores(Writer $writer, \Closure $inArea): void
    {
        $locations = fn ($query) => $query->withoutGlobalScopes();
        $splices = CoreSplice::query()
            ->whereHas('coreA.cable', $inArea)
            ->orWhereHas('coreB.cable', $inArea)
            ->with(['location' => $locations, 'coreA.cable' => $locations, 'coreB.cable' => $locations])
            ->orderBy('location_type')->orderBy('location_id')->orderBy('id')
            ->get();
        $terminations = CoreTermination::query()
            ->whereHas('core.cable', $inArea)
            ->with(['location' => $locations, 'core.cable' => $locations])
            ->orderBy('location_type')->orderBy('location_id')->orderBy('kind')->orderBy('port')
            ->get();

        // core id => what it is joined to, for the core sheet
        $links = [];
        foreach ($splices as $splice) {
            $at = $this->assetCode($splice->location);
            foreach ([['coreA', 'coreB'], ['coreB', 'coreA']] as [$self, $other]) {
                if ($splice->{$self}) {
                    $links[$splice->{$self}->id][] = 'Sambung ke '.$this->coreName($splice->{$other})." di {$at}";
                }
            }
        }
        foreach ($terminations as $t) {
            $links[$t->core_id][] = ($t->kind === 'input' ? 'Input splitter ' : 'Port ').$t->port.' '.$this->assetCode($t->location);
        }

        $this->sheet($writer, 'Core', ['kabel', 'core', 'tube', 'warna_tube', 'warna_core', 'status', 'terhubung', 'catatan'], [18, 7, 7, 12, 12, 11, 60, 30]);
        $inArea(Cable::query())->select(['id', 'code', 'tube_size'])->orderBy('code')->with(['coreRows' => fn ($q) => $q->orderBy('number')])->lazy()->each(
            function (Cable $cable) use ($writer, $links) {
                foreach ($cable->coreRows as $core) {
                    $size = max(1, (int) $cable->tube_size);
                    $tube = intdiv($core->number - 1, $size) + 1;
                    $position = ($core->number - 1) % $size + 1;
                    $writer->addRow($this->row([
                        $this->cableName($cable), $core->number, $tube, self::FIBER_COLORS[($tube - 1) % 12],
                        self::FIBER_COLORS[($position - 1) % 12].($position > 12 ? ' (strip)' : ''),
                        $core->status->value, implode('; ', $links[$core->id] ?? []) ?: null, $core->notes,
                    ]));
                }
            },
        );

        $this->sheet($writer, 'Sambungan', ['lokasi', 'jenis_lokasi', 'kabel_a', 'core_a', 'sisi_a', 'kabel_b', 'core_b', 'sisi_b', 'catatan'], [20, 12, 18, 8, 8, 18, 8, 8, 30]);
        foreach ($splices as $splice) {
            $writer->addRow($this->row([
                $this->assetCode($splice->location), self::LOCATION_LABELS[$splice->location_type] ?? $splice->location_type,
                $this->cableName($splice->coreA?->cable), $splice->coreA?->number, $splice->core_a_side,
                $this->cableName($splice->coreB?->cable), $splice->coreB?->number, $splice->core_b_side, $splice->notes,
            ]));
        }

        $this->sheet($writer, 'Terminasi', ['lokasi', 'jenis_lokasi', 'masuk_ke', 'nomor', 'kabel', 'core', 'sisi', 'catatan'], [20, 12, 16, 8, 18, 8, 8, 30]);
        foreach ($terminations as $t) {
            $writer->addRow($this->row([
                $this->assetCode($t->location), self::LOCATION_LABELS[$t->location_type] ?? $t->location_type,
                $t->kind === 'input' ? 'input splitter' : 'port output', $t->port,
                $this->cableName($t->core?->cable), $t->core?->number, $t->side, $t->notes,
            ]));
        }
    }

    private function coreName(?CableCore $core): string
    {
        return $core ? $this->cableName($core->cable)." core {$core->number}" : '?';
    }

    /**
     * The cable's code, or its id (as in the "id" column of the Kabel sheet) for cables that have none.
     */
    private function cableName(?Cable $cable): ?string
    {
        return $cable ? ($cable->code ?: '#'.$cable->id) : null;
    }

    /**
     * How a cable end, splice or termination location is named: the POP by its name, the rest by code.
     */
    private function assetCode(?Model $asset): ?string
    {
        return $asset instanceof Area ? $asset->name : $asset?->code;
    }

    /**
     * Empty ODC and ODP sheets with the import headers, plus a sheet explaining the columns.
     */
    public function template(string $outputPath): void
    {
        $writer = $this->open($outputPath);

        $this->sheet($writer, 'Petunjuk', ['Petunjuk impor data jaringan'], [110], first: true);
        foreach ([
            'Isi sheet "ODC" dan "ODP" mulai baris 2. Baris judul (baris 1) jangan diubah. Boleh mengisi salah satu sheet saja.',
            'Kolom "kode" adalah kunci: kode yang sudah ada akan DIPERBARUI, kode baru akan DITAMBAHKAN.',
            'Saat memperbarui, sel yang dikosongkan tidak mengubah data lama.',
            'Data baru wajib punya kode, latitude, dan longitude. Koordinat boleh memakai titik atau koma desimal (-6.9175 atau -6,9175).',
            'kapasitas: ODC 1–1024 port; ODP 1–64 port, umumnya 8 atau 16 (port ODP dibuat otomatis).',
            'splitter: 1:2, 1:4, 1:8, 1:16, atau 1:32. status: aktif, rencana, atau rusak. tanggal_pasang: 2025-01-31 atau 31/01/2025.',
            'ODC: olt, slot_pon, port_pon (opsional) menyambungkan ODC ke port PON OLT di POP area yang sama, mis. ZTE-C320 KJN-01 | 2 | 4. Ketiganya diisi bersamaan.',
            'ODP: kode_odc diisi kode ODC induk (dari sheet ODC di file ini atau yang sudah ada di aplikasi), port_odc = nomor port di ODC.',
            'Jika ada satu saja baris yang salah, tidak ada data yang disimpan; semua kesalahan ditampilkan beserta nomor barisnya.',
            'Contoh ODC: ODC-BDG-01 | ODC Dago | -6.8915 | 107.6107 | Jl. Dago 10 | 48 | 1:8 | aktif',
            'Contoh ODP: ODP-BDG-01/01 | | ODC-BDG-01 | 1 | -6.8921 | 107.6112 | Jl. Dago 12 | T-101 | 8 | 1:8 | aktif',
            'Tips: file hasil "Ekspor Excel" memakai format yang sama, jadi bisa langsung diedit lalu diimpor kembali.',
        ] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        $this->sheet($writer, 'ODC', Columns::ODC, [16, 24, 12, 12, 30, 10, 10, 10, 14, 30, 18, 9, 9]);
        $this->sheet($writer, 'ODP', Columns::ODP, [18, 20, 16, 9, 12, 12, 30, 10, 10, 10, 10, 14, 30]);

        $writer->close();
    }

    /**
     * A data row whose text always stays text: OpenSpout turns strings starting with "=" into live
     * formulas, so an address like =HYPERLINK(...) typed in the app would run in Excel.
     *
     * @param  list<bool|float|int|string|null>  $values
     */
    private function row(array $values): Row
    {
        return new Row(array_map(fn ($value) => is_string($value) ? new StringCell($value, null) : Cell::fromValue($value), $values));
    }

    private function open(string $outputPath): Writer
    {
        $writer = new Writer;
        $writer->setCreator(config('app.name'));
        $writer->openToFile($outputPath);

        return $writer;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<int>  $widths
     */
    private function sheet(Writer $writer, string $name, array $headers, array $widths, bool $first = false): void
    {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        foreach ($widths as $i => $width) {
            $sheet->setColumnWidth($width, $i + 1);
        }

        $style = (new Style)->setFontBold()->setBackgroundColor('DDEBF7');
        $writer->addRow(Row::fromValues($headers, $style));
    }
}
