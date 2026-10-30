<?php

namespace App\Services\Transfer;

use App\Models\Area;
use App\Models\Cable;
use App\Models\Odc;
use App\Models\Odp;
use XMLWriter;

/**
 * Writes POPs, ODC, ODP and cables as KML for Google Earth / Google My Maps.
 *
 * Each placemark carries its fields as ExtendedData (tipe, kapasitas, ...), which KmlReader reads back,
 * so a KML export can also be re-imported.
 */
class KmlExporter
{
    /** KML colors are aabbggrr. */
    private const CABLE_COLORS = ['feeder' => 'ff0c58ea', 'distribusi' => 'ffb29108', 'drop' => 'ff048aca'];

    private const CABLE_WIDTHS = ['feeder' => 5, 'distribusi' => 3, 'drop' => 2];

    private const ICON = 'https://maps.google.com/mapfiles/kml/paddle/%s.png';

    public function export(?int $areaId, string $outputPath, string $title): void
    {
        $inArea = fn ($query) => $query->when($areaId, fn ($q) => $q->where('area_id', $areaId));

        $xml = new XMLWriter;
        $xml->openUri($outputPath);
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('kml');
        $xml->writeAttribute('xmlns', 'http://www.opengis.net/kml/2.2');
        $xml->startElement('Document');
        $xml->writeElement('name', $title);

        $this->styles($xml);

        $xml->startElement('Folder');
        $xml->writeElement('name', 'POP');
        Area::query()
            ->whereNotNull('center_lat')
            ->when($areaId, fn ($q) => $q->whereKey($areaId))
            // Areas have no area scope; users tied to one area only get their own POP.
            ->when(auth()->user()?->seesAllAreas() === false, fn ($q) => $q->whereKey(auth()->user()->area_id))
            ->with('olts:id,area_id,code')
            ->orderBy('name')
            ->get()
            ->each(fn (Area $pop) => $this->point($xml, $pop->name, 'pop', $pop->lat, $pop->lng, [
                'tipe' => 'pop', 'kode' => $pop->code, 'alamat' => $pop->address, 'olt' => $pop->olts->pluck('code')->implode(', '),
                'catatan' => $pop->notes,
            ]));
        $xml->endElement();

        $xml->startElement('Folder');
        $xml->writeElement('name', 'ODC');
        $inArea(Odc::query())->with('area:id,code')->withCount('odps')->orderBy('code')->lazy()->each(fn (Odc $odc) => $this->point($xml, $odc->code, 'odc-'.$odc->status->value, $odc->lat, $odc->lng, [
            'tipe' => 'odc', 'nama' => $odc->name, 'area' => $odc->area?->code, 'alamat' => $odc->address, 'kapasitas' => $odc->capacity,
            'splitter' => $odc->splitter_ratio, 'status' => $odc->status->value, 'tanggal_pasang' => $odc->installed_at?->format('Y-m-d'),
            'jumlah_odp' => $odc->odps_count, 'catatan' => $odc->notes,
        ]));
        $xml->endElement();

        $xml->startElement('Folder');
        $xml->writeElement('name', 'ODP');
        $inArea(Odp::query())->with(['area:id,code', 'odc:id,code'])->withPortUsage()->orderBy('code')->lazy()->each(fn (Odp $odp) => $this->point($xml, $odp->code, $this->odpStyle($odp), $odp->lat, $odp->lng, [
            'tipe' => 'odp', 'nama' => $odp->name, 'area' => $odp->area?->code, 'kode_odc' => $odp->odc?->code, 'port_odc' => $odp->odc_port,
            'alamat' => $odp->address, 'no_tiang' => $odp->pole_number, 'kapasitas' => $odp->capacity, 'splitter' => $odp->splitter_ratio,
            'status' => $odp->status->value, 'port_terpakai' => $odp->used_ports, 'port_kosong' => $odp->free_ports,
            'tanggal_pasang' => $odp->installed_at?->format('Y-m-d'), 'catatan' => $odp->notes,
        ]));
        $xml->endElement();

        $xml->startElement('Folder');
        $xml->writeElement('name', 'Kabel');
        $inArea(Cable::query())->with(['from:id,code', 'to:id,code'])->orderBy('type')->orderBy('code')->lazy()->each(fn (Cable $cable) => $this->line($xml, $cable));
        $xml->endElement();

        $xml->endElement(); // Document
        $xml->endElement(); // kml
        $xml->endDocument();
        $xml->flush();
    }

