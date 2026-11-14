<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { PHOTO_DRAFTS, useApproval } from '@/composables/useApproval';
import { compressImage } from '@/lib/image';
import type { Photo, PhotoOwner, SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Camera, ChevronLeft, ChevronRight, ClipboardPaste, ImageUp, LoaderCircle, Pencil, Trash2 } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, inject, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    ownerType: PhotoOwner;
    ownerId: number;
    photos: Photo[];
    canEdit: boolean;
    canDelete: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

const me = usePage<SharedData>().props.auth.user;
const { needsApproval, saveLabel } = useApproval();

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

function onFilesChosen(event: Event) {
    uploadFiles(Array.from((event.target as HTMLInputElement).files ?? []));
}

// ---- Paste: Ctrl+V anywhere while this gallery is shown, or the "Tempel" button (phones have no Ctrl+V)
const imagesIn = (files: File[]) => files.filter((f) => f.type.startsWith('image/'));

useEventListener(document, 'paste', (event: ClipboardEvent) => {
    if (!props.canEdit || uploading.value || viewing.value !== null) return;
    const data = event.clipboardData;
    const images = imagesIn(Array.from(data?.files ?? []));
    if (!images.length) return;

    // Text copied from e.g. Word carries an image too; pasting it into another field must still paste the text.
    const typing = (event.target as HTMLElement | null)?.closest('input, textarea, [contenteditable="true"]');
    if (typing && data?.types.includes('text/plain')) return;

    event.preventDefault();
    uploadFiles(images);
});

const canReadClipboard = typeof navigator !== 'undefined' && window.isSecureContext && 'read' in (navigator.clipboard ?? {});

async function pasteFromClipboard() {
    uploadError.value = '';
    try {
        const files: File[] = [];
        for (const item of await navigator.clipboard.read()) {
            const type = item.types.find((t) => t.startsWith('image/'));
            if (type) files.push(new File([await item.getType(type)], `tempel.${type.split('/')[1]}`, { type }));
        }
        if (!files.length) uploadError.value = 'Tidak ada gambar di clipboard. Salin fotonya dulu.';
        else uploadFiles(files);
    } catch {
        uploadError.value = 'Tidak bisa membaca clipboard. Izinkan akses clipboard di browser, atau gunakan Tambah foto.';
    }
}

async function uploadFiles(files: File[]) {
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

const mayDelete = (photo: Photo) => props.canDelete || (props.canEdit && (photo.user_id === me.id || needsApproval.value));

function destroy(photo: Photo) {
    if (!confirm(needsApproval.value ? 'Ajukan hapus foto ini? Foto baru terhapus setelah disetujui admin.' : 'Hapus foto ini?')) return;
    router.delete(route('photos.destroy', { photo: photo.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            viewing.value = null;
            emit('changed');
        },
    });
}

// ---- Editing a photo: its caption, or a new image in its place (a draft for technicians)
const photoDrafts = inject(PHOTO_DRAFTS, null);
const draftOf = (photo: Photo) => photoDrafts?.value.get(photo.id) ?? null;

const editing = ref(false);
const editCaption = ref('');
const replacement = ref<File | null>(null);
const replacementPreview = ref<string | null>(null);
const replaceInput = ref<HTMLInputElement>();
const saving = ref(false);
const editError = ref('');

watch(viewing, () => cancelEdit());

function startEdit(photo: Photo) {
    editCaption.value = photo.caption ?? '';
    editing.value = true;
}

function cancelEdit() {
    editing.value = false;
    editError.value = '';
    setReplacement(null);
}

function setReplacement(file: File | null) {
    if (replacementPreview.value) URL.revokeObjectURL(replacementPreview.value);
    replacement.value = file;
    replacementPreview.value = file ? URL.createObjectURL(file) : null;
    if (replaceInput.value) replaceInput.value.value = '';
}

async function onReplacementChosen(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    editError.value = '';
    try {
        setReplacement(await compressImage(file));
    } catch {
        editError.value = 'File gambar tidak bisa dibaca.';
    }
}

function saveEdit(photo: Photo) {
    saving.value = true;
    editError.value = '';
    router.post(
        route('photos.update', { photo: photo.id }),
        { _method: 'put', caption: editCaption.value, ...(replacement.value ? { photo: replacement.value } : {}) },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                viewing.value = null;
                emit('changed');
            },
            onError: (errors) => (editError.value = errors.photo ?? errors.caption ?? Object.values(errors)[0] ?? 'Gagal menyimpan.'),
            onFinish: () => (saving.value = false),
        },
    );
}

