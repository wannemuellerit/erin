<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminDashboard from '@/components/product/dashboards/AdminDashboard.vue';
import CandidateDashboard from '@/components/product/dashboards/CandidateDashboard.vue';
import EmployerDashboard from '@/components/product/dashboards/EmployerDashboard.vue';
import { useProductNavigation } from '@/composables/useProductNavigation';

const { role } = useProductNavigation();
withDefaults(defineProps<{ dashboard?: Record<string, unknown> | null }>(), {
    dashboard: null,
});
</script>

<template>
    <Head title="Dashboard" />
    <AdminDashboard
        v-if="role === 'super_admin' || role === 'support'"
        :dashboard="dashboard as never"
    />
    <CandidateDashboard
        v-else-if="role === 'candidate'"
        :dashboard="dashboard as never"
    />
    <EmployerDashboard v-else :dashboard="dashboard as never" />
</template>
