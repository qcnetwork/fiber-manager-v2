<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import AssetForm from '@/components/map/AssetForm.vue';
import CableForm from '@/components/map/CableForm.vue';
import CablePanel from '@/components/map/CablePanel.vue';
import CustomerForm from '@/components/map/CustomerForm.vue';
import CustomerPanel from '@/components/map/CustomerPanel.vue';
import MoveCustomerDialog from '@/components/map/MoveCustomerDialog.vue';
import NearestPanel from '@/components/map/NearestPanel.vue';
import NetworkMap from '@/components/map/NetworkMap.vue';
import OdcPanel from '@/components/map/OdcPanel.vue';
import OdpPanel from '@/components/map/OdpPanel.vue';
import OltForm from '@/components/map/OltForm.vue';
import OltPortDialog from '@/components/map/OltPortDialog.vue';
import PopForm from '@/components/map/PopForm.vue';
import PopPanel from '@/components/map/PopPanel.vue';
import PortDialog from '@/components/map/PortDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import AppLayout from '@/layouts/AppLayout.vue';
import { isUrl, parseCoordinates, precisionWarning, resolveLocation } from '@/lib/coordinates';
import { currentPosition, getJson } from '@/lib/http';
import { cableTypes } from '@/lib/leaflet';
import type {
    Area,
    AssetKind,
    BreadcrumbItem,
    Cable,
    CableEndKind,
    CableEndpoint,
    CableLine,
    Customer,
    CustomerMarker,
    LatLng,
    MapItemKind,
    NearestOdp,
    Odc,
    OdcDetail,
    OdcMarker,
    Odp,
    OdpMarker,
    OdpPort,
    Olt,
    Pop,
    PopMarker,
} from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Box, ChevronDown, ChevronUp, CircleDot, Crosshair, Eye, EyeOff, LoaderCircle, Maximize, Minimize, Radar, Search, Spline } from '@lucide/vue';
import { useDebounceFn, useFullscreen } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    areas: Area[];
    can: { edit: boolean; delete: boolean; chooseArea: boolean };
    coverageRadius: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Peta Jaringan', href: '/map' }];

const mapRef = ref<InstanceType<typeof NetworkMap>>();

// ---- Initial view: last position this browser used, else the user's area, else Indonesia.
const VIEW_KEY = 'odp-map-view';
function initialView(): { center: [number, number]; zoom: number } {
    try {
        const saved = JSON.parse(localStorage.getItem(VIEW_KEY) ?? 'null');
        if (saved?.center && saved?.zoom) return saved;
    } catch {
        // ignore unavailable or corrupt storage
    }
    const area = props.areas.find((a) => a.center_lat !== null && a.center_lng !== null);
    return area ? { center: [area.center_lat!, area.center_lng!], zoom: 15 } : { center: [-2.5, 118], zoom: 5 };
}
const view = initialView();

// ---- Markers in view
const pops = ref<PopMarker[]>([]);
const odcs = ref<OdcMarker[]>([]);
const odps = ref<OdpMarker[]>([]);
const cables = ref<CableLine[]>([]);
const truncated = ref(false);
const filters = ref({ area_id: null as number | null, status: '' });
let lastBbox = '';
let featureRequest = 0;

async function loadFeatures(bbox = lastBbox) {
    if (!bbox) return;
    lastBbox = bbox;
    const requestId = ++featureRequest;
    const data = await getJson<{ pops: PopMarker[]; odcs: OdcMarker[]; odps: OdpMarker[]; cables: CableLine[]; truncated: boolean }>(
        route('map.features'),
        { bbox, ...filters.value },
    );
    if (requestId !== featureRequest) return; // a newer pan/zoom already answered
    pops.value = data.pops;
    odcs.value = data.odcs;
    odps.value = data.odps;
    cables.value = data.cables;
    truncated.value = data.truncated;
}
watch(filters, () => loadFeatures(), { deep: true });

// ---- Customers: off by default and only fetched when zoomed in, since there can be thousands.
const CUSTOMER_MIN_ZOOM = 15;
const showCustomers = ref(false);
const customers = ref<CustomerMarker[]>([]);
const customersTruncated = ref(false);
const zoom = ref(view.zoom);
let customerRequest = 0;

async function loadCustomers() {
    const requestId = ++customerRequest;
    if (!showCustomers.value || zoom.value < CUSTOMER_MIN_ZOOM || !lastBbox) {
        customers.value = [];
        return;
    }
    const data = await getJson<{ customers: CustomerMarker[]; truncated: boolean }>(route('map.customers'), {
        bbox: lastBbox,
        area_id: filters.value.area_id,
    });
    if (requestId !== customerRequest) return;
    customers.value = data.customers;
    customersTruncated.value = data.truncated;
}
watch([showCustomers, () => filters.value.area_id], loadCustomers);

const mapCenter = ref<[number, number]>(view.center);

