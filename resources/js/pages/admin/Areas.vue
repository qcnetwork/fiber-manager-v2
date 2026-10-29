<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Area, BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type AreaRow = Area & { users_count: number; odcs_count: number; odps_count: number };

defineProps<{ areas: AreaRow[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Area / Cabang', href: '/admin/areas' }];

const editing = ref<AreaRow | null>(null);
const form = useForm({ code: '', name: '', center_lat: '' as string | number, center_lng: '' as string | number });
const page = usePage();
const pageErrors = computed(() => page.props.errors as Record<string, string>);

function edit(area: AreaRow) {
    editing.value = area;
    form.clearErrors();
    form.code = area.code;
    form.name = area.name;
    form.center_lat = area.center_lat ?? '';
    form.center_lng = area.center_lng ?? '';
}

function reset() {
    editing.value = null;
    form.reset();
    form.clearErrors();
}

function submit() {
    const options = { preserveScroll: true, onSuccess: reset };
    if (editing.value) form.put(route('admin.areas.update', { area: editing.value.id }), options);
    else form.post(route('admin.areas.store'), options);
}

function destroy(area: AreaRow) {
    if (confirm(`Hapus area ${area.name}?`)) router.delete(route('admin.areas.destroy', { area: area.id }), { preserveScroll: true });
}
</script>

<template>
    <Head title="Area / Cabang" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="grid flex-1 items-start gap-4 p-4 lg:grid-cols-[1fr_22rem]">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Daftar area</CardTitle>
                </CardHeader>
                <CardContent class="overflow-x-auto">
                    <InputError class="mb-2" :message="pageErrors.area" />
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr>
                                <th class="py-2 font-medium">Kode</th>
                                <th class="py-2 font-medium">Nama</th>
                                <th class="py-2 text-right font-medium">User</th>
                                <th class="py-2 text-right font-medium">ODC</th>
                                <th class="py-2 text-right font-medium">ODP</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="area in areas" :key="area.id">
                                <td class="py-2 font-mono">{{ area.code }}</td>
                                <td class="py-2">{{ area.name }}</td>
                                <td class="py-2 text-right tabular-nums">{{ area.users_count }}</td>
                                <td class="py-2 text-right tabular-nums">{{ area.odcs_count }}</td>
                                <td class="py-2 text-right tabular-nums">{{ area.odps_count }}</td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <Button variant="ghost" size="icon" title="Edit" @click="edit(area)"><Pencil class="size-4" /></Button>
                                    <Button variant="ghost" size="icon" title="Hapus" @click="destroy(area)"
                                        ><Trash2 class="size-4 text-red-600"
                                    /></Button>
                                </td>
                            </tr>
                            <tr v-if="!areas.length">
                                <td colspan="6" class="py-6 text-center text-muted-foreground">Belum ada area.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">{{ editing ? `Edit ${editing.name}` : 'Tambah area' }}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form class="grid gap-4" @submit.prevent="submit">
                        <div class="grid gap-1.5">
                            <Label for="code">Kode</Label>
                            <Input id="code" v-model="form.code" required placeholder="BDG" />
                            <InputError :message="form.errors.code" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="name">Nama</Label>
                            <Input id="name" v-model="form.name" required placeholder="Bandung" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div class="grid gap-1.5">
                            <Label>Titik tengah peta (opsional)</Label>
                            <div class="grid grid-cols-2 gap-2">
                                <Input v-model="form.center_lat" inputmode="decimal" placeholder="Latitude" />
                                <Input v-model="form.center_lng" inputmode="decimal" placeholder="Longitude" />
                            </div>
                            <InputError :message="form.errors.center_lat || form.errors.center_lng" />
                        </div>
                        <div class="flex gap-2">
                            <Button type="submit" class="flex-1" :disabled="form.processing">Simpan</Button>
                            <Button v-if="editing" type="button" variant="outline" @click="reset">Batal</Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
