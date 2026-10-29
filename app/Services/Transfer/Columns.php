<?php

namespace App\Services\Transfer;

/**
 * Spreadsheet/KML field names shared by export, the import template and the importer,
 * so an exported file can be edited and imported back.
 */
final class Columns
{
    /** Columns of the ODC sheet that the importer reads. */
    public const ODC = ['kode', 'nama', 'latitude', 'longitude', 'alamat', 'kapasitas', 'splitter', 'status', 'tanggal_pasang', 'catatan'];

    /** Columns of the ODP sheet that the importer reads. */
    public const ODP = [
        'kode', 'nama', 'kode_odc', 'port_odc', 'latitude', 'longitude', 'alamat', 'no_tiang',
        'kapasitas', 'splitter', 'status', 'tanggal_pasang', 'catatan',
    ];

    /**
     * Other header spellings people use in their own spreadsheets, per sheet.
     */
    private const ALIASES = [
        'common' => [
            'lat' => 'latitude', 'lintang' => 'latitude',
            'lng' => 'longitude', 'lon' => 'longitude', 'long' => 'longitude', 'bujur' => 'longitude',
            'code' => 'kode', 'name' => 'nama', 'address' => 'alamat',
            'capacity' => 'kapasitas', 'jumlah_port' => 'kapasitas',
            'rasio_splitter' => 'splitter', 'splitter_ratio' => 'splitter',
            'tgl_pasang' => 'tanggal_pasang', 'installed_at' => 'tanggal_pasang',
            'keterangan' => 'catatan', 'notes' => 'catatan',
        ],
        'odc' => ['kode_odc' => 'kode', 'nama_odc' => 'nama'],
        'odp' => [
            'kode_odp' => 'kode', 'nama_odp' => 'nama', 'odc' => 'kode_odc', 'odc_induk' => 'kode_odc',
            'port' => 'port_odc', 'tiang' => 'no_tiang', 'nomor_tiang' => 'no_tiang', 'pole_number' => 'no_tiang',
        ],
    ];

    /**
     * Maps a header cell to its column key, or null when the column is not one we read.
     */
    public static function normalize(string $header, string $sheet): ?string
    {
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($header))), '_');
        $key = self::ALIASES[$sheet][$key] ?? self::ALIASES['common'][$key] ?? $key;
        $known = $sheet === 'odc' ? self::ODC : self::ODP;

        return in_array($key, $known, true) ? $key : null;
    }
}
