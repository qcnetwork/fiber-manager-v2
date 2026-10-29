<script setup lang="ts">
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { NearestOdp } from '@/types';
import { Crosshair, LoaderCircle, X } from 'lucide-vue-next';

defineProps<{
    point: { lat: number; lng: number } | null;
    results: NearestOdp[] | null;
    loading: boolean;
    error: string;
}>();

const radius = defineModel<number>('radius', { required: true });

// Includes the configured coverage radius even when it is not one of the usual steps.
const radiusOptions = [...new Set([100, 150, 250, 350, 500, 1000, radius.value])].sort((a, b) => a - b);

const emit = defineEmits<{
    useGps: [];
    select: [odp: NearestOdp];
    close: [];
}>();
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold">Cari ODP terdekat</h2>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <p class="rounded-md bg-muted p-2 text-xs text-muted-foreground">
            Klik lokasi calon pelanggan di peta, atau pakai GPS. Hanya ODP dengan port kosong yang ditampilkan.
        </p>

        <div class="flex items-end gap-2">
            <div class="grid flex-1 gap-1.5">
                <Label for="radius">Radius</Label>
                <NativeSelect id="radius" v-model.number="radius">
                    <option v-for="r in radiusOptions" :key="r" :value="r">{{ r }} m</option>
                </NativeSelect>
            </div>
            <Button variant="outline" :disabled="loading" @click="emit('useGps')"><Crosshair class="size-4" /> GPS</Button>
        </div>

        <div v-if="point" class="font-mono text-xs text-muted-foreground">Titik: {{ point.lat.toFixed(6) }}, {{ point.lng.toFixed(6) }}</div>

        <div v-if="loading" class="flex items-center gap-2 text-sm text-muted-foreground"><LoaderCircle class="size-4 animate-spin" /> Mencari…</div>
        <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
        <template v-else-if="results">
            <p v-if="!results.length" class="text-sm text-muted-foreground">
                Tidak ada ODP dengan port kosong dalam radius {{ radius }} m. Coba perbesar radius.
            </p>
            <ul v-else class="divide-y rounded-md border text-sm">
                <li v-for="(odp, i) in results" :key="odp.id">
                    <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-muted" @click="emit('select', odp)">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="i === 0 ? 'bg-rose-600 text-white' : 'bg-muted text-muted-foreground'"
                            >{{ i + 1 }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ odp.code }}</div>
                            <div class="truncate text-xs text-muted-foreground">{{ odp.free_ports }} port kosong dari {{ odp.capacity }}</div>
                        </div>
                        <span class="text-sm font-semibold">{{ odp.distance }} m</span>
                    </button>
                </li>
            </ul>
            <p v-if="results.length" class="text-xs text-muted-foreground">
                Jarak garis lurus; panjang kabel drop sebenarnya biasanya lebih panjang mengikuti jalur tiang.
            </p>
        </template>
    </div>
</template>