function onBounds(bbox: string, center: [number, number], newZoom: number) {
    loadFeatures(bbox);
    zoom.value = newZoom;
    loadCustomers();
    mapCenter.value = center;
    try {
        localStorage.setItem(VIEW_KEY, JSON.stringify({ center, zoom: newZoom }));
    } catch {
        // storage unavailable
    }
}

// ---- Side panel state
type Mode = 'idle' | 'detail' | 'form' | 'nearest' | 'cable' | 'customer-form' | 'pop-form';
const mode = ref<Mode>('idle');
const selected = ref<{ kind: MapItemKind; id: number } | null>(null);
const popDetail = ref<Pop | null>(null);
const odpDetail = ref<Odp | null>(null);
const odcDetail = ref<OdcDetail | null>(null);
const cableDetail = ref<Cable | null>(null);
const customerDetail = ref<Customer | null>(null);
const loadingDetail = ref(false);

function clearDetails() {
    popDetail.value = null;
    odpDetail.value = null;
    odcDetail.value = null;
    cableDetail.value = null;
    customerDetail.value = null;
}

async function select(kind: MapItemKind, id: number, fly = false) {
    // Reloading the same item (after a change) keeps the panel content until the new data arrives.
    const same = selected.value?.kind === kind && selected.value.id === id;
    mode.value = 'detail';
    selected.value = { kind, id };
    loadingDetail.value = true;
    try {
        if (kind === 'pop') {
            const pop = (await getJson<{ pop: Pop }>(route('pops.show', { pop: id }))).pop;
            if (!same) clearDetails();
            popDetail.value = pop;
            if (fly && pop.lat !== null && pop.lng !== null) mapRef.value?.flyTo(pop.lat, pop.lng);
        } else if (kind === 'customer') {
            const customer = (await getJson<{ customer: Customer }>(route('customers.show', { customer: id }))).customer;
            if (!same) clearDetails();
            customerDetail.value = customer;
            const at = customer.lat !== null && customer.lng !== null ? customer : customer.port?.odp;
            if (fly && at) mapRef.value?.flyTo(at.lat!, at.lng!);
        } else if (kind === 'cable') {
            const cable = (await getJson<{ cable: Cable }>(route('cables.show', { cable: id }))).cable;
            if (!same) clearDetails();
            cableDetail.value = cable;
            const middle = cable.path[Math.floor(cable.path.length / 2)];
            if (fly && middle) mapRef.value?.flyTo(middle[0], middle[1]);
        } else if (kind === 'odp') {
            const odp = (await getJson<{ odp: Odp }>(route('odps.show', { odp: id }))).odp;
            if (!same) clearDetails();
            odpDetail.value = odp;
            if (fly) mapRef.value?.flyTo(odp.lat, odp.lng);
        } else {
            const odc = await getJson<OdcDetail>(route('odcs.show', { odc: id }));
            if (!same) clearDetails();
            odcDetail.value = odc;
            if (fly) mapRef.value?.flyTo(odc.odc.lat, odc.odc.lng);
        }
    } finally {
        loadingDetail.value = false;
    }
}

function closePanel() {
    mode.value = 'idle';
    selected.value = null;
    clearDetails();
    pick.value = null;
    nearest.value = null;
    draft.value = null;
}

// ---- Customers: edit (incl. location) and move to another port
const movingCustomer = ref<Customer | null>(null);

/** Selected customer highlighted on the map, also while the customer layer is off. */
const customerFocus = computed(() => {
    const c = customerDetail.value;
    if (!c || c.lat === null || c.lng === null) return null;
    const odp = c.port?.odp;
    return { lat: c.lat, lng: c.lng, odp_latlng: odp ? ([odp.lat, odp.lng] as [number, number]) : null };
});

function startCustomerEdit() {
    const c = customerDetail.value;
    if (!c) return;
    pick.value = c.lat !== null && c.lng !== null ? { lat: c.lat, lng: c.lng } : null;
    formKey.value++;
    mode.value = 'customer-form';
}

function afterCustomerChange() {
    const c = customerDetail.value;
    pick.value = null;
    loadFeatures(); // port usage of the ODPs changed
    loadCustomers();
    if (c) select('customer', c.id);
}

function onCustomerMoved() {
    movingCustomer.value = null;
    afterCustomerChange();
}

// ---- POP: location, OLTs and their PON ports
const oltFormPopId = ref<number | null>(null);
const editingOlt = ref<Olt | null>(null);
const activeOltPort = ref<{ oltId: number; portId: number } | null>(null);

// Looked up in the latest POP data, so the port dialog shows changes right after saving.
const activeOlt = computed(() => popDetail.value?.olts.find((o) => o.id === activeOltPort.value?.oltId) ?? null);
const activePonPort = computed(() => activeOlt.value?.ports.find((p) => p.id === activeOltPort.value?.portId) ?? null);

function openOltForm(olt: Olt | null) {
    editingOlt.value = olt;
    oltFormPopId.value = popDetail.value?.id ?? null;
}

function afterPopChange() {
    const pop = popDetail.value;
    oltFormPopId.value = null;
    pick.value = null;
    loadFeatures(); // POP port usage and guide lines
    if (pop) select('pop', pop.id);
}

