<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const messages = computed(() => {
    const flash = { ...((page.props.flash ?? {}) as Record<string, unknown>) };
    const toast = page.flash?.toast as
        { type?: unknown; message?: unknown } | undefined;

    if (
        toast &&
        typeof toast.type === 'string' &&
        typeof toast.message === 'string'
    ) {
        flash[toast.type] = toast.message;
    }

    return ['success', 'warning', 'error'].flatMap((kind) =>
        typeof flash[kind] === 'string' && flash[kind]
            ? [{ kind, text: flash[kind] as string }]
            : [],
    );
});
const errors = computed(() =>
    [
        ...new Set(
            Object.values(
                (page.props.errors ?? {}) as Record<string, unknown>,
            ).flat(),
        ),
    ].filter(
        (value): value is string => typeof value === 'string' && value !== '',
    ),
);
</script>

<template>
    <div
        v-if="messages.length || errors.length"
        class="sticky top-0 z-30 mx-4 space-y-2 bg-background py-2 sm:mx-6 lg:mx-8"
        data-test="request-feedback"
    >
        <p
            v-for="message in messages"
            :key="message.kind"
            :role="message.kind === 'success' ? 'status' : 'alert'"
            class="rounded-xl border border-border bg-card px-4 py-3 text-sm text-card-foreground"
        >
            {{ message.text }}
        </p>
        <ul
            v-if="errors.length"
            role="alert"
            class="list-inside list-disc rounded-xl border border-destructive bg-card px-4 py-3 text-sm text-card-foreground"
        >
            <li v-for="error in errors" :key="error">{{ error }}</li>
        </ul>
    </div>
</template>
