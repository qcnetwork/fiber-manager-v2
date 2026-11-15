export type ActivityAction = 'created' | 'updated' | 'deleted' | 'moved' | 'imported';

/** What kind of record an activity entry is about. */
export const activityTypes: Record<string, string> = {
    pop: 'POP',
    olt: 'OLT',
    olt_port: 'Port OLT',
    odc: 'ODC',
    odp: 'ODP',
    odp_port: 'Port ODP',
    joint: 'Joint box/Roset',
    splice: 'Sambungan core',
    customer: 'Pelanggan',
    cable: 'Kabel',
    photo: 'Foto',
    cable_core: 'Core kabel',
    user: 'Pengguna',
    import: 'Impor file',
};

export const activityActions: Record<ActivityAction, { label: string; class: string }> = {
    created: { label: 'Tambah', class: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    updated: { label: 'Ubah', class: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' },
    deleted: { label: 'Hapus', class: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
    moved: { label: 'Pindah', class: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    imported: { label: 'Impor', class: 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300' },
};

/** Readable names of the logged columns. */
export const activityFields: Record<string, string> = {
    photo: 'Foto',
    code: 'Kode',
    name: 'Nama',
    lat: 'Latitude',
    lng: 'Longitude',
    center_lat: 'Latitude',
    center_lng: 'Longitude',
    address: 'Alamat',
    pole_number: 'No. tiang',
    capacity: 'Kapasitas',
    splitter_ratio: 'Splitter',
    status: 'Status',
    installed_at: 'Tanggal pasang',
    notes: 'Catatan',
    area_id: 'Area',
    odc_id: 'ODC',
    odc_port: 'Port ODC',
    odp_id: 'ODP',
    olt_id: 'OLT',
    olt_port_id: 'Port OLT',
    customer_id: 'Pelanggan',
    customer_code: 'Kode pelanggan',
    phone: 'Telepon',
    package: 'Paket',
    ont_sn: 'SN ONT',
    type: 'Jenis',
    sambungan: 'Sambungan',
    tube_size: 'Core per tube',
    cores: 'Core',
    path: 'Jalur',
    length_m: 'Panjang (m)',
    from_type: 'Dari (jenis)',
    from_id: 'Dari (id)',
    to_type: 'Ke (jenis)',
    to_id: 'Ke (id)',
    caption: 'Keterangan',
    username: 'Username',
    email: 'Email',
    role: 'Role',
    password: 'Password',
    brand: 'Merek',
    ip_address: 'IP',
    pon_ports: 'Jumlah PON',
    slot: 'Slot',
    port: 'Port',
    number: 'Nomor',
    odc: 'ODC',
    odp: 'ODP',
    cable: 'Kabel',
    skipped: 'Dilewati',
    request: 'Approval',
};

export type ActivityChange = [unknown, unknown];

const show = (value: unknown) => (value === null || value === undefined || value === '' ? '–' : String(value));

/** One readable line per changed field. */
export function changeLines(action: string, changes: Record<string, ActivityChange> | null): { field: string; text: string }[] {
    return Object.entries(changes ?? {}).map(([key, [before, after]]) => {
        const field = activityFields[key] ?? key;
        if (action === 'deleted') return { field, text: show(before) };
        // New values only: imports, created records, the approval note.
        if (action === 'imported' || key === 'request' || (action === 'created' && before === null && after !== null))
            return { field, text: show(after) };
        // Masked columns (password, cable route) are stored without values.
        if (before === null && after === null) return { field, text: 'diubah' };
        return { field, text: `${show(before)} → ${show(after)}` };
    });
}
