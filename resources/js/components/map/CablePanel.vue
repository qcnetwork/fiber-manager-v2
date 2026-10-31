<script setup lang="ts">
import StatusBadge from '@/components/map/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { cableTypes, endLabel, formatLength } from '@/lib/cables';
import type { Cable, CableEndKind } from '@/types';
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
const ends = computed(() => [
    { label: 'Dari', kind: props.cable.from_type, asset: props.cable.from },
    { label: 'Ke', kind: props.cable.to_type, asset: props.cable.to },
]);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="text-xs font-medium text-muted-foreground">Kabel {{ type.label }} · {{ cable.area?.name }}</div>
                <h2 class="text-lg font-semibold leading-tight">{{ cable.code ?? `Kabel ${type.label}` }}</h2>
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

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
            <template v-for="end in ends" :key="end.label">
                <dt class="text-muted-foreground">{{ end.label }}</dt>
                <dd>
                    <button
                        v-if="end.asset && end.kind"
                        class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                        @click="emit('selectAsset', end.kind, end.asset.id)"
                    >
                        {{ endLabel(end.kind, end.asset.code) }}
                    </button>
                    <span v-else class="text-muted-foreground">Titik bebas</span>
                </dd>
            </template>
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
