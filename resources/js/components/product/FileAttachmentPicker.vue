<script setup lang="ts">
import { Paperclip, X } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        id: string;
        modelValue: File[];
        label: string;
        removeLabel: string;
        accept?: string;
        maxFiles?: number;
        disabled?: boolean;
        compact?: boolean;
    }>(),
    {
        accept: '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.mp3,.m4a,.ogg,.wav,.webm',
        maxFiles: 8,
        disabled: false,
        compact: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [files: File[]];
}>();

const input = ref<HTMLInputElement | null>(null);
const selectedLabel = computed(() =>
    props.modelValue.length > 0
        ? `${props.label} (${props.modelValue.length}/${props.maxFiles})`
        : props.label,
);

function selectFiles(event: Event): void {
    const target = event.target as HTMLInputElement;
    const next = Array.from(target.files ?? []).slice(0, props.maxFiles);
    emit('update:modelValue', next);
}

function removeFile(index: number): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((_, fileIndex) => fileIndex !== index),
    );

    if (input.value) {
        input.value.value = '';
    }
}
</script>

<template>
    <div :class="compact ? 'shrink-0' : 'space-y-2'">
        <label
            :for="id"
            class="erin-focus relative inline-flex cursor-pointer items-center justify-center gap-2 rounded-xl font-bold"
            :class="
                compact
                    ? 'size-9 text-slate-400 hover:bg-white hover:text-[var(--erin-primary)]'
                    : 'min-h-10 border border-slate-200 bg-white px-3 text-xs text-slate-700 hover:border-blue-300 hover:text-blue-700'
            "
            :aria-label="selectedLabel"
        >
            <Paperclip class="size-4" aria-hidden="true" />
            <span v-if="!compact">{{ selectedLabel }}</span>
            <span
                v-else-if="modelValue.length"
                class="absolute -top-1 -right-1 grid size-4 place-items-center rounded-full bg-blue-600 text-[9px] text-white"
            >
                {{ modelValue.length }}
            </span>
            <input
                :id="id"
                ref="input"
                type="file"
                multiple
                class="sr-only"
                :accept="accept"
                :disabled="disabled"
                @change="selectFiles"
            />
        </label>

        <ul
            v-if="!compact && modelValue.length"
            class="flex flex-wrap gap-2"
            :aria-label="selectedLabel"
        >
            <li
                v-for="(file, index) in modelValue"
                :key="`${file.name}-${file.size}-${index}`"
                class="inline-flex max-w-full items-center gap-2 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs text-slate-700"
            >
                <span class="truncate">{{ file.name }}</span>
                <button
                    type="button"
                    class="erin-focus rounded text-slate-500 hover:text-red-600"
                    :aria-label="`${removeLabel}: ${file.name}`"
                    :disabled="disabled"
                    @click="removeFile(index)"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </li>
        </ul>
    </div>
</template>
