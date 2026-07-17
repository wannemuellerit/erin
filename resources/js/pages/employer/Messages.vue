<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MessageSquare } from '@lucide/vue';
import MessagingWorkspace from '@/components/product/MessagingWorkspace.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import type { Conversation } from '@/types';
withDefaults(
    defineProps<{ conversations?: Conversation[]; selected?: number | null }>(),
    { conversations: () => [], selected: null },
);
</script>
<template>
    <Head title="Nachrichten" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Kommunikation"
            title="Nachrichtencenter"
            description="Alle Gespräche, Anhänge und Übersetzungen an einem Ort."
            :icon="MessageSquare"
        /><MessagingWorkspace
            perspective="employer"
            :conversations="conversations"
            :selected="selected"
        />
    </div>
</template>
