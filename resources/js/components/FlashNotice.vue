<script setup lang="ts">
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, X } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Short messages from the server (request sent, approved, …) shown as a toast; also keeps the
 * approval badge counts fresh while the page stays open.
 */
const page = usePage<SharedData>();
const message = ref<{ text: string; error: boolean } | null>(null);
let hideTimer: ReturnType<typeof setTimeout> | undefined;

function show(text: string | null | undefined, error: boolean) {
    if (!text) return;
    message.value = { text, error };
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => (message.value = null), error ? 10000 : 5000);
}

// Every response brings a new flash object, so the same message sent twice shows twice.
watch(
    () => page.props.flash,
    (flash) => {
        show(flash?.notice, false);
        show(flash?.noticeError, true);
    },
    { immediate: true },
);

// Badge counts: a light partial reload every minute, skipped while the tab is hidden.
let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (!page.props.auth.needsApproval && !page.props.auth.canApprove) return;
    poll = setInterval(() => {
        if (document.visibilityState === 'visible') router.reload({ only: ['approvals'] });
    }, 60_000);
});
onBeforeUnmount(() => {
    clearInterval(poll);
    clearTimeout(hideTimer);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200"
        enter-from-class="-translate-y-2 opacity-0"
        leave-active-class="transition duration-150"
        leave-to-class="opacity-0"
    >
        <div
            v-if="message"
            role="status"
            class="fixed top-3 left-1/2 z-[2000] flex w-[calc(100%-2rem)] max-w-md -translate-x-1/2 items-start gap-2 rounded-lg border px-3 py-2 text-sm shadow-lg"
            :class="
                message.error
                    ? 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100'
                    : 'border-green-300 bg-green-50 text-green-900 dark:border-green-900 dark:bg-green-950 dark:text-green-100'
            "
        >
            <CircleAlert v-if="message.error" class="mt-0.5 size-4 shrink-0" />
            <CircleCheck v-else class="mt-0.5 size-4 shrink-0" />
            <span class="flex-1">{{ message.text }}</span>
            <button type="button" class="opacity-60 hover:opacity-100" aria-label="Tutup" @click="message = null"><X class="size-4" /></button>
        </div>
    </Transition>
</template>
