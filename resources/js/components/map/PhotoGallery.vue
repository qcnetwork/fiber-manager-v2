<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { compressImage } from '@/lib/image';
import type { Photo, PhotoOwner, SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Camera, ChevronLeft, ChevronRight, LoaderCircle, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    ownerType: PhotoOwner;
    ownerId: number;
    photos: Photo[];
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const me = usePage<SharedData>().props.auth.user;

// ---- Upload: compress in the browser, then send one photo per request
const fileInput = ref<HTMLInputElement>();
const caption = ref('');
const uploading = ref<{ index: number; total: number; percent: number } | null>(null);
const uploadError = ref('');

function postPhoto(file: File): Promise<void> {
    return new Promise((resolve, reject) => {
        router.post(
            route('photos.store'),
            { photoable_type: props.ownerType, photoable_id: props.ownerId, photo: file, caption: caption.value },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onProgress: (e) => {
                    if (uploading.value && e?.percentage !== undefined) uploading.value.percent = e.percentage;
                },
                onSuccess: () => resolve(),
                onError: (errors) => reject(new Error(errors.photo ?? Object.values(errors)[0] ?? 'Upload gagal.')),
            },
        );
    });
}

async function onFilesChosen(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []);
    if (!files.length) return;
    uploadError.value = '';

    try {
        for (const [i, file] of files.entries()) {
            uploading.value = { index: i + 1, total: files.length, percent: 0 };
            await postPhoto(await compressImage(file));
        }
        caption.value = '';
    } catch (e) {
        uploadError.value = (e as Error).message;
    } finally {
        uploading.value = null;
        if (fileInput.value) fileInput.value.value = '';
        emit('changed');
    }
}

// ---- Viewer
const viewing = ref<number | null>(null);
const current = computed(() => (viewing.value === null ? null : props.photos[viewing.value]));

function step(delta: number) {
    if (viewing.value === null) return;
    viewing.value = (viewing.value + delta + props.photos.length) % props.photos.length;
}

const mayDelete = (photo: Photo) => props.canDelete || (props.canEdit && photo.user_id === me.id);

function destroy(photo: Photo) {
    if (!confirm('Hapus foto ini?')) return;
    router.delete(route('photos.destroy', { photo: photo.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            viewing.value = null;
            emit('changed');
        },
    });
}

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <div>
        <div class="mb-2 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Foto ({{ photos.length }})</h3>
        </div>

        <div v-if="photos.length" class="grid grid-cols-3 gap-2">
            <button
                v-for="(photo, i) in photos"
                :key="photo.id"
                type="button"
                class="aspect-square overflow-hidden rounded-md border bg-muted"
                :title="photo.caption ?? ''"
                @click="viewing = i"
            >
                <img :src="photo.thumb_url" :alt="photo.caption ?? 'Foto'" loading="lazy" class="size-full object-cover" />
            </button>
        </div>
        <p v-else-if="!canEdit" class="text-sm text-muted-foreground">Belum ada foto.</p>

        <div v-if="canEdit" class="mt-2 flex gap-2">
            <Input v-model="caption" placeholder="Keterangan foto (opsional)" class="h-9" :disabled="!!uploading" />
            <Button type="button" variant="outline" size="sm" class="h-9 shrink-0" :disabled="!!uploading" @click="fileInput?.click()">
                <LoaderCircle v-if="uploading" class="size-4 animate-spin" />
                <Camera v-else class="size-4" />
                Tambah foto
            </Button>
            <!-- No `capture` attribute: on phones this offers both the camera and the gallery. -->
            <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="onFilesChosen" />
        </div>
        <p v-if="uploading" class="mt-1 text-xs text-muted-foreground">
            Mengunggah {{ uploading.index }}/{{ uploading.total }}… {{ uploading.percent }}%
        </p>
        <p v-if="uploadError" class="mt-1 text-sm text-red-600">{{ uploadError }}</p>

        <Dialog :open="!!current" @update:open="(open) => !open && (viewing = null)">
            <DialogContent v-if="current" class="max-w-3xl gap-3 p-3 sm:p-4">
                <DialogTitle class="pr-8 text-base">{{ current.caption || 'Foto' }}</DialogTitle>
                <DialogDescription class="text-xs">
                    {{ current.user?.name ?? 'Tidak diketahui' }} · {{ formatDate(current.created_at) }} · {{ viewing! + 1 }}/{{ photos.length }}
                </DialogDescription>

                <div class="relative flex items-center justify-center rounded-md bg-black">
                    <img :src="current.url" :alt="current.caption ?? 'Foto'" class="max-h-[70svh] w-auto object-contain" />
                    <template v-if="photos.length > 1">
                        <Button
                            variant="secondary"
                            size="icon"
                            class="absolute left-2 top-1/2 -translate-y-1/2 opacity-80"
                            title="Sebelumnya"
                            @click="step(-1)"
                        >
                            <ChevronLeft class="size-5" />
                        </Button>
                        <Button
                            variant="secondary"
                            size="icon"
                            class="absolute right-2 top-1/2 -translate-y-1/2 opacity-80"
                            title="Berikutnya"
                            @click="step(1)"
                        >
                            <ChevronRight class="size-5" />
                        </Button>
                    </template>
                </div>

                <div class="flex justify-between gap-2">
                    <Button as="a" :href="current.url" target="_blank" variant="outline" size="sm">Buka ukuran penuh</Button>
                    <Button v-if="mayDelete(current)" variant="destructive" size="sm" @click="destroy(current)">
                        <Trash2 class="size-4" /> Hapus foto
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
