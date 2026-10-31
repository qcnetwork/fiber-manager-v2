<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getJson } from '@/lib/http';
import type { Olt, OltPort, Pop } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { Link2, LoaderCircle, Search, Unlink } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    pop: Pop | null;
    olt: Olt | null;
    port: OltPort | null;
    canEdit: boolean;
    canManage: boolean;
}>();

const emit = defineEmits<{
    changed: [];
    selectOdc: [id: number];
    close: [];
}>();

interface OdcChoice {
    id: number;
    code: string;
    distance: number | null;
}

// ---- Connecting: ODCs of this POP's area, closest to the POP first
const odcs = ref<OdcChoice[]>([]);
const filter = ref('');
const loading = ref(false);
const error = ref('');
const busy = ref(false);

watch(
    () => props.port,
    async (port) => {
        if (!port || !props.pop) return;
        filter.value = '';
        error.value = '';
        status.status = port.status;
        status.notes = port.notes ?? '';
        status.clearErrors();
        if (!props.canEdit) return;

        loading.value = true;
        try {
            odcs.value = await getJson<OdcChoice[]>(route('odcs.options'), { area_id: props.pop.id, lat: props.pop.lat, lng: props.pop.lng });
        } finally {
            loading.value = false;
        }
    },
);

const connected = computed(() => new Set(props.port?.odcs.map((o) => o.id)));
const choices = computed(() => {
    const q = filter.value.trim().toLowerCase();
    return odcs.value.filter((o) => !connected.value.has(o.id) && (!q || o.code.toLowerCase().includes(q))).slice(0, 30);
});

function connect(odc: OdcChoice) {
    busy.value = true;
    error.value = '';
    router.post(
        route('olt-ports.connect', { port: props.port!.id }),
        { odc_id: odc.id },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('changed'),
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Gagal menyambungkan.'),
            onFinish: () => (busy.value = false),
        },
    );
}

function disconnect(odc: { id: number; code: string }) {
    if (!confirm(`Lepas ${odc.code} dari port ini?`)) return;
    busy.value = true;
    router.delete(route('olt-ports.disconnect', { port: props.port!.id, odc: odc.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('changed'),
        onFinish: () => (busy.value = false),
    });
}

// ---- Admins: broken flag and note
const status = useForm({ status: 'normal' as OltPort['status'], notes: '' });
function saveStatus() {
    status.patch(route('olt-ports.update', { port: props.port!.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('changed'),
    });
}
</script>

<template>
    <Dialog :open="!!port" @update:open="(open) => !open && emit('close')">
        <DialogContent v-if="port && olt" class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ olt.code }} · slot {{ port.slot }} / port {{ port.port }}</DialogTitle>
                <DialogDescription>
                    {{
                        port.status === 'rusak'
                            ? 'Port ditandai rusak.'
                            : port.odcs.length
                              ? 'Port ini menyuplai ODC berikut.'
                              : 'Port ini belum dipakai.'
                    }}
                    <span v-if="port.notes"> Catatan: {{ port.notes }}</span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="port.odcs.length" class="grid gap-2">
                <Label>ODC tersambung</Label>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="odc in port.odcs" :key="odc.id" class="flex items-center gap-2 px-3 py-1.5">
                        <button
                            type="button"
                            class="flex-1 truncate text-left font-medium text-blue-600 hover:underline dark:text-blue-400"
                            @click="emit('selectOdc', odc.id)"
                        >
                            {{ odc.code }}
                        </button>
                        <Button v-if="canEdit" variant="ghost" size="icon" class="size-8" title="Lepas" :disabled="busy" @click="disconnect(odc)"
                            ><Unlink class="size-4"
                        /></Button>
                    </li>
                </ul>
            </div>

            <div v-if="canEdit" class="grid gap-2">
                <Label>{{ port.odcs.length ? 'Tambah ODC lain ke port ini' : 'Sambungkan ODC' }}</Label>
                <div class="relative">
                    <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input v-model="filter" class="pl-8" placeholder="Cari kode ODC…" />
                </div>
                <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground">
                    <LoaderCircle class="size-4 animate-spin" /> Memuat…
                </div>
                <ul v-else-if="choices.length" class="max-h-56 divide-y overflow-y-auto rounded-md border text-sm">
                    <li v-for="odc in choices" :key="odc.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-muted disabled:opacity-50"
                            :disabled="busy"
                            @click="connect(odc)"
                        >
                            <span class="flex items-center gap-2 truncate font-medium"><Link2 class="size-3.5 shrink-0" /> {{ odc.code }}</span>
                            <span v-if="odc.distance !== null" class="shrink-0 text-xs text-muted-foreground">{{ odc.distance }} m dari POP</span>
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">Tidak ada ODC yang cocok di area ini.</p>
                <p class="text-xs text-muted-foreground">ODC yang sudah tersambung ke port lain akan dipindah ke port ini.</p>
                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            </div>

            <form v-if="canManage" class="grid gap-2 border-t pt-3" @submit.prevent="saveStatus">
                <Label>Kondisi port</Label>
                <div class="flex gap-2">
                    <NativeSelect v-model="status.status" class="h-9 w-32">
                        <option value="normal">Normal</option>
                        <option value="rusak">Rusak</option>
                    </NativeSelect>
                    <Input v-model="status.notes" class="h-9" placeholder="Catatan (opsional)" />
                    <Button type="submit" size="sm" class="h-9" :disabled="status.processing">Simpan</Button>
                </div>
                <InputError :message="status.errors.status || status.errors.notes" />
            </form>
        </DialogContent>
    </Dialog>
</template>
