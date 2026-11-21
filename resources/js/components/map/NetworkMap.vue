<script setup lang="ts">
import L, {
    baseLayers,
    cableStyle,
    cableTypes,
    formatLength,
    jointIcon,
    odcIcon,
    odpIcon,
    linkedVertexIcon,
    midpointIcon,
    pickIcon,
    popIcon,
    vertexIcon,
} from '@/lib/leaflet';
import type { CableEndKind, CableLine, CustomerMarker, JointMarker, LatLng, MapItemKind, OdcMarker, OdpMarker, PopMarker } from '@/types';
import { endLabel, jointTypes } from '@/lib/cables';
import type { MapLayerPrefs, MapLayerToggle } from '@/lib/mapLayers';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

type DraftLink = { kind: CableEndKind; id: number };

const props = defineProps<{
    odcs: OdcMarker[];
    odps: OdpMarker[];
    cables: CableLine[];
    selected: { kind: MapItemKind; id: number } | null;
    /** Path of the cable being drawn; while set, map clicks add vertices and existing cables ignore clicks. */
    draft: LatLng[] | null;
    /** Per draft vertex: whether it is attached to a POP/ODC/ODP. */
    draftLinks?: boolean[];
    pick: { lat: number; lng: number } | null;
    nearest: { lat: number; lng: number; radius: number; results: { lat: number; lng: number; route?: [number, number][] | null }[] } | null;
    center: [number, number];
    zoom: number;
    /** Start with the cable layer unticked; it can still be switched on from the layer menu. */
    hideCables?: boolean;
    /** Customers in view; only fetched while the "Pelanggan" layer is switched on. */
    customers?: CustomerMarker[];
    /** The customer open in the panel, shown even while the customer layer is off. */
    customerFocus?: { lat: number; lng: number; odp_latlng: [number, number] | null } | null;
    /** POPs in view; only the network map passes them (adds the "POP" layer). */
    pops?: PopMarker[];
    /** Existing assets a draft changes (orange) or removes (red). */
    changeMarks?: { lat: number | null; lng: number | null; path: LatLng[] | null; action: string; label: string }[];
    /** Joint boxes and rosettes in view; only the network map passes them (adds the "Joint/Roset" layer). */
    joints?: JointMarker[];
    /** Layers ticked when the map opens (the user's Pengaturan → Peta); built-in defaults when absent. */
    layers?: MapLayerPrefs;
}>();

const DEFAULT_ON: Record<MapLayerToggle, boolean> = {
    cables: true,
    pops: true,
    odcs: true,
    odps: true,
    joints: true,
    links: false,
    customers: false,
    cluster: true,
};

const emit = defineEmits<{
    bounds: [bbox: string, center: [number, number], zoom: number];
    select: [kind: MapItemKind, id: number];
    click: [lat: number, lng: number];
    pickMove: [lat: number, lng: number];
    /** A vertex dropped somewhere new; `link` is the asset the user chose to attach it to, if any. */
    draftMove: [index: number, lat: number, lng: number, link: DraftLink | null];
    /** A click near an asset while drawing, after the user chose whether to attach it. */
    draftAdd: [lat: number, lng: number, link: DraftLink | null];
    /** A new vertex placed between two existing ones, ending up at `index`. */
    draftInsert: [index: number, lat: number, lng: number, link: DraftLink | null];
    draftRemove: [index: number];
    customersToggle: [visible: boolean];
}>();

const el = ref<HTMLDivElement>();

let map: L.Map;
let resizeObserver: ResizeObserver;
const popLayer = L.layerGroup();
const jointLayer = L.layerGroup();
const odcLayer = L.layerGroup();
// The "ODP" overlay holds either the clustered or the plain group; the "Kelompokkan ODP" toggle swaps them.
const odpLayer = L.layerGroup();
const odpClusterGroup = L.markerClusterGroup({ disableClusteringAtZoom: 17, maxClusterRadius: 50, showCoverageOnHover: false });
const odpPlainGroup = L.layerGroup();
/** Only a switch in the layer menu: on the map while ODPs are clustered, never holds markers. */
const clusterToggle = L.layerGroup();
const CLUSTER_KEY = 'map.clusterOdp';

function readClusterPreference(): boolean {
    try {
        return localStorage.getItem(CLUSTER_KEY) !== '0';
    } catch {
        return true;
    }
}

