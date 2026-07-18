<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InterviewCenter from '@/components/product/InterviewCenter.vue';
import type { Availability, Interview } from '@/types';

withDefaults(
    defineProps<{
        interviews?: Interview[];
        availability?: Availability[];
        timezone?: string;
    }>(),
    {
        interviews: () => [],
        availability: () => [],
        timezone: 'Europe/Berlin',
    },
);
const { t } = useI18n();
</script>

<template>
    <Head :title="t('employer.interviews.metaTitle')" />
    <InterviewCenter
        perspective="employer"
        :interviews="interviews"
        :availability="availability"
        :timezone="timezone"
    />
</template>
