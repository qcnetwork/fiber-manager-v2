<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cableTypes } from '@/lib/cables';
import { coreInfo } from '@/lib/cores';
import { getJson } from '@/lib/http';
import type { CableEndKind, SpliceData } from '@/types';
import { router } from '@inertiajs/vue3';
import { FileText, LoaderCircle, Plus, Trash2 } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Core-to-core splices at one location (joint box/rosette, ODC, ODP): the list and a form to add a run of them.
 */
const props = defineProps<{
    kind: CableEndKind;
    id: number;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    selectCable: [id: number];
}>();

const data = ref<SpliceData | null>(null);
async function load() {
    data.value = await getJson<SpliceData>(route('splices.index'), { location_type: props.kind, location_id: props.id });
}
onMounted(load);
watch(() => [props.kind, props.id], load);

type SpliceCable = SpliceData['cables'][number];
const cableById = computed(() => new Map((data.value?.cables ?? []).map((c) => [c.id, c])));
const cableName = (cable: Pick<SpliceCable, 'id' | 'code' | 'type'> | undefined) =>
    cable ? (cable.code ?? `Kabel ${cableTypes[cable.type]?.label ?? ''} #${cable.id}`) : 'Kabel lain';

function coreText(cableId: number, number: number) {
    const cable = cableById.value.get(cableId);
    return cable ? `#${number} ${coreInfo(number, cable.tube_size).color.name}` : `#${number}`;
}
function coreColor(cableId: number, number: number) {
    const cable = cableById.value.get(cableId);
    return cable ? coreInfo(number, cable.tube_size) : null;
}

// ---- Adding: core A from cable A spliced to core B from cable B, optionally a run of consecutive cores
const adding = ref(false);
const form = ref({
    cableA: null as number | null,
    coreA: null as number | null,
    cableB: null as number | null,
    coreB: null as number | null,
    count: 1,
    notes: '',
});
const error = ref('');
const saving = ref(false);

function startAdding() {
    const cables = data.value?.cables ?? [];
    form.value = { cableA: cables[0]?.id ?? null, coreA: null, cableB: cables[1]?.id ?? cables[0]?.id ?? null, coreB: null, count: 1, notes: '' };
    error.value = '';
    adding.value = true;
}

/** Cores still free to splice here: not spliced at this location and not already spliced at both ends. */
function freeCores(cableId: number | null) {
    const cable = cableId ? cableById.value.get(cableId) : undefined;
    return (cable?.cores_list ?? []).filter((c) => !c.spliced_here && c.splices < (data.value?.max_splices ?? 2));
}
const freeA = computed(() => freeCores(form.value.cableA));
const freeB = computed(() => freeCores(form.value.cableB));

watch(
    () => form.value.cableA,
    () => (form.value.coreA = freeA.value[0]?.number ?? null),
);
watch(
    () => form.value.cableB,
    () => {
        // Splicing a cable to itself: start B on another core than A.
        const options = freeB.value.filter((c) => !(form.value.cableA === form.value.cableB && c.number === form.value.coreA));
        form.value.coreB = options[0]?.number ?? null;
    },
);

/** The pairs the form would make; stops at the first core that isn't free. */
const pairs = computed(() => {
    const { cableA, coreA, cableB, coreB, count } = form.value;
    const a = cableA ? cableById.value.get(cableA) : undefined;
    const b = cableB ? cableById.value.get(cableB) : undefined;
    if (!a || !b || !coreA || !coreB) return [];
    const result: { a: number; b: number; numberA: number; numberB: number }[] = [];
    for (let i = 0; i < Math.max(1, count); i++) {
        const ca = freeA.value.find((c) => c.number === coreA + i);
        const cb = freeB.value.find((c) => c.number === coreB + i);
        if (!ca || !cb || ca.id === cb.id) break;
        result.push({ a: ca.id, b: cb.id, numberA: ca.number, numberB: cb.number });
    }
    return result;
});

function save() {
    if (!pairs.value.length) return;
    saving.value = true;
    error.value = '';
    router.post(
        route('splices.store'),
        { location_type: props.kind, location_id: props.id, pairs: pairs.value.map(({ a, b }) => ({ a, b })), notes: form.value.notes || null },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                adding.value = false;
                load();
            },
            onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Gagal menyimpan sambungan.'),
            onFinish: () => (saving.value = false),
        },
    );
}

function remove(spliceId: number) {
    if (!confirm('Hapus sambungan core ini?')) return;
    router.delete(route('splices.destroy', { splice: spliceId }), { preserveScroll: true, preserveState: true, onSuccess: load });
}
</script>

