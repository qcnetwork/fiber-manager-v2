<script setup lang="ts">
import { applyInitial, useApproval } from '@/composables/useApproval';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { currentPosition, getJson } from '@/lib/http';
import { cableTypes, jointTypes } from '@/lib/cables';
import type { Area, AssetKind, CableType, Odc, OdcOption, Odp, OltPortStatus, SpliceBox, SpliceBoxType } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Crosshair, LoaderCircle, X } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    kind: AssetKind;
    asset: Odc | Odp | SpliceBox | null;
    location: { lat: number; lng: number };
    areas: Area[];
    canChooseArea: boolean;
    defaultAreaId: number | null;
    defaultOdcId?: number | null;
    /** Values of a technician's request an approver edits before approving it. */
    initial?: Record<string, unknown> | null;
    changeRequestId?: number | null;
}>();

const emit = defineEmits<{
    locate: [lat: number, lng: number];
    saved: [];
    cancel: [];
}>();

const odp = props.kind === 'odp' ? (props.asset as Odp | null) : null;
const odc = props.kind === 'odc' ? (props.asset as Odc | null) : null;
const joint = props.kind === 'joint' ? (props.asset as SpliceBox | null) : null;
const isNew = !props.asset;
const label = { odc: 'ODC', odp: 'ODP', joint: 'Joint/Roset' }[props.kind];
const defaultCapacity = { odc: 48, odp: 8, joint: null }[props.kind];

const form = useForm({
    area_id: props.asset?.area_id ?? props.defaultAreaId,
    code: props.asset?.code ?? '',
    name: props.asset?.name ?? '',
    status: props.asset?.status ?? 'aktif',
    lat: props.location.lat.toFixed(7),
    lng: props.location.lng.toFixed(7),
    address: props.asset?.address ?? '',
    capacity: (props.asset?.capacity ?? defaultCapacity ?? '') as number | string,
    splitter_ratio: props.asset?.splitter_ratio ?? (props.kind === 'odp' ? '1:8' : ''),
    installed_at: props.asset?.installed_at ?? '',
    notes: props.asset?.notes ?? '',
    // ODP only
    odc_id: odp?.odc_id ?? props.defaultOdcId ?? null,
    odc_port: odp?.odc_port ?? null,
    pole_number: odp?.pole_number ?? joint?.pole_number ?? '',
    // Joint/rosette only
    type: (joint?.type ?? 'joint_box') as SpliceBoxType,
    // ODC only: the OLT PON port feeding it
    olt_port_id: odc?.olt_port_id ?? null,
    // Nearby cables to connect the asset to on save
    connect_cables: [] as number[],
    change_request_id: props.changeRequestId ?? null,
});
applyInitial(form, props.initial);

// For the auto-code hint on joints.
const areaCode = computed(() => props.areas.find((a) => a.id === Number(form.area_id))?.code ?? null);

// ---- Cables passing near the marker, offered for connecting the asset to
interface NearbyCable {
    id: number;
    code: string | null;
    type: CableType;
    cores: number;
    distance_m: number;
    area_id: number;
    area: { id: number; code: string; name: string } | null;
    connected: boolean;
}
const nearbyCables = ref<NearbyCable[]>([]);
const nearbyRadius = ref(0);
async function loadNearbyCables() {
    const data = await getJson<{ radius: number; cables: NearbyCable[] }>(route('cables.nearby'), {
        lat: form.lat,
        lng: form.lng,
        area_id: form.area_id,
        kind: props.asset ? props.kind : undefined,
        id: props.asset?.id,
    });
    nearbyRadius.value = data.radius;
    nearbyCables.value = data.cables;
    // Ticks for cables no longer in reach are dropped.
    form.connect_cables = form.connect_cables.filter((id) => data.cables.some((c) => c.id === id && !c.connected));
}
onMounted(loadNearbyCables);
watch(() => form.area_id, loadNearbyCables);
watch(() => props.location, useDebounceFn(loadNearbyCables, 400));

const cableName = (c: NearbyCable) => c.code ?? `Kabel ${cableTypes[c.type]?.label ?? c.type} #${c.id}`;

// The map marker is the source of truth for the location; typed coordinates move it back.
watch(
    () => props.location,
    (loc) => {
        form.lat = loc.lat.toFixed(7);
        form.lng = loc.lng.toFixed(7);
    },
);

function onCoordsTyped() {
    const lat = parseFloat(String(form.lat));
    const lng = parseFloat(String(form.lng));
    if (!isNaN(lat) && !isNaN(lng)) emit('locate', lat, lng);
}