// The network map starts from the user's setting; pages without one remember the last choice in this browser.
let clusterOdps = props.layers ? props.layers.cluster : readClusterPreference();
odpLayer.addLayer(clusterOdps ? odpClusterGroup : odpPlainGroup);

function setClusterOdps(on: boolean) {
    if (on === clusterOdps) return;
    clusterOdps = on;
    try {
        localStorage.setItem(CLUSTER_KEY, on ? '1' : '0');
    } catch {
        // Private window or blocked storage: the choice just isn't remembered.
    }
    odpLayer.clearLayers();
    odpLayer.addLayer(on ? odpClusterGroup : odpPlainGroup);
    renderAssets();
}

const odpTarget = () => (clusterOdps ? odpClusterGroup : odpPlainGroup);

const linkLayer = L.layerGroup();
const cableLayer = L.layerGroup();
// Canvas with a hit tolerance so thin cable lines are easy to tap on a phone.
const cableRenderer = L.canvas({ tolerance: 8 });
const draftLayer = L.layerGroup();
/** Marks on existing assets a technician's draft changes or removes. */
const changeLayer = L.layerGroup();
const pickLayer = L.layerGroup();
const nearestLayer = L.layerGroup();
// Customers can number in the thousands: drawn on canvas, and the layer starts switched off.
const customerLayer = L.layerGroup();
// Same canvas as the cables: with two canvases stacked, the top one swallows every click meant for the other.
const customerRenderer = cableRenderer;
const customerFocusLayer = L.layerGroup();
let myLocation: L.CircleMarker | null = null;

const isSelected = (kind: MapItemKind, id: number) => props.selected?.kind === kind && props.selected.id === id;

