<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <!-- The app icon (public/icon-192.png, made from resources/images/appicon.png). -->
    <img src="/icon-192.png" alt="QCNET" :class="className" v-bind="$attrs" />
</template>
