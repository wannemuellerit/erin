<script setup lang="ts">
import { Inbox } from '@lucide/vue';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
    }>(),
    {
        title: 'Keine Einträge vorhanden',
        description: 'Für die gewählten Filter wurden keine Daten gefunden.',
    },
);
</script>

<template>
    <div class="grid min-h-52 place-items-center px-6 py-10 text-center">
        <div>
            <span
                class="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"
            >
                <Inbox class="size-5" />
            </span>
            <h3 class="mt-4 text-sm font-bold text-slate-800">{{ title }}</h3>
            <p class="mt-1 max-w-sm text-xs leading-5 text-slate-500">
                {{ description }}
            </p>
        </div>
    </div>
</template>
