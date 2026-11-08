<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cableTypes, endLabel } from '@/lib/cables';
import { coreInfo, coreStatuses } from '@/lib/cores';
import type { CableType, CoreTrace } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer } from '@lucide/vue';
import { computed, ref } from 'vue';

type Segment = CoreTrace['segments'][number];

interface Row {
    splice_id: number | null;
    notes: string | null;
    a: Segment;
    b: Segment | null;
    trace: CoreTrace;
}

const props = defineProps<{
    sheet: { kind: string; id: number; title: string; subtitle: string; address: string | null; area: string | null };
    cables: { id: number; code: string | null; type: CableType; cores: number; tube_size: number; spliced_here: number }[];
    rows: Row[];
    generatedAt: string;
}>();

const isCable = props.sheet.kind === 'cable';

const cableName = (cable: { id: number; code: string | null; type: CableType }) =>
    cable.code ?? `Kabel ${cableTypes[cable.type]?.label ?? ''} #${cable.id}`;

function color(segment: Segment) {
    return coreInfo(segment.number, segment.cable.tube_size);
}

/** "T2 · 5" when the cable has several tubes, else just the core number. */
function corePosition(segment: Segment) {
    const info = color(segment);
    return segment.cable.cores > segment.cable.tube_size ? `T${info.tube} · ${segment.number}` : String(segment.number);
}

// ---- Filtering: by cable code, core number, asset code or notes; on a cable sheet optionally only spliced cores
const query = ref('');
const onlySpliced = ref(false);

function rowText(row: Row): string {
    const parts = [row.notes, row.a.notes, row.trace.start?.code, row.trace.end?.code];
    for (const s of row.trace.segments) parts.push(cableName(s.cable), `#${s.number}`, color(s).color.name);
    for (const j of row.trace.joins) parts.push(j.code);
    return parts.filter(Boolean).join(' ').toLowerCase();
}

const visibleRows = computed(() => {
    const q = query.value.trim().toLowerCase();
    return props.rows.filter((row) => (!onlySpliced.value || row.trace.segments.length > 1) && (!q || rowText(row).includes(q)));
});

/** Is this splice point the location the sheet is about? */
const isHere = (kind: string, id: number) => !isCable && kind === props.sheet.kind && id === props.sheet.id;

function printSheet() {
    window.print();
}
</script>

