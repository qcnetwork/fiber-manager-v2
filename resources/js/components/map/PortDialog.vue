<script setup lang="ts">
import { applyInitial, useApproval } from '@/composables/useApproval';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OdpPort } from '@/types';
import { precisionWarning, resolveLocation } from '@/lib/coordinates';
import { currentPosition } from '@/lib/http';
import { useForm } from '@inertiajs/vue3';
import { Crosshair, LoaderCircle, MapPinOff, TriangleAlert } from '@lucide/vue';
import { ref, watch } from 'vue';

const props = defineProps<{
    port: OdpPort | null;
    odpCode: string;
    /** Values of a technician's request an approver edits before approving it. */
    initial?: Record<string, unknown> | null;
    changeRequestId?: number | null;
    /** The technician's own draft being changed. */
    draftId?: number | null;
}>();

const emit = defineEmits<{
    saved: [];
    close: [];
}>();

const emptyCustomer = { name: '', customer_code: '', phone: '', address: '', package: '', ont_sn: '', lat: '', lng: '' };

// ---- Customer location: GPS on site, or a pasted coordinate / Google Maps link
const locating = ref(false);
const locationError = ref('');
const warning = ref<string | null>(null);
const pasted = ref('');

const form = useForm({
    status: 'kosong' as OdpPort['status'],
    notes: '',
    // In use, but nobody knows by whom yet: no customer record is created.
    customer_unknown: false,
    customer: { ...emptyCustomer },
    change_request_id: props.changeRequestId ?? null,
    draft_id: props.draftId ?? null,
});

watch(
    () => props.port,
    (port) => {
        if (!port) return;
        form.clearErrors();
        form.status = port.status;
        form.notes = port.notes ?? '';
        form.customer_unknown = port.status === 'terpakai' && !port.customer;
        form.customer = {
            name: port.customer?.name ?? '',
            customer_code: port.customer?.customer_code ?? '',
            phone: port.customer?.phone ?? '',
            address: port.customer?.address ?? '',
            package: port.customer?.package ?? '',
            ont_sn: port.customer?.ont_sn ?? '',
            lat: port.customer?.lat != null ? Number(port.customer.lat).toFixed(7) : '',
            lng: port.customer?.lng != null ? Number(port.customer.lng).toFixed(7) : '',
        };
        pasted.value = '';
        locationError.value = '';
        warning.value = null;
        if (props.initial) {
            applyInitial(form, props.initial);
            form.customer = { ...emptyCustomer, ...((props.initial.customer as object | null) ?? {}) };
        }
    },
    { immediate: true },
);

function setLocation(lat: number, lng: number) {
    form.customer.lat = lat.toFixed(7);
    form.customer.lng = lng.toFixed(7);
}

async function useGps() {
    locating.value = true;
    locationError.value = '';
    try {
        const pos = await currentPosition();
        warning.value = null;
        setLocation(pos.lat, pos.lng);
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
        setLocation(loc.lat, loc.lng);
    } catch (e) {
        locationError.value = (e as Error).message;
    } finally {
        locating.value = false;
    }
}

function clearLocation() {
    warning.value = null;
    form.customer.lat = '';
    form.customer.lng = '';
}