    private function styles(XMLWriter $xml): void
    {
        $icons = [
            'pop' => 'red-stars', 'odc-aktif' => 'blu-square', 'odc-rencana' => 'wht-square', 'odc-rusak' => 'purple-square',
            'odp-hijau' => 'grn-circle', 'odp-kuning' => 'ylw-circle', 'odp-merah' => 'red-circle',
            'odp-rencana' => 'wht-circle', 'odp-rusak' => 'purple-circle',
        ];
        foreach ($icons as $id => $icon) {
            $xml->startElement('Style');
            $xml->writeAttribute('id', $id);
            $xml->startElement('IconStyle');
            $xml->writeElement('scale', str_starts_with($id, 'odp') ? '0.8' : '1.1');
            $xml->startElement('Icon');
            $xml->writeElement('href', sprintf(self::ICON, $icon));
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
        }

        foreach (self::CABLE_COLORS as $type => $color) {
            foreach (['aktif' => $color, 'rencana' => '99'.substr($color, 2), 'rusak' => 'ffed3a7c'] as $status => $statusColor) {
                $xml->startElement('Style');
                $xml->writeAttribute('id', "kabel-{$type}-{$status}");
                $xml->startElement('LineStyle');
                $xml->writeElement('color', $statusColor);
                $xml->writeElement('width', (string) self::CABLE_WIDTHS[$type]);
                $xml->endElement();
                $xml->endElement();
            }
        }
    }

    private function odpStyle(Odp $odp): string
    {
        return match (true) {
            $odp->status->value !== 'aktif' => 'odp-'.$odp->status->value,
            $odp->used_ports >= $odp->capacity => 'odp-merah',
            $odp->used_ports / max($odp->capacity, 1) >= 0.5 => 'odp-kuning',
            default => 'odp-hijau',
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function point(XMLWriter $xml, string $name, string $style, float $lat, float $lng, array $fields): void
    {
        $xml->startElement('Placemark');
        $xml->writeElement('name', $name);
        $xml->writeElement('styleUrl', '#'.$style);
        $this->describe($xml, $fields);
        $xml->startElement('Point');
        $xml->writeElement('coordinates', "{$lng},{$lat},0");
        $xml->endElement();
        $xml->endElement();
    }

    private function line(XMLWriter $xml, Cable $cable): void
    {
        $xml->startElement('Placemark');
        $xml->writeElement('name', $cable->code ?? 'Kabel '.$cable->type->value);
        $xml->writeElement('styleUrl', "#kabel-{$cable->type->value}-{$cable->status->value}");
        $this->describe($xml, [
            'tipe' => 'kabel', 'kode' => $cable->code, 'jenis' => $cable->type->value, 'core' => $cable->cores, 'status' => $cable->status->value,
            'dari' => $cable->from?->code, 'ke' => $cable->to?->code, 'panjang_m' => $cable->length_m, 'catatan' => $cable->notes,
        ]);
        $xml->startElement('LineString');
        $xml->writeElement('tessellate', '1');
        $xml->writeElement('coordinates', implode(' ', array_map(fn (array $p) => "{$p[1]},{$p[0]},0", $cable->path)));
        $xml->endElement();
        $xml->endElement();
    }

    /**
     * Readable table in the balloon plus machine-readable ExtendedData.
     *
     * @param  array<string, mixed>  $fields
     */
    private function describe(XMLWriter $xml, array $fields): void
    {
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');

        $rows = '';
        foreach ($fields as $key => $value) {
            $rows .= '<tr><td><b>'.e(str_replace('_', ' ', $key)).'</b></td><td>'.e((string) $value).'</td></tr>';
        }
        $xml->startElement('description');
        $xml->writeCdata('<table>'.$rows.'</table>');
        $xml->endElement();

        $xml->startElement('ExtendedData');
        foreach ($fields as $key => $value) {
            $xml->startElement('Data');
            $xml->writeAttribute('name', $key);
            $xml->writeElement('value', (string) $value);
            $xml->endElement();
        }
        $xml->endElement();
    }
}
