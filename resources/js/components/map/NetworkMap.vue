<script setup lang="ts">
import L, { baseLayers, cableStyle, cableTypes, formatLength, odcIcon, odpColor, odpIcon, pickIcon, vertexIcon } from '@/lib/leaflet';
import type { CableLine, LatLng, MapItemKind, OdcMarker, OdpMarker } from '@/types';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    odcs: OdcMarker[];
    odps: OdpMarker[];
    cables: CableLine[];
    selected: { kind: MapItemKind; id: number } | null;
    /** Path of the cable being drawn; while set, map clicks add vertices and existing cables ignore clicks. */
    draft: LatLng[] | null;
    pick: { lat: number; lng: number } | null;
    nearest: { lat: number; lng: number; radius: number; results: { lat: number; lng: number }[] } | null;
    center: [number, number];
    zoom: number;
    /** Start with the cable layer unticked; it can still be switched on from the layer menu. */
    hideCables?: boolean;
}>();

const emit = defineEmits<{
    bounds: [bbox: string, center: [number, number], zoom: number];
    select: [kind: MapItemKind, id: number];
    click: [lat: number, lng: number];
    pickMove: [lat: number, lng: number];
    draftMove: [index: number, lat: number, lng: number];
    draftRemove: [index: number];
}>();

const el = ref<HTMLDivElement>();

let map: L.Map;
let resizeObserver: ResizeObserver;
const odcLayer = L.layerGroup();
const odpLayer = L.markerClusterGroup({ disableClusteringAtZoom: 17, maxClusterRadius: 50, showCoverageOnHover: false });
const linkLayer = L.layerGroup();
const cableLayer = L.layerGroup();
// Canvas with a hit tolerance so thin cable lines are easy to tap on a phone.
const cableRenderer = L.canvas({ tolerance: 8 });
const draftLayer = L.layerGroup();
const pickLayer = L.layerGroup();
const nearestLayer = L.layerGroup();
let myLocation: L.CircleMarker | null = null;

const isSelected = (kind: MapItemKind, id: number) => props.selected?.kind === kind && props.selected.id === id;

function renderAssets() {
    odcLayer.clearLayers();
    odpLayer.clearLayers();
    linkLayer.clearLayers();

    for (const odc of props.odcs) {
        L.marker([odc.lat, odc.lng], { icon: odcIcon(odc.status, isSelected('odc', odc.id)), zIndexOffset: 1000 })
            .bindTooltip(`<b>${odc.code}</b><br>${odc.odps_count} ODP`, { direction: 'top', offset: [0, -10] })
            .on('click', () => emit('select', 'odc', odc.id))
            .addTo(odcLayer);
    }

    for (const odp of props.odps) {
        L.marker([odp.lat, odp.lng], { icon: odpIcon(odpColor(odp), isSelected('odp', odp.id)) })
            .bindTooltip(`<b>${odp.code}</b><br>Port ${odp.used_ports}/${odp.capacity} terpakai`, { direction: 'top', offset: [0, -8] })
            .on('click', () => emit('select', 'odp', odp.id))
            .addTo(odpLayer);

        if (odp.odc_latlng) {
            L.polyline([odp.odc_latlng, [odp.lat, odp.lng]], {
                color: '#1d4ed8',
                weight: 1.5,
                opacity: 0.6,
                dashArray: '4 4',
                interactive: false,
            }).addTo(linkLayer);
        }
    }
}

function renderCables() {
    cableLayer.clearLayers();

    for (const cable of props.cables) {
        const selected = isSelected('cable', cable.id);
        const line = L.polyline(cable.path, {
            ...cableStyle(cable, selected),
            interactive: !props.draft,
            renderer: cableRenderer,
            bubblingMouseEvents: false,
        })
            .bindTooltip(
                `<b>${cable.code ?? 'Kabel ' + cableTypes[cable.type]?.label}</b><br>${cable.cores} core · ${formatLength(cable.length_m)}`,
                { sticky: true },
            )
            .on('click', () => emit('select', 'cable', cable.id))
            .addTo(cableLayer);

        if (selected) line.bringToFront();
    }
}

function renderDraft() {
    draftLayer.clearLayers();
    const path = props.draft;
    map.getContainer().style.cursor = path ? 'crosshair' : '';
    renderCables(); // toggles whether existing cables take clicks
    if (!path) return;

    L.polyline(path, { color: '#e11d48', weight: 4, dashArray: '6 6', interactive: false }).addTo(draftLayer);

    path.forEach(([lat, lng], index) => {
        L.marker([lat, lng], { icon: vertexIcon, draggable: true, zIndexOffset: 3000, title: 'Geser untuk memindah, klik untuk menghapus titik' })
            .on('dragend', (e) => {
                const pos = (e.target as L.Marker).getLatLng();
                emit('draftMove', index, pos.lat, pos.lng);
            })
            .on('click', () => emit('draftRemove', index))
            .addTo(draftLayer);
    });
}

