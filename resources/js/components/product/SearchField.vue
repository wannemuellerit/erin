<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Search } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { cn } from '@/lib/utils';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        label?: string;
        placeholder?: string;
        size?: 'sm' | 'md';
        class?: HTMLAttributes['class'];
    }>(),
    {
        modelValue: '',
        label: '',
        placeholder: '',
        size: 'md',
        class: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
</script>

<template>
    <label class="relative block min-w-0">
        <span class="sr-only">{{ label || t('searchField.label') }}</span>
        <Search
            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
        />
        <input
            v-bind="$attrs"
            type="search"
            :value="modelValue"
            :placeholder="placeholder || t('searchField.placeholder')"
            :class="
                cn(
                    'erin-focus w-full rounded-xl border border-slate-200 bg-white pr-3 pl-10 text-sm placeholder:text-slate-400',
                    size === 'sm' ? 'h-10' : 'h-11',
                    props.class,
                )
            "
            @input="
                emit(
                    'update:modelValue',
                    ($event.target as HTMLInputElement).value,
                )
            "
        />
    </label>
</template>
