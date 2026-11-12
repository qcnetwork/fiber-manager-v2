<script setup lang="ts">
import NetworkMap from '@/components/map/NetworkMap.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatLength } from '@/lib/cables';
import { geocode, precisionWarning, resolveLocation, type GeocodeResult, type LocationPrecision } from '@/lib/coordinates';
import { currentPosition, getJson } from '@/lib/http';
import type { Area, BreadcrumbItem, CableLine, OdcMarker, OdpMarker, SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronUp,
    CircleAlert,
    CircleCheck,
    CircleX,
    Copy,
    Crosshair,
    ExternalLink,
    LoaderCircle,
    MapPin,
    Maximize,
    Minimize,
    PanelLeftClose,
    PanelLeftOpen,
    Pencil,
    Search,
    TriangleAlert,
} from '@lucide/vue';
import { useFullscreen } from '@vueuse/core';
import { computed, ref, watch } from 'vue';

interface CoverageOdp {
    id: number;
    code: string;
    lat: number;
    lng: number;
    capacity: number;
    distance: number;
    used_ports: number;
    free_ports: number;
    status: string;
    /** Set when `distance` follows the roads. */
    straight_distance: number | null;
    /** Road path [lat, lng] to the highlighted ODP. */
    route: [number, number][] | null;
}

interface CoverageResult {
    status: 'tercover' | 'penuh' | 'tidak_tercover';
    radius: number;
    /** 'jalan': distances follow the roads; 'lurus': straight lines (routing off or unavailable). */
    distance_mode: 'jalan' | 'lurus';
    available: CoverageOdp[];
    full: CoverageOdp[];
    planned: CoverageOdp[];
    nearest_outside: CoverageOdp | null;
}

const props = defineProps<{
    radius: number;
    radiusLimits: [number, number];
    routingAvailable: boolean;
    canEditRadius: boolean;
    areas: Area[];
}>();

// ---- Road distances on/off: off is faster (no wait for the routing server). Remembered per browser.
const ROAD_KEY = 'odp-coverage-road';
function initialRoad(): boolean {
    try {
        return localStorage.getItem(ROAD_KEY) !== '0';
    } catch {
        return true;
    }
}
const roadOn = ref(initialRoad());
watch(roadOn, (on) => {
    try {
        localStorage.setItem(ROAD_KEY, on ? '1' : '0');
    } catch {
        // storage unavailable
    }
    recheck();
});

// ---- Super admin: change the coverage radius for everyone
const editingRadius = ref(false);
const radiusForm = useForm({ radius: props.radius });
function editRadius() {
    radiusForm.radius = props.radius;
    radiusForm.clearErrors();
    editingRadius.value = true;
}
function saveRadius() {
    radiusForm.put(route('admin.coverage-radius.update'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            editingRadius.value = false;
            recheck();
        },
    });
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cek Coverage', href: '/coverage' }];
const mapRef = ref<InstanceType<typeof NetworkMap>>();
// Sales only needs ODC/ODP to judge coverage; they get no cable routes at all.
const isSales = usePage<SharedData>().props.auth.user.role === 'sales';

// ---- Map: start where the user last looked, else their area
const VIEW_KEY = 'odp-coverage-view';
function initialView(): { center: [number, number]; zoom: number } {
    try {
        const saved = JSON.parse(localStorage.getItem(VIEW_KEY) ?? 'null');
        if (saved?.center && saved?.zoom) return saved;
    } catch {
        // storage unavailable
    }
    const area = props.areas.find((a) => a.center_lat !== null && a.center_lng !== null);
    return area ? { center: [area.center_lat!, area.center_lng!], zoom: 14 } : { center: [-2.5, 118], zoom: 5 };
}
const view = initialView();

const odcs = ref<OdcMarker[]>([]);
const odps = ref<OdpMarker[]>([]);
const cables = ref<CableLine[]>([]);
let lastBbox = '';
let featureRequest = 0;

async function onBounds(bbox: string, center: [number, number], zoom: number) {
    lastBbox = bbox;
    try {
        localStorage.setItem(VIEW_KEY, JSON.stringify({ center, zoom }));
    } catch {
        // storage unavailable
    }
    const requestId = ++featureRequest;
    const data = await getJson<{ odcs: OdcMarker[]; odps: OdpMarker[]; cables: CableLine[] }>(route('map.features'), { bbox, status: 'aktif' });
    if (requestId !== featureRequest) return;
    odcs.value = data.odcs;
    odps.value = data.odps;
    cables.value = data.cables;
}