const locating = ref(false);
const gpsError = ref('');
async function useGps() {
    locating.value = true;
    gpsError.value = '';
    try {
        const pos = await currentPosition();
        emit('locate', pos.lat, pos.lng);
    } catch (e) {
        gpsError.value = (e as Error).message;
    } finally {
        locating.value = false;
    }
}

const odcOptions = ref<OdcOption[]>([]);
async function loadOdcOptions() {
    if (props.kind !== 'odp') return;
    // ODCs of every area, nearest first: an ODP may hang off an ODC across the area border.
    odcOptions.value = await getJson<OdcOption[]>(route('odcs.options'), { lat: form.lat, lng: form.lng });
}
onMounted(loadOdcOptions);
watch(() => form.area_id, loadOdcOptions);
// Keep the "distance to ODC" hints current while the marker is being placed.
watch(() => props.location, useDebounceFn(loadOdcOptions, 400));

// ---- ODC uplink: PON ports of the OLTs at the chosen area's POP
interface OltOption {
    id: number;
    area_id: number;
    code: string;
    area: { id: number; code: string; name: string } | null;
    ports: { id: number; slot: number; port: number; status: OltPortStatus; odcs: { id: number; code: string }[] }[];
}
const oltOptions = ref<OltOption[]>([]);
/** OLTs at every POP, the chosen area's own POP first: an ODC may be fed from a POP across the area border. */
async function loadOltOptions() {
    if (props.kind !== 'odc') return;
    const olts = await getJson<OltOption[]>(route('olts.options'));
    const own = Number(form.area_id);
    oltOptions.value = [...olts.filter((o) => o.area_id === own), ...olts.filter((o) => o.area_id !== own)];
}
onMounted(loadOltOptions);
watch(() => form.area_id, loadOltOptions);

/** The area code of something in another area than the asset being edited, else null. */
const otherArea = (item: { area_id: number; area: { code: string } | null }) =>
    form.area_id && item.area_id !== Number(form.area_id) ? (item.area?.code ?? '?') : null;

function portLabel(port: OltOption['ports'][number]) {
    const others = port.odcs.filter((o) => o.id !== odc?.id).map((o) => o.code);
    return [`Slot ${port.slot} / Port ${port.port}`, port.status === 'rusak' ? 'rusak' : null, others.length ? `dipakai ${others.join(', ')}` : null]
        .filter(Boolean)
        .join(' — ');
}

const freeOdcPorts = computed(() => {
    const odc = odcOptions.value.find((o) => o.id === Number(form.odc_id));
    if (!odc) return [];
    const taken = new Set(odc.used_ports.filter((p) => !(odp && odp.odc_id === odc.id && p === odp.odc_port)));
    return Array.from({ length: odc.capacity }, (_, i) => i + 1).filter((p) => !taken.has(p));
});

// Common sizes as quick picks; any other number can be typed.
const odpCapacities = [2, 4, 8, 16, 24, 32];

