<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import InterviewCenter from '@/components/product/InterviewCenter.vue';
import type { Availability, Interview, InterviewCenterProps } from '@/types';

withDefaults(
    defineProps<{
        interviews?: Interview[];
        availability?: Availability[];
        timezone?: string;
        applications?: InterviewCenterProps['applications'];
    }>(),
    {
        interviews: () => [],
        availability: () => [],
        timezone: 'Europe/Berlin',
        applications: () => [],
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
        :applications="applications"
    />
</template>
