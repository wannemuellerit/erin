<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
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
</script>

<template>
    <Head title="Interviews" />
    <InterviewCenter
        perspective="candidate"
        :interviews="interviews"
        :availability="availability"
        :timezone="timezone"
    />
</template>
