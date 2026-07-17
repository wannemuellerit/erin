<script setup lang="ts">
import type { StatusTone } from '@/types';

withDefaults(
    defineProps<{
        label: string;
        tone?: StatusTone;
        dot?: boolean;
    }>(),
    {
        tone: 'slate',
        dot: true,
    },
);

const tones: Record<StatusTone, string> = {
    blue: 'bg-blue-50 text-blue-700 ring-blue-600/10',
    teal: 'bg-teal-50 text-teal-700 ring-teal-600/10',
    orange: 'bg-orange-50 text-orange-700 ring-orange-600/10',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/10',
    yellow: 'bg-amber-50 text-amber-700 ring-amber-600/10',
    red: 'bg-red-50 text-red-700 ring-red-600/10',
    slate: 'bg-slate-100 text-slate-600 ring-slate-500/10',
    violet: 'bg-violet-50 text-violet-700 ring-violet-600/10',
};

const dots: Record<StatusTone, string> = {
    blue: 'bg-blue-500',
    teal: 'bg-teal-500',
    orange: 'bg-orange-500',
    green: 'bg-emerald-500',
    yellow: 'bg-amber-500',
    red: 'bg-red-500',
    slate: 'bg-slate-400',
    violet: 'bg-violet-500',
};
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1.5 rounded-full px-2.5 py-1 text-xs leading-none font-semibold ring-1 ring-inset"
        :class="tones[tone]"
    >
        <span
            v-if="dot"
            class="size-1.5 shrink-0 rounded-full"
            :class="dots[tone]"
        />
        <span class="truncate">{{ label }}</span>
    </span>
</template>
