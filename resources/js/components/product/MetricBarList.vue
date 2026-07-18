<script setup lang="ts">
import { computed } from 'vue';

type MetricBar = {
    id: string | number;
    label: string;
    value: number;
    detail?: string;
};

const props = withDefaults(
    defineProps<{
        items: MetricBar[];
        tone?: 'blue' | 'teal' | 'orange';
    }>(),
    {
        tone: 'blue',
    },
);

const max = computed(() =>
    Math.max(1, ...props.items.map((item) => item.value)),
);
const toneClass = computed(
    () =>
        ({
            blue: 'bg-blue-500',
            teal: 'bg-teal-500',
            orange: 'bg-orange-500',
        })[props.tone],
);
const widthFor = (value: number) =>
    value <= 0 ? '0%' : `${Math.max(2, (value / max.value) * 100)}%`;
</script>

<template>
    <div class="space-y-4">
        <div v-for="item in items" :key="item.id">
            <div class="mb-1.5 flex items-center justify-between gap-3 text-xs">
                <span class="truncate font-semibold text-slate-700">
                    {{ item.label }}
                </span>
                <span class="shrink-0 text-slate-500">
                    {{ item.detail ?? item.value }}
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                    class="h-full rounded-full transition-[width]"
                    :class="[toneClass, { 'min-w-1': item.value > 0 }]"
                    :style="{
                        width: widthFor(item.value),
                    }"
                />
            </div>
        </div>
    </div>
</template>