function submit() {
    form.transform((data) => ({
        ...data,
        customer: { ...data.customer, lat: data.customer.lat || null, lng: data.customer.lng || null },
    })).patch(route('ports.update', { port: props.port!.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
}
const { saveLabel } = useApproval();
</script>

<template>
    <Dialog :open="!!port" @update:open="(open) => !open && emit('close')">
        <DialogContent class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Port {{ port?.number }} — {{ odpCode }}</DialogTitle>
                <DialogDescription>Ubah status port dan data pelanggan yang terhubung.</DialogDescription>
            </DialogHeader>

            <form id="port-form" class="grid gap-4" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label for="port_status">Status port</Label>
                    <NativeSelect id="port_status" v-model="form.status">
                        <option value="kosong">Kosong</option>
                        <option value="terpakai">Terpakai</option>
                        <option value="booking">Booking</option>
                        <option value="rusak">Rusak</option>
                    </NativeSelect>
                    <InputError :message="form.errors.status" />
                </div>

                <label v-if="form.status === 'terpakai'" class="flex items-start gap-2 text-sm">
                    <input v-model="form.customer_unknown" type="checkbox" class="mt-0.5 size-4 rounded border-input" />
                    <span>
                        Pelanggan belum diketahui
                        <span class="block text-xs text-muted-foreground"
                            >Port sudah dipakai tapi nama pelanggannya belum ada. Bisa dilengkapi nanti.</span
                        >
                    </span>
                </label>
                <p
                    v-if="form.status === 'terpakai' && form.customer_unknown && port?.customer"
                    class="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    Pelanggan <b>{{ port.customer.name }}</b> akan dilepas dari port ini.
                </p>

                <template v-if="form.status === 'terpakai' && !form.customer_unknown">
                    <div class="grid gap-1.5">
                        <Label for="c_name">Nama pelanggan</Label>
                        <Input id="c_name" v-model="form.customer.name" required />
                        <InputError :message="(form.errors as Record<string, string>)['customer.name']" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="c_code">ID pelanggan</Label>
                            <Input id="c_code" v-model="form.customer.customer_code" />
                            <InputError :message="(form.errors as Record<string, string>)['customer.customer_code']" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="c_phone">No. HP</Label>
                            <Input id="c_phone" v-model="form.customer.phone" inputmode="tel" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="c_address">Alamat</Label>
                        <Input id="c_address" v-model="form.customer.address" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-1.5">
                            <Label for="c_package">Paket</Label>
                            <Input id="c_package" v-model="form.customer.package" placeholder="20 Mbps" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="c_sn">SN ONT</Label>
                            <Input id="c_sn" v-model="form.customer.ont_sn" />
                        </div>
                    </div>
                    <div class="grid gap-1.5">
                        <div class="flex items-center justify-between">
                            <Label for="c_lat">Lokasi pelanggan</Label>
                            <div class="flex gap-1">
                                <Button
                                    v-if="form.customer.lat || form.customer.lng"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    title="Hapus lokasi"
                                    @click="clearLocation"
                                >
                                    <MapPinOff class="size-4" />
                                </Button>
                                <Button type="button" variant="outline" size="sm" :disabled="locating" @click="useGps">
                                    <LoaderCircle v-if="locating" class="size-4 animate-spin" />
                                    <Crosshair v-else class="size-4" />
                                    GPS
                                </Button>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">Pakai GPS di rumah pelanggan, atau tempel koordinat / link Google Maps.</p>
                        <div class="grid grid-cols-2 gap-2">
                            <Input id="c_lat" v-model="form.customer.lat" inputmode="decimal" placeholder="Latitude" />
                            <Input v-model="form.customer.lng" inputmode="decimal" placeholder="Longitude" />
                        </div>
                        <div class="flex gap-2">
                            <Input
                                v-model="pasted"
                                class="h-9"
                                placeholder="Tempel koordinat atau link Google Maps"
                                @keydown.enter.prevent="usePasted"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-9 shrink-0"
                                :disabled="locating || !pasted.trim()"
                                @click="usePasted"
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
                        <InputError
                            :message="
                                locationError ||
                                (form.errors as Record<string, string>)['customer.lat'] ||
                                (form.errors as Record<string, string>)['customer.lng']
                            "
                        />
                    </div>
                </template>
                <p
                    v-else-if="form.status !== 'terpakai' && port?.customer"
                    class="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    Pelanggan <b>{{ port.customer.name }}</b> akan dilepas dari port ini.
                </p>

                <div class="grid gap-1.5">
                    <Label for="port_notes">Catatan</Label>
                    <Input id="port_notes" v-model="form.notes" />
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="emit('close')">Batal</Button>
                <Button type="submit" form="port-form" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    {{ saveLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
