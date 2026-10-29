const valid = (lat: number, lng: number) => Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);

/**
 * Reads a location from text people paste: "-7.0429, 109.5813", "-7.0429 109.5813", or a Google Maps link
 * (".../@-7.0429,109.5813,17z", "?q=-7.0429,109.5813", "!3d-7.0429!4d109.5813").
 * Short links (maps.app.goo.gl) don't contain coordinates and return null.
 */
export function parseCoordinates(text: string): { lat: number; lng: number } | null {
    const input = decodeURIComponent(text.trim());

    const patterns = [
        /!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/, // place pin in a Google Maps link
        /[?&](?:q|query|ll|destination|center)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/,
        /@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/, // map center in a Google Maps link
        /^(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)$/, // plain "lat, lng"
    ];

    for (const pattern of patterns) {
        const match = input.match(pattern);
        if (match) {
            const lat = parseFloat(match[1]);
            const lng = parseFloat(match[2]);
            if (valid(lat, lng)) return { lat, lng };
        }
    }

    return null;
}

export const isUrl = (text: string) => /^https?:\/\//i.test(text.trim());

export interface GeocodeResult {
    label: string;
    lat: number;
    lng: number;
}

/**
 * Address search with OpenStreetMap Nominatim, preferring results inside the visible map area.
 * Only the typed address is sent; Nominatim's usage policy allows light, user-triggered searches like this.
 */
export async function geocode(query: string, bbox?: string): Promise<GeocodeResult[]> {
    const params = new URLSearchParams({ format: 'jsonv2', limit: '5', countrycodes: 'id', 'accept-language': 'id', q: query });
    if (bbox) params.set('viewbox', bbox);

    const response = await fetch(`https://nominatim.openstreetmap.org/search?${params}`);
    if (!response.ok) throw new Error('Pencarian alamat sedang tidak tersedia.');

    const rows: { display_name: string; lat: string; lon: string }[] = await response.json();
    return rows.map((r) => ({ label: r.display_name, lat: parseFloat(r.lat), lng: parseFloat(r.lon) }));
}
