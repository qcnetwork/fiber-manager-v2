<script setup lang="ts">
import { ChevronDown, Info } from '@lucide/vue';
import { ref, watch } from 'vue';

defineProps<{ title: string }>();

const KEY = 'map-hints';

// Folded by default on phones, where the bottom sheet is short and the tips would push the form out of view.
// The user's last choice is remembered for every map form.
function initial(): boolean {
    try {
        const saved = localStorage.getItem(KEY);
        if (saved) return saved === 'open';
    } catch {
        // Storage blocked: fall back to the screen size.
    }
    return typeof window !== 'undefined' && window.matchMedia('(min-width: 768px)').matches;
}

const open = ref(initial());
watch(open, (value) => {
    try {
        localStorage.setItem(KEY, value ? 'open' : 'closed');
    } catch {
        // Not remembered; nothing else to do.
    }
});
</script>

<template>
    <div class="rounded-md bg-muted text-xs text-muted-foreground">
        <button type="button" class="flex w-full items-center gap-1.5 px-2 py-1.5 text-left font-medium" :aria-expanded="open" @click="open = !open">
            <Info class="size-3.5 shrink-0" />
            <span class="flex-1">{{ title }}</span>
            <ChevronDown class="size-3.5 shrink-0 transition-transform" :class="open && 'rotate-180'" />
        </button>
        <div v-if="open" class="px-2 pb-2">
            <slot />
        </div>
    </div>
</template>
