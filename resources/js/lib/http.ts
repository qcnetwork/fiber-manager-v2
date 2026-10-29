/**
 * GET a JSON endpoint of this app. Mutations go through Inertia's router so CSRF and validation errors are handled there.
 */
export async function getJson<T>(url: string, params: Record<string, string | number | boolean | null | undefined> = {}): Promise<T> {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
        if (value !== null && value !== undefined && value !== '') query.set(key, String(value));
    }

    const response = await fetch(query.size ? `${url}?${query}` : url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });

    if (!response.ok) throw new Error(`Permintaan gagal (${response.status})`);

    return response.json();
}

/**
 * Current position from the device GPS.
 */
export function currentPosition(): Promise<{ lat: number; lng: number; accuracy: number }> {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) return reject(new Error('Perangkat tidak mendukung GPS.'));

        navigator.geolocation.getCurrentPosition(
            (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
            (err) => reject(new Error(err.code === err.PERMISSION_DENIED ? 'Izin lokasi ditolak.' : 'Lokasi tidak bisa didapatkan.')),
            { enableHighAccuracy: true, timeout: 15000 },
        );
    });
}
