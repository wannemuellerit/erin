<script setup lang="ts">
import type { Component } from 'vue';
import { useI18n } from 'vue-i18n';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

withDefaults(
    defineProps<{
        as?: string | Component;
        panel?: boolean;
        label?: string;
    }>(),
    {
        as: 'div',
        panel: true,
        label: '',
    },
);

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
</script>

<template>
    <component
        :is="as"
        :aria-label="label || t('filterToolbar.label')"
        :class="panel ? 'erin-panel overflow-hidden' : ''"
    >
        <div class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
            <div v-if="$slots.tabs" class="shrink-0">
                <slot name="tabs" />
            </div>
            <div class="min-w-0 flex-1">
                <slot />
            </div>
            <div
                v-if="$slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
        <div
            v-if="$slots.filters"
            class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3"
        >
            <slot name="filters" />
        </div>
    </component>
</template>