// Everything typed by people (names, codes) goes through this before it lands in tooltip/popup HTML.
const escapeHtml = (text: string) => text.replace(/[&<>"']/g, (ch) => `&#${ch.charCodeAt(0)};`);

/** Drafts are drawn faded and labelled until they are approved. */
const DRAFT_OPACITY = 0.55;
const draftTag = (item: { draft?: boolean }) => (item.draft ? '<span style="color:#c2410c;font-weight:600">DRAFT</span> · ' : '');

function renderChangeMarks() {
    changeLayer.clearLayers();
    for (const mark of props.changeMarks ?? []) {
        const color = mark.action === 'deleted' ? '#dc2626' : '#ea580c';
        const tip = `<span style="color:${color};font-weight:600">DRAFT ${mark.action === 'deleted' ? 'hapus' : 'ubah'}</span> · ${escapeHtml(mark.label)}`;
        if (mark.path?.length) {
            L.polyline(mark.path, { color, weight: 6, opacity: 0.5, dashArray: '4 8', interactive: false }).addTo(changeLayer);
        }
        if (mark.lat !== null && mark.lng !== null) {
            L.circleMarker([mark.lat, mark.lng], { radius: 16, color, weight: 3, dashArray: '4 4', fill: false, interactive: false })
                .bindTooltip(tip, { permanent: false, direction: 'top', offset: [0, -16] })
                .addTo(changeLayer);
        }
    }
}

function renderAssets() {
    popLayer.clearLayers();
    odcLayer.clearLayers();
    odpClusterGroup.clearLayers();
    odpPlainGroup.clearLayers();
    linkLayer.clearLayers();

    for (const pop of props.pops ?? []) {
        L.marker([pop.lat, pop.lng], { icon: popIcon(isSelected('pop', pop.id)), zIndexOffset: 2000 })
            .bindTooltip(`<b>${escapeHtml(pop.name)}</b><br>${pop.olts_count} OLT · port PON ${pop.ports_used}/${pop.ports_total}`, {
                direction: 'top',
                offset: [0, -14],
            })
            .on('click', (e) => onAssetClick(e, 'pop', pop.id))
            .addTo(popLayer);
    }

    for (const odc of props.odcs) {
        L.marker([odc.lat, odc.lng], {
            icon: odcIcon(odc.status, isSelected('odc', odc.id)),
            zIndexOffset: 1000,
            opacity: odc.draft ? DRAFT_OPACITY : 1,
        })
            .bindTooltip(`${draftTag(odc)}<b>${escapeHtml(odc.code)}</b><br>${odc.odps_count} ODP`, { direction: 'top', offset: [0, -10] })
            .on('click', (e) => onAssetClick(e, 'odc', odc.id))
            .addTo(odcLayer);

        if (odc.pop_latlng) {
            L.polyline([odc.pop_latlng, [odc.lat, odc.lng]], {
                color: '#0f172a',
                weight: 1.5,
                opacity: 0.5,
                dashArray: '2 6',
                interactive: false,
            }).addTo(linkLayer);
        }
    }

    for (const odp of props.odps) {
        L.marker([odp.lat, odp.lng], { icon: odpIcon(odp, isSelected('odp', odp.id)), opacity: odp.draft ? DRAFT_OPACITY : 1 })
            .bindTooltip(`${draftTag(odp)}<b>${escapeHtml(odp.code)}</b><br>Port ${odp.used_ports}/${odp.capacity} terpakai`, {
                direction: 'top',
                offset: [0, -8],
            })
            .on('click', (e) => onAssetClick(e, 'odp', odp.id))
            .addTo(odpTarget());

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

    jointLayer.clearLayers();
    for (const joint of props.joints ?? []) {
        L.marker([joint.lat, joint.lng], {
            icon: jointIcon(joint.type, joint.status, isSelected('joint', joint.id)),
            zIndexOffset: 500,
            opacity: joint.draft ? DRAFT_OPACITY : 1,
        })
            .bindTooltip(`${draftTag(joint)}<b>${escapeHtml(joint.code)}</b><br>${jointTypes[joint.type]?.label ?? 'Joint'}`, {
                direction: 'top',
                offset: [0, -8],
            })
            .on('click', (e) => onAssetClick(e, 'joint', joint.id))
            .addTo(jointLayer);
    }
}

function renderCables() {
    cableLayer.clearLayers();

    for (const cable of props.cables) {
        const selected = isSelected('cable', cable.id);
        const line = L.polyline(cable.path, {
            ...cableStyle(cable, selected),
            ...(cable.draft ? { dashArray: '10 8', opacity: 0.9 } : {}),
            interactive: !props.draft,
            renderer: cableRenderer,
            bubblingMouseEvents: false,
        })
            .bindTooltip(
                `${draftTag(cable)}<b>${escapeHtml(cable.code ?? 'Kabel ' + cableTypes[cable.type]?.label)}</b><br>${cable.cores} core · ${formatLength(cable.length_m)}`,
                { sticky: true },
            )
            .on('click', (e) => onCableClick(e, cable))
            .addTo(cableLayer);

        if (selected) line.bringToFront();
    }
    // Customer dots share the canvas; keep them above the lines so they stay clickable where a cable passes.
    customerLayer.eachLayer((layer) => (layer as L.Path).bringToFront());
}

/** Pixels from the click within which another cable counts as passing through the same spot. */
const CABLE_HIT_PX = 12;

function cablesAt(point: L.Point): CableLine[] {
    return props.cables.filter((cable) => {
        const pts = cable.path.map((p) => map.latLngToContainerPoint(p));
        return pts.some((p, i) => i > 0 && L.LineUtil.pointToSegmentDistance(point, pts[i - 1], p) <= CABLE_HIT_PX);
    });
}

const cableLabel = (cable: CableLine) => cable.code ?? 'Kabel ' + (cableTypes[cable.type]?.label ?? cable.type);

/**
 * Cables often share a route; a click there opens a list of every cable under the pointer instead of picking the topmost one.
 */
function onCableClick(e: L.LeafletMouseEvent, cable: CableLine) {
    const hits = cablesAt(e.containerPoint);
    if (hits.length <= 1) {
        emit('select', 'cable', cable.id);
        return;
    }

    const list = document.createElement('div');
    list.className = 'flex flex-col gap-0.5';
    list.innerHTML = `<div class="mb-1 text-xs font-semibold text-gray-600">${hits.length} kabel di titik ini</div>`;
    const popup = L.popup({ closeButton: true, autoPan: true, maxWidth: 260 });

    for (const hit of hits) {
        const type = cableTypes[hit.type] ?? cableTypes.distribusi;
        const status = hit.status === 'aktif' ? '' : ` · ${hit.status}`;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'flex w-full items-center gap-2 rounded px-1.5 py-1 text-left hover:bg-gray-100';
        button.innerHTML =
            `<span class="h-1.5 w-5 shrink-0 rounded-full" style="background:${hit.status === 'rusak' ? '#7c3aed' : type.color}"></span>` +
            `<span class="min-w-0"><span class="block truncate font-semibold">${escapeHtml(cableLabel(hit))}</span>` +
            `<span class="block text-xs text-gray-500">${type.label} · ${hit.cores} core · ${formatLength(hit.length_m)}${status}</span></span>`;
        button.addEventListener('click', () => {
            map.closePopup(popup);
            emit('select', 'cable', hit.id);
        });
        list.appendChild(button);
    }

    popup.setLatLng(e.latlng).setContent(list).openOn(map);
}

const CUSTOMER_COLOR = '#0d9488';
const customerLabel = (c: { name: string; customer_code: string | null }) => (c.customer_code ? `${c.name} (${c.customer_code})` : c.name);

function renderCustomers() {
    customerLayer.clearLayers();

    for (const c of props.customers ?? []) {
        if (c.odp_latlng) {
            L.polyline([c.odp_latlng, [c.lat, c.lng]], {
                color: '#64748b',
                weight: 1,
                opacity: 0.6,
                interactive: false,
                renderer: customerRenderer,
            }).addTo(customerLayer);
        }
        L.circleMarker([c.lat, c.lng], {
            radius: 5,
            color: '#fff',
            weight: 1.5,
            fillColor: CUSTOMER_COLOR,
            fillOpacity: 1,
            renderer: customerRenderer,
            bubblingMouseEvents: false,
        })
            .bindTooltip(`<b>${escapeHtml(c.name)}</b>${c.customer_code ? '<br>' + escapeHtml(c.customer_code) : ''}`, { direction: 'top' })
            .on('click', (e) => onAssetClick(e, 'customer', c.id))
            .addTo(customerLayer);
    }
}

function renderCustomerFocus() {
    customerFocusLayer.clearLayers();
    const c = props.customerFocus;
    if (!c) return;

    if (c.odp_latlng) {
        L.polyline([c.odp_latlng, [c.lat, c.lng]], { color: CUSTOMER_COLOR, weight: 3, dashArray: '6 4', interactive: false }).addTo(
            customerFocusLayer,
        );
    }
    L.circleMarker([c.lat, c.lng], { radius: 9, color: '#fff', weight: 3, fillColor: CUSTOMER_COLOR, fillOpacity: 1, interactive: false }).addTo(
        customerFocusLayer,
    );
}

function renderDraft() {
    draftLayer.clearLayers();
    const path = props.draft;
    map.getContainer().style.cursor = path ? 'crosshair' : '';
    renderCables(); // toggles whether existing cables take clicks
    if (!path) return;

    L.polyline(path, { color: '#e11d48', weight: 4, dashArray: '6 6', interactive: false }).addTo(draftLayer);

    path.forEach(([lat, lng], index) => {
        const linked = props.draftLinks?.[index] ?? false;
        L.marker([lat, lng], {
            icon: linked ? linkedVertexIcon : vertexIcon,
            draggable: true,
            zIndexOffset: 3000,
            title: linked ? 'Tersambung ke aset · geser untuk melepas, klik untuk menghapus' : 'Geser untuk memindah, klik untuk menghapus titik',
        })
            .on('dragend', (e) => placeDraftPoint((e.target as L.Marker).getLatLng(), (lat, lng, link) => emit('draftMove', index, lat, lng, link)))
            .on('click', () => emit('draftRemove', index))
            .addTo(draftLayer);
    });

    // A faint handle halfway along each segment: drag it (or tap it) to add a point in the middle of the cable.
    path.forEach(([lat, lng], index) => {
        if (index === path.length - 1) return;
        const [nextLat, nextLng] = path[index + 1];
        const middle = L.latLng((lat + nextLat) / 2, (lng + nextLng) / 2);
        L.marker(middle, { icon: midpointIcon, draggable: true, zIndexOffset: 2900, title: 'Geser atau klik untuk menambah titik di sini' })
            .on('dragend', (e) =>
                placeDraftPoint((e.target as L.Marker).getLatLng(), (lat, lng, link) => emit('draftInsert', index + 1, lat, lng, link)),
            )
            .on('click', () => emit('draftInsert', index + 1, middle.lat, middle.lng, null))
            .addTo(draftLayer);
    });
}

/**
 * Settles a dragged draft point: next to an asset it asks whether to attach (closing the question leaves a plain point
 * where it was dropped); elsewhere it is a plain point.
 */
function placeDraftPoint(pos: L.LatLng, apply: (lat: number, lng: number, link: DraftLink | null) => void) {
    const candidates = assetsNear(map.latLngToContainerPoint(pos));
    if (!candidates.length) {
        apply(pos.lat, pos.lng, null);
        return;
    }
    chooseLink(
        pos,
        candidates,
        (link) => apply(pos.lat, pos.lng, link),
        () => apply(pos.lat, pos.lng, null),
    );
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

    // The road path when there is one, else a straight line.
    n.results.forEach((odp, i) => {
        const path: L.LatLngExpression[] = odp.route?.length
            ? odp.route
            : [
                  [n.lat, n.lng],
                  [odp.lat, odp.lng],
              ];
        L.polyline(path, {
            color: i === 0 ? '#e11d48' : '#fb7185',
            weight: i === 0 ? 4 : 1.5,
            dashArray: i === 0 ? undefined : '3 5',
            interactive: false,
        }).addTo(nearestLayer);
    });
}

const SNAP_PX = 20;

type Candidate = DraftLink & { label: string; dist: number };

/**
 * POPs/ODCs/ODPs within reach of a point on screen, nearest first; exact hits on a marker are hard on a phone.
 */
function assetsNear(point: L.Point): Candidate[] {
    const found: Candidate[] = [];
    const consider = (kind: CableEndKind, asset: { id: number; lat: number; lng: number }, code: string) => {
        const dist = map.latLngToContainerPoint([asset.lat, asset.lng]).distanceTo(point);
        if (dist <= SNAP_PX) found.push({ kind, id: asset.id, label: endLabel(kind, code), dist });
    };
    props.pops?.forEach((pop) => consider('pop', pop, pop.name));
    props.odcs.forEach((odc) => consider('odc', odc, odc.code));
    props.odps.forEach((odp) => consider('odp', odp, odp.code));
    props.joints?.forEach((joint) => consider('joint', joint, joint.code));
    // Customers only while their layer is shown: the end of a drop cable.
    if (map.hasLayer(customerLayer)) props.customers?.forEach((c) => consider('customer', c, customerLabel(c)));
    return found.sort((a, b) => a.dist - b.dist);
}

/**
 * Asks whether a cable point near an asset is attached to it (and to which one, when several are close) or is a plain point.
 * `onDismiss` runs when the popup is closed without an answer.
 */
function chooseLink(at: L.LatLng, candidates: Candidate[], onChoose: (link: DraftLink | null) => void, onDismiss?: () => void) {
    let answered = false;
    const popup = L.popup({ closeButton: true, autoPan: true, maxWidth: 280 });
    const box = document.createElement('div');
    box.className = 'flex flex-col gap-1';
    box.innerHTML = `<div class="mb-0.5 text-xs font-semibold text-gray-600">Titik ini dekat aset. Sambungkan kabel?</div>`;

    const option = (html: string, className: string, link: DraftLink | null) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `w-full rounded px-2 py-1.5 text-left text-sm ${className}`;
        button.innerHTML = html;
        button.addEventListener('click', () => {
            answered = true;
            map.closePopup(popup);
            onChoose(link);
        });
        box.appendChild(button);
    };

    for (const c of candidates) {
        option(`Sambungkan ke <b>${escapeHtml(c.label)}</b>`, 'bg-green-600 font-medium text-white hover:bg-green-700', { kind: c.kind, id: c.id });
    }
    option('Titik biasa, tidak disambung', 'border border-gray-300 hover:bg-gray-100', null);

    popup.on('remove', () => {
        if (!answered) onDismiss?.();
    });
    popup.setLatLng(at).setContent(box).openOn(map);
}

/**
 * A click on a POP/ODC/ODP marker selects it, except while drawing a cable: then it asks whether to attach the cable there.
 */
function onAssetClick(e: L.LeafletMouseEvent, kind: CableEndKind, id: number) {
    if (!props.draft) {
        emit('select', kind, id);
        return;
    }
    const at = e.latlng;
    chooseLink(at, assetsNear(e.containerPoint), (link) => emit('draftAdd', at.lat, at.lng, link));
}

function emitBounds() {
    const center = map.getCenter();
    emit('bounds', map.getBounds().pad(0.2).toBBoxString(), [center.lat, center.lng], map.getZoom());
}

onMounted(() => {
    map = L.map(el.value!, { center: props.center, zoom: props.zoom });

    // Which layers start ticked: the user's own defaults (Pengaturan → Peta) where the page passes them.
    const start = (key: MapLayerToggle) => props.layers?.[key] ?? DEFAULT_ON[key];

    const layers = baseLayers();
    (props.layers?.base === 'satelit' ? layers.Satelit : layers.Peta).addTo(map);
    [changeLayer, customerFocusLayer, draftLayer, pickLayer, nearestLayer].forEach((layer) => layer.addTo(map));
    if (!props.hideCables && start('cables')) cableLayer.addTo(map);
    if (start('odcs')) odcLayer.addTo(map);
    if (start('odps')) odpLayer.addTo(map);
    // The straight ODC–ODP guide lines start hidden by default now that real cable routes are drawn.
    if (start('links')) linkLayer.addTo(map);

    const overlays: Record<string, L.Layer> = props.hideCables
        ? { ODC: odcLayer, ODP: odpLayer, 'Garis bantu': linkLayer }
        : { Kabel: cableLayer, ODC: odcLayer, ODP: odpLayer, 'Garis bantu': linkLayer };
    if (props.pops) {
        if (start('pops')) popLayer.addTo(map);
        overlays.POP = popLayer;
    }
    if (props.joints) {
        if (start('joints')) jointLayer.addTo(map);
        overlays['Joint/Roset'] = jointLayer;
    }
    // Only pages that pass customers offer the layer (never the sales coverage page).
    if (props.customers) {
        if (start('customers')) customerLayer.addTo(map);
        overlays.Pelanggan = customerLayer;
    }
    overlays['Kelompokkan ODP'] = clusterToggle;
    if (clusterOdps) clusterToggle.addTo(map);
    L.control.layers(layers, overlays, { position: 'topright' }).addTo(map);
    map.on('overlayadd', (e) => {
        if (e.layer === customerLayer) emit('customersToggle', true);
        if (e.layer === clusterToggle) setClusterOdps(true);
    });
    map.on('overlayremove', (e) => {
        if (e.layer === customerLayer) emit('customersToggle', false);
        if (e.layer === clusterToggle) setClusterOdps(false);
    });
    L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(map);

    map.on('moveend', emitBounds);
    map.on('click', (e) => {
        const candidates = props.draft ? assetsNear(e.containerPoint) : [];
        if (!candidates.length) {
            emit('click', e.latlng.lat, e.latlng.lng);
            return;
        }
        const at = e.latlng;
        chooseLink(at, candidates, (link) => emit('draftAdd', at.lat, at.lng, link));
    });

    // The sidebar and panels change the container size without a window resize.
    resizeObserver = new ResizeObserver(() => map.invalidateSize());
    resizeObserver.observe(el.value!);

    renderAssets();
    renderCables();
    renderChangeMarks();
    emitBounds();
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    map?.remove();
});

watch([() => props.pops, () => props.odcs, () => props.odps, () => props.joints, () => props.selected], renderAssets);
watch([() => props.cables, () => props.selected], renderCables);
watch([() => props.draft, () => props.draftLinks], renderDraft, { deep: true });
watch(() => props.changeMarks, renderChangeMarks);
watch(() => props.pick, renderPick, { deep: true });
watch(() => props.nearest, renderNearest);
watch(() => props.customers, renderCustomers);
watch(() => props.customerFocus, renderCustomerFocus);

defineExpose({
    flyTo(lat: number, lng: number, zoom?: number) {
        const z = Math.max(zoom ?? map.getZoom(), 17);
        let target = L.latLng(lat, lng);
        // On phones the bottom sheet covers the lower half: aim for a point higher up the screen.
        const { x: width, y: height } = map.getSize();
        if (width < 768) target = map.unproject(map.project(target, z).add([0, height * 0.22]), z);
        map.flyTo(target, z, { duration: 0.6 });
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
