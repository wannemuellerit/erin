<script setup lang="ts">
import { X } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

withDefaults(
    defineProps<{
        label: string;
        removable?: boolean;
        tone?: 'blue' | 'teal' | 'orange' | 'slate';
    }>(),
    {
        removable: false,
        tone: 'blue',
    },
);

defineEmits<{
    remove: [];
}>();

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});

const tones = {
    blue: 'bg-blue-50 text-blue-700',
    teal: 'bg-teal-50 text-teal-700',
    orange: 'bg-orange-50 text-orange-700',
    slate: 'bg-slate-100 text-slate-600',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold"
        :class="tones[tone]"
    >
        <span>{{ label }}</span>
        <button
            v-if="removable"
            type="button"
            class="erin-focus -mr-1 grid size-5 place-items-center rounded-full hover:bg-black/5"
            :aria-label="t('filterChip.remove', { label })"
            @click="$emit('remove')"
        >
            <X class="size-3" />
        </button>
    </span>
</template>
