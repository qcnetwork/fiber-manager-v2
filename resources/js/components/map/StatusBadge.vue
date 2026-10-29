<script setup lang="ts">
import type { AssetStatus } from '@/types';

defineProps<{ status: AssetStatus }>();

const styles: Record<AssetStatus, string> = {
    aktif: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300',
    rencana: 'bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    rusak: 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300',
};
</script>

<template>
    <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="styles[status]">{{ status }}</span>
</template>
