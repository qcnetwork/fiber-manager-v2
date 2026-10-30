<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { currentPosition, getJson } from '@/lib/http';
import type { Area, AssetKind, Odc, OdcOption, Odp, OltPortStatus } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import { Crosshair, LoaderCircle, X } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps<{
    kind: AssetKind;
    asset: Odc | Odp | null;
    location: { lat: number; lng: number };
    areas: Area[];
    canChooseArea: boolean;
    defaultAreaId: number | null;
    defaultOdcId?: number | null;
}>();

const emit = defineEmits<{
    locate: [lat: number, lng: number];
    saved: [];
    cancel: [];
}>();

const odp = props.kind === 'odp' ? (props.asset as Odp | null) : null;
const odc = props.kind === 'odc' ? (props.asset as Odc | null) : null;
const isNew = !props.asset;
const label = props.kind === 'odc' ? 'ODC' : 'ODP';

const form = useForm({
    area_id: props.asset?.area_id ?? props.defaultAreaId,
    code: props.asset?.code ?? '',
    name: props.asset?.name ?? '',
    status: props.asset?.status ?? 'aktif',
    lat: props.location.lat.toFixed(7),
    lng: props.location.lng.toFixed(7),
    address: props.asset?.address ?? '',
    capacity: props.asset?.capacity ?? (props.kind === 'odc' ? 48 : 8),
    splitter_ratio: props.asset?.splitter_ratio ?? (props.kind === 'odc' ? '' : '1:8'),
    installed_at: props.asset?.installed_at ?? '',
    notes: props.asset?.notes ?? '',
    // ODP only
    odc_id: odp?.odc_id ?? props.defaultOdcId ?? null,
    odc_port: odp?.odc_port ?? null,
    pole_number: odp?.pole_number ?? '',
    // ODC only: the OLT PON port feeding it
    olt_port_id: odc?.olt_port_id ?? null,
});

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
    odcOptions.value = await getJson<OdcOption[]>(route('odcs.options'), { area_id: form.area_id, lat: form.lat, lng: form.lng });
}
onMounted(loadOdcOptions);
watch(() => form.area_id, loadOdcOptions);
// Keep the "distance to ODC" hints current while the marker is being placed.
watch(() => props.location, useDebounceFn(loadOdcOptions, 400));

// ---- ODC uplink: PON ports of the OLTs at the chosen area's POP
interface OltOption {
    id: number;
    code: string;
    ports: { id: number; slot: number; port: number; status: OltPortStatus; odcs: { id: number; code: string }[] }[];
}
const oltOptions = ref<OltOption[]>([]);
async function loadOltOptions() {
    if (props.kind !== 'odc' || !form.area_id) return;
    oltOptions.value = await getJson<OltOption[]>(route('olts.options'), { area_id: form.area_id });
    // A port of another POP no longer applies after changing the area.
    if (form.olt_port_id && !oltOptions.value.some((o) => o.ports.some((p) => p.id === form.olt_port_id))) form.olt_port_id = null;
}
onMounted(loadOltOptions);
watch(() => form.area_id, loadOltOptions);

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

// Common sizes, plus an unusual stored value (e.g. 9 from older data) so editing keeps it.
const odpCapacities = computed(() => [...new Set([2, 4, 8, 16, 24, 32, Number(form.capacity)])].sort((a, b) => a - b));

function submit() {
    const resource = props.kind === 'odc' ? 'odcs' : 'odps';
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') };

    form.transform((data) => {
        if (props.kind === 'odc') {
            const { odc_id, odc_port, pole_number, ...rest } = data; // eslint-disable-line @typescript-eslint/no-unused-vars
            return { ...rest, olt_port_id: rest.olt_port_id || null };
        }
        const { olt_port_id, ...rest } = data; // eslint-disable-line @typescript-eslint/no-unused-vars
        return { ...rest, odc_id: rest.odc_id || null, odc_port: rest.odc_id ? rest.odc_port : null };
    });

    if (isNew) form.post(route(`${resource}.store`), options);
    else form.put(route(`${resource}.update`, { [props.kind]: props.asset!.id }), options);
}
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

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="code">Kode {{ label }}</Label>
                <Input id="code" v-model="form.code" required :placeholder="kind === 'odc' ? 'ODC-BDG-01' : 'ODP-BDG-01/01'" />
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

        <div v-if="kind === 'odc'" class="grid gap-1.5">
            <Label for="olt_port_id">Port PON OLT (sumber)</Label>
            <NativeSelect id="olt_port_id" v-model="form.olt_port_id">
                <option :value="null">— Belum tersambung —</option>
                <optgroup v-for="olt in oltOptions" :key="olt.id" :label="olt.code">
                    <option v-for="port in olt.ports" :key="port.id" :value="port.id">{{ portLabel(port) }}</option>
                </optgroup>
            </NativeSelect>
            <p v-if="!oltOptions.length" class="text-xs text-muted-foreground">Belum ada OLT di POP area ini. Tambahkan lewat titik POP di peta.</p>
            <InputError :message="form.errors.olt_port_id" />
        </div>

        <template v-if="kind === 'odp'">
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-1.5">
                    <Label for="odc_id">ODC induk</Label>
                    <NativeSelect id="odc_id" v-model="form.odc_id">
                        <option :value="null">— Tidak ada —</option>
                        <option v-for="odc in odcOptions" :key="odc.id" :value="odc.id">
                            {{ odc.code }}{{ odc.distance !== null ? ` (${odc.distance} m)` : '' }}
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

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="capacity">{{ kind === 'odc' ? 'Kapasitas port output' : 'Jumlah port' }}</Label>
                <Input v-if="kind === 'odc'" id="capacity" v-model="form.capacity" type="number" min="1" max="1024" />
                <NativeSelect v-else id="capacity" v-model="form.capacity">
                    <option v-for="c in odpCapacities" :key="c" :value="c">{{ c }} port</option>
                </NativeSelect>
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
                class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <InputError :message="form.errors.notes" />
        </div>

        <div class="flex gap-2">
            <Button type="submit" class="flex-1" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Simpan
            </Button>
            <Button type="button" variant="outline" @click="emit('cancel')">Batal</Button>
        </div>
    </form>
</template>
