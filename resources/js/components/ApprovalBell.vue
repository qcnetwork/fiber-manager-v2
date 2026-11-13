<script setup lang="ts">
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';

const page = usePage<SharedData>();
const auth = computed(() => page.props.auth);
const count = computed(() => (auth.value.canApprove ? page.props.approvals?.pending : page.props.approvals?.decided_unseen) ?? 0);
const title = computed(() =>
    auth.value.canApprove ? `${count.value} request menunggu persetujuan` : count.value ? `${count.value} request sudah diputuskan` : 'Request saya',
);
</script>

<template>
    <Link
        v-if="auth.canApprove || auth.needsApproval"
        :href="route('approvals.index')"
        class="relative inline-flex size-9 items-center justify-center rounded-md hover:bg-muted"
        :title="title"
        :aria-label="title"
    >
        <Bell class="size-5" />
        <span
            v-if="count"
            class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] leading-4 font-semibold text-white tabular-nums"
        >
            {{ count > 99 ? '99+' : count }}
        </span>
    </Link>
</template>