// ---- Checking a point
const point = ref<{ lat: number; lng: number } | null>(null);
const result = ref<CoverageResult | null>(null);
const checking = ref(false);
const error = ref('');

// Where the checked point came from; links get an accuracy warning.
const precision = ref<LocationPrecision>('exact');
const warning = computed(() => (point.value ? precisionWarning(precision.value) : null));

// ---- Clear map: hide the panel, and/or the browser's full screen (whole page, so the panel still shows)
const panelHidden = ref(false);
const fullscreen = useFullscreen(typeof document !== 'undefined' ? document.documentElement : undefined);

async function check(lat: number, lng: number, from: LocationPrecision = 'exact') {
    panelHidden.value = false; // tapping the map while the panel is hidden shows the result
    precision.value = from;
    point.value = { lat, lng };
    geoResults.value = [];
    checking.value = true;
    error.value = '';
    try {
        const road = props.routingAvailable && roadOn.value ? 1 : 0;
        result.value = await getJson<CoverageResult>(route('coverage.check'), { lat, lng, road });
        mapRef.value?.showRadius(lat, lng, props.radius);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        checking.value = false;
    }
}

// Checks the same point again after a setting changed.
function recheck() {
    if (point.value) check(point.value.lat, point.value.lng, precision.value);
}

const mapOverlay = computed(() => {
    if (!point.value || !result.value) return null;
    const lines = result.value.available.length ? result.value.available : result.value.full;
    return { ...point.value, radius: result.value.radius, results: lines };
});

// ---- Input: coordinates, Google Maps link, or address
const query = ref('');
const geoResults = ref<GeocodeResult[]>([]);
const searching = ref(false);

async function search() {
    const text = query.value.trim();
    if (!text) return;
    error.value = '';
    geoResults.value = [];

    searching.value = true;
    try {
        const coords = await resolveLocation(text);
        if (coords) {
            searching.value = false;
            return await check(coords.lat, coords.lng, coords.precision);
        }

        geoResults.value = await geocode(text, lastBbox);
        if (!geoResults.value.length)
            error.value = 'Alamat tidak ditemukan. Coba tulis lebih umum (desa/kecamatan), lalu tap lokasi tepatnya di peta.';
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        searching.value = false;
    }
}

const locating = ref(false);
async function useGps() {
    locating.value = true;
    error.value = '';
    try {
        const pos = await currentPosition();
        query.value = `${pos.lat.toFixed(6)}, ${pos.lng.toFixed(6)}`;
        await check(pos.lat, pos.lng);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        locating.value = false;
    }
}

function onMapClick(lat: number, lng: number) {
    query.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    check(lat, lng);
}

// ---- Result presentation
// Distances follow the roads unless the routing server was unavailable.
const byRoad = computed(() => result.value?.distance_mode === 'jalan');
const via = computed(() => (byRoad.value ? ' lewat jalan' : ''));

const verdict = computed(() => {
    const r = result.value;
    if (!r) return null;
    return {
        tercover: {
            title: 'Tercover',
            text: `Ada ODP dengan port kosong dalam jarak ${r.radius} m${via.value}.`,
            icon: CircleCheck,
            tone: 'border-green-600 bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200',
        },
        penuh: {
            title: 'ODP penuh',
            text: `Ada ODP dalam ${r.radius} m${via.value}, tetapi semua port-nya terpakai. Ajukan penambahan kapasitas ke tim teknis.`,
            icon: CircleAlert,
            tone: 'border-amber-500 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100',
        },
        tidak_tercover: {
            title: 'Belum tercover',
            text: `Tidak ada ODP aktif dengan port kosong dalam ${r.radius} m${via.value}.`,
            icon: CircleX,
            tone: 'border-red-600 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200',
        },
    }[r.status];
});

// A distance close to the limit may not hold once the drop follows the poles.
const nearLimit = computed(() => {
    const best = result.value?.available[0];
    return best && best.distance > result.value!.radius * 0.8;
});

