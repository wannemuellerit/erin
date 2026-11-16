<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        flush?: boolean;
    }>(),
    {
        title: '',
        description: '',
        flush: false,
    },
);
</script>

<template>
    <section class="erin-panel overflow-hidden">
        <div
            v-if="title || description || $slots.actions"
            class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h2 v-if="title" class="font-bold text-foreground">
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="mt-0.5 text-sm text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </div>
        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
