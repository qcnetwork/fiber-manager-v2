// Fiber/tube color code (TIA-598, the order used on most cables sold in Indonesia).

export const fiberColors = [
    { name: 'Biru', hex: '#2563eb' },
    { name: 'Oranye', hex: '#f97316' },
    { name: 'Hijau', hex: '#16a34a' },
    { name: 'Coklat', hex: '#92400e' },
    { name: 'Abu-abu', hex: '#9ca3af' },
    { name: 'Putih', hex: '#ffffff' },
    { name: 'Merah', hex: '#dc2626' },
    { name: 'Hitam', hex: '#111827' },
    { name: 'Kuning', hex: '#facc15' },
    { name: 'Ungu', hex: '#7c3aed' },
    { name: 'Pink', hex: '#f472b6' },
    { name: 'Tosca', hex: '#14b8a6' },
] as const;

export type FiberColor = (typeof fiberColors)[number];

export interface CoreInfo {
    number: number;
    tube: number;
    /** Position of the core inside its tube, from 1. */
    position: number;
    color: FiberColor;
    tubeColor: FiberColor;
    /** Tubes with more than 12 cores repeat the colors with a stripe/ring marking. */
    striped: boolean;
}

/**
 * Tube, position and colors of a core from its number and the cable's cores per tube.
 */
export function coreInfo(number: number, tubeSize: number): CoreInfo {
    const size = Math.max(1, tubeSize);
    const tube = Math.floor((number - 1) / size) + 1;
    const position = ((number - 1) % size) + 1;
    return {
        number,
        tube,
        position,
        color: fiberColors[(position - 1) % 12],
        tubeColor: fiberColors[(tube - 1) % 12],
        striped: position > 12,
    };
}

/** "Core 14 · Tube 2 (Oranye) · Oranye" style label; the tube is only mentioned when the cable has several. */
export function coreLabel(number: number, tubeSize: number, cores: number): string {
    const info = coreInfo(number, tubeSize);
    const tube = cores > tubeSize ? ` · Tube ${info.tube} (${info.tubeColor.name})` : '';
    return `Core ${number}${tube} · ${info.color.name}${info.striped ? ' bergaris' : ''}`;
}

export const coreStatuses = {
    kosong: { label: 'Kosong', class: 'bg-muted text-muted-foreground' },
    terpakai: { label: 'Terpakai', class: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' },
    rusak: { label: 'Rusak', class: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300' },
} as const;

/** Cores per tube to choose from in the cable form. */
export const tubeSizes = [1, 2, 4, 6, 8, 12, 24];

/** "ODP-07 · Input 1" / "ODP-07 · Port 3 → Budi" for a core ending at an ODP. */
export function terminationLabel(
    t: { kind: string; port: number; odp?: { code: string } | null; customer?: { name: string } | null },
    withOdp = true,
): string {
    const where = t.kind === 'input' ? `Input splitter ${t.port}` : `Port ${t.port}`;
    const customer = t.kind === 'output' && t.customer ? ` → ${t.customer.name}` : '';
    return `${withOdp && t.odp ? `${t.odp.code} · ` : ''}${where}${customer}`;
}
