<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        modelValue?: string;
        class?: HTMLAttributes['class'];
    }>(),
    {
        modelValue: '',
        class: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();
</script>

<template>
    <textarea
        v-bind="$attrs"
        :value="modelValue"
        :class="
            cn(
                'erin-focus min-h-28 w-full rounded-xl border border-border bg-card px-3.5 py-3 text-sm leading-6 text-foreground placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:bg-muted disabled:text-muted-foreground',
                props.class,
            )
        "
        @input="
            emit(
                'update:modelValue',
                ($event.target as HTMLTextAreaElement).value,
            )
        "
    />
</template>
