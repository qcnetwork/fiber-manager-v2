<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cableTypes } from '@/lib/cables';
import { coreInfo, terminationLabel } from '@/lib/cores';
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
    /** Terminations changed: the ODP panel shows them on its ports. */
    changed: [];
}>();

/** "ODP" / "ODC" in texts about ending cores here. */
const terminalLabel = computed(() => (data.value?.terminal?.kind ?? props.kind).toUpperCase());

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

// ---- ODP only: a core ending on a splitter input or straight on an output port
const terminating = ref(false);
const termForm = ref({ cable: null as number | null, core: null as number | null, target: '' as string, notes: '' });
const termError = ref('');

function startTerminating() {
    termForm.value = { cable: data.value?.cables[0]?.id ?? null, core: null, target: targets.value.find((t) => !t.taken)?.value ?? '', notes: '' };
    termError.value = '';
    terminating.value = true;
}
const freeTerm = computed(() => freeCores(termForm.value.cable));
watch(
    () => termForm.value.cable,
    () => (termForm.value.core = freeTerm.value[0]?.number ?? null),
);

/** Splitter inputs and output ports to end a core on; ones already holding a core are shown but disabled. */
const targets = computed(() => {
    const odp = data.value?.terminal;
    if (!odp) return [];
    const taken = new Set((data.value?.terminations ?? []).map((t) => `${t.kind}:${t.port}`));
    const inputs = Array.from({ length: odp.max_inputs }, (_, i) => ({
        value: `input:${i + 1}`,
        label: `Input splitter ${i + 1}${odp.splitter_ratio && i === 0 ? ` (${odp.splitter_ratio})` : ''}`,
        taken: taken.has(`input:${i + 1}`),
    }));
    const outputs = odp.ports.map((p) => ({
        value: `output:${p.number}`,
        label: `Port ${p.number}${p.feeds ? ` · ${p.feeds}` : p.status !== 'kosong' ? ` · ${p.status}` : ''}`,
        taken: taken.has(`output:${p.number}`),
    }));
    return [...inputs, ...outputs];
});

function saveTermination() {
    const core = freeTerm.value.find((c) => c.number === termForm.value.core);
    const [kind, port] = termForm.value.target.split(':');
    if (!core || !kind) return;
    saving.value = true;
    termError.value = '';
    router.post(
        route('terminations.store'),
        { location_type: props.kind, location_id: props.id, core_id: core.id, kind, port: Number(port), notes: termForm.value.notes || null },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                terminating.value = false;
                load();
                emit('changed');
            },
            onError: (errors) => (termError.value = Object.values(errors)[0] ?? 'Gagal menyimpan.'),
            onFinish: () => (saving.value = false),
        },
    );
}

function removeTermination(id: number) {
    if (!confirm(`Lepas core ini dari ${terminalLabel.value}?`)) return;
    router.delete(route('terminations.destroy', { termination: id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            load();
            emit('changed');
        },
    });
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

            <!-- ODP: cores ending on the splitter input or an output port -->
            <div v-if="data.terminal" class="mt-4">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-sm font-semibold">Core ke input/output {{ terminalLabel }} ({{ data.terminations.length }})</h3>
                    <Button v-if="canEdit && data.cables.length && !terminating" variant="outline" size="sm" @click="startTerminating">
                        <Plus class="size-4" /> Sambung ke {{ terminalLabel }}
                    </Button>
                </div>

                <div v-if="terminating" class="mb-3 flex flex-col gap-3 rounded-md border bg-muted/40 p-3">
                    <div class="grid grid-cols-[1fr_auto] gap-2">
                        <div class="grid gap-1">
                            <Label class="text-xs">Kabel</Label>
                            <NativeSelect v-model.number="termForm.cable">
                                <option v-for="c in data.cables" :key="c.id" :value="c.id">{{ cableName(c) }} ({{ c.cores }} core)</option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Core</Label>
                            <NativeSelect v-model.number="termForm.core" class="w-28">
                                <option v-for="c in freeTerm" :key="c.id" :value="c.number">{{ coreText(termForm.cable!, c.number) }}</option>
                            </NativeSelect>
                        </div>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Disambung ke</Label>
                        <NativeSelect v-model="termForm.target">
                            <optgroup label="Input (masuk ke splitter)">
                                <option
                                    v-for="t in targets.filter((t) => t.value.startsWith('input'))"
                                    :key="t.value"
                                    :value="t.value"
                                    :disabled="t.taken"
                                >
                                    {{ t.label }}{{ t.taken ? ' — sudah dipakai' : '' }}
                                </option>
                            </optgroup>
                            <optgroup label="Output (langsung ke port)">
                                <option
                                    v-for="t in targets.filter((t) => t.value.startsWith('output'))"
                                    :key="t.value"
                                    :value="t.value"
                                    :disabled="t.taken"
                                >
                                    {{ t.label }}{{ t.taken ? ' — sudah dipakai' : '' }}
                                </option>
                            </optgroup>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-1">
                        <Label class="text-xs">Catatan (opsional)</Label>
                        <Input v-model="termForm.notes" />
                    </div>
                    <InputError :message="termError" />
                    <div class="flex gap-2">
                        <Button size="sm" :disabled="saving || !termForm.core || !termForm.target" @click="saveTermination">
                            <LoaderCircle v-if="saving" class="size-4 animate-spin" /> Simpan
                        </Button>
                        <Button size="sm" variant="outline" @click="terminating = false">Batal</Button>
                    </div>
                </div>

                <ul v-if="data.terminations.length" class="divide-y rounded-md border text-sm">
                    <li v-for="t in data.terminations" :key="t.id" class="flex items-center gap-2 px-2 py-1.5">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-x-1.5">
                                <button
                                    class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                    @click="emit('selectCable', t.core.cable_id)"
                                >
                                    {{ cableName(cableById.get(t.core.cable_id)) }}
                                </button>
                                <span
                                    v-if="coreColor(t.core.cable_id, t.core.number)"
                                    class="core-dot size-3"
                                    :class="coreColor(t.core.cable_id, t.core.number)!.striped && 'is-striped'"
                                    :style="{ '--c': coreColor(t.core.cable_id, t.core.number)!.color.hex }"
                                />
                                <span class="tabular-nums">{{ coreText(t.core.cable_id, t.core.number) }}</span>
                                <span class="text-muted-foreground">→</span>
                                <span class="font-medium">
                                    {{
                                        terminationLabel(
                                            { ...t, feeds: t.kind === 'output' ? data.terminal.ports.find((p) => p.number === t.port)?.feeds : null },
                                            false,
                                        )
                                    }}
                                </span>
                            </div>
                            <div v-if="t.notes" class="text-xs text-muted-foreground">{{ t.notes }}</div>
                        </div>
                        <Button
                            v-if="canEdit"
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            :title="`Lepas dari ${terminalLabel}`"
                            @click="removeTermination(t.id)"
                        >
                            <Trash2 class="size-3.5" />
                        </Button>
                    </li>
                </ul>
                <p v-else-if="!terminating" class="text-sm text-muted-foreground">
                    Belum ada core yang masuk ke splitter atau port {{ terminalLabel }} ini.
                </p>
            </div>
        </template>
    </div>
</template>