function deleteOlt(olt: Olt) {
    const used = olt.ports.reduce((n, p) => n + p.odcs.length, 0);
    if (!confirm(`Hapus OLT ${olt.code}?${used ? `\n${used} ODC akan kehilangan sambungan ke OLT.` : ''}`)) return;
    router.delete(route('olts.destroy', { olt: olt.id }), { preserveScroll: true, preserveState: true, onSuccess: afterPopChange });
}

function startPopEdit() {
    const pop = popDetail.value;
    if (!pop) return;
    pick.value = pop.lat !== null && pop.lng !== null ? { lat: pop.lat, lng: pop.lng } : { lat: mapCenter.value[0], lng: mapCenter.value[1] };
    formKey.value++;
    mode.value = 'pop-form';
}

function selectOdcFromPop(id: number) {
    activeOltPort.value = null;
    select('odc', id, true);
}

// ---- Create / edit
const formKind = ref<AssetKind>('odp');
const formAsset = ref<Odc | Odp | null>(null);
const formDefaultOdcId = ref<number | null>(null);
const pick = ref<{ lat: number; lng: number } | null>(null);
const formKey = ref(0);

function startCreate(kind: AssetKind, odcId: number | null = null, near?: { lat: number; lng: number }) {
    closePanel();
    formKind.value = kind;
    formAsset.value = null;
    formDefaultOdcId.value = odcId;
    pick.value = near ? { lat: near.lat + 0.0005, lng: near.lng + 0.0005 } : { lat: mapCenter.value[0], lng: mapCenter.value[1] };
    formKey.value++;
    mode.value = 'form';
}

function startEdit() {
    const asset = odpDetail.value ?? odcDetail.value?.odc;
    if (!asset) return;
    formKind.value = odpDetail.value ? 'odp' : 'odc';
    formAsset.value = asset;
    formDefaultOdcId.value = null;
    pick.value = { lat: asset.lat, lng: asset.lng };
    formKey.value++;
    mode.value = 'form';
}

function onFormSaved() {
    const editing = formAsset.value ? { kind: formKind.value, id: formAsset.value.id } : null;
    pick.value = null;
    loadFeatures();
    if (editing) select(editing.kind, editing.id);
    else closePanel();
}

function onFormCancel() {
    const editing = formAsset.value ? { kind: formKind.value, id: formAsset.value.id } : null;
    pick.value = null;
    if (editing) select(editing.kind, editing.id);
    else closePanel();
}

function locate(lat: number, lng: number) {
    pick.value = { lat, lng };
    mapRef.value?.flyTo(lat, lng);
}

function destroy() {
    // Customers are released from their port in the port dialog, and POPs are areas (admin page); neither is deleted here.
    if (!selected.value || selected.value.kind === 'customer' || selected.value.kind === 'pop') return;
    const { kind, id } = selected.value;
    const label = {
        odp: `ODP ${odpDetail.value?.code}?\nSemua data port ODP ini ikut terhapus.`,
        odc: `ODC ${odcDetail.value?.odc.code}?\nODP yang terhubung akan kehilangan ODC induknya.`,
        cable: `kabel ${cableDetail.value?.code ?? ''}?`,
    }[kind];
    if (!confirm(`Hapus ${label}`)) return;

    router.delete(route(`${kind}s.destroy`, { [kind]: id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            closePanel();
            loadFeatures();
        },
    });
}

function refreshSelected() {
    if (selected.value) select(selected.value.kind, selected.value.id);
}

// ---- Ports
const activePort = ref<OdpPort | null>(null);
function onPortSaved() {
    activePort.value = null;
    if (selected.value) select(selected.value.kind, selected.value.id);
    loadFeatures();
}

// ---- Nearest ODP
const nearestPoint = ref<{ lat: number; lng: number } | null>(null);
const nearestResults = ref<NearestOdp[] | null>(null);
const nearestRadius = ref(props.coverageRadius);
const nearestLoading = ref(false);
const nearestError = ref('');
const nearest = ref<{ lat: number; lng: number; radius: number; results: NearestOdp[] } | null>(null);
// Set when the point came from a Google Maps link, which may be off.
const nearestWarning = ref<string | null>(null);

function startNearest() {
    closePanel();
    nearestPoint.value = null;
    nearestResults.value = null;
    nearestError.value = '';
    nearestWarning.value = null;
    mode.value = 'nearest';
}

function nearestAt(lat: number, lng: number, warning: string | null = null) {
    nearestWarning.value = warning;
    nearestPoint.value = { lat, lng };
    return searchNearest();
}

async function nearestFromText(text: string) {
    nearestLoading.value = true;
    nearestError.value = '';
    try {
        const loc = await resolveLocation(text);
        if (!loc) throw new Error('Isi dengan koordinat (mis. -7.0429, 109.5813) atau link Google Maps.');
        mapRef.value?.flyTo(loc.lat, loc.lng);
        await nearestAt(loc.lat, loc.lng, precisionWarning(loc.precision));
    } catch (e) {
        nearestError.value = (e as Error).message;
        nearestLoading.value = false;
    }
}

