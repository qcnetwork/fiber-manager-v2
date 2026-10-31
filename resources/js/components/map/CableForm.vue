<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cableTypes, endLabel, formatLength, pathLength } from '@/lib/cables';
import type { Area, Cable, CableEndpoint, CableType, LatLng } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle, Undo2, X } from '@lucide/vue';
import { computed, watch } from 'vue';

const props = defineProps<{
    cable: Cable | null;
    path: LatLng[];
    from: CableEndpoint | null;
    to: CableEndpoint | null;
    areas: Area[];
    canChooseArea: boolean;
    defaultAreaId: number | null;
}>();

const emit = defineEmits<{
    undo: [];
    clear: [];
    saved: [];
    cancel: [];
}>();

const isNew = !props.cable;
const defaultCores: Record<CableType, number> = { feeder: 48, distribusi: 12, drop: 1 };

const form = useForm({
    area_id: props.cable?.area_id ?? props.defaultAreaId,
    code: props.cable?.code ?? '',
    type: (props.cable?.type ?? 'distribusi') as CableType,
    cores: props.cable?.cores ?? 12,
    status: props.cable?.status ?? 'aktif',
    notes: props.cable?.notes ?? '',
});

// Suggest the usual core count when the type changes on a new cable.
watch(
    () => form.type,
    (type) => {
        if (isNew) form.cores = defaultCores[type];
    },
);

const length = computed(() => pathLength(props.path));

function submit() {
    const options = { preserveScroll: true, preserveState: true, onSuccess: () => emit('saved') };

    form.transform((data) => ({
        ...data,
        path: props.path,
        from_type: props.from?.type ?? null,
        from_id: props.from?.id ?? null,
        to_type: props.to?.type ?? null,
        to_id: props.to?.id ?? null,
    }));

    if (isNew) form.post(route('cables.store'), options);
    else form.put(route('cables.update', { cable: props.cable!.id }), options);
}

const errors = computed(() => form.errors as Record<string, string>);
const endError = computed(() => errors.value.from_id || errors.value.to_id || errors.value.from_type || errors.value.to_type);
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">{{ isNew ? 'Gambar jalur kabel' : 'Edit jalur kabel' }}</h2>
            <Button type="button" variant="ghost" size="icon" @click="emit('cancel')"><X class="size-4" /></Button>
        </div>

        <ul class="list-disc space-y-0.5 rounded-md bg-muted p-2 pl-6 text-xs text-muted-foreground">
            <li>Klik peta untuk menambah titik jalur (ikuti jalan/tiang).</li>
            <li>Klik ikon ODC/ODP untuk menyambung ujung kabel ke aset itu.</li>
            <li>Geser kotak kecil untuk memindah titik, klik kotak untuk menghapusnya.</li>
        </ul>

        <div class="rounded-md border p-3 text-sm">
            <div class="flex items-baseline justify-between">
                <span class="text-muted-foreground">Panjang</span>
                <span class="text-xl font-semibold tabular-nums">{{ formatLength(length) }}</span>
            </div>
            <div class="mt-1 text-xs text-muted-foreground">{{ path.length }} titik</div>
            <div class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                <span class="text-muted-foreground">Dari</span>
                <span class="font-medium">{{ from ? endLabel(from.type, from.code) : '— titik bebas —' }}</span>
                <span class="text-muted-foreground">Ke</span>
                <span class="font-medium">{{ to ? endLabel(to.type, to.code) : '— titik bebas —' }}</span>
            </div>
            <div class="mt-3 flex gap-2">
                <Button type="button" variant="outline" size="sm" :disabled="!path.length" @click="emit('undo')">
                    <Undo2 class="size-4" /> Batalkan titik
                </Button>
                <Button type="button" variant="outline" size="sm" :disabled="!path.length" @click="emit('clear')">Ulang dari awal</Button>
            </div>
            <InputError class="mt-2" :message="errors.path || endError" />
        </div>

        <div v-if="canChooseArea" class="grid gap-1.5">
            <Label for="cable_area">Area</Label>
            <NativeSelect id="cable_area" v-model="form.area_id" required>
                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }} ({{ area.code }})</option>
            </NativeSelect>
            <InputError :message="form.errors.area_id" />
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="cable_type">Jenis kabel</Label>
                <NativeSelect id="cable_type" v-model="form.type">
                    <option v-for="(t, key) in cableTypes" :key="key" :value="key">{{ t.label }}</option>
                </NativeSelect>
                <InputError :message="form.errors.type" />
            </div>
            <div class="grid gap-1.5">
                <Label for="cable_cores">Jumlah core</Label>
                <NativeSelect id="cable_cores" v-model.number="form.cores">
                    <option v-for="c in [1, 2, 4, 6, 8, 12, 24, 48, 72, 96, 144, 288]" :key="c" :value="c">{{ c }} core</option>
                </NativeSelect>
                <InputError :message="form.errors.cores" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="grid gap-1.5">
                <Label for="cable_code">Kode (opsional)</Label>
                <Input id="cable_code" v-model="form.code" placeholder="FDR-BDG-01" />
                <InputError :message="form.errors.code" />
            </div>
            <div class="grid gap-1.5">
                <Label for="cable_status">Status</Label>
                <NativeSelect id="cable_status" v-model="form.status">
                    <option value="aktif">Aktif</option>
                    <option value="rencana">Rencana</option>
                    <option value="rusak">Rusak</option>
                </NativeSelect>
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="cable_notes">Catatan</Label>
            <textarea
                id="cable_notes"
                v-model="form.notes"
                rows="2"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
            <InputError :message="form.errors.notes" />
        </div>

        <div class="flex gap-2">
            <Button type="submit" class="flex-1" :disabled="form.processing || path.length < 2">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Simpan kabel
            </Button>
            <Button type="button" variant="outline" @click="emit('cancel')">Batal</Button>
        </div>
    </form>
</template>
