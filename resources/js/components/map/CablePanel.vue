<script setup lang="ts">
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { cableTypes, endLabel, formatLength } from '@/lib/cables';
import type { Cable, CableEndKind, CableRoutePoint } from '@/types';
import { Pencil, Trash2, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    cable: Cable;
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{
    edit: [];
    delete: [];
    close: [];
    selectAsset: [kind: CableEndKind, id: number];
}>();

const type = computed(() => cableTypes[props.cable.type]);

/** Start, pass-through points and end; older responses without a route fall back to the two ends. */
const route = computed<CableRoutePoint[]>(
    () =>
        props.cable.route ?? [
            { role: 'from', kind: props.cable.from_type, asset: props.cable.from ?? null, vertex: 0, distance_m: 0 },
            {
                role: 'to',
                kind: props.cable.to_type,
                asset: props.cable.to ?? null,
                vertex: props.cable.path.length - 1,
                distance_m: props.cable.length_m,
            },
        ],
);
const viaCount = computed(() => route.value.filter((p) => p.role === 'via').length);
const roleLabel = { from: 'Awal', via: 'Lewat', to: 'Ujung' };
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">Kabel {{ type.label }} · {{ cable.area?.name }}</div>
                <h2 class="text-lg leading-tight font-semibold">{{ cable.code ?? `Kabel ${type.label}` }}</h2>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :status="cable.status" />
            <span class="inline-flex items-center gap-1.5 text-sm">
                <span class="inline-block h-1.5 w-5 rounded-full" :style="{ background: type.color }" />
                {{ type.label }}
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-md border p-3">
                <div class="text-xs text-muted-foreground">Panjang</div>
                <div class="text-xl font-semibold tabular-nums">{{ formatLength(cable.length_m) }}</div>
            </div>
            <div class="rounded-md border p-3">
                <div class="text-xs text-muted-foreground">Kapasitas</div>
                <div class="text-xl font-semibold tabular-nums">{{ cable.cores }} core</div>
            </div>
        </div>

        <div>
            <div class="mb-2 flex items-baseline justify-between">
                <h3 class="text-sm font-semibold">Rute kabel</h3>
                <span class="text-xs text-muted-foreground">{{ viaCount ? `${viaCount} titik sambung` : 'tanpa titik sambung' }}</span>
            </div>
            <ol class="relative">
                <li v-for="(point, i) in route" :key="`${point.role}-${point.vertex}`" class="relative flex gap-3 pb-1">
                    <!-- Line down to the next point, with the leg length beside it. -->
                    <div class="flex w-4 flex-col items-center">
                        <span
                            class="mt-1 block size-3 shrink-0 rounded-full border-2"
                            :class="point.asset ? 'border-green-600 bg-green-600' : 'border-muted-foreground bg-background'"
                        />
                        <span v-if="i < route.length - 1" class="w-0.5 flex-1" :style="{ background: type.color }" />
                    </div>
                    <div class="min-w-0 flex-1 pb-2">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-xs text-muted-foreground">{{ roleLabel[point.role] }}</span>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ formatLength(point.distance_m) }}</span>
                        </div>
                        <button
                            v-if="point.asset && point.kind"
                            class="text-left text-sm font-medium text-blue-600 hover:underline dark:text-blue-400"
                            @click="emit('selectAsset', point.kind, point.asset.id)"
                        >
                            {{ endLabel(point.kind, point.asset.code) }}
                        </button>
                        <span v-else class="text-sm text-muted-foreground">Titik bebas</span>
                        <div v-if="i < route.length - 1" class="mt-1 text-xs text-muted-foreground">
                            ↓ {{ formatLength(route[i + 1].distance_m - point.distance_m) }}
                        </div>
                    </div>
                </li>
            </ol>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <dt class="text-muted-foreground">Titik jalur</dt>
            <dd>{{ cable.path.length }}</dd>
            <template v-if="cable.notes">
                <dt class="text-muted-foreground">Catatan</dt>
                <dd class="whitespace-pre-line">{{ cable.notes }}</dd>
            </template>
        </dl>

        <div class="flex flex-wrap gap-2">
            <Button v-if="canEdit" variant="outline" size="sm" @click="emit('edit')"><Pencil class="size-4" /> Edit jalur</Button>
            <Button v-if="canDelete" variant="destructive" size="sm" @click="emit('delete')"><Trash2 class="size-4" /> Hapus</Button>
        </div>
    </div>
</template>