<template>
    <Head :title="`Splice sheet ${sheet.title}`" />

    <div class="min-h-svh bg-background text-foreground print:bg-white print:text-black">
        <div class="mx-auto max-w-6xl px-4 py-4 print:max-w-none print:px-0 print:py-0">
            <!-- Toolbar (not printed) -->
            <div class="mb-4 flex flex-wrap items-center gap-2 print:hidden">
                <Button as-child variant="outline" size="sm">
                    <Link href="/map"><ArrowLeft class="size-4" /> Peta</Link>
                </Button>
                <Button size="sm" @click="printSheet"><Printer class="size-4" /> Cetak / PDF</Button>
                <Input v-model="query" type="search" placeholder="Cari kabel, core, warna, ODP…" class="h-9 w-64" />
                <label v-if="isCable" class="flex items-center gap-2 text-sm">
                    <input v-model="onlySpliced" type="checkbox" class="size-4 rounded border-input" /> Hanya core yang disambung
                </label>
                <span class="ml-auto text-xs text-muted-foreground">{{ visibleRows.length }} dari {{ rows.length }} baris</span>
            </div>

            <!-- Header -->
            <header class="mb-4 flex flex-wrap items-end justify-between gap-2 border-b pb-3">
                <div>
                    <div class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Splice sheet · {{ sheet.subtitle }}<template v-if="sheet.area"> · {{ sheet.area }}</template>
                    </div>
                    <h1 class="text-2xl font-bold">{{ sheet.title }}</h1>
                    <div v-if="sheet.address" class="text-sm text-muted-foreground">{{ sheet.address }}</div>
                </div>
                <div class="text-right text-xs text-muted-foreground">Dibuat {{ generatedAt }}</div>
            </header>

            <!-- Cables at the location -->
            <section v-if="!isCable" class="mb-4">
                <h2 class="mb-1 text-sm font-semibold">Kabel di titik ini ({{ cables.length }})</h2>
                <div class="flex flex-wrap gap-2 text-sm">
                    <span v-for="c in cables" :key="c.id" class="inline-flex items-center gap-1.5 rounded border px-2 py-0.5">
                        <span class="inline-block h-1.5 w-4 rounded-full" :style="{ background: cableTypes[c.type]?.color }" />
                        <b>{{ cableName(c) }}</b>
                        <span class="text-muted-foreground">{{ c.cores }} core · {{ c.spliced_here }} disambung</span>
                    </span>
                </div>
            </section>

            <p v-if="!rows.length" class="rounded-md border p-4 text-sm text-muted-foreground">
                {{ isCable ? 'Kabel ini belum punya data core.' : 'Belum ada core yang disambung di titik ini.' }}
            </p>

            <!-- Sheet -->
            <div v-else class="overflow-x-auto rounded-md border print:overflow-visible print:rounded-none">
                <table class="w-full text-sm print:text-[10px]">
                    <thead class="bg-muted/60 text-left text-xs print:bg-gray-100">
                        <tr>
                            <th class="px-2 py-1.5">No</th>
                            <template v-if="isCable">
                                <th class="px-2 py-1.5">Core</th>
                                <th class="px-2 py-1.5">Status</th>
                                <th class="px-2 py-1.5">Keterangan</th>
                            </template>
                            <template v-else>
                                <th class="px-2 py-1.5">Kabel A · core</th>
                                <th class="px-2 py-1.5"></th>
                                <th class="px-2 py-1.5">Kabel B · core</th>
                            </template>
                            <th class="px-2 py-1.5">Mulai</th>
                            <th class="px-2 py-1.5">Jalur core</th>
                            <th class="px-2 py-1.5">Ujung</th>
                            <th v-if="!isCable" class="px-2 py-1.5">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, i) in visibleRows" :key="row.splice_id ?? row.a.core_id" class="break-inside-avoid align-top">
                            <td class="px-2 py-1.5 text-muted-foreground tabular-nums">{{ i + 1 }}</td>

                            <template v-if="isCable">
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span
                                            class="core-dot size-3.5"
                                            :class="color(row.a).striped && 'is-striped'"
                                            :style="{ '--c': color(row.a).color.hex }"
                                        />
                                        <b class="tabular-nums">{{ corePosition(row.a) }}</b>
                                        <span class="text-muted-foreground">{{ color(row.a).color.name }}</span>
                                    </span>
                                </td>
                                <td class="px-2 py-1.5">
                                    <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="coreStatuses[row.a.status].class">
                                        {{ coreStatuses[row.a.status].label }}
                                    </span>
                                </td>
                                <td class="px-2 py-1.5">{{ row.a.notes ?? '' }}</td>
                            </template>

                            <template v-else>
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <div class="font-medium">{{ cableName(row.a.cable) }}</div>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span
                                            class="core-dot size-3.5"
                                            :class="color(row.a).striped && 'is-striped'"
                                            :style="{ '--c': color(row.a).color.hex }"
                                        />
                                        <span class="tabular-nums">{{ corePosition(row.a) }}</span>
                                        <span class="text-muted-foreground">{{ color(row.a).color.name }}</span>
                                    </span>
                                </td>
                                <td class="px-1 py-1.5 text-center text-muted-foreground">↔</td>
                                <td class="px-2 py-1.5 whitespace-nowrap">
                                    <template v-if="row.b">
                                        <div class="font-medium">{{ cableName(row.b.cable) }}</div>
                                        <span class="inline-flex items-center gap-1.5">
                                            <span
                                                class="core-dot size-3.5"
                                                :class="color(row.b).striped && 'is-striped'"
                                                :style="{ '--c': color(row.b).color.hex }"
                                            />
                                            <span class="tabular-nums">{{ corePosition(row.b) }}</span>
                                            <span class="text-muted-foreground">{{ color(row.b).color.name }}</span>
                                        </span>
                                    </template>
                                </td>
                            </template>

                            <td class="px-2 py-1.5 font-medium whitespace-nowrap">
                                {{ row.trace.start ? endLabel(row.trace.start.kind, row.trace.start.code) : '—' }}
                            </td>

                            <!-- The full path: cable/core, splice point, cable/core, … -->
                            <td class="px-2 py-1.5">
                                <span class="flex flex-wrap items-center gap-x-1 gap-y-0.5">
                                    <template v-for="(segment, s) in row.trace.segments" :key="segment.core_id">
                                        <span class="inline-flex items-center gap-1 rounded border px-1 whitespace-nowrap">
                                            <span
                                                class="core-dot size-2.5"
                                                :class="color(segment).striped && 'is-striped'"
                                                :style="{ '--c': color(segment).color.hex }"
                                            />
                                            {{ cableName(segment.cable) }} <span class="text-muted-foreground">#{{ segment.number }}</span>
                                        </span>
                                        <template v-if="row.trace.joins[s]">
                                            <span class="text-muted-foreground">▸</span>
                                            <span
                                                class="whitespace-nowrap"
                                                :class="
                                                    isHere(row.trace.joins[s].kind, row.trace.joins[s].id) ? 'font-bold' : 'text-muted-foreground'
                                                "
                                            >
                                                {{ endLabel(row.trace.joins[s].kind, row.trace.joins[s].code ?? '?') }}
                                            </span>
                                            <span class="text-muted-foreground">▸</span>
                                        </template>
                                    </template>
                                </span>
                            </td>

                            <td class="px-2 py-1.5 font-medium whitespace-nowrap">
                                {{ row.trace.end ? endLabel(row.trace.end.kind, row.trace.end.code) : '—' }}
                            </td>
                            <td v-if="!isCable" class="px-2 py-1.5 text-muted-foreground">{{ row.notes ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="mt-3 text-xs text-muted-foreground print:mt-2">
                Mulai/Ujung = ujung kabel di awal dan akhir jalur core. "—" berarti ujung kabel belum tersambung ke aset. Warna core mengikuti standar
                TIA-598{{ isCable ? '' : '; titik ini dicetak tebal di kolom jalur' }}.
            </p>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: A4 landscape;
        margin: 10mm;
    }
    /* Keep core colors when printing. */
    .core-dot {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
}
</style>