<template>
    <div>
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Sambungan core ({{ data?.splices.length ?? 0 }})</h3>
            <div class="flex gap-1.5">
                <Button v-if="data?.splices.length" as="a" :href="route('splice-sheet', { kind, id })" target="_blank" variant="outline" size="sm">
                    <FileText class="size-4" /> Splice sheet
                </Button>
                <Button v-if="canEdit && data && data.cables.length && !adding" variant="outline" size="sm" @click="startAdding">
                    <Plus class="size-4" /> Sambung
                </Button>
            </div>
        </div>

        <div v-if="!data" class="flex items-center gap-2 text-sm text-muted-foreground"><LoaderCircle class="size-4 animate-spin" /> Memuat…</div>

        <template v-else>
            <p v-if="!data.cables.length" class="text-sm text-muted-foreground">
                Belum ada kabel yang tersambung ke titik ini, jadi belum ada core yang bisa disambung.
            </p>

            <!-- Add form -->
            <div v-if="adding" class="mb-3 flex flex-col gap-3 rounded-md border bg-muted/40 p-3">
                <div class="grid grid-cols-[1fr_auto] gap-2">
                    <div class="grid gap-1">
                        <Label class="text-xs">Kabel A</Label>
                        <NativeSelect v-model.number="form.cableA">
                            <option v-for="c in data.cables" :key="c.id" :value="c.id">{{ cableName(c) }} ({{ c.cores }} core)</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Core</Label>
                        <NativeSelect v-model.number="form.coreA" class="w-28">
                            <option v-for="c in freeA" :key="c.id" :value="c.number">{{ coreText(form.cableA!, c.number) }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Kabel B</Label>
                        <NativeSelect v-model.number="form.cableB">
                            <option v-for="c in data.cables" :key="c.id" :value="c.id">{{ cableName(c) }} ({{ c.cores }} core)</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Core</Label>
                        <NativeSelect v-model.number="form.coreB" class="w-28">
                            <option v-for="c in freeB" :key="c.id" :value="c.number">{{ coreText(form.cableB!, c.number) }}</option>
                        </NativeSelect>
                    </div>
                </div>
                <div class="grid grid-cols-[auto_1fr] items-end gap-2">
                    <div class="grid gap-1">
                        <Label class="text-xs">Jumlah core berurutan</Label>
                        <Input v-model.number="form.count" type="number" min="1" max="288" class="w-28" />
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Catatan (opsional)</Label>
                        <Input v-model="form.notes" placeholder="mis. tray 1" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    <template v-if="pairs.length">
                        Akan menyambung {{ pairs.length }} core:
                        {{
                            pairs.length === 1
                                ? `#${pairs[0].numberA} ↔ #${pairs[0].numberB}`
                                : `#${pairs[0].numberA}–${pairs.at(-1)!.numberA} ↔ #${pairs[0].numberB}–${pairs.at(-1)!.numberB}`
                        }}
                        <template v-if="pairs.length < form.count"> (berhenti di core yang sudah tersambung)</template>
                    </template>
                    <template v-else>Pilih core yang masih bebas di kedua kabel.</template>
                </p>
                <InputError :message="error" />
                <div class="flex gap-2">
                    <Button size="sm" :disabled="saving || !pairs.length" @click="save">
                        <LoaderCircle v-if="saving" class="size-4 animate-spin" /> Simpan sambungan
                    </Button>
                    <Button size="sm" variant="outline" @click="adding = false">Batal</Button>
                </div>
            </div>

            <!-- Splices made here -->
            <ul v-if="data.splices.length" class="divide-y rounded-md border text-sm">
                <li v-for="s in data.splices" :key="s.id" class="flex items-center gap-2 px-2 py-1.5">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-1.5">
                            <button class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="emit('selectCable', s.a.cable_id)">
                                {{ cableName(cableById.get(s.a.cable_id)) }}
                            </button>
                            <span
                                v-if="coreColor(s.a.cable_id, s.a.number)"
                                class="core-dot size-3"
                                :class="coreColor(s.a.cable_id, s.a.number)!.striped && 'is-striped'"
                                :style="{ '--c': coreColor(s.a.cable_id, s.a.number)!.color.hex }"
                            />
                            <span class="tabular-nums">{{ coreText(s.a.cable_id, s.a.number) }}</span>
                            <span class="text-muted-foreground">↔</span>
                            <button class="font-medium text-blue-600 hover:underline dark:text-blue-400" @click="emit('selectCable', s.b.cable_id)">
                                {{ cableName(cableById.get(s.b.cable_id)) }}
                            </button>
                            <span
                                v-if="coreColor(s.b.cable_id, s.b.number)"
                                class="core-dot size-3"
                                :class="coreColor(s.b.cable_id, s.b.number)!.striped && 'is-striped'"
                                :style="{ '--c': coreColor(s.b.cable_id, s.b.number)!.color.hex }"
                            />
                            <span class="tabular-nums">{{ coreText(s.b.cable_id, s.b.number) }}</span>
                        </div>
                        <div v-if="s.notes" class="text-xs text-muted-foreground">{{ s.notes }}</div>
                    </div>
                    <Button v-if="canEdit" variant="ghost" size="icon" class="size-7" title="Hapus sambungan" @click="remove(s.id)">
                        <Trash2 class="size-3.5" />
                    </Button>
                </li>
            </ul>
            <p v-else-if="data.cables.length && !adding" class="text-sm text-muted-foreground">Belum ada core yang disambung di sini.</p>
        </template>
    </div>
</template>
