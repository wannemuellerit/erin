<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

withDefaults(
    defineProps<{
        id?: string;
        label: string;
        description?: string;
        error?: string;
        required?: boolean;
    }>(),
    {
        id: '',
        description: '',
        error: '',
        required: false,
    },
);

const { t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
</script>

<template>
    <div>
        <label :for="id || undefined" class="text-sm font-bold text-slate-700">
            {{ label }}
            <span v-if="required" class="text-red-500" aria-hidden="true"
                >*</span
            >
            <span v-if="required" class="sr-only">
                ({{ t('formField.required') }})
            </span>
        </label>
        <p v-if="description" class="mt-1 text-xs leading-5 text-slate-500">
            {{ description }}
        </p>
        <div class="mt-1.5">
            <slot />
        </div>
        <InputError v-if="error" class="mt-1.5" :message="error" />
    </div>
</template>
