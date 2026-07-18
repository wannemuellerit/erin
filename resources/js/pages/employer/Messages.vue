<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MessageSquare } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MessagingWorkspace from '@/components/product/MessagingWorkspace.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import type { Conversation } from '@/types';
withDefaults(
    defineProps<{ conversations?: Conversation[]; selected?: number | null }>(),
    { conversations: () => [], selected: null },
);
const { t } = useI18n();
</script>
<template>
    <Head :title="t('employer.messages.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.messages.eyebrow')"
            :title="t('employer.messages.title')"
            :description="t('employer.messages.description')"
            :icon="MessageSquare"
        /><MessagingWorkspace
            perspective="employer"
            :conversations="conversations"
            :selected="selected"
        />
    </div>
</template>