function renderPick() {
    pickLayer.clearLayers();
    if (!props.pick) return;

    L.marker([props.pick.lat, props.pick.lng], { icon: pickIcon, draggable: true, zIndexOffset: 2000 })
        .on('dragend', (e) => {
            const { lat, lng } = (e.target as L.Marker).getLatLng();
            emit('pickMove', lat, lng);
        })
        .addTo(pickLayer);
}

function renderNearest() {
    nearestLayer.clearLayers();
    const n = props.nearest;
    if (!n) return;

    L.circle([n.lat, n.lng], { radius: n.radius, color: '#e11d48', weight: 1, fillOpacity: 0.06, interactive: false }).addTo(nearestLayer);
    L.marker([n.lat, n.lng], { icon: pickIcon, interactive: false }).addTo(nearestLayer);

    n.results.forEach((odp, i) => {
        L.polyline(
            [
                [n.lat, n.lng],
                [odp.lat, odp.lng],
            ],
            { color: i === 0 ? '#e11d48' : '#fb7185', weight: i === 0 ? 3 : 1.5, dashArray: i === 0 ? undefined : '3 5', interactive: false },
        ).addTo(nearestLayer);
    });
}

const SNAP_PX = 20;

/**
 * While drawing a cable, a click close to an ODC/ODP counts as clicking it; exact hits are hard on a phone.
 */
function assetNear(point: L.Point): { kind: 'odc' | 'odp'; id: number } | null {
    let best: { kind: 'odc' | 'odp'; id: number; dist: number } | null = null;
    const consider = (kind: 'odc' | 'odp', asset: { id: number; lat: number; lng: number }) => {
        const dist = map.latLngToContainerPoint([asset.lat, asset.lng]).distanceTo(point);
        if (dist <= SNAP_PX && (!best || dist < best.dist)) best = { kind, id: asset.id, dist };
    };
    props.odcs.forEach((odc) => consider('odc', odc));
    props.odps.forEach((odp) => consider('odp', odp));
    return best;
}

function emitBounds() {
    const center = map.getCenter();
    emit('bounds', map.getBounds().pad(0.2).toBBoxString(), [center.lat, center.lng], map.getZoom());
}

onMounted(() => {
    map = L.map(el.value!, { center: props.center, zoom: props.zoom });

    const layers = baseLayers();
    layers.Peta.addTo(map);
    // The straight ODC–ODP guide lines start hidden now that real cable routes are drawn; they stay available in the layer menu.
    [cableLayer, odcLayer, odpLayer, draftLayer, pickLayer, nearestLayer]
        .filter((layer) => !(props.hideCables && layer === cableLayer))
        .forEach((layer) => layer.addTo(map));

    L.control.layers(layers, { Kabel: cableLayer, ODC: odcLayer, ODP: odpLayer, 'Garis ODC–ODP': linkLayer }, { position: 'topright' }).addTo(map);
    L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);

    map.on('moveend', emitBounds);
    map.on('click', (e) => {
        const snapped = props.draft ? assetNear(e.containerPoint) : null;
        if (snapped) emit('select', snapped.kind, snapped.id);
        else emit('click', e.latlng.lat, e.latlng.lng);
    });

    // The sidebar and panels change the container size without a window resize.
    resizeObserver = new ResizeObserver(() => map.invalidateSize());
    resizeObserver.observe(el.value!);

    renderAssets();
    renderCables();
    emitBounds();
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.remove();
});

watch([() => props.odcs, () => props.odps, () => props.selected], renderAssets);
watch([() => props.cables, () => props.selected], renderCables);
watch(() => props.draft, renderDraft, { deep: true });
watch(() => props.pick, renderPick, { deep: true });
watch(() => props.nearest, renderNearest);

defineExpose({
    flyTo(lat: number, lng: number, zoom?: number) {
        map.flyTo([lat, lng], Math.max(zoom ?? map.getZoom(), 17), { duration: 0.6 });
    },
    /** Zooms so the whole circle of `radius` meters around the point is visible. */
    showRadius(lat: number, lng: number, radius: number) {
        map.flyToBounds(L.latLng(lat, lng).toBounds(radius * 2.4), { duration: 0.6 });
    },
    showMyLocation(lat: number, lng: number, accuracy: number) {
        myLocation?.remove();
        myLocation = L.circleMarker([lat, lng], { radius: 8, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 })
            .bindTooltip(`Lokasi saya (±${Math.round(accuracy)} m)`)
            .addTo(map);
        map.flyTo([lat, lng], 18, { duration: 0.6 });
    },
});
</script>

<template>
    <div ref="el" class="absolute inset-0 z-0" />
</template>
