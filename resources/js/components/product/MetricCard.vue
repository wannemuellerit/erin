<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from '@lucide/vue';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        change?: string;
        trend?: 'up' | 'down' | 'neutral';
        hint?: string;
        icon?: Component;
        tone?: 'blue' | 'teal' | 'orange' | 'violet';
    }>(),
    {
        change: '',
        trend: 'neutral',
        hint: '',
        icon: undefined,
        tone: 'blue',
    },
);

const toneClasses = {
    blue: 'bg-blue-50 text-blue-600 ring-blue-100',
    teal: 'bg-teal-50 text-teal-600 ring-teal-100',
    orange: 'bg-orange-50 text-orange-600 ring-orange-100',
    violet: 'bg-violet-50 text-violet-600 ring-violet-100',
};
</script>

<template>
    <article
        class="erin-panel group p-5 transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-200/50"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-slate-500">{{ label }}</p>
                <p
                    class="mt-2 text-2xl font-bold tracking-tight text-slate-950"
                >
                    {{ value }}
                </p>
            </div>
            <div
                v-if="icon"
                class="flex size-10 items-center justify-center rounded-xl ring-1"
                :class="toneClasses[tone]"
            >
                <component :is="icon" class="size-5" />
            </div>
        </div>
        <div v-if="change || hint" class="mt-4 flex items-center gap-2 text-xs">
            <span
                v-if="change"
                class="inline-flex items-center gap-0.5 font-semibold"
                :class="{
                    'text-emerald-600': trend === 'up',
                    'text-red-600': trend === 'down',
                    'text-slate-500': trend === 'neutral',
                }"
            >
                <ArrowUpRight v-if="trend === 'up'" class="size-3.5" />
                <ArrowDownRight v-else-if="trend === 'down'" class="size-3.5" />
                <Minus v-else class="size-3.5" />
                {{ change }}
            </span>
            <span v-if="hint" class="text-slate-400">{{ hint }}</span>
        </div>
    </article>
</template>