onBeforeUnmount(() => setReplacement(null));

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
                class="relative aspect-square overflow-hidden rounded-md border bg-muted"
                :class="draftOf(photo) && 'ring-2 ring-orange-500'"
                :title="photo.caption ?? ''"
                @click="viewing = i"
            >
                <img
                    :src="photo.thumb_url"
                    :alt="photo.caption ?? 'Foto'"
                    loading="lazy"
                    class="size-full object-cover"
                    :class="draftOf(photo) === 'deleted' && 'opacity-40'"
                />
                <span
                    v-if="draftOf(photo)"
                    class="absolute inset-x-0 bottom-0 bg-orange-600/90 px-1 py-0.5 text-center text-[10px] font-semibold text-white"
                >
                    {{ draftOf(photo) === 'deleted' ? 'Draft hapus' : 'Draft edit' }}
                </span>
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
            <Button
                v-if="canReadClipboard"
                type="button"
                variant="outline"
                size="sm"
                class="h-9 shrink-0 px-2.5"
                title="Tempel foto dari clipboard (Ctrl+V)"
                :disabled="!!uploading"
                @click="pasteFromClipboard"
            >
                <ClipboardPaste class="size-4" />
                <span class="sr-only">Tempel foto</span>
            </Button>
            <!-- No `capture` attribute: on phones this offers both the camera and the gallery. -->
            <input ref="fileInput" type="file" accept="image/*" multiple class="hidden" @change="onFilesChosen" />
        </div>
        <p v-if="canEdit && !uploading" class="mt-1 hidden text-xs text-muted-foreground md:block">Bisa juga tempel foto dengan Ctrl+V.</p>
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
                            class="absolute top-1/2 left-2 -translate-y-1/2 opacity-80"
                            title="Sebelumnya"
                            @click="step(-1)"
                        >
                            <ChevronLeft class="size-5" />
                        </Button>
                        <Button
                            variant="secondary"
                            size="icon"
                            class="absolute top-1/2 right-2 -translate-y-1/2 opacity-80"
                            title="Berikutnya"
                            @click="step(1)"
                        >
                            <ChevronRight class="size-5" />
                        </Button>
                    </template>
                </div>

                <p
                    v-if="draftOf(current)"
                    class="rounded-md border border-orange-300 bg-orange-50 px-3 py-1.5 text-xs text-orange-900 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200"
                >
                    {{
                        draftOf(current) === 'deleted'
                            ? 'Ada draft untuk menghapus foto ini.'
                            : 'Ada draft untuk mengubah foto ini. Lihat atau hapus drafnya di bar draft.'
                    }}
                </p>

                <form v-if="editing" class="flex flex-col gap-2 rounded-md border p-3" @submit.prevent="saveEdit(current)">
                    <Input v-model="editCaption" placeholder="Keterangan foto (opsional)" class="h-9" maxlength="255" :disabled="saving" />
                    <div class="flex items-center gap-2">
                        <img v-if="replacementPreview" :src="replacementPreview" alt="Foto pengganti" class="size-16 rounded border object-cover" />
                        <Button type="button" variant="outline" size="sm" :disabled="saving" @click="replaceInput?.click()">
                            <ImageUp class="size-4" /> {{ replacement ? 'Pilih foto lain' : 'Ganti foto' }}
                        </Button>
                        <Button v-if="replacement" type="button" variant="ghost" size="sm" :disabled="saving" @click="setReplacement(null)">
                            Batal ganti
                        </Button>
                        <input ref="replaceInput" type="file" accept="image/*" class="hidden" @change="onReplacementChosen" />
                    </div>
                    <p v-if="needsApproval" class="text-xs text-muted-foreground">Perubahan disimpan sebagai draft dan berlaku setelah disetujui.</p>
                    <p v-if="editError" class="text-sm text-red-600">{{ editError }}</p>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="ghost" size="sm" :disabled="saving" @click="cancelEdit">Batal</Button>
                        <Button type="submit" size="sm" :disabled="saving">
                            <LoaderCircle v-if="saving" class="size-4 animate-spin" />
                            {{ saveLabel }}
                        </Button>
                    </div>
                </form>

                <div v-else class="flex flex-wrap justify-between gap-2">
                    <Button as="a" :href="current.url" target="_blank" variant="outline" size="sm">Buka ukuran penuh</Button>
                    <div class="flex gap-2">
                        <Button v-if="canEdit" variant="outline" size="sm" @click="startEdit(current)"><Pencil class="size-4" /> Edit / ganti</Button>
                        <Button v-if="mayDelete(current)" variant="destructive" size="sm" @click="destroy(current)">
                            <Trash2 class="size-4" /> {{ needsApproval ? 'Hapus (draft)' : 'Hapus foto' }}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
