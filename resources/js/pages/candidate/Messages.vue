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
            description="Unternehmen, Erin-Support und alle Unterlagen in einem sicheren Chat."
            :icon="MessageSquare"
        /><MessagingWorkspace
            perspective="candidate"
            :conversations="conversations"
            :selected="selected"
        />
    </div>
</template>
