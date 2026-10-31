<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { cableTypes } from '@/lib/cables';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Download, FileSpreadsheet, Globe, LoaderCircle, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';

type Issue = { where: string; message: string };
interface ImportResult {
    ok: boolean;
    file: string;
    created: Partial<Record<'odc' | 'odp' | 'cable', number>>;
    updated: Partial<Record<'odc' | 'odp' | 'cable', number>>;
    skipped: Issue[];
    errors: Issue[];
}

const props = defineProps<{
    areas: { id: number; code: string; name: string }[];
    can: { import: boolean; chooseArea: boolean };
    maxRows: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Ekspor & Impor', href: '/data' }];

// ---- Export: plain links, the browser handles the download
const exportArea = ref<number | null>(null);
const exportUrl = (format: 'xlsx' | 'kml') => route('data.export', { format, ...(exportArea.value ? { area_id: exportArea.value } : {}) });

// ---- Import
const form = useForm({
    file: null as File | null,
    area_id: props.areas[0]?.id ?? null,
    point_mode: 'auto',
    import_lines: true,
    cable_type: 'distribusi',
});
const fileInput = ref<HTMLInputElement>();
const isKml = computed(() => /\.km[lz]$/i.test(form.file?.name ?? ''));

function submit() {
    form.post(route('data.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('file');
            if (fileInput.value) fileInput.value.value = '';
        },
    });
}

const page = usePage<{ flash: { importResult: ImportResult | null } }>();
const result = computed(() => page.props.flash.importResult);

const labels = { odc: 'ODC', odp: 'ODP', cable: 'kabel' } as const;
const summary = (counts: ImportResult['created']) =>
    (Object.keys(labels) as (keyof typeof labels)[])
        .filter((k) => counts[k])
        .map((k) => `${counts[k]} ${labels[k]}`)
        .join(', ') || '—';

const MAX_SHOWN = 200;
</script>

