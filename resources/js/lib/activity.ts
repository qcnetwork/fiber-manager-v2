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
    customer: 'Pelanggan',
    cable: 'Kabel',
    photo: 'Foto',
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
