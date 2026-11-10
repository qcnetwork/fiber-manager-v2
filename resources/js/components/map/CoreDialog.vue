<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cableTypes, endLabel } from '@/lib/cables';
import { coreInfo, coreLabel, coreStatuses, endTerminationLabel } from '@/lib/cores';
import { getJson } from '@/lib/http';
import type { Cable, CableCore, CableEndKind, CoreStatus, CoreTrace } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    cable: Cable;
    core: CableCore | null;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    saved: [];
    close: [];
    selectCable: [id: number];
    selectAsset: [kind: CableEndKind, id: number];
}>();

// ---- The fiber path this core is part of
/** One path per piece of the core: a core cut where its cable passes a joint carries a separate fiber each way. */
const traces = ref<CoreTrace[] | null>(null);
const traceLoading = ref(false);
watch(
    () => props.core?.id,
    async (id) => {
        traces.value = null;
        if (!id) return;
        traceLoading.value = true;
        try {
            traces.value = (await getJson<{ traces: CoreTrace[] }>(route('cable-cores.trace', { core: id }))).traces;
        } finally {
            traceLoading.value = false;
        }
    },
    { immediate: true },
);

type Segment = CoreTrace['segments'][number];
const segmentName = (s: Segment) => s.cable.code ?? `Kabel ${cableTypes[s.cable.type]?.label ?? ''} #${s.cable.id}`;
/** Where this piece of the core runs, e.g. "ODC-01 → JB-02". */
const spanText = (s: Segment) => `${s.span.from?.code ?? 'ujung kabel'} → ${s.span.to?.code ?? 'ujung kabel'}`;
/** One uncut core, spliced to nothing and ending on nothing. */
const isUnconnected = computed(() => {
    const t = traces.value;
    return !!t && t.length === 1 && t[0].segments.length === 1 && !t[0].start_termination && !t[0].end_termination;
});

const form = useForm({ status: 'kosong' as CoreStatus, notes: '' });

watch(
    () => props.core,
    (core) => {
        if (!core) return;
        form.status = core.status;
        form.notes = core.notes ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

const info = computed(() => (props.core ? coreInfo(props.core.number, props.cable.tube_size) : null));
const cableName = computed(() => props.cable.code ?? `Kabel #${props.cable.id}`);

function submit() {
    if (!props.core) return;
    form.patch(route('cable-cores.update', { core: props.core.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('saved'),
    });
}
</script>

<template>
    <Dialog :open="!!core" @update:open="(open) => !open && emit('close')">
        <DialogContent v-if="core && info" class="max-h-[90svh] overflow-y-auto sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span class="core-dot size-4" :style="{ '--c': info.color.hex }" :class="info.striped && 'is-striped'" />
                    Core {{ core.number }} — {{ cableName }}
                </DialogTitle>
                <DialogDescription>{{ coreLabel(core.number, cable.tube_size, cable.cores) }}</DialogDescription>
            </DialogHeader>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="grid gap-1.5">
                    <Label>Status</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="(s, key) in coreStatuses"
                            :key="key"
                            type="button"
                            class="rounded-md border px-2 py-1.5 text-sm"
                            :class="form.status === key ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                            :disabled="!canEdit"
                            @click="form.status = key"
                        >
                            {{ s.label }}
                        </button>
                    </div>
                    <InputError :message="form.errors.status" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="core_notes">Keterangan (dipakai untuk apa)</Label>
                    <Input id="core_notes" v-model="form.notes" :disabled="!canEdit" placeholder="mis. Feeder ke ODC-03 port 2" />
                    <InputError :message="form.errors.notes" />
                </div>

                <div class="grid gap-1.5">
                    <Label>Jalur core</Label>
                    <div v-if="traceLoading" class="flex items-center gap-2 text-sm text-muted-foreground">
                        <LoaderCircle class="size-4 animate-spin" /> Menelusuri…
                    </div>
                    <p v-else-if="traces && isUnconnected" class="text-sm text-muted-foreground">
                        Core ini belum disambung ke core kabel lain atau ke input/port ODP/ODC. Sambungan dibuat di panel Joint/Roset, ODC atau ODP.
                    </p>
                    <template v-else-if="traces">
                        <p v-if="traces.length > 1" class="text-xs text-muted-foreground">
                            Core ini dipotong di tengah jalur, jadi membawa {{ traces.length }} jalur terpisah.
                        </p>
                        <ol v-for="(trace, t) in traces" :key="t" class="rounded-md border p-2 text-sm">
                            <li v-if="traces.length > 1" class="pb-1 text-xs font-semibold">Jalur {{ t + 1 }}</li>
                            <li v-if="trace.start || trace.start_termination" class="pb-1 text-xs text-muted-foreground">
                                Mulai:
                                <button
                                    v-if="trace.start"
                                    class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    @click="emit('selectAsset', trace.start.kind, trace.start.id)"
                                >
                                    {{ endLabel(trace.start.kind, trace.start.code) }}
                                </button>
                                <b v-if="trace.start_termination" class="text-foreground">
                                    · {{ endTerminationLabel(trace.start_termination, trace.start) }}
                                </b>
                            </li>
                            <template v-for="(segment, i) in trace.segments" :key="segment.piece">
                                <li class="flex flex-wrap items-center gap-x-2 rounded px-1 py-1" :class="segment.core_id === core.id && 'bg-muted'">
                                    <span
                                        class="core-dot size-3.5"
                                        :class="coreInfo(segment.number, segment.cable.tube_size).striped && 'is-striped'"
                                        :style="{ '--c': coreInfo(segment.number, segment.cable.tube_size).color.hex }"
                                    />
                                    <button
                                        class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        @click="emit('selectCable', segment.cable.id)"
                                    >
                                        {{ segmentName(segment) }}
                                    </button>
                                    <span class="text-muted-foreground">
                                        core {{ segment.number }} · {{ coreInfo(segment.number, segment.cable.tube_size).color.name }}
                                    </span>
                                    <span class="w-full pl-5 text-xs text-muted-foreground">{{ spanText(segment) }}</span>
                                </li>
                                <li v-if="trace.joins[i]" class="py-0.5 pl-6 text-xs text-muted-foreground">
                                    ↕ disambung di
                                    <button
                                        class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                        @click="emit('selectAsset', trace.joins[i].kind, trace.joins[i].id)"
                                    >
                                        {{ endLabel(trace.joins[i].kind, trace.joins[i].code ?? '?') }}
                                    </button>
                                    <template v-if="trace.joins[i].notes"> ({{ trace.joins[i].notes }})</template>
                                </li>
                            </template>
                            <li v-if="trace.end || trace.end_termination" class="pt-1 text-xs text-muted-foreground">
                                Selesai:
                                <button
                                    v-if="trace.end"
                                    class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    @click="emit('selectAsset', trace.end.kind, trace.end.id)"
                                >
                                    {{ endLabel(trace.end.kind, trace.end.code) }}
                                </button>
                                <b v-if="trace.end_termination" class="text-foreground">
                                    · {{ endTerminationLabel(trace.end_termination, trace.end) }}
                                </b>
                            </li>
                        </ol>
                    </template>
                </div>

                <DialogFooter v-if="canEdit" class="gap-2">
                    <Button type="button" variant="outline" @click="emit('close')">Batal</Button>
                    <Button type="submit" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Simpan
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