async function searchNearest() {
    if (!nearestPoint.value) return;
    nearestLoading.value = true;
    nearestError.value = '';
    try {
        const data = await getJson<{ odps: NearestOdp[] }>(route('map.nearest'), { ...nearestPoint.value, radius: nearestRadius.value });
        nearestResults.value = data.odps;
        nearest.value = { ...nearestPoint.value, radius: nearestRadius.value, results: data.odps };
    } catch (e) {
        nearestError.value = (e as Error).message;
    } finally {
        nearestLoading.value = false;
    }
}
watch(nearestRadius, searchNearest);

async function nearestFromGps() {
    nearestLoading.value = true;
    try {
        const pos = await currentPosition();
        mapRef.value?.flyTo(pos.lat, pos.lng);
        await nearestAt(pos.lat, pos.lng);
    } catch (e) {
        nearestError.value = (e as Error).message;
        nearestLoading.value = false;
    }
}

// ---- Cable drawing: the page owns the path so map clicks and the form share it
const draft = ref<LatLng[] | null>(null);
/** Per path vertex: the POP/ODC/ODP it is attached to. The first and last are the cable ends, the rest are pass-through points. */
const draftLinks = ref<(CableEndpoint | null)[]>([]);
const draftFrom = computed(() => draftLinks.value[0] ?? null);
const draftTo = computed(() => (draftLinks.value.length > 1 ? (draftLinks.value[draftLinks.value.length - 1] ?? null) : null));
const draftStops = computed(() =>
    draftLinks.value.flatMap((link, vertex) => (link && vertex > 0 && vertex < draftLinks.value.length - 1 ? [{ vertex, ...link }] : [])),
);
const editingCable = ref<Cable | null>(null);

function startCable(cable: Cable | null = null) {
    closePanel();
    editingCable.value = cable;
    draft.value = cable ? cable.path.map(([lat, lng]) => [lat, lng] as LatLng) : [];
    draftLinks.value = draft.value.map(() => null);
    for (const point of cable?.route ?? []) {
        if (point.kind && point.asset) draftLinks.value[point.vertex] = { type: point.kind, id: point.asset.id, code: point.asset.code };
    }
    formKey.value++;
    mode.value = 'cable';
}

function addCablePoint(lat: number, lng: number) {
    draft.value!.push([lat, lng]);
    draftLinks.value.push(null);
}

/**
 * The location and label of a POP/ODC/ODP a cable point attaches to (a POP is shown by its name; its code is the area code).
 */
function linkTarget(kind: CableEndKind, id: number): { lat: number; lng: number; link: CableEndpoint } | null {
    const pop = kind === 'pop' ? pops.value.find((o) => o.id === id) : undefined;
    const asset = pop ?? (kind === 'odc' ? odcs.value : odps.value).find((o) => o.id === id);
    return asset ? { lat: asset.lat, lng: asset.lng, link: { type: kind, id, code: pop ? pop.name : asset.code } } : null;
}

/**
 * Attaching a POP/ODC/ODP while drawing adds a vertex on it: the start, the end,
 * or—once the line goes on past it—a point the cable passes through.
 */
function attachCablePoint(kind: CableEndKind, id: number) {
    const target = linkTarget(kind, id);
    if (!target || !draft.value) return;

    // Attaching the asset the line already ends at again does nothing.
    const lastLink = draftLinks.value[draftLinks.value.length - 1];
    if (lastLink && lastLink.type === kind && lastLink.id === id) return;

    draft.value.push([target.lat, target.lng]);
    draftLinks.value.push(target.link);
}

/**
 * A click near an asset while drawing, after the user chose to attach it or keep a plain point.
 */
function onDraftAdd(lat: number, lng: number, link: { kind: CableEndKind; id: number } | null) {
    if (link) attachCablePoint(link.kind, link.id);
    else addCablePoint(lat, lng);
}

function moveCablePoint(index: number, lat: number, lng: number, link: { kind: CableEndKind; id: number } | null) {
    const target = link ? linkTarget(link.kind, link.id) : null;
    // Dropped onto an asset the user chose to attach: snap onto it; anywhere else the point is plain.
    draft.value![index] = target ? [target.lat, target.lng] : [lat, lng];
    draftLinks.value[index] = target?.link ?? null;
}

function removeCablePoint(index: number) {
    draft.value!.splice(index, 1);
    draftLinks.value.splice(index, 1);
}

function undoCablePoint() {
    removeCablePoint(draft.value!.length - 1);
}

function clearCable() {
    draft.value = [];
    draftLinks.value = [];
}

function onCableSaved() {
    const editing = editingCable.value;
    draft.value = null;
    loadFeatures();
    if (editing) select('cable', editing.id);
    else closePanel();
}

function onCableCancel() {
    const editing = editingCable.value;
    draft.value = null;
    if (editing) select('cable', editing.id);
    else closePanel();
}