function submit() {
    const resource = `${props.kind}s`;
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') };

    /* eslint-disable @typescript-eslint/no-unused-vars */
    form.transform((data) => {
        if (props.kind === 'joint') {
            const { odc_id, odc_port, olt_port_id, splitter_ratio, ...rest } = data;
            return { ...rest, capacity: rest.capacity || null };
        }
        if (props.kind === 'odc') {
            const { odc_id, odc_port, pole_number, type, ...rest } = data;
            return { ...rest, olt_port_id: rest.olt_port_id || null };
        }
        const { olt_port_id, type, ...rest } = data;
        return { ...rest, odc_id: rest.odc_id || null, odc_port: rest.odc_id ? rest.odc_port : null };
    });
    /* eslint-enable @typescript-eslint/no-unused-vars */

    if (isNew) form.post(route(`${resource}.store`), options);
    else form.put(route(`${resource}.update`, { [props.kind]: props.asset!.id }), options);
}
const { saveLabel } = useApproval();
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">{{ isNew ? `Tambah ${label}` : `Edit ${label}` }}</h2>
            <Button type="button" variant="ghost" size="icon" @click="emit('cancel')"><X class="size-4" /></Button>
        </div>

        <p v-if="isNew" class="rounded-md bg-muted p-2 text-xs text-muted-foreground">
            Klik peta atau geser penanda merah untuk menentukan lokasi, atau pakai GPS perangkat.
        </p>

        <div v-if="canChooseArea" class="grid gap-1.5">
            <Label for="area_id">Area</Label>
            <NativeSelect id="area_id" v-model="form.area_id" required>
                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }} ({{ area.code }})</option>
            </NativeSelect>
            <InputError :message="form.errors.area_id" />
        </div>

        <div v-if="kind === 'joint'" class="grid gap-1.5">
            <Label>Jenis</Label>
            <div class="grid grid-cols-2 gap-2">
                <button
                    v-for="(t, key) in jointTypes"
                    :key="key"
                    type="button"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="form.type === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    @click="form.type = key as SpliceBoxType"
                >
                    {{ t.label }}
                </button>
            </div>
            <InputError :message="form.errors.type" />
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="code">Kode {{ kind === 'joint' ? jointTypes[form.type]?.label : label }}</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    :required="kind !== 'joint'"
                    :placeholder="{ odc: 'ODC-BDG-01', odp: 'ODP-BDG-01/01', joint: 'Kosongkan = otomatis' }[kind]"
                />
                <p v-if="kind === 'joint' && !form.code" class="text-xs text-muted-foreground">
                    Dibuat otomatis, mis. {{ form.type === 'roset' ? 'RS' : 'JB' }}-{{ areaCode ?? 'AREA' }}-01
                </p>
                <InputError :message="form.errors.code" />
            </div>
            <div class="grid gap-1.5">
                <Label for="status">Status</Label>
                <NativeSelect id="status" v-model="form.status">
                    <option value="aktif">Aktif</option>
                    <option value="rencana">Rencana</option>
                    <option value="rusak">Rusak</option>
                </NativeSelect>
                <InputError :message="form.errors.status" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="name">Nama / keterangan singkat</Label>
            <Input id="name" v-model="form.name" />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-1.5">
            <div class="flex items-center justify-between">
                <Label>Koordinat</Label>
                <Button type="button" variant="outline" size="sm" :disabled="locating" @click="useGps">
                    <LoaderCircle v-if="locating" class="size-4 animate-spin" />
                    <Crosshair v-else class="size-4" />
                    Pakai GPS
                </Button>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <Input v-model="form.lat" inputmode="decimal" placeholder="Latitude" @change="onCoordsTyped" />
                <Input v-model="form.lng" inputmode="decimal" placeholder="Longitude" @change="onCoordsTyped" />
            </div>
            <InputError :message="gpsError || form.errors.lat || form.errors.lng" />
        </div>

        <div class="grid gap-1.5">
            <Label for="address">Alamat</Label>
            <Input id="address" v-model="form.address" />
            <InputError :message="form.errors.address" />
        </div>

        <div class="grid gap-1.5">
            <Label>Sambungkan ke kabel terdekat</Label>
            <p v-if="!nearbyCables.length" class="text-xs text-muted-foreground">
                Tidak ada kabel dalam radius {{ nearbyRadius }} m dari lokasi ini.
            </p>
            <div v-else class="flex flex-col gap-1 rounded-md border p-2">
                <label
                    v-for="cable in nearbyCables"
                    :key="cable.id"
                    class="flex items-center gap-2 rounded px-1 py-1 text-sm"
                    :class="cable.connected ? 'text-muted-foreground' : 'cursor-pointer hover:bg-muted'"
                >
                    <input
                        v-if="!cable.connected"
                        v-model="form.connect_cables"
                        type="checkbox"
                        :value="cable.id"
                        class="size-4 rounded border-input"
                    />
                    <span v-else class="inline-block size-4 text-center text-green-600">✓</span>
                    <span class="inline-block h-1.5 w-4 shrink-0 rounded-full" :style="{ background: cableTypes[cable.type]?.color }" />
                    <span class="min-w-0 flex-1 truncate">
                        {{ cableName(cable) }}
                        <span v-if="otherArea(cable)" class="text-xs text-amber-700 dark:text-amber-400">· area {{ otherArea(cable) }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground tabular-nums">
                        {{ cable.connected ? 'sudah tersambung' : `${cable.distance_m} m · ${cable.cores} core` }}
                    </span>
                </label>
            </div>
            <p v-if="form.connect_cables.length" class="text-xs text-muted-foreground">
                Jalur kabel yang dicentang akan ditarik ke {{ label }} ini dan {{ label }} tercatat sebagai titik sambung kabel tersebut.
            </p>
            <InputError :message="Object.entries(form.errors).find(([key]) => key.startsWith('connect_cables'))?.[1]" />
        </div>

        <div v-if="kind === 'odc'" class="grid gap-1.5">
            <Label for="olt_port_id">Port PON OLT (sumber)</Label>
            <NativeSelect id="olt_port_id" v-model="form.olt_port_id">
                <option :value="null">— Belum tersambung —</option>
                <optgroup v-for="olt in oltOptions" :key="olt.id" :label="`${olt.code} · POP ${olt.area?.name ?? '?'}`">
                    <option v-for="port in olt.ports" :key="port.id" :value="port.id">{{ portLabel(port) }}</option>
                </optgroup>
            </NativeSelect>
            <p v-if="!oltOptions.length" class="text-xs text-muted-foreground">Belum ada OLT. Tambahkan lewat titik POP di peta.</p>
            <InputError :message="form.errors.olt_port_id" />
        </div>

        <template v-if="kind === 'odp'">
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-1.5">
                    <Label for="odc_id">ODC induk</Label>
                    <NativeSelect id="odc_id" v-model="form.odc_id">
                        <option :value="null">— Tidak ada —</option>
                        <option v-for="odc in odcOptions" :key="odc.id" :value="odc.id">
                            {{ odc.code }}{{ otherArea(odc) ? ` · area ${otherArea(odc)}` : ''
                            }}{{ odc.distance !== null ? ` (${odc.distance} m)` : '' }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.odc_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="odc_port">Port ODC</Label>
                    <NativeSelect id="odc_port" v-model="form.odc_port" :disabled="!form.odc_id">
                        <option :value="null">—</option>
                        <option v-for="p in freeOdcPorts" :key="p" :value="p">Port {{ p }}</option>
                    </NativeSelect>
                    <InputError :message="form.errors.odc_port" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label for="pole_number">No. tiang</Label>
                <Input id="pole_number" v-model="form.pole_number" />
                <InputError :message="form.errors.pole_number" />
            </div>
        </template>

        <div v-if="kind === 'joint'" class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="pole_number">No. tiang</Label>
                <Input id="pole_number" v-model="form.pole_number" />
                <InputError :message="form.errors.pole_number" />
            </div>
            <div class="grid gap-1.5">
                <Label for="capacity">Kapasitas sambungan (core)</Label>
                <Input id="capacity" v-model="form.capacity" type="number" inputmode="numeric" min="1" max="1000" placeholder="opsional" />
                <InputError :message="form.errors.capacity" />
            </div>
        </div>

        <div v-else class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="capacity">{{ kind === 'odc' ? 'Kapasitas port output' : 'Jumlah port' }}</Label>
                <Input v-if="kind === 'odc'" id="capacity" v-model="form.capacity" type="number" min="1" max="1024" />
                <template v-else>
                    <Input id="capacity" v-model="form.capacity" type="number" inputmode="numeric" min="1" max="64" required />
                    <div class="flex flex-wrap gap-1">
                        <button
                            v-for="c in odpCapacities"
                            :key="c"
                            type="button"
                            class="rounded border px-1.5 py-0.5 text-xs tabular-nums hover:bg-muted"
                            :class="Number(form.capacity) === c ? 'border-primary bg-primary text-primary-foreground hover:bg-primary' : ''"
                            @click="form.capacity = c"
                        >
                            {{ c }}
                        </button>
                    </div>
                </template>
                <InputError :message="form.errors.capacity" />
            </div>
            <div class="grid gap-1.5">
                <Label for="splitter_ratio">Splitter</Label>
                <NativeSelect id="splitter_ratio" v-model="form.splitter_ratio">
                    <option value="">—</option>
                    <option v-for="r in ['1:2', '1:4', '1:8', '1:16', '1:32']" :key="r" :value="r">{{ r }}</option>
                </NativeSelect>
                <InputError :message="form.errors.splitter_ratio" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="installed_at">Tanggal pasang</Label>
            <Input id="installed_at" v-model="form.installed_at" type="date" />
            <InputError :message="form.errors.installed_at" />
        </div>

        <div class="grid gap-1.5">
            <Label for="notes">Catatan</Label>
            <textarea
                id="notes"
                v-model="form.notes"
                rows="2"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
            />
            <InputError :message="form.errors.notes" />
        </div>

        <div class="flex gap-2">
            <Button type="submit" class="flex-1" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                {{ saveLabel }}
            </Button>
            <Button type="button" variant="outline" @click="emit('cancel')">Batal</Button>
        </div>
    </form>
</template>
