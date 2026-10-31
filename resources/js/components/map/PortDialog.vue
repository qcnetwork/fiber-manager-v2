<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { OdpPort } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { watch } from 'vue';

const props = defineProps<{
    port: OdpPort | null;
    odpCode: string;
}>();

const emit = defineEmits<{
    saved: [];
    close: [];
}>();

const emptyCustomer = { name: '', customer_code: '', phone: '', address: '', package: '', ont_sn: '' };

const form = useForm({
    status: 'kosong' as OdpPort['status'],
    notes: '',
    customer: { ...emptyCustomer },
});

watch(
    () => props.port,
    (port) => {
        if (!port) return;
        form.clearErrors();
        form.status = port.status;
        form.notes = port.notes ?? '';
        form.customer = {
            name: port.customer?.name ?? '',
            customer_code: port.customer?.customer_code ?? '',
            phone: port.customer?.phone ?? '',
            address: port.customer?.address ?? '',
            package: port.customer?.package ?? '',
            ont_sn: port.customer?.ont_sn ?? '',
        };
    },
    { immediate: true },
);

function submit() {
    form.patch(route('ports.update', { port: props.port!.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
}
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
                        <option value="terpakai">Terpakai (ada pelanggan)</option>
                        <option value="booking">Booking</option>
                        <option value="rusak">Rusak</option>
                    </NativeSelect>
                    <InputError :message="form.errors.status" />
                </div>

                <template v-if="form.status === 'terpakai'">
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
                </template>
                <p v-else-if="port?.customer" class="rounded-md bg-amber-50 p-2 text-xs text-amber-800 dark:bg-amber-950 dark:text-amber-200">
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
                    Simpan
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
