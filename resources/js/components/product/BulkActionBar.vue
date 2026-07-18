<script setup lang="ts">
import { CheckSquare2, X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

withDefaults(
    defineProps<{
        count: number;
        visible?: boolean;
    }>(),
    {
        visible: true,
    },
);

defineEmits<{
    clear: [];
}>();

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
</script>

<template>
    <div
        v-if="visible && count > 0"
        class="flex flex-col gap-3 rounded-2xl border border-blue-200 bg-blue-50 px-4 py-3 shadow-sm sm:flex-row sm:items-center"
        role="status"
        aria-live="polite"
    >
        <div
            class="flex min-w-0 flex-1 items-center gap-2 text-sm font-bold text-blue-900"
        >
            <CheckSquare2 class="size-4 shrink-0 text-blue-600" />
            {{ t('bulkActionBar.selected', count) }}
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <slot />
            <button
                type="button"
                class="erin-focus inline-flex h-9 items-center gap-1.5 rounded-xl px-3 text-xs font-bold text-blue-700 hover:bg-blue-100"
                @click="$emit('clear')"
            >
                <X class="size-3.5" />
                {{ t('bulkActionBar.clear') }}
            </button>
        </div>
    </div>
</template>
