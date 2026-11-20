/** Which layers the network map starts with (Pengaturan → Peta). Mirrors App\Support\MapLayers. */
export interface MapLayerPrefs {
    base: 'peta' | 'satelit';
    cables: boolean;
    pops: boolean;
    odcs: boolean;
    odps: boolean;
    joints: boolean;
    links: boolean;
    customers: boolean;
    cluster: boolean;
}

export type MapLayerToggle = Exclude<keyof MapLayerPrefs, 'base'>;

/** Same names as the layer menu on the map, so the settings page reads the same. */
export const mapLayerToggles: { key: MapLayerToggle; label: string; description: string }[] = [
    { key: 'cables', label: 'Kabel', description: 'Jalur kabel yang sudah digambar.' },
    { key: 'pops', label: 'POP', description: 'Lokasi POP / OLT.' },
    { key: 'odcs', label: 'ODC', description: 'Semua titik ODC.' },
    { key: 'odps', label: 'ODP', description: 'Semua titik ODP.' },
    { key: 'joints', label: 'Joint/Roset', description: 'Joint box dan roset di jalur kabel.' },
    { key: 'links', label: 'Garis bantu', description: 'Garis lurus ODC → ODP.' },
    { key: 'customers', label: 'Pelanggan', description: 'Titik pelanggan, tampil saat peta diperbesar.' },
    { key: 'cluster', label: 'Kelompokkan ODP', description: 'ODP yang berdekatan digabung jadi satu lingkaran berangka.' },
];
