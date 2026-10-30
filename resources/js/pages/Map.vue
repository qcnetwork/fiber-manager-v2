<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import AssetForm from '@/components/map/AssetForm.vue';
import CableForm from '@/components/map/CableForm.vue';
import CablePanel from '@/components/map/CablePanel.vue';
import NearestPanel from '@/components/map/NearestPanel.vue';
import NetworkMap from '@/components/map/NetworkMap.vue';
import OdcPanel from '@/components/map/OdcPanel.vue';
import OdpPanel from '@/components/map/OdpPanel.vue';
import PortDialog from '@/components/map/PortDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { isUrl, parseCoordinates, precisionWarning, resolveLocation } from '@/lib/coordinates';
import { currentPosition, getJson } from '@/lib/http';
import { cableTypes } from '@/lib/leaflet';
import type {
    Area,
    AssetKind,
    BreadcrumbItem,
    Cable,
    CableEndpoint,
    CableLine,
    LatLng,
    MapItemKind,
    NearestOdp,
    Odc,
    OdcDetail,
    OdcMarker,
    Odp,
    OdpMarker,
    OdpPort,
} from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Box, CircleDot, Crosshair, LoaderCircle, Radar, Search, Spline } from 'lucide-vue-next';
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
    const data = await getJson<{ odcs: OdcMarker[]; odps: OdpMarker[]; cables: CableLine[]; truncated: boolean }>(route('map.features'), {
        bbox,
        ...filters.value,
    });
    if (requestId !== featureRequest) return; // a newer pan/zoom already answered
    odcs.value = data.odcs;
    odps.value = data.odps;
    cables.value = data.cables;
    truncated.value = data.truncated;
}
watch(filters, () => loadFeatures(), { deep: true });

const mapCenter = ref<[number, number]>(view.center);

function onBounds(bbox: string, center: [number, number], zoom: number) {
    loadFeatures(bbox);
    mapCenter.value = center;
    try {
        localStorage.setItem(VIEW_KEY, JSON.stringify({ center, zoom }));
    } catch {
        // storage unavailable
    }
}

// ---- Side panel state
type Mode = 'idle' | 'detail' | 'form' | 'nearest' | 'cable';
const mode = ref<Mode>('idle');
const selected = ref<{ kind: MapItemKind; id: number } | null>(null);
const odpDetail = ref<Odp | null>(null);
const odcDetail = ref<OdcDetail | null>(null);
const cableDetail = ref<Cable | null>(null);
const loadingDetail = ref(false);

async function select(kind: MapItemKind, id: number, fly = false) {
    mode.value = 'detail';
    selected.value = { kind, id };
    loadingDetail.value = true;
    try {
        if (kind === 'cable') {
            odpDetail.value = null;
            odcDetail.value = null;
            cableDetail.value = (await getJson<{ cable: Cable }>(route('cables.show', { cable: id }))).cable;
            return;
        }
        cableDetail.value = null;
        if (kind === 'odp') {
            odcDetail.value = null;
            odpDetail.value = (await getJson<{ odp: Odp }>(route('odps.show', { odp: id }))).odp;
            if (fly) mapRef.value?.flyTo(odpDetail.value.lat, odpDetail.value.lng);
        } else {
            odpDetail.value = null;
            odcDetail.value = await getJson<OdcDetail>(route('odcs.show', { odc: id }));
            if (fly) mapRef.value?.flyTo(odcDetail.value.odc.lat, odcDetail.value.odc.lng);
        }
    } finally {
        loadingDetail.value = false;
    }
}

