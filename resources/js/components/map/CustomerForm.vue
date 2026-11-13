<script setup lang="ts">
import { applyInitial, useApproval } from '@/composables/useApproval';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { precisionWarning, resolveLocation } from '@/lib/coordinates';
import { currentPosition } from '@/lib/http';
import type { Customer } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Crosshair, LoaderCircle, MapPinOff, TriangleAlert, X } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    customer: Customer;
    /** The draggable marker on the map; null while the customer has no location. */
    location: { lat: number; lng: number } | null;
    /** Values of a technician's request an approver edits before approving it. */
    initial?: Record<string, unknown> | null;
    changeRequestId?: number | null;
}>();

const emit = defineEmits<{
    locate: [lat: number, lng: number];
    clearLocation: [];
    saved: [];
    cancel: [];
}>();

const form = useForm({
    name: props.customer.name,
    customer_code: props.customer.customer_code ?? '',
    phone: props.customer.phone ?? '',
    address: props.customer.address ?? '',
    package: props.customer.package ?? '',
    ont_sn: props.customer.ont_sn ?? '',
    lat: props.location?.lat.toFixed(7) ?? '',
    lng: props.location?.lng.toFixed(7) ?? '',
    change_request_id: props.changeRequestId ?? null,
});
applyInitial(form, props.initial);

// The map marker is the source of truth for the location; typed coordinates move it back.
watch(
    () => props.location,
    (loc) => {
        form.lat = loc?.lat.toFixed(7) ?? '';
        form.lng = loc?.lng.toFixed(7) ?? '';
    },
);

function onCoordsTyped() {
    const lat = parseFloat(String(form.lat));
    const lng = parseFloat(String(form.lng));
    if (!isNaN(lat) && !isNaN(lng)) {
        warning.value = null;
        emit('locate', lat, lng);
    }
}

// ---- Location from GPS or a pasted link / coordinates
const locating = ref(false);
const locationError = ref('');
const warning = ref<string | null>(null);
const pasted = ref('');

async function useGps() {
    locating.value = true;
    locationError.value = '';
    try {
        const pos = await currentPosition();
        warning.value = null;
        emit('locate', pos.lat, pos.lng);
    } catch (e) {
        locationError.value = (e as Error).message;
    } finally {
        locating.value = false;
    }
}

async function usePasted() {
    if (!pasted.value.trim()) return;
    locating.value = true;
    locationError.value = '';
    try {
        const loc = await resolveLocation(pasted.value);
        if (!loc) throw new Error('Isi dengan koordinat (mis. -7.0429, 109.5813) atau link Google Maps.');
        warning.value = precisionWarning(loc.precision);
        pasted.value = '';
        emit('locate', loc.lat, loc.lng);
    } catch (e) {
        locationError.value = (e as Error).message;
    } finally {
        locating.value = false;
    }
}

function clearLocation() {
    warning.value = null;
    emit('clearLocation');
}

function submit() {
    form.transform((data) => ({ ...data, lat: data.lat || null, lng: data.lng || null })).patch(
        route('customers.update', { customer: props.customer.id }),
        { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') },
    );
}
const { saveLabel } = useApproval();
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Edit pelanggan</h2>
            <Button type="button" variant="ghost" size="icon" @click="emit('cancel')"><X class="size-4" /></Button>
        </div>

        <div class="grid gap-1.5">
            <Label for="c_name">Nama pelanggan</Label>
            <Input id="c_name" v-model="form.name" required />
            <InputError :message="form.errors.name" />
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="c_code">ID pelanggan</Label>
                <Input id="c_code" v-model="form.customer_code" />
                <InputError :message="form.errors.customer_code" />
            </div>
            <div class="grid gap-1.5">
                <Label for="c_phone">No. HP</Label>
                <Input id="c_phone" v-model="form.phone" inputmode="tel" />
                <InputError :message="form.errors.phone" />
            </div>
        </div>
        <div class="grid gap-1.5">
            <Label for="c_address">Alamat</Label>
            <Input id="c_address" v-model="form.address" />
            <InputError :message="form.errors.address" />
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="c_package">Paket</Label>
                <Input id="c_package" v-model="form.package" placeholder="20 Mbps" />
            </div>
            <div class="grid gap-1.5">
                <Label for="c_sn">SN ONT</Label>
                <Input id="c_sn" v-model="form.ont_sn" />
            </div>
        </div>

        <div class="grid gap-2 rounded-md border p-3">
            <div class="flex items-center justify-between gap-2">
                <Label>Lokasi rumah</Label>
                <div class="flex gap-1">
                    <Button v-if="location" type="button" variant="ghost" size="sm" title="Hapus koordinat" @click="clearLocation">
                        <MapPinOff class="size-4" />
                    </Button>
                    <Button type="button" variant="outline" size="sm" :disabled="locating" @click="useGps">
                        <LoaderCircle v-if="locating" class="size-4 animate-spin" />
                        <Crosshair v-else class="size-4" />
                        GPS
                    </Button>
                </div>
            </div>
            <p class="text-xs text-muted-foreground">
                Klik peta atau geser penanda merah, pakai GPS di lokasi, atau tempel koordinat / link Google Maps.
            </p>
            <div class="grid grid-cols-2 gap-2">
                <Input v-model="form.lat" inputmode="decimal" placeholder="Latitude" @change="onCoordsTyped" />
                <Input v-model="form.lng" inputmode="decimal" placeholder="Longitude" @change="onCoordsTyped" />
            </div>
            <div class="flex gap-2">
                <Input v-model="pasted" class="h-9" placeholder="Tempel koordinat atau link Google Maps" @keydown.enter.prevent="usePasted" />
                <Button type="button" variant="outline" size="sm" class="h-9 shrink-0" :disabled="locating || !pasted.trim()" @click="usePasted"
                    >Pakai</Button
                >
            </div>
            <p
                v-if="warning"
                class="flex gap-2 rounded-md border border-amber-400 bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                <TriangleAlert class="size-4 shrink-0" />
                <span>{{ warning }}</span>
            </p>
            <InputError :message="locationError || form.errors.lat || form.errors.lng" />
        </div>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                {{ saveLabel }}
            </Button>
            <Button type="button" variant="outline" @click="emit('cancel')">Batal</Button>
        </div>
    </form>
</template>
