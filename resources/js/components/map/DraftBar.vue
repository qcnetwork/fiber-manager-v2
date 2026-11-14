<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { activityActions, activityTypes, type ActivityAction } from '@/lib/activity';
import type { DraftItem } from '@/types';
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, LoaderCircle, Send, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

/**
 * A technician's drafts not sent yet: the list, and sending them together for approval.
 */
const props = defineProps<{ items: DraftItem[] }>();

const emit = defineEmits<{ focus: [item: DraftItem] }>();

const open = ref(false);
const note = ref('');
const sending = ref(false);

function submit() {
    sending.value = true;
    router.post(
        route('drafts.submit'),
        { note: note.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                note.value = '';
                open.value = false;
            },
            onFinish: () => (sending.value = false),
        },
    );
}

function discardAll() {
    if (!confirm(`Buang semua ${props.items.length} draft? Perubahan yang belum dikirim akan hilang.`)) return;
    router.delete(route('drafts.destroy-all'), { preserveScroll: true, preserveState: true });
}

const actionLabel = (item: DraftItem) => activityActions[item.action as ActivityAction]?.label ?? item.action;
</script>

<template>
    <div class="pointer-events-auto w-full rounded-lg border border-orange-300 bg-background shadow-lg dark:border-orange-900">
        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm" @click="open = !open">
            <span class="flex size-6 items-center justify-center rounded-full bg-orange-600 text-xs font-semibold text-white tabular-nums">
                {{ items.length }}
            </span>
            <span class="flex-1 font-medium">Perubahan belum dikirim</span>
            <ChevronUp v-if="open" class="size-4" />
            <ChevronDown v-else class="size-4" />
        </button>

        <div v-if="open" class="flex flex-col gap-2 border-t px-3 py-2">
            <ul class="flex max-h-48 flex-col gap-0.5 overflow-y-auto text-sm">
                <li v-for="item in items" :key="item.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-1.5 py-1 text-left hover:bg-muted disabled:opacity-60"
                        :disabled="item.lat === null && !item.path?.length"
                        @click="emit('focus', item)"
                    >
                        <span class="rounded px-1 text-[11px] font-medium" :class="activityActions[item.action as ActivityAction]?.class">
                            {{ actionLabel(item) }}
                        </span>
                        <span class="text-xs text-muted-foreground">{{ activityTypes[item.kind] ?? item.kind }}</span>
                        <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                    </button>
                </li>
            </ul>
            <textarea
                v-model="note"
                rows="2"
                maxlength="1000"
                placeholder="Catatan untuk admin (opsional)"
                class="rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
            />
            <div class="flex gap-2">
                <Button size="sm" class="flex-1" :disabled="sending" @click="submit">
                    <LoaderCircle v-if="sending" class="size-4 animate-spin" />
                    <Send v-else class="size-4" />
                    Kirim ke approval
                </Button>
                <Button size="sm" variant="outline" :disabled="sending" title="Buang semua draft" @click="discardAll"
                    ><Trash2 class="size-4"
                /></Button>
            </div>
        </div>
    </div>
</template>