function onMapSelect(kind: MapItemKind, id: number) {
    if (mode.value === 'cable') {
        if (kind === 'pop' || kind === 'odc' || kind === 'odp') attachCablePoint(kind, id);
    } else if (mode.value !== 'customer-form') {
        select(kind, id);
    }
}

// ---- Map clicks depend on what the panel is doing
function onMapClick(lat: number, lng: number) {
    if (mode.value === 'cable') {
        addCablePoint(lat, lng);
    } else if (mode.value === 'form' || mode.value === 'customer-form' || mode.value === 'pop-form') {
        pick.value = { lat, lng };
    } else if (mode.value === 'nearest') {
        nearestAt(lat, lng);
    } else if (mode.value === 'detail') {
        closePanel();
    }
}

// ---- Toolbar: search & my location
const query = ref('');
const searchResults = ref<
    { kind: MapItemKind; id: number; code: string; name: string | null; detail: string | null; lat: number | null; lng: number | null }[]
>([]);
const kindLabels = { pop: 'POP', odc: 'ODC', odp: 'ODP', customer: 'PLG', cable: 'KBL' };
// Pasted coordinates or a Google Maps link are a location, not a code to look up.
const queryIsLocation = computed(() => !!parseCoordinates(query.value) || isUrl(query.value));
const runSearch = useDebounceFn(async () => {
    const q = query.value.trim();
    searchResults.value = q.length < 2 || queryIsLocation.value ? [] : await getJson(route('map.search'), { q });
}, 300);
watch(query, runSearch);

function nearestFromQuery() {
    const text = query.value;
    query.value = '';
    startNearest();
    nearestFromText(text);
}

function pickSearchResult(r: { kind: MapItemKind; id: number }) {
    query.value = '';
    searchResults.value = [];
    select(r.kind, r.id, true);
}

const locatingMe = ref(false);
async function showMyLocation() {
    locatingMe.value = true;
    try {
        const pos = await currentPosition();
        mapRef.value?.showMyLocation(pos.lat, pos.lng, pos.accuracy);
    } catch (e) {
        alert((e as Error).message);
    } finally {
        locatingMe.value = false;
    }
}

const defaultAreaId = computed(() => filters.value.area_id ?? props.areas[0]?.id ?? null);
const panelOpen = computed(() => mode.value !== 'idle');

// Phone bottom sheet: starts at half height so the map stays visible; collapses again when the panel content changes.
const sheetExpanded = ref(false);

// ---- Clear map: hide the toolbar, and/or use the browser's full screen (hides its address bar on phones)
const toolbarHidden = ref(false);
watch(toolbarHidden, (hidden) => {
    if (hidden) {
        query.value = '';
        searchResults.value = [];
    }
});

// The whole page goes full screen, not just the map, so dialogs and panels still show.
const {
    isSupported: canFullscreen,
    isFullscreen,
    toggle: toggleFullscreen,
} = useFullscreen(typeof document !== 'undefined' ? document.documentElement : undefined);
watch([mode, selected], () => (sheetExpanded.value = false));
</script>