function closePanel() {
    mode.value = 'idle';
    selected.value = null;
    odpDetail.value = null;
    odcDetail.value = null;
    cableDetail.value = null;
    pick.value = null;
    nearest.value = null;
    draft.value = null;
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
    if (!selected.value) return;
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
const draftFrom = ref<CableEndpoint | null>(null);
const draftTo = ref<CableEndpoint | null>(null);
const editingCable = ref<Cable | null>(null);

function startCable(cable: Cable | null = null) {
    closePanel();
    editingCable.value = cable;
    draft.value = cable ? cable.path.map(([lat, lng]) => [lat, lng] as LatLng) : [];
    draftFrom.value = cable?.from && cable.from_type ? { type: cable.from_type, id: cable.from.id, code: cable.from.code } : null;
    draftTo.value = cable?.to && cable.to_type ? { type: cable.to_type, id: cable.to.id, code: cable.to.code } : null;
    formKey.value++;
    mode.value = 'cable';
}

function addCablePoint(lat: number, lng: number) {
    draft.value!.push([lat, lng]);
    draftTo.value = null; // the line now continues past whatever it ended at
}

/**
 * Clicking an ODC/ODP while drawing attaches the cable end to it.
 */
function attachCableEnd(kind: AssetKind, id: number) {
    const asset = kind === 'odc' ? odcs.value.find((o) => o.id === id) : odps.value.find((o) => o.id === id);
    if (!asset || !draft.value) return;

    const end = { type: kind, id, code: asset.code };
    draft.value.push([asset.lat, asset.lng]);
    if (draft.value.length === 1) draftFrom.value = end;
    else draftTo.value = end;
}

function moveCablePoint(index: number, lat: number, lng: number) {
    draft.value![index] = [lat, lng];
    // Dragging an attached end off its asset detaches it.
    if (index === 0) draftFrom.value = null;
    if (index === draft.value!.length - 1) draftTo.value = null;
}

function removeCablePoint(index: number) {
    const last = draft.value!.length - 1;
    draft.value!.splice(index, 1);
    if (index === 0) draftFrom.value = null;
    if (index === last) draftTo.value = null;
}

function undoCablePoint() {
    removeCablePoint(draft.value!.length - 1);
}

function clearCable() {
    draft.value = [];
    draftFrom.value = null;
    draftTo.value = null;
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
    if (mode.value === 'cable' && kind !== 'cable') attachCableEnd(kind, id);
    else select(kind, id);
}

// ---- Map clicks depend on what the panel is doing
function onMapClick(lat: number, lng: number) {
    if (mode.value === 'cable') {
        addCablePoint(lat, lng);
    } else if (mode.value === 'form') {
        pick.value = { lat, lng };
    } else if (mode.value === 'nearest') {
        nearestAt(lat, lng);
    } else if (mode.value === 'detail') {
        closePanel();
    }
}

// ---- Toolbar: search & my location
const query = ref('');
const searchResults = ref<{ kind: AssetKind; id: number; code: string; name: string | null; lat: number; lng: number }[]>([]);
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

function pickSearchResult(r: { kind: AssetKind; id: number }) {
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
</script>

<template>
    <Head title="Peta Jaringan" />

    <AppLayout :breadcrumbs="breadcrumbs">
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
                        :selected="selected"
                        :pick="mode === 'form' ? pick : null"
                        :nearest="mode === 'nearest' ? nearest : null"
                        :center="view.center"
                        :zoom="view.zoom"
                        @bounds="onBounds"
                        @select="onMapSelect"
                        @click="onMapClick"
                        @pick-move="(lat, lng) => (pick = { lat, lng })"
                        @draft-move="moveCablePoint"
                        @draft-remove="removeCablePoint"
                    />

                    <!-- Toolbar -->
                    <div class="pointer-events-none absolute left-14 right-14 top-2 z-10 flex flex-wrap items-start gap-2">
                        <div class="pointer-events-auto relative w-full max-w-64">
                            <Search class="absolute left-2.5 top-3 size-4 text-muted-foreground" />
                            <Input
                                v-model="query"
                                placeholder="Kode ODC/ODP, koordinat, atau link Maps…"
                                class="h-10 bg-background pl-8 shadow"
                                @keydown.enter="queryIsLocation && nearestFromQuery()"
                            />
                            <div
                                v-if="queryIsLocation"
                                class="absolute mt-1 w-full overflow-hidden rounded-md border bg-background text-sm shadow-lg"
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
                                class="absolute mt-1 w-full overflow-hidden rounded-md border bg-background text-sm shadow-lg"
                            >
                                <li v-for="r in searchResults" :key="r.kind + r.id">
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-muted"
                                        @click="pickSearchResult(r)"
                                    >
                                        <span class="rounded bg-muted px-1.5 text-[10px] font-bold uppercase">{{ r.kind }}</span>
                                        <span class="truncate">{{ r.code }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <div class="pointer-events-auto flex flex-wrap gap-2">
                            <template v-if="can.edit">
                                <Button size="sm" class="h-10 shadow" @click="startCreate('odc')"><Box class="size-4" /> ODC</Button>
                                <Button size="sm" class="h-10 shadow" @click="startCreate('odp')"><CircleDot class="size-4" /> ODP</Button>
                                <Button size="sm" class="h-10 shadow" @click="startCable()"><Spline class="size-4" /> Kabel</Button>
                            </template>
                            <Button size="sm" variant="secondary" class="h-10 border shadow" @click="startNearest"
                                ><Radar class="size-4" /> Terdekat</Button
                            >
                            <Button
                                size="icon"
                                variant="secondary"
                                class="size-10 border shadow"
                                title="Lokasi saya"
                                :disabled="locatingMe"
                                @click="showMyLocation"
                            >
                                <LoaderCircle v-if="locatingMe" class="size-4 animate-spin" />
                                <Crosshair v-else class="size-4" />
                            </Button>
                            <NativeSelect v-if="areas.length > 1" v-model="filters.area_id" class="h-10 w-auto shadow">
                                <option :value="null">Semua area</option>
                                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }}</option>
                            </NativeSelect>
                            <NativeSelect v-model="filters.status" class="h-10 w-auto shadow">
                                <option value="">Semua status</option>
                                <option value="aktif">Aktif</option>
                                <option value="rencana">Rencana</option>
                                <option value="rusak">Rusak</option>
                            </NativeSelect>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="absolute bottom-6 right-2 z-10 hidden rounded-md border bg-background/90 p-2 text-xs shadow sm:block">
                        <div class="mb-1 font-semibold">Okupansi ODP</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-green-600" />&lt; 50%</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-amber-500" />≥ 50%</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-red-600" />Penuh</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-gray-400" />Rencana</div>
                        <div><span class="mr-1 inline-block size-2.5 rounded-full bg-violet-600" />Rusak</div>
                        <div class="mb-1 mt-2 font-semibold">Kabel</div>
                        <div v-for="(t, key) in cableTypes" :key="key">
                            <span class="mr-1 inline-block h-1 w-4 rounded-full align-middle" :style="{ background: t.color }" />{{ t.label }}
                        </div>
                    </div>

                    <div
                        v-if="truncated"
                        class="absolute bottom-6 left-1/2 z-10 -translate-x-1/2 rounded-md bg-amber-100 px-3 py-1 text-xs text-amber-900 shadow"
                    >
                        Terlalu banyak titik di layar — perbesar peta untuk melihat semuanya.
                    </div>
                </div>

                <!-- Panel: bottom sheet on phones, right column on desktop -->
                <aside
                    v-if="panelOpen"
                    class="absolute inset-x-0 bottom-0 z-20 max-h-[65%] overflow-y-auto rounded-t-xl border-t bg-background p-4 shadow-2xl md:static md:max-h-none md:w-96 md:rounded-none md:border-l md:border-t-0 md:shadow-none"
                >
                    <div
                        v-if="mode === 'detail' && loadingDetail && !odpDetail && !odcDetail && !cableDetail"
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
    </AppLayout>
</template>