const mapsUrl = computed(() => (point.value ? `https://www.google.com/maps?q=${point.value.lat},${point.value.lng}` : ''));
const odpMapsUrl = (odp: CoverageOdp) => `https://www.google.com/maps?q=${odp.lat},${odp.lng}`;

const copied = ref(false);
async function copyResult() {
    if (!result.value || !point.value || !verdict.value) return;
    const r = result.value;
    const best = r.available[0];
    const lines = [
        `Cek coverage ${new Date().toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}`,
        `Lokasi: ${point.value.lat.toFixed(6)}, ${point.value.lng.toFixed(6)}${precision.value === 'exact' ? '' : ' (dari link Google Maps, perlu dicek)'}`,
        mapsUrl.value,
        `Hasil: ${verdict.value.title.toUpperCase()} (batas ${r.radius} m, jarak ${byRoad.value ? 'lewat jalan' : 'garis lurus'})`,
        best ? `ODP: ${best.code} — ${best.distance} m, ${best.free_ports} port kosong` : null,
        !best && r.full[0] ? `ODP terdekat (penuh): ${r.full[0].code} — ${r.full[0].distance} m` : null,
        !best && r.nearest_outside
            ? `ODP terdekat yang masih ada port: ${r.nearest_outside.code} — ${formatLength(r.nearest_outside.distance)}`
            : null,
    ].filter(Boolean);

    const text = lines.join('\n');
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Clipboard API needs HTTPS; fall back for plain-http LAN access.
        const area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}
</script>

