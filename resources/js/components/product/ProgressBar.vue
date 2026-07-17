<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number;
        label?: string;
        showValue?: boolean;
        tone?: 'blue' | 'teal' | 'orange' | 'green';
    }>(),
    {
        label: '',
        showValue: true,
        tone: 'blue',
    },
);

const safeValue = computed(() => Math.max(0, Math.min(100, props.value)));
const tones = {
    blue: 'bg-blue-600',
    teal: 'bg-teal-500',
    orange: 'bg-orange-500',
    green: 'bg-emerald-500',
};
</script>

<template>
    <div>
        <div
            v-if="label || showValue"
            class="mb-2 flex items-center justify-between gap-3 text-xs"
        >
            <span class="font-medium text-slate-600">{{ label }}</span>
            <span v-if="showValue" class="font-bold text-slate-900"
                >{{ safeValue }} %</span
            >
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-slate-100">
            <div
                class="h-full rounded-full transition-[width] duration-700"
                :class="tones[tone]"
                :style="{ width: `${safeValue}%` }"
            />
        </div>
    </div>
</template>
