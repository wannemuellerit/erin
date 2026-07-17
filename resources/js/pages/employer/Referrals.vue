<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ReferralDashboard from '@/components/product/ReferralDashboard.vue';

type ReferralCode = {
    id: number;
    code: string;
    url: string;
    commission_cents?: number;
    currency?: string;
};
type ReferralMetrics = {
    clicks: number;
    registrations: number;
    applications: number;
    placements: number;
    approved_cents: number;
    paid_cents: number;
};
type Referral = {
    id: number;
    status: string;
    clicked_at?: string | null;
    registered_at?: string | null;
    hired_at?: string | null;
    hold_until?: string | null;
    approved_at?: string | null;
    paid_at?: string | null;
    commission_cents?: number;
    currency?: string;
};

defineProps<{
    code?: ReferralCode | null;
    metrics?: ReferralMetrics;
    referrals?: Referral[];
}>();
</script>

<template>
    <Head title="Referrals" />
    <ReferralDashboard
        perspective="employer"
        :code="code"
        :metrics="metrics"
        :referrals="referrals"
    />
</template>
