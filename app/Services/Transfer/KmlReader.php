<?php

namespace App\Services\Transfer;

use DOMDocument;
use DOMElement;
use ZipArchive;

/**
 * Reads placemarks from a .kml or .kmz file (Google Earth / Google My Maps).
 *
 * Points become ODC/ODP rows and lines become cable rows. Whether a point is an ODC or an ODP is taken,
 * in order, from our own ExtendedData "tipe", the placemark name ("ODP-..."), or an enclosing folder name.
 */
class KmlReader
{
    public const MAX_BYTES = 30 * 1024 * 1024;

    /**
     * @return array{
     *     points: list<array{label: string, kind: ?string, data: array<string, mixed>}>,
     *     lines: list<array{label: string, type: ?string, data: array<string, mixed>}>
     * }
     */
    public function read(string $path, string $originalName): array
    {
        $xml = str_ends_with(strtolower($originalName), '.kmz') ? $this->unzip($path) : (string) file_get_contents($path);

        $dom = new DOMDocument;
        // No LIBXML_NOENT/DTDLOAD: entities and external resources are never expanded or fetched.
        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw new ImportException('File KML tidak valid atau rusak.');
        }

        $points = [];
        $lines = [];

        foreach ($dom->getElementsByTagNameNS('*', 'Placemark') as $placemark) {
            $name = trim($this->childText($placemark, 'name') ?? '');
            $extended = $this->extendedData($placemark);
            $folders = $this->folderNames($placemark);
            $label = 'KML "'.($name !== '' ? $name : 'tanpa nama').'"';

            // POPs in our own export are for viewing only; they are managed in the app.
            if (mb_strtolower($extended['tipe'] ?? '') === 'pop') {
                continue;
            }

            foreach ($placemark->getElementsByTagNameNS('*', 'Point') as $point) {
                $coords = $this->coordinates($point);
                if (! $coords) {
                    continue;
                }
                [$lat, $lng] = $coords[0];

                $points[] = [
                    'label' => $label,
                    'kind' => $this->pointKind($name, $extended, $folders),
                    'data' => [...$extended, 'kode' => $name !== '' ? $name : null, 'latitude' => $lat, 'longitude' => $lng],
                ];
            }

            // Our own export marks cables with tipe=kabel and keeps their (possibly empty) code separately from the display name.
            $lineName = ($extended['tipe'] ?? null) === 'kabel' ? ($extended['kode'] ?? '') : $name;

            $lineStrings = iterator_to_array($placemark->getElementsByTagNameNS('*', 'LineString'));
            foreach ($lineStrings as $i => $lineString) {
                $path = $this->coordinates($lineString);
                $partName = count($lineStrings) > 1 && $lineName !== '' ? "{$lineName} (".($i + 1).')' : $lineName;

                $lines[] = [
                    'label' => count($lineStrings) > 1 ? "{$label} bagian ".($i + 1) : $label,
                    'type' => $this->cableType($name, $extended, $folders),
                    'data' => [
                        'kode' => $partName !== '' ? $partName : null,
                        'core' => $extended['core'] ?? null,
                        'status' => $extended['status'] ?? null,
                        'path' => $path,
                    ],
                ];
            }
        }

        if (! $points && ! $lines) {
            throw new ImportException('Tidak ada titik atau garis di file KML ini.');
        }

        return ['points' => $points, 'lines' => $lines];
    }

    private function unzip(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ImportException('File KMZ tidak bisa dibuka.');
        }

        // Google Earth names it doc.kml; otherwise take the first .kml inside.
        $index = $zip->locateName('doc.kml', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR);
        for ($i = 0; $index === false && $i < $zip->numFiles; $i++) {
            if (str_ends_with(strtolower((string) $zip->getNameIndex($i)), '.kml')) {
                $index = $i;
            }
        }

        $stat = $index === false ? false : $zip->statIndex($index);
        if ($stat === false || $stat['size'] > self::MAX_BYTES) {
            $zip->close();
            throw new ImportException($stat === false ? 'Tidak ada file .kml di dalam KMZ.' : 'Isi KMZ terlalu besar.');
        }

        $xml = (string) $zip->getFromIndex($index);
        $zip->close();

        return $xml;
    }

    /**
     * @return list<array{0: float, 1: float}> [lat, lng] pairs
     */
    private function coordinates(DOMElement $geometry): array
    {
        $text = trim($this->childText($geometry, 'coordinates') ?? '');
        $points = [];

        foreach (preg_split('/\s+/', $text) ?: [] as $tuple) {
            $parts = explode(',', $tuple);
            if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                $points[] = [(float) $parts[1], (float) $parts[0]]; // KML is lng,lat
            }
        }

        return $points;
    }

    /**
     * @return array<string, string>
     */
    private function extendedData(DOMElement $placemark): array
    {
        $data = [];
        foreach ($placemark->getElementsByTagNameNS('*', 'Data') as $item) {
            $data[mb_strtolower($item->getAttribute('name'))] = trim($this->childText($item, 'value') ?? '');
        }
        foreach ($placemark->getElementsByTagNameNS('*', 'SimpleData') as $item) {
            $data[mb_strtolower($item->getAttribute('name'))] = trim($item->textContent);
        }

        return array_filter($data, fn ($v) => $v !== '');
    }

    /**
     * @return list<string> enclosing folder names, innermost first
     */
    private function folderNames(DOMElement $placemark): array
    {
        $names = [];
        for ($node = $placemark->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->localName === 'Folder') {
                $names[] = (string) $this->childText($node, 'name');
            }
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $extended
     * @param  list<string>  $folders
     */
    private function pointKind(string $name, array $extended, array $folders): ?string
    {
        $tipe = mb_strtolower($extended['tipe'] ?? '');
        if (in_array($tipe, ['odc', 'odp'], true)) {
            return $tipe;
        }

        foreach ([$name, ...$folders] as $text) {
            if (preg_match('/\b(odc|odp)\b/i', $text, $m)) {
                return strtolower($m[1]);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $extended
     * @param  list<string>  $folders
     */
    private function cableType(string $name, array $extended, array $folders): ?string
    {
        foreach ([$extended['jenis'] ?? '', $name, ...$folders] as $text) {
            if (preg_match('/\b(feeder|distribusi|distribution|drop)\b/i', $text, $m)) {
                return ['distribution' => 'distribusi'][strtolower($m[1])] ?? strtolower($m[1]);
            }
        }

        return null;
    }

    /**
     * Text of the first direct child element with this local name.
     */
    private function childText(DOMElement $element, string $localName): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child->textContent;
            }
        }

        return null;
    }
}
