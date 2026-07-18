<script setup lang="ts">
import { Building2, FileCheck2, LifeBuoy, Users } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
type Dashboard = {
    kind: 'admin';
    users?: number;
    candidates?: number;
    companies?: number;
    documents_in_review?: number;
    open_tickets?: number;
    applications?: number;
};
withDefaults(defineProps<{ dashboard?: Dashboard | null }>(), {
    dashboard: null,
});
const { t } = useI18n();
</script>
<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('dashboard.admin.eyebrow')"
            :title="t('dashboard.admin.title')"
            :description="t('dashboard.admin.description')"
        />
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                :label="t('dashboard.admin.users')"
                :value="dashboard?.users ?? 0"
                :icon="Users"
            />
            <MetricCard
                :label="t('dashboard.admin.companies')"
                :value="dashboard?.companies ?? 0"
                :icon="Building2"
                tone="teal"
            />
            <MetricCard
                :label="t('dashboard.admin.documentsInReview')"
                :value="dashboard?.documents_in_review ?? 0"
                :icon="FileCheck2"
                tone="orange"
            />
            <MetricCard
                :label="t('dashboard.admin.openTickets')"
                :value="dashboard?.open_tickets ?? 0"
                :icon="LifeBuoy"
                tone="violet"
            />
        </section>
        <SectionCard :title="t('dashboard.admin.marketplace')">
            <dl class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-5">
                    <dt class="text-xs text-slate-600">
                        {{ t('dashboard.admin.candidates') }}
                    </dt>
                    <dd class="mt-1 text-3xl font-extrabold text-slate-950">
                        {{ dashboard?.candidates ?? 0 }}
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-5">
                    <dt class="text-xs text-slate-600">
                        {{ t('dashboard.admin.applications') }}
                    </dt>
                    <dd class="mt-1 text-3xl font-extrabold text-slate-950">
                        {{ dashboard?.applications ?? 0 }}
                    </dd>
                </div>
            </dl>
        </SectionCard>
    </div>
</template>
