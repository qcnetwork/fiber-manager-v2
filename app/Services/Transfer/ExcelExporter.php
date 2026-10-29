<?php

namespace App\Services\Transfer;

use App\Enums\PortStatus;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use App\Models\OdpPort;
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
    public function export(?int $areaId, string $outputPath): void
    {
        $writer = $this->open($outputPath);
        $inArea = fn ($query) => $query->when($areaId, fn ($q) => $q->where('area_id', $areaId));

        $this->sheet($writer, 'ODC', [...Columns::ODC, 'area', 'jumlah_odp'], [16, 24, 12, 12, 30, 10, 10, 10, 14, 30, 14, 12], first: true);
        $inArea(Odc::query())->with('area:id,code')->withCount('odps')->orderBy('code')->lazy()->each(
            fn (Odc $odc) => $writer->addRow(Row::fromValues([
                $odc->code, $odc->name, $odc->lat, $odc->lng, $odc->address, $odc->capacity, $odc->splitter_ratio,
                $odc->status->value, $odc->installed_at?->format('Y-m-d'), $odc->notes, $odc->area?->code, $odc->odps_count,
            ])),
        );

        $this->sheet($writer, 'ODP', [...Columns::ODP, 'area', 'port_terpakai', 'port_kosong'], [18, 20, 16, 9, 12, 12, 30, 10, 10, 10, 10, 14, 30, 8, 13, 12]);
        $inArea(Odp::query())->with(['area:id,code', 'odc:id,code'])->withPortUsage()->orderBy('code')->lazy()->each(
            fn (Odp $odp) => $writer->addRow(Row::fromValues([
                $odp->code, $odp->name, $odp->odc?->code, $odp->odc_port, $odp->lat, $odp->lng, $odp->address, $odp->pole_number,
                $odp->capacity, $odp->splitter_ratio, $odp->status->value, $odp->installed_at?->format('Y-m-d'), $odp->notes,
                $odp->area?->code, $odp->used_ports, $odp->free_ports,
            ])),
        );

        $this->sheet($writer, 'Pelanggan', ['kode_odp', 'port', 'status_port', 'nama', 'id_pelanggan', 'hp', 'alamat', 'paket', 'sn_ont', 'catatan_port'], [18, 6, 12, 24, 14, 16, 30, 14, 18, 24]);
        OdpPort::query()
            ->whereHas('odp', $inArea) // through Odp, so the area scope applies too
            ->where('odp_ports.status', '!=', PortStatus::Kosong)
            ->with(['odp:id,code', 'customer'])
            ->join('odps', 'odps.id', '=', 'odp_ports.odp_id')
            ->orderBy('odps.code')->orderBy('odp_ports.number')
            ->select('odp_ports.*')
            ->lazy()
            ->each(fn (OdpPort $port) => $writer->addRow(Row::fromValues([
                $port->odp->code, $port->number, $port->status->value, $port->customer?->name, $port->customer?->customer_code,
                $port->customer?->phone, $port->customer?->address, $port->customer?->package, $port->customer?->ont_sn, $port->notes,
            ])));

        $this->sheet($writer, 'Kabel', ['kode', 'jenis', 'core', 'status', 'dari', 'ke', 'panjang_m', 'jumlah_titik', 'catatan'], [18, 12, 8, 10, 18, 18, 11, 12, 30]);
        $inArea(Cable::query())->with(['from:id,code', 'to:id,code'])->orderBy('type')->orderBy('code')->lazy()->each(
            fn (Cable $cable) => $writer->addRow(Row::fromValues([
                $cable->code, $cable->type->value, $cable->cores, $cable->status->value, $cable->from?->code, $cable->to?->code,
                $cable->length_m, count($cable->path), $cable->notes,
            ])),
        );

        $writer->close();
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
            'ODP: kode_odc diisi kode ODC induk (dari sheet ODC di file ini atau yang sudah ada di aplikasi), port_odc = nomor port di ODC.',
            'Jika ada satu saja baris yang salah, tidak ada data yang disimpan; semua kesalahan ditampilkan beserta nomor barisnya.',
            'Contoh ODC: ODC-BDG-01 | ODC Dago | -6.8915 | 107.6107 | Jl. Dago 10 | 48 | 1:8 | aktif',
            'Contoh ODP: ODP-BDG-01/01 | | ODC-BDG-01 | 1 | -6.8921 | 107.6112 | Jl. Dago 12 | T-101 | 8 | 1:8 | aktif',
            'Tips: file hasil "Ekspor Excel" memakai format yang sama, jadi bisa langsung diedit lalu diimpor kembali.',
        ] as $line) {
            $writer->addRow(Row::fromValues([$line]));
        }

        $this->sheet($writer, 'ODC', Columns::ODC, [16, 24, 12, 12, 30, 10, 10, 10, 14, 30]);
        $this->sheet($writer, 'ODP', Columns::ODP, [18, 20, 16, 9, 12, 12, 30, 10, 10, 10, 10, 14, 30]);

        $writer->close();
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