<template>
    <Head title="Peta Jaringan" />

    <AppLayout :breadcrumbs="breadcrumbs" map-page>
        <div class="relative min-h-[480px] flex-1">
            <!-- Fixed to the content area so only the panel scrolls, not the page. -->
            <div class="absolute inset-0 flex overflow-hidden md:rounded-b-xl">
                <!-- Map -->
                <div class="relative flex-1">
                    <NetworkMap
                        ref="mapRef"
                        :odcs="odcs"
                        :odps="odps"
                        :cables="cables"
                        :draft="mode === 'cable' ? draft : null"
                        :draft-links="draftLinks.map(Boolean)"
                        :selected="selected"
                        :pick="mode === 'form' || mode === 'customer-form' || mode === 'pop-form' ? pick : null"
                        :nearest="mode === 'nearest' ? nearest : null"
                        :pops="pops"
                        :customers="customers"
                        :customer-focus="mode === 'detail' ? customerFocus : null"
                        :center="view.center"
                        :zoom="view.zoom"
                        @bounds="onBounds"
                        @customers-toggle="(on) => (showCustomers = on)"
                        @select="onMapSelect"
                        @click="onMapClick"
                        @pick-move="(lat, lng) => (pick = { lat, lng })"
                        @draft-move="moveCablePoint"
                        @draft-add="onDraftAdd"
                        @draft-remove="removeCablePoint"
                    />

                    <!-- Phones: no page header, so the menu floats over the map (the zoom buttons are hidden there; pinch instead). -->
                    <SidebarTrigger
                        v-if="!toolbarHidden"
                        class="absolute top-2 left-2 z-10 size-10 rounded-full border bg-background shadow-sm md:hidden"
                    />

                    <!-- Toolbar hidden for a clear map: one button brings it back (bottom right on phones, top left on desktop). -->
                    <Button
                        v-if="toolbarHidden"
                        variant="secondary"
                        class="absolute right-3 bottom-[calc(env(safe-area-inset-bottom)+1.75rem)] z-10 h-11 rounded-full border px-4 shadow-lg md:top-2 md:right-auto md:bottom-auto md:left-14 md:h-10 md:rounded-md md:shadow-sm"
                        :class="panelOpen && 'max-md:hidden'"
                        title="Tampilkan pencarian dan menu"
                        @click="toolbarHidden = false"
                    >
                        <ChevronUp class="size-4 md:hidden" />
                        <Eye class="size-4 max-md:hidden" />
                        Menu
                    </Button>

                    <!-- Toolbar: docked at the bottom on phones (within thumb reach), along the top on desktop -->
                    <div
                        v-show="!toolbarHidden"
                        class="pointer-events-none absolute inset-x-2 bottom-[calc(env(safe-area-inset-bottom)+1.5rem)] z-10 flex flex-col-reverse gap-2 md:inset-x-auto md:top-2 md:right-14 md:bottom-auto md:left-14 md:flex-row md:flex-wrap md:items-start"
                        :class="panelOpen && 'max-md:hidden'"
                    >
                        <div class="pointer-events-auto relative flex w-full gap-2 md:max-w-80">
                            <div class="relative min-w-0 flex-1">
                                <Search class="absolute top-3.5 left-3 size-4 text-muted-foreground md:top-3 md:left-2.5" />
                                <Input
                                    v-model="query"
                                    type="search"
                                    enterkeyhint="search"
                                    placeholder="Kode, nama, alamat, catatan, koordinat, link Maps…"
                                    class="h-11 rounded-full bg-background pl-9 text-base shadow-lg md:h-10 md:rounded-md md:pl-8 md:text-sm md:shadow-sm"
                                    @keydown.enter="queryIsLocation && nearestFromQuery()"
                                />
                            </div>
                            <Button
                                variant="secondary"
                                size="icon"
                                class="size-11 shrink-0 rounded-full border shadow-lg md:size-10 md:rounded-md md:shadow-sm"
                                title="Sembunyikan menu (peta penuh)"
                                @click="toolbarHidden = true"
                            >
                                <ChevronDown class="size-5 md:hidden" />
                                <EyeOff class="size-4 max-md:hidden" />
                            </Button>
                            <!-- Results open upwards on phones, so they stay above the keyboard and the thumb. -->
                            <div
                                v-if="queryIsLocation"
                                class="absolute bottom-full mb-1 w-full overflow-hidden rounded-md border bg-background text-sm shadow-lg md:top-full md:bottom-auto md:mt-1 md:mb-0"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted"
                                    @click="nearestFromQuery"
                                >
                                    <Radar class="size-4 shrink-0" /> Cari ODP terdekat dari lokasi ini
                                </button>
                            </div>
                            <ul
                                v-if="searchResults.length"
                                class="absolute bottom-full mb-1 max-h-[50svh] w-full overflow-y-auto rounded-md border bg-background text-sm shadow-lg md:top-full md:bottom-auto md:mt-1 md:mb-0"
                            >
                                <li v-for="r in searchResults" :key="r.kind + r.id">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2.5 text-left hover:bg-muted md:py-2"
                                        @click="pickSearchResult(r)"
                                    >
                                        <span
                                            class="rounded px-1.5 text-[10px] font-bold uppercase"
                                            :class="r.kind === 'customer' ? 'bg-teal-600 text-white' : 'bg-muted'"
                                            >{{ kindLabels[r.kind] }}</span
                                        >
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate">{{ r.code }}</span>
                                            <span v-if="r.kind === 'customer' && r.name" class="block truncate text-xs text-muted-foreground">{{
                                                r.name
                                            }}</span>
                                            <span v-if="r.detail" class="block truncate text-xs text-muted-foreground italic">{{ r.detail }}</span>
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- One swipeable row on phones; wraps on desktop. -->
                        <div
                            class="pointer-events-auto -mx-2 flex scrollbar-none gap-2 overflow-x-auto px-2 pb-1 *:shrink-0 md:mx-0 md:flex-wrap md:overflow-visible md:px-0 md:pb-0"
                        >
                            <Button size="sm" variant="secondary" class="h-10 border shadow-sm" @click="startNearest"
                                ><Radar class="size-4" /> Terdekat</Button
                            >
                            <Button
                                size="sm"
                                variant="secondary"
                                class="h-10 border shadow-sm md:w-10 md:px-0"
                                title="Lokasi saya"
                                :disabled="locatingMe"
                                @click="showMyLocation"
                            >
                                <LoaderCircle v-if="locatingMe" class="size-4 animate-spin" />
                                <Crosshair v-else class="size-4" />
                                <span class="md:hidden">Lokasi saya</span>
                            </Button>
                            <Button
                                v-if="canFullscreen"
                                size="sm"
                                variant="secondary"
                                class="h-10 border shadow-sm md:w-10 md:px-0"
                                :title="isFullscreen ? 'Keluar dari layar penuh' : 'Layar penuh'"
                                @click="toggleFullscreen()"
                            >
                                <Minimize v-if="isFullscreen" class="size-4" />
                                <Maximize v-else class="size-4" />
                                <span class="md:hidden">{{ isFullscreen ? 'Keluar layar penuh' : 'Layar penuh' }}</span>
                            </Button>
                            <template v-if="can.edit">
                                <Button size="sm" class="h-10 shadow-sm" @click="startCreate('odc')"><Box class="size-4" /> ODC</Button>
                                <Button size="sm" class="h-10 shadow-sm" @click="startCreate('odp')"><CircleDot class="size-4" /> ODP</Button>
                                <Button size="sm" class="h-10 shadow-sm" @click="startCable()"><Spline class="size-4" /> Kabel</Button>
                            </template>
                            <NativeSelect v-if="areas.length > 1" v-model="filters.area_id" class="h-10 w-auto shadow-sm">
                                <option :value="null">Semua area</option>
                                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }}</option>
                            </NativeSelect>
                            <NativeSelect v-model="filters.status" class="h-10 w-auto shadow-sm">
                                <option value="">Semua status</option>
                                <option value="aktif">Aktif</option>
                                <option value="rencana">Rencana</option>
                                <option value="rusak">Rusak</option>
                            </NativeSelect>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div
                        v-show="!toolbarHidden"
                        class="absolute right-2 bottom-6 z-10 hidden rounded-md border bg-background/90 p-2 text-xs shadow-sm sm:block"
                    >
                        <div class="mb-1 font-semibold">Okupansi ODP</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-green-600" />&lt; 50%</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-amber-500" />≥ 50%</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-red-600" />Penuh</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-gray-400" />Rencana</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-violet-600" />Rusak</div>
                        <div class="mt-1 flex items-center gap-1.5 text-muted-foreground">
                            <span class="inline-block size-3.5"><span class="odp-pin" style="--c: #f59e0b; --p: 60%" /></span>
                            Lingkar = port terpakai
                        </div>
                        <div class="mt-2 mb-1 font-semibold">Kabel</div>
                        <div v-for="(t, key) in cableTypes" :key="key">
                            <span class="mr-1 inline-block h-1 w-4 rounded-full align-middle" :style="{ background: t.color }" />{{ t.label }}
                        </div>
                    </div>

                    <div
                        v-if="truncated"
                        :class="!panelOpen && !toolbarHidden && 'max-md:bottom-32'"
                        class="absolute bottom-6 left-1/2 z-10 w-max max-w-[90%] -translate-x-1/2 rounded-md bg-amber-100 px-3 py-1 text-xs text-amber-900 shadow-sm"
                    >
                        Terlalu banyak titik di layar — perbesar peta untuk melihat semuanya.
                    </div>
                    <div
                        v-else-if="showCustomers && (zoom < CUSTOMER_MIN_ZOOM || customersTruncated)"
                        :class="!panelOpen && !toolbarHidden && 'max-md:bottom-32'"
                        class="absolute bottom-6 left-1/2 z-10 w-max max-w-[90%] -translate-x-1/2 rounded-md bg-teal-50 px-3 py-1 text-xs text-teal-900 shadow-sm"
                    >
                        {{
                            zoom < CUSTOMER_MIN_ZOOM
                                ? 'Perbesar peta untuk menampilkan pelanggan.'
                                : 'Terlalu banyak pelanggan di layar — sebagian tidak ditampilkan, perbesar peta.'
                        }}
                    </div>
                </div>

                <!-- Panel: bottom sheet on phones (half height so the map stays usable, tap the handle to expand), right column on desktop -->
                <aside
                    v-if="panelOpen"
                    class="absolute inset-x-0 bottom-0 z-20 overflow-y-auto overscroll-contain rounded-t-2xl border-t bg-background px-4 pb-[calc(env(safe-area-inset-bottom)+1rem)] shadow-2xl transition-[max-height] duration-200 md:static md:max-h-none md:w-96 md:rounded-none md:border-t-0 md:border-l md:p-4 md:shadow-none"
                    :class="sheetExpanded ? 'max-h-[88%]' : 'max-h-[45%]'"
                >
                    <button
                        type="button"
                        class="sticky top-0 z-10 -mx-4 mb-2 flex w-[calc(100%+2rem)] justify-center bg-background pt-2.5 pb-2 md:hidden"
                        :title="sheetExpanded ? 'Perkecil panel' : 'Perbesar panel'"
                        @click="sheetExpanded = !sheetExpanded"
                    >
                        <span class="h-1.5 w-10 rounded-full bg-muted-foreground/40" />
                    </button>
                    <div
                        v-if="mode === 'detail' && loadingDetail && !popDetail && !odpDetail && !odcDetail && !cableDetail && !customerDetail"
                        class="flex items-center gap-2 text-sm text-muted-foreground"
                    >
                        <LoaderCircle class="size-4 animate-spin" /> Memuat…
                    </div>

                    <OdpPanel
                        v-else-if="mode === 'detail' && odpDetail"
                        :odp="odpDetail"
                        :can-edit="can.edit"
                        :can-delete="can.delete"
                        @close="closePanel"
                        @edit="startEdit"
                        @delete="destroy"
                        @open-port="(port) => (activePort = port)"
                        @refresh="refreshSelected"
                        @select-odc="(id) => select('odc', id, true)"
                        @open-customer="(id) => select('customer', id, true)"
                    />

                    <CustomerPanel
                        v-else-if="mode === 'detail' && customerDetail"
                        :customer="customerDetail"
                        :can-edit="can.edit"
                        :can-delete="can.delete"
                        @close="closePanel"
                        @edit="startCustomerEdit"
                        @move="movingCustomer = customerDetail"
                        @refresh="refreshSelected"
                        @select-odp="(id) => select('odp', id, true)"
                    />

                    <CustomerForm
                        v-else-if="mode === 'customer-form' && customerDetail"
                        :key="formKey"
                        :customer="customerDetail"
                        :location="pick"
                        @locate="locate"
                        @clear-location="pick = null"
                        @saved="afterCustomerChange"
                        @cancel="afterCustomerChange"
                    />

                    <OdcPanel
                        v-else-if="mode === 'detail' && odcDetail"
                        :detail="odcDetail"
                        :can-edit="can.edit"
                        :can-delete="can.delete"
                        @close="closePanel"
                        @edit="startEdit"
                        @delete="destroy"
                        @add-odp="startCreate('odp', odcDetail.odc.id, odcDetail.odc)"
                        @refresh="refreshSelected"
                        @select-odp="(id) => select('odp', id, true)"
                        @select-pop="(id) => select('pop', id, true)"
                    />

                    <PopPanel
                        v-else-if="mode === 'detail' && popDetail"
                        :pop="popDetail"
                        :can-edit="can.edit"
                        :can-manage="can.delete"
                        @close="closePanel"
                        @refresh="refreshSelected"
                        @edit-location="startPopEdit"
                        @add-olt="openOltForm(null)"
                        @edit-olt="openOltForm"
                        @delete-olt="deleteOlt"
                        @open-port="(olt, port) => (activeOltPort = { oltId: olt.id, portId: port.id })"
                        @select-odc="selectOdcFromPop"
                    />

                    <PopForm
                        v-else-if="mode === 'pop-form' && popDetail && pick"
                        :key="formKey"
                        :pop="popDetail"
                        :location="pick"
                        @locate="locate"
                        @saved="afterPopChange"
                        @cancel="afterPopChange"
                    />

                    <CablePanel
                        v-else-if="mode === 'detail' && cableDetail"
                        :cable="cableDetail"
                        :can-edit="can.edit"
                        :can-delete="can.delete"
                        @close="closePanel"
                        @edit="startCable(cableDetail)"
                        @delete="destroy"
                        @select-asset="(kind, id) => select(kind, id, true)"
                    />

                    <CableForm
                        v-else-if="mode === 'cable' && draft"
                        :key="formKey"
                        :cable="editingCable"
                        :path="draft"
                        :from="draftFrom"
                        :to="draftTo"
                        :stops="draftStops"
                        :areas="areas"
                        :can-choose-area="can.chooseArea"
                        :default-area-id="editingCable?.area_id ?? defaultAreaId"
                        @undo="undoCablePoint"
                        @clear="clearCable"
                        @saved="onCableSaved"
                        @cancel="onCableCancel"
                    />

                    <AssetForm
                        v-else-if="mode === 'form' && pick"
                        :key="formKey"
                        :kind="formKind"
                        :asset="formAsset"
                        :location="pick"
                        :areas="areas"
                        :can-choose-area="can.chooseArea"
                        :default-area-id="formAsset?.area_id ?? defaultAreaId"
                        :default-odc-id="formDefaultOdcId"
                        @locate="locate"
                        @saved="onFormSaved"
                        @cancel="onFormCancel"
                    />

                    <NearestPanel
                        v-else-if="mode === 'nearest'"
                        v-model:radius="nearestRadius"
                        :point="nearestPoint"
                        :results="nearestResults"
                        :loading="nearestLoading"
                        :error="nearestError"
                        :warning="nearestWarning"
                        @use-gps="nearestFromGps"
                        @locate="nearestFromText"
                        @select="(odp) => select('odp', odp.id, true)"
                        @close="closePanel"
                    />
                </aside>
            </div>
        </div>

        <PortDialog :port="activePort" :odp-code="odpDetail?.code ?? ''" @saved="onPortSaved" @close="activePort = null" />
        <MoveCustomerDialog :customer="movingCustomer" @moved="onCustomerMoved" @close="movingCustomer = null" />
        <OltForm :pop-id="oltFormPopId" :olt="editingOlt" @saved="afterPopChange" @close="oltFormPopId = null" />
        <OltPortDialog
            :pop="popDetail"
            :olt="activeOlt"
            :port="activePonPort"
            :can-edit="can.edit"
            :can-manage="can.delete"
            @changed="afterPopChange"
            @select-odc="selectOdcFromPop"
            @close="activeOltPort = null"
        />
    </AppLayout>
</template>
