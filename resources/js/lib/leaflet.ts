// Leaflet assigns window.L when it loads, which the markercluster plugin extends.
import { cableTypes } from '@/lib/cables';
import L from 'leaflet';
import 'leaflet.markercluster';

import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import 'leaflet/dist/leaflet.css';
import '../../css/map-icons.css';

export default L;
export { cableTypes, formatLength, pathLength } from '@/lib/cables';

export const baseLayers = () => ({
    Peta: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 20,
        maxNativeZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    }),
    Satelit: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 20,
        maxNativeZoom: 19,
        attribution: 'Tiles &copy; Esri',
    }),
});

/**
 * Marker color for an ODP: green below half full, amber while ports remain, red when full.
 */
export function odpColor(odp: { status: string; capacity: number; used_ports: number }): string {
    if (odp.status === 'rusak') return '#7c3aed';
    if (odp.status === 'rencana') return '#9ca3af';
    if (odp.used_ports >= odp.capacity) return '#dc2626';
    if (odp.used_ports / odp.capacity >= 0.5) return '#f59e0b';
    return '#16a34a';
}

const odpIcons = new Map<string, L.DivIcon>();

/**
 * ODP marker (see map-icons.css): the ring fills with the share of used ports.
 * Icons are cached per color, fill step and selection, so markers share a handful of icon objects.
 */
export function odpIcon(odp: { status: string; capacity: number; used_ports: number }, selected = false): L.DivIcon {
    const color = odpColor(odp);
    // Rounded to 5% steps: invisible at this size, and keeps the cache small.
    const percent = odp.capacity > 0 ? Math.min(100, Math.round((odp.used_ports / odp.capacity) * 20) * 5) : 0;
    const key = `${color}|${percent}|${selected}`;

    let icon = odpIcons.get(key);
    if (!icon) {
        const size = selected ? 24 : 18;
        icon = L.divIcon({
            className: '',
            html: `<div class="odp-pin${selected ? ' is-selected' : ''}" style="--c:${color};--p:${percent}%"></div>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
        });
        odpIcons.set(key, icon);
    }
    return icon;
}

export function odcIcon(status: string, selected = false): L.DivIcon {
    const size = selected ? 26 : 20;
    const color = status === 'rusak' ? '#7c3aed' : status === 'rencana' ? '#9ca3af' : '#1d4ed8';
    return L.divIcon({
        className: '',
        html: `<div style="width:${size}px;height:${size}px;border-radius:4px;background:${color};border:${selected ? 3 : 2}px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.35);color:#fff;font:700 9px/1 sans-serif;display:flex;align-items:center;justify-content:center">ODC</div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

export function cableStyle(cable: { type: string; status: string }, selected = false): L.PolylineOptions {
    const type = cableTypes[cable.type] ?? cableTypes.distribusi;
    return {
        color: cable.status === 'rusak' ? '#7c3aed' : type.color,
        weight: type.weight + (selected ? 3 : 0),
        opacity: selected ? 1 : 0.85,
        dashArray: cable.status === 'rencana' ? '8 6' : undefined,
        lineCap: 'round',
        lineJoin: 'round',
    };
}

export const vertexIcon = L.divIcon({
    className: '',
    html: '<div style="width:12px;height:12px;border-radius:3px;background:#fff;border:2px solid #e11d48;box-shadow:0 0 0 1px rgba(0,0,0,.3)"></div>',
    iconSize: [12, 12],
    iconAnchor: [6, 6],
});

export const pickIcon = L.divIcon({
    className: '',
    html: '<div style="width:28px;height:28px;border-radius:9999px 9999px 9999px 0;transform:rotate(-45deg);background:#e11d48;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5)"></div>',
    iconSize: [28, 28],
    iconAnchor: [14, 28],
});
