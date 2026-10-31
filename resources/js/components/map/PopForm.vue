<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { precisionWarning, resolveLocation } from '@/lib/coordinates';
import { currentPosition } from '@/lib/http';
import type { Pop } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { Crosshair, LoaderCircle, TriangleAlert, X } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    pop: Pop;
    /** The draggable marker on the map. */
    location: { lat: number; lng: number };
}>();

const emit = defineEmits<{
    locate: [lat: number, lng: number];
    saved: [];
    cancel: [];
}>();

const form = useForm({
    lat: props.location.lat.toFixed(7),
    lng: props.location.lng.toFixed(7),
    address: props.pop.address ?? '',
    notes: props.pop.notes ?? '',
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
    if (!isNaN(lat) && !isNaN(lng)) {
        warning.value = null;
        emit('locate', lat, lng);
    }
}

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

function submit() {
    form.patch(route('pops.update', { pop: props.pop.id }), { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-xs font-medium text-muted-foreground">POP · {{ pop.code }}</div>
                <h2 class="text-lg font-semibold">Edit {{ pop.name }}</h2>
            </div>
            <Button type="button" variant="ghost" size="icon" @click="emit('cancel')"><X class="size-4" /></Button>
        </div>

        <div class="grid gap-2 rounded-md border p-3">
            <div class="flex items-center justify-between gap-2">
                <Label>Lokasi POP</Label>
                <Button type="button" variant="outline" size="sm" :disabled="locating" @click="useGps">
                    <LoaderCircle v-if="locating" class="size-4 animate-spin" />
                    <Crosshair v-else class="size-4" />
                    GPS
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">Klik peta atau geser penanda merah. Kabel feeder yang berujung di POP ikut berpindah.</p>
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

        <div class="grid gap-1.5">
            <Label for="pop_address">Alamat</Label>
            <Input id="pop_address" v-model="form.address" />
            <InputError :message="form.errors.address" />
        </div>
        <div class="grid gap-1.5">
            <Label for="pop_notes">Catatan</Label>
            <textarea
                id="pop_notes"
                v-model="form.notes"
                rows="2"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
        </div>
        <p class="text-xs text-muted-foreground">Kode dan nama POP sama dengan area; ubah lewat menu Admin › Area.</p>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Simpan
            </Button>
            <Button type="button" variant="outline" @click="emit('cancel')">Batal</Button>
        </div>
    </form>
</template>
