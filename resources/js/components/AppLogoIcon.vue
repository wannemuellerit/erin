<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        :class="className"
        v-bind="$attrs"
    >
        <rect width="40" height="40" rx="12" fill="#2563EB" />
        <path d="M11 11.5h18v5H17v4h10.2v5H17v7H11v-21Z" fill="white" />
        <circle cx="30.5" cy="11" r="4.5" fill="#F97316" />
    </svg>
</template>
