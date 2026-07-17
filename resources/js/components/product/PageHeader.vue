<script setup lang="ts">
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        eyebrow?: string;
        title: string;
        description?: string;
        icon?: Component;
    }>(),
    {
        eyebrow: '',
        description: '',
        icon: undefined,
    },
);
</script>

<template>
    <div
        class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between"
    >
        <div class="flex min-w-0 gap-3.5">
            <div
                v-if="icon"
                class="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 ring-1 ring-blue-100"
            >
                <component :is="icon" class="size-5" />
            </div>
            <div class="min-w-0">
                <p
                    v-if="eyebrow"
                    class="mb-1 text-xs font-bold tracking-[0.12em] text-blue-600 uppercase"
                >
                    {{ eyebrow }}
                </p>
                <h1
                    class="text-2xl font-bold tracking-tight text-slate-950 sm:text-[1.75rem]"
                >
                    {{ title }}
                </h1>
                <p
                    v-if="description"
                    class="mt-1.5 max-w-3xl text-sm leading-6 text-slate-500"
                >
                    {{ description }}
                </p>
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
