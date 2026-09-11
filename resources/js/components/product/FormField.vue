<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { productMessages } from '@/i18n/product-locales';

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
    messages: productMessages,
});
</script>

<template>
    <div>
        <label
            :for="id || undefined"
            class="text-sm font-bold text-muted-foreground"
        >
            {{ label }}
            <span
                v-if="required"
                class="text-red-600 dark:text-red-400"
                aria-hidden="true"
                >*</span
            >
            <span v-if="required" class="sr-only">
                ({{ t('formField.required') }})
            </span>
        </label>
        <p
            v-if="description"
            class="mt-1 text-xs leading-5 text-muted-foreground"
        >
            {{ description }}
        </p>
        <div class="mt-1.5">
            <slot />
        </div>
        <InputError v-if="error" class="mt-1.5" :message="error" />
    </div>
</template>
