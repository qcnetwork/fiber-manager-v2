// Kept free of Leaflet so pages without a map (e.g. the dashboard) can use these.

export const cableTypes: Record<string, { label: string; color: string; weight: number }> = {
    feeder: { label: 'Feeder', color: '#ea580c', weight: 5 },
    distribusi: { label: 'Distribusi', color: '#0891b2', weight: 3.5 },
    drop: { label: 'Drop', color: '#ca8a04', weight: 2 },
};

/**
 * Length in meters of a [lat, lng] path; mirrors GeoService::pathLength on the server.
 */
export function pathLength(path: [number, number][]): number {
    const R = 6371000;
    const rad = (d: number) => (d * Math.PI) / 180;
    let total = 0;
    for (let i = 1; i < path.length; i++) {
        const [lat1, lng1] = path[i - 1];
        const [lat2, lng2] = path[i];
        const a = Math.sin(rad(lat2 - lat1) / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(rad(lng2 - lng1) / 2) ** 2;
        total += R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }
    return total;
}

export function formatLength(meters: number): string {
    return meters >= 1000 ? `${(meters / 1000).toLocaleString('id-ID', { maximumFractionDigits: 2 })} km` : `${Math.round(meters)} m`;
}
