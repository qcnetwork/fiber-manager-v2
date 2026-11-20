<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { mapLayerToggles, type MapLayerPrefs } from '@/lib/mapLayers';
import { type BreadcrumbItem } from '@/types';

const props = defineProps<{
    layers: MapLayerPrefs;
    defaults: MapLayerPrefs;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Pengaturan peta', href: '/settings/map' }];

const form = useForm<MapLayerPrefs>({ ...props.layers });

const bases: { value: MapLayerPrefs['base']; label: string }[] = [
    { value: 'peta', label: 'Peta' },
    { value: 'satelit', label: 'Satelit' },
];

function save() {
    form.put(route('map-settings.update'), { preserveScroll: true });
}

function resetToDefaults() {
    Object.assign(form, props.defaults);
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Pengaturan peta" />

        <SettingsLayout>
            <form class="space-y-6" @submit.prevent="save">
                <HeadingSmall
                    title="Pengaturan peta"
                    description="Layer yang langsung tampil saat Peta Jaringan dibuka (centang di kanan atas peta). Di peta tetap bisa diubah sementara."
                />

                <fieldset class="space-y-2">
                    <legend class="mb-2 text-sm font-medium">Peta dasar</legend>
                    <div class="flex gap-2">
                        <label
                            v-for="base in bases"
                            :key="base.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-muted"
                        >
                            <input v-model="form.base" type="radio" name="base" :value="base.value" class="accent-primary" />
                            {{ base.label }}
                        </label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium">Layer yang dicentang</legend>
                    <ul class="divide-y rounded-md border">
                        <li v-for="layer in mapLayerToggles" :key="layer.key">
                            <Label :for="`layer-${layer.key}`" class="flex cursor-pointer items-start gap-3 p-3 font-normal">
                                <Checkbox :id="`layer-${layer.key}`" v-model:checked="form[layer.key]" class="mt-0.5" />
                                <span class="grid gap-0.5">
                                    <span class="text-sm font-medium">{{ layer.label }}</span>
                                    <span class="text-xs text-muted-foreground">{{ layer.description }}</span>
                                </span>
                            </Label>
                        </li>
                    </ul>
                </fieldset>

                <div class="flex flex-wrap items-center gap-3">
                    <Button type="submit" :disabled="form.processing">Simpan</Button>
                    <Button type="button" variant="outline" @click="resetToDefaults">Kembalikan bawaan</Button>
                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p v-show="form.recentlySuccessful" class="text-sm text-muted-foreground">Tersimpan.</p>
                    </Transition>
                </div>
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