<template>
    <Head title="Ekspor & Impor" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid flex-1 items-start gap-4 p-4 lg:grid-cols-2">
            <!-- Export -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><Download class="size-4" /> Ekspor data</CardTitle>
                    <CardDescription>Unduh semua ODC, ODP, pelanggan, dan jalur kabel.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4">
                    <div v-if="can.chooseArea" class="grid gap-1.5">
                        <Label for="export_area">Area</Label>
                        <NativeSelect id="export_area" v-model="exportArea">
                            <option :value="null">Semua area</option>
                            <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }} ({{ area.code }})</option>
                        </NativeSelect>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <a :href="exportUrl('xlsx')" class="flex items-start gap-3 rounded-lg border p-3 transition hover:bg-muted">
                            <FileSpreadsheet class="mt-0.5 size-6 shrink-0 text-green-600" />
                            <span>
                                <span class="block font-medium">Excel (.xlsx)</span>
                                <span class="block text-xs text-muted-foreground"
                                    >Sheet ODC, ODP, Pelanggan, Kabel. Bisa diedit lalu diimpor kembali.</span
                                >
                            </span>
                        </a>
                        <a :href="exportUrl('kml')" class="flex items-start gap-3 rounded-lg border p-3 transition hover:bg-muted">
                            <Globe class="mt-0.5 size-6 shrink-0 text-blue-600" />
                            <span>
                                <span class="block font-medium">KML</span>
                                <span class="block text-xs text-muted-foreground"
                                    >Buka di Google Earth atau Google My Maps, lengkap dengan warna & jalur kabel.</span
                                >
                            </span>
                        </a>
                    </div>
                </CardContent>
            </Card>

            <!-- Import -->
            <Card v-if="can.import">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base"><Upload class="size-4" /> Impor data</CardTitle>
                    <CardDescription>
                        Excel (sheet "ODC" &amp; "ODP") atau KML/KMZ dari Google Earth/My Maps.
                        <a :href="route('data.template')" class="text-blue-600 hover:underline dark:text-blue-400">Unduh template Excel</a>.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div v-if="can.chooseArea" class="grid gap-1.5">
                            <Label for="import_area">Impor ke area</Label>
                            <NativeSelect id="import_area" v-model="form.area_id" required>
                                <option v-for="area in areas" :key="area.id" :value="area.id">{{ area.name }} ({{ area.code }})</option>
                            </NativeSelect>
                            <InputError :message="form.errors.area_id" />
                        </div>

                        <div class="grid gap-1.5">
                            <Label for="import_file">File</Label>
                            <input
                                id="import_file"
                                ref="fileInput"
                                type="file"
                                accept=".xlsx,.kml,.kmz"
                                required
                                class="block w-full rounded-md border border-input bg-background text-sm file:mr-3 file:border-0 file:bg-muted file:px-3 file:py-2 file:text-sm file:font-medium"
                                @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
                            />
                            <InputError :message="form.errors.file" />
                        </div>

                        <div v-if="isKml" class="grid gap-3 rounded-md border bg-muted/40 p-3">
                            <div class="grid gap-1.5">
                                <Label for="point_mode">Titik (placemark) diimpor sebagai</Label>
                                <NativeSelect id="point_mode" v-model="form.point_mode">
                                    <option value="auto">Otomatis — dari nama/folder yang mengandung "ODC" atau "ODP"</option>
                                    <option value="odp">Semua titik sebagai ODP</option>
                                    <option value="odc">Semua titik sebagai ODC</option>
                                </NativeSelect>
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="form.import_lines" type="checkbox" class="size-4 rounded border-input" />
                                Impor garis sebagai jalur kabel
                            </label>
                            <div v-if="form.import_lines" class="grid gap-1.5">
                                <Label for="cable_type">Jenis kabel jika tidak terbaca dari nama/folder</Label>
                                <NativeSelect id="cable_type" v-model="form.cable_type">
                                    <option v-for="(t, key) in cableTypes" :key="key" :value="key">{{ t.label }}</option>
                                </NativeSelect>
                            </div>
                        </div>

                        <ul class="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                            <li>
                                Kode adalah kunci: kode yang sudah ada <b>diperbarui</b>, kode baru <b>ditambahkan</b>. Sel kosong tidak mengubah data
                                lama.
                            </li>
                            <li>Semua baris diperiksa dulu. Jika ada satu saja yang salah, <b>tidak ada data yang disimpan</b>.</li>
                            <li>
                                Ujung garis kabel dalam radius 15 m dari ODC/ODP otomatis tersambung. Maksimal
                                {{ maxRows.toLocaleString('id-ID') }} baris per impor.
                            </li>
                        </ul>

                        <Button type="submit" :disabled="form.processing || !form.file">
                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                            <Upload v-else class="size-4" />
                            {{ form.processing ? 'Memproses…' : 'Periksa & impor' }}
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <!-- Result of the last import -->
            <Card v-if="result" class="lg:col-span-2" :class="result.ok ? 'border-green-500/50' : 'border-red-500/50'">
                <CardHeader class="pb-3">
                    <CardTitle
                        class="flex items-center gap-2 text-base"
                        :class="result.ok ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'"
                    >
                        <CircleCheck v-if="result.ok" class="size-5" />
                        <CircleAlert v-else class="size-5" />
                        {{ result.ok ? 'Impor berhasil' : `Impor dibatalkan — ${result.errors.length} kesalahan, tidak ada data yang disimpan` }}
                    </CardTitle>
                    <CardDescription>{{ result.file }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 text-sm">
                    <div v-if="result.ok" class="grid gap-1 sm:grid-cols-2">
                        <div><span class="text-muted-foreground">Ditambahkan:</span> {{ summary(result.created) }}</div>
                        <div><span class="text-muted-foreground">Diperbarui:</span> {{ summary(result.updated) }}</div>
                    </div>

                    <div v-for="(list, title) in { Kesalahan: result.errors, Dilewati: result.skipped }" :key="title">
                        <template v-if="list.length">
                            <h3 class="mb-1 font-semibold">{{ title }} ({{ list.length }})</h3>
                            <div class="max-h-80 overflow-auto rounded-md border">
                                <table class="w-full text-left text-sm">
                                    <tbody class="divide-y">
                                        <tr v-for="(issue, i) in list.slice(0, MAX_SHOWN)" :key="i">
                                            <td class="px-3 py-1.5 align-top font-medium whitespace-nowrap">{{ issue.where }}</td>
                                            <td class="px-3 py-1.5">{{ issue.message }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p v-if="list.length > MAX_SHOWN" class="mt-1 text-xs text-muted-foreground">
                                Menampilkan {{ MAX_SHOWN }} dari {{ list.length }}.
                            </p>
                        </template>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