<template>
    <Head title="Cek Coverage" />

    <AppLayout :breadcrumbs="breadcrumbs" map-page>
        <div class="relative min-h-[560px] flex-1">
            <div class="absolute inset-0 flex flex-col-reverse overflow-hidden md:flex-row md:rounded-b-xl">
                <!-- Panel: bottom on phones (search within thumb reach, map above), left column on desktop -->
                <section
                    v-show="!panelHidden"
                    class="max-h-[55%] shrink-0 overflow-y-auto overscroll-contain rounded-t-2xl border-t p-4 pb-[calc(env(safe-area-inset-bottom)+1rem)] shadow-[0_-4px_12px_rgba(0,0,0,.08)] md:max-h-none md:w-[400px] md:rounded-none md:border-t-0 md:border-r md:pb-4 md:shadow-none"
                >
                    <div class="mb-3 flex items-start justify-between gap-2 max-md:mb-2">
                        <div>
                            <h1 class="text-lg font-semibold max-md:hidden">Cek coverage calon pelanggan</h1>
                            <form v-if="editingRadius" class="mt-1 flex flex-wrap items-center gap-1.5 text-sm" @submit.prevent="saveRadius">
                                <label for="coverage-radius" class="text-muted-foreground">Batas jarak</label>
                                <Input
                                    id="coverage-radius"
                                    v-model.number="radiusForm.radius"
                                    type="number"
                                    :min="radiusLimits[0]"
                                    :max="radiusLimits[1]"
                                    step="10"
                                    class="h-8 w-24"
                                />
                                <span class="text-muted-foreground">m</span>
                                <Button type="submit" size="sm" class="h-8" :disabled="radiusForm.processing">Simpan</Button>
                                <Button type="button" variant="ghost" size="sm" class="h-8" @click="editingRadius = false">Batal</Button>
                                <p v-if="radiusForm.errors.radius" class="w-full text-xs text-red-600">{{ radiusForm.errors.radius }}</p>
                            </form>
                            <p v-else class="flex items-center gap-1 text-sm text-muted-foreground max-md:text-xs">
                                Batas jarak ODP ke pelanggan: {{ radius }} m.
                                <button
                                    v-if="canEditRadius"
                                    type="button"
                                    class="rounded p-1 hover:bg-muted hover:text-foreground"
                                    title="Ubah batas jarak (berlaku untuk semua pengguna)"
                                    @click="editRadius"
                                >
                                    <Pencil class="size-3.5" />
                                    <span class="sr-only">Ubah batas jarak</span>
                                </button>
                            </p>
                            <label v-if="routingAvailable" class="mt-1.5 flex w-fit cursor-pointer items-center gap-2 text-sm max-md:text-xs">
                                <input v-model="roadOn" type="checkbox" class="size-4 accent-primary" />
                                <span>Hitung jarak lewat jalan</span>
                                <span class="text-xs text-muted-foreground">{{ roadOn ? '(lebih akurat)' : '(garis lurus, lebih cepat)' }}</span>
                            </label>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <Button
                                v-if="fullscreen.isSupported.value"
                                variant="outline"
                                size="icon"
                                class="size-9"
                                :title="fullscreen.isFullscreen.value ? 'Keluar dari layar penuh' : 'Layar penuh'"
                                @click="fullscreen.toggle()"
                            >
                                <Minimize v-if="fullscreen.isFullscreen.value" class="size-4" />
                                <Maximize v-else class="size-4" />
                            </Button>
                            <Button variant="outline" size="icon" class="size-9" title="Sembunyikan panel (peta penuh)" @click="panelHidden = true">
                                <ChevronDown class="size-5 md:hidden" />
                                <PanelLeftClose class="size-4 max-md:hidden" />
                            </Button>
                        </div>
                    </div>

                    <form class="flex gap-2" @submit.prevent="search">
                        <div class="relative flex-1">
                            <Search class="absolute top-3.5 left-2.5 size-4 text-muted-foreground md:top-3" />
                            <Input
                                v-model="query"
                                type="search"
                                enterkeyhint="search"
                                class="h-11 pl-8 text-base md:h-10 md:text-sm"
                                placeholder="Alamat, koordinat, atau link Google Maps"
                            />
                        </div>
                        <Button type="submit" class="h-11 md:h-10" :disabled="searching || checking">
                            <LoaderCircle v-if="searching || checking" class="size-4 animate-spin" />
                            <span v-else>Cek</span>
                        </Button>
                    </form>
                    <div class="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>Atau tap lokasi di peta.</span>
                        <Button variant="outline" size="sm" :disabled="locating" @click="useGps">
                            <LoaderCircle v-if="locating" class="size-4 animate-spin" />
                            <Crosshair v-else class="size-4" />
                            Lokasi saya
                        </Button>
                    </div>

                    <ul v-if="geoResults.length" class="mt-3 divide-y rounded-md border text-sm">
                        <li v-for="(g, i) in geoResults" :key="i">
                            <button
                                type="button"
                                class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-muted"
                                @click="check(g.lat, g.lng)"
                            >
                                <MapPin class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                <span>{{ g.label }}</span>
                            </button>
                        </li>
                        <li class="px-3 py-1.5 text-xs text-muted-foreground">
                            Hasil alamat bisa kurang tepat — pastikan titiknya di peta, tap ulang bila perlu.
                        </li>
                    </ul>

                    <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

                    <p
                        v-if="warning && !error"
                        class="mt-3 flex gap-2 rounded-md border border-amber-400 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100"
                    >
                        <TriangleAlert class="size-4 shrink-0" />
                        <span>{{ warning }}</span>
                    </p>

                    <!-- Result -->
                    <div v-if="result && verdict && point" class="mt-4 grid gap-3">
                        <div class="rounded-lg border-l-4 p-4" :class="verdict.tone">
                            <div class="flex items-center gap-2 text-xl font-bold">
                                <component :is="verdict.icon" class="size-6" />
                                {{ verdict.title }}
                            </div>
                            <p class="mt-1 text-sm">{{ verdict.text }}</p>
                        </div>

                        <div v-if="result.available.length">
                            <h2 class="mb-1 text-sm font-semibold">ODP yang bisa dipakai</h2>
                            <ul class="divide-y rounded-md border text-sm">
                                <li v-for="(odp, i) in result.available" :key="odp.id" class="flex items-center gap-3 px-3 py-2">
                                    <span
                                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                        :class="i === 0 ? 'bg-green-600 text-white' : 'bg-muted text-muted-foreground'"
                                        >{{ i + 1 }}</span
                                    >
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-medium">{{ odp.code }}</div>
                                        <div class="text-xs text-muted-foreground">{{ odp.free_ports }} port kosong dari {{ odp.capacity }}</div>
                                    </div>
                                    <span class="font-semibold tabular-nums">{{ odp.distance }} m</span>
                                    <Button
                                        as="a"
                                        :href="odpMapsUrl(odp)"
                                        target="_blank"
                                        variant="outline"
                                        size="icon"
                                        class="size-8 shrink-0"
                                        :title="`Buka ${odp.code} di Google Maps`"
                                    >
                                        <ExternalLink class="size-4" />
                                        <span class="sr-only">Google Maps</span>
                                    </Button>
                                </li>
                            </ul>
                            <p v-if="nearLimit" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                Jarak mendekati batas {{ radius }} m — konfirmasi ke teknisi, karena kabel drop mengikuti jalur tiang.
                            </p>
                        </div>

                        <div v-if="result.full.length && !result.available.length">
                            <h2 class="mb-1 text-sm font-semibold">ODP terdekat (penuh)</h2>
                            <ul class="divide-y rounded-md border text-sm">
                                <li v-for="odp in result.full" :key="odp.id" class="flex items-center gap-3 px-3 py-2">
                                    <span class="min-w-0 flex-1 truncate font-medium">{{ odp.code }}</span>
                                    <span class="tabular-nums">{{ odp.distance }} m · {{ odp.used_ports }}/{{ odp.capacity }}</span>
                                    <Button
                                        as="a"
                                        :href="odpMapsUrl(odp)"
                                        target="_blank"
                                        variant="outline"
                                        size="icon"
                                        class="size-8 shrink-0"
                                        :title="`Buka ${odp.code} di Google Maps`"
                                    >
                                        <ExternalLink class="size-4" />
                                        <span class="sr-only">Google Maps</span>
                                    </Button>
                                </li>
                            </ul>
                        </div>

                        <p v-if="result.nearest_outside" class="rounded-md bg-muted p-3 text-sm">
                            ODP terdekat yang masih ada port: <b>{{ result.nearest_outside.code }}</b
                            >, {{ formatLength(result.nearest_outside.distance) }} — di luar batas {{ radius }} m.
                        </p>

                        <p v-if="result.planned.length" class="rounded-md bg-muted p-3 text-sm">
                            Ada ODP <b>rencana</b> (belum aktif) dalam {{ radius }} m:
                            {{ result.planned.map((o) => `${o.code} (${o.distance} m)`).join(', ') }}.
                        </p>

                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" @click="copyResult">
                                <Copy class="size-4" />
                                {{ copied ? 'Tersalin' : 'Salin hasil' }}
                            </Button>
                            <Button as="a" :href="mapsUrl" target="_blank" variant="outline" size="sm"
                                ><ExternalLink class="size-4" /> Google Maps</Button
                            >
                        </div>
                        <p v-if="byRoad" class="text-xs text-muted-foreground">
                            Jarak dihitung lewat jalan (perkiraan, jalur kabel bisa berbeda). Data jalan © OpenStreetMap contributors, rute oleh
                            OSRM/FOSSGIS.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Jarak dihitung garis lurus dari titik lokasi ke ODP{{
                                routingAvailable && roadOn ? ' (server rute jalan sedang tidak tersedia)' : ''
                            }}.
                        </p>
                    </div>
                </section>

                <!-- Map -->
                <div class="relative min-h-[260px] flex-1">
                    <SidebarTrigger class="absolute top-2 left-2 z-10 size-10 rounded-full border bg-background shadow-sm md:hidden" />
                    <!-- Panel hidden for a clear map: this brings it back (bottom right on phones, top left on desktop). -->
                    <Button
                        v-if="panelHidden"
                        variant="secondary"
                        class="absolute right-3 bottom-[calc(env(safe-area-inset-bottom)+1.75rem)] z-10 h-11 rounded-full border px-4 shadow-lg md:top-2 md:right-auto md:bottom-auto md:left-14 md:h-10 md:rounded-md md:shadow-sm"
                        title="Tampilkan panel cek coverage"
                        @click="panelHidden = false"
                    >
                        <ChevronUp class="size-4 md:hidden" />
                        <PanelLeftOpen class="size-4 max-md:hidden" />
                        Cek coverage
                    </Button>
                    <NetworkMap
                        ref="mapRef"
                        :odcs="odcs"
                        :odps="odps"
                        :cables="cables"
                        :draft="null"
                        :selected="null"
                        :pick="null"
                        :nearest="mapOverlay"
                        :center="view.center"
                        :zoom="view.zoom"
                        :hide-cables="isSales"
                        @bounds="onBounds"
                        @click="onMapClick"
                        @select="() => {}"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
