import { getJson } from '@/lib/http';

const valid = (lat: number, lng: number) => Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);

/**
 * How far a location can be trusted: typed coordinates, the place pin of a Google Maps link,
 * or only the middle of the map view the link was shared from.
 */
export type LocationPrecision = 'exact' | 'pin' | 'center';

export interface ParsedLocation {
    lat: number;
    lng: number;
    precision: LocationPrecision;
}

/**
 * Reads a location from text people paste: "-7.0429, 109.5813", "-7.0429 109.5813", or a Google Maps link
 * (".../@-7.0429,109.5813,17z", "?q=-7.0429,109.5813", "!3d-7.0429!4d109.5813").
 * Short links (maps.app.goo.gl) don't contain coordinates and return null; use resolveLocation for those.
 */
export function parseCoordinates(text: string): ParsedLocation | null {
    let input = text.trim();
    try {
        input = decodeURIComponent(input);
    } catch {
        // malformed escape: read it as typed
    }

    const patterns: [RegExp, LocationPrecision][] = [
        [/^(-?\d+(?:\.\d+)?)\s*[,;\s]\s*(-?\d+(?:\.\d+)?)$/, 'exact'], // plain "lat, lng"
        [/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/, 'pin'], // place pin in a Google Maps link
        [/[?&](?:q|query|ll|destination|daddr)=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/, 'pin'],
        [/\/(?:place|search|dir)\/(-?\d+(?:\.\d+)?),[\s+]*(-?\d+(?:\.\d+)?)/, 'pin'],
        [/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/, 'center'], // map view center
        [/[?&]center=(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/, 'center'],
    ];

    for (const [pattern, precision] of patterns) {
        const match = input.match(pattern);
        if (match) {
            const lat = parseFloat(match[1]);
            const lng = parseFloat(match[2]);
            if (valid(lat, lng)) return { lat, lng, precision };
        }
    }

    return null;
}

export const isUrl = (text: string) => /^https?:\/\//i.test(text.trim());

/**
 * Like parseCoordinates, but also opens short Google Maps links through the server.
 * Returns null when the text is neither coordinates nor a link (e.g. an address); throws when a link can't be read.
 */
export async function resolveLocation(text: string): Promise<ParsedLocation | null> {
    const parsed = parseCoordinates(text);
    if (parsed || !isUrl(text)) return parsed;

    const data = await getJson<{ lat: number; lng: number; precision: 'pin' | 'center' }>(route('map.resolve-link'), { url: text.trim() });
    return { lat: data.lat, lng: data.lng, precision: data.precision };
}

/**
 * Warning shown for locations taken from a Google Maps link; typed coordinates need none.
 */
export function precisionWarning(precision: LocationPrecision): string | null {
    const tip = 'Untuk hasil lebih akurat, pakai koordinat: di Google Maps tahan (long-press) titik rumah pelanggan, lalu salin angka koordinatnya.';
    if (precision === 'pin')
        return `Lokasi diambil dari link Google Maps dan bisa bergeser dari titik sebenarnya (mis. link nama tempat/usaha). Cek posisinya di peta. ${tip}`;
    if (precision === 'center')
        return `Link ini tidak memuat pin lokasi, hanya titik tengah tampilan peta — bisa meleset jauh. Cek posisinya di peta, tap ulang bila perlu. ${tip}`;
    return null;
}

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
