<script setup lang="ts">
import PhotoGallery from '@/components/map/PhotoGallery.vue';
import { Button } from '@/components/ui/button';
import { activityActions, activityTypes, changeLines, type ActivityAction } from '@/lib/activity';
import type { DraftItem, PhotoOwner } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Pencil, Trash2, TriangleAlert, X } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{
    item: DraftItem;
    /** Photo drafts added to this (draft) asset. */
    photos: DraftItem[];
    /** The technician's own draft (editable); otherwise an item of a batch an approver looks at. */
    own: boolean;
}>();

const emit = defineEmits<{
    close: [];
    edit: [];
    discard: [];
    changed: [];
}>();

const main = computed(() => props.item.preview?.[0] ?? null);
const related = computed(() => props.item.preview?.slice(1) ?? []);
const action = computed(() => activityActions[props.item.action as ActivityAction]);
const photoOwner = computed<PhotoOwner | null>(() =>
    props.item.action === 'created' && ['odc', 'odp', 'joint'].includes(props.item.kind) ? (props.item.kind as PhotoOwner) : null,
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                    <span class="rounded bg-orange-100 px-1.5 py-0.5 font-semibold text-orange-800 dark:bg-orange-950 dark:text-orange-300">
                        {{ own ? 'DRAFT' : 'MENUNGGU APPROVAL' }}
                    </span>
                    <span v-if="action" class="rounded px-1.5 py-0.5 font-medium" :class="action.class">{{ action.label }}</span>
                    <span class="text-muted-foreground">{{ activityTypes[item.kind] ?? item.kind }}</span>
                </div>
                <h2 class="mt-1 text-lg font-semibold break-all">{{ item.label }}</h2>
            </div>
            <Button variant="ghost" size="icon" @click="emit('close')"><X class="size-4" /></Button>
        </div>

        <p class="text-xs text-muted-foreground">
            {{
                own
                    ? 'Belum tersimpan ke data jaringan. Kirim semua draft ke approval lewat bar draft di peta.'
                    : 'Bagian dari paket yang menunggu persetujuan.'
            }}
        </p>

        <p
            v-if="item.conflict"
            class="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" /> {{ item.conflict }}
        </p>

        <dl
            v-if="main && changeLines(main.action, main.changes).length"
            class="grid grid-cols-[auto_1fr] gap-x-3 rounded-md border px-3 py-2 text-xs"
        >
            <template v-for="line in changeLines(main.action, main.changes)" :key="line.field">
                <dt class="text-muted-foreground">{{ line.field }}</dt>
                <dd class="mb-0.5 break-all">{{ line.text }}</dd>
            </template>
        </dl>
        <details v-if="related.length" class="text-xs">
            <summary class="cursor-pointer text-muted-foreground">+ {{ related.length }} perubahan terkait</summary>
            <ul class="mt-1 flex flex-col gap-0.5">
                <li v-for="(entry, i) in related" :key="i">{{ activityTypes[entry.type] ?? entry.type }} {{ entry.label }}</li>
            </ul>
        </details>

        <!-- A change to an existing photo: the photo as it is, and the image replacing it. -->
        <div
            v-if="item.kind === 'photo' && (item.subject_id || item.has_file)"
            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
        >
            <a v-if="item.subject_id" :href="route('photos.file', { photo: item.subject_id })" target="_blank" rel="noopener">
                <img
                    :src="route('photos.file', { photo: item.subject_id, thumb: 1 })"
                    alt="Foto sekarang"
                    class="h-20 w-20 rounded-md border object-cover"
                    :class="item.action === 'deleted' && 'opacity-50'"
                    loading="lazy"
                />
            </a>
            <span v-if="item.subject_id && item.has_file">→</span>
            <a v-if="item.has_file" :href="route('approvals.file', { changeRequest: item.id, index: 0 })" target="_blank" rel="noopener">
                <img
                    :src="route('approvals.file', { changeRequest: item.id, index: 0 })"
                    alt="Foto baru"
                    class="h-20 w-20 rounded-md border object-cover"
                    loading="lazy"
                />
            </a>
        </div>

        <div v-if="photos.length" class="flex flex-wrap gap-2">
            <a v-for="p in photos" :key="p.id" :href="route('approvals.file', { changeRequest: p.id, index: 0 })" target="_blank" rel="noopener">
                <img
                    :src="route('approvals.file', { changeRequest: p.id, index: 0 })"
                    :alt="p.label ?? 'Foto draft'"
                    class="h-20 w-20 rounded-md border object-cover"
                    loading="lazy"
                />
            </a>
        </div>

        <!-- Photos can be added to a new asset before it exists; they are applied with it. -->
        <PhotoGallery
            v-if="own && photoOwner"
            :owner-type="photoOwner"
            :owner-id="item.token"
            :photos="[]"
            :can-edit="true"
            :can-delete="false"
            @changed="emit('changed')"
        />

        <p v-if="own && item.kind === 'odp' && item.action === 'created'" class="text-xs text-muted-foreground">
            Isi port/pelanggan dan sambungan core bisa dilakukan setelah ODP ini disetujui.
        </p>

        <div class="flex flex-wrap gap-2">
            <template v-if="own">
                <Button v-if="item.editable" variant="outline" size="sm" @click="emit('edit')"><Pencil class="size-4" /> Edit draft</Button>
                <Button variant="destructive" size="sm" @click="emit('discard')"><Trash2 class="size-4" /> Hapus draft</Button>
            </template>
            <Button v-else-if="item.editable" as-child variant="outline" size="sm">
                <Link :href="route('map', { approval: item.id })"><Pencil class="size-4" /> Edit di peta</Link>
            </Button>
        </div>
    </div>
</template>
