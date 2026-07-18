<script setup lang="ts">
import type { Component } from 'vue';
import { Inbox } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string | null;
        icon?: Component;
        compact?: boolean;
        panel?: boolean;
    }>(),
    {
        icon: Inbox,
        compact: false,
        panel: false,
    },
);

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});

const resolvedTitle = computed(() => props.title ?? t('emptyState.title'));
const resolvedDescription = computed(
    () => props.description ?? t('emptyState.description'),
);
</script>

<template>
    <div
        class="grid place-items-center text-center"
        :class="[
            compact ? 'min-h-36 px-5 py-8' : 'min-h-64 px-6 py-10',
            panel ? 'erin-panel' : '',
        ]"
    >
        <div>
            <span
                class="mx-auto grid place-items-center rounded-2xl bg-slate-100 text-slate-400"
                :class="compact ? 'size-11' : 'size-14'"
            >
                <component :is="icon" :class="compact ? 'size-5' : 'size-6'" />
            </span>
            <h2
                class="mt-4 font-bold text-slate-900"
                :class="compact ? 'text-sm' : 'text-base'"
            >
                {{ resolvedTitle }}
            </h2>
            <p
                v-if="resolvedDescription"
                class="mx-auto mt-2 max-w-md leading-6 text-slate-500"
                :class="compact ? 'text-xs' : 'text-sm'"
            >
                {{ resolvedDescription }}
            </p>
            <div
                v-if="$slots.actions"
                class="mt-5 flex flex-wrap items-center justify-center gap-2"
            >
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
