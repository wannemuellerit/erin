<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    CalendarDays,
    FileWarning,
    Sparkles,
    UserRound,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import PlatformAd from '@/components/product/PlatformAd.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useStatusLabels } from '@/composables/useStatusLabels';
type Dashboard = {
    kind: 'candidate';
    requires_profile?: boolean;
    profile_completeness?: number;
    can_apply?: boolean;
    matching_jobs?: number;
    active_applications?: number;
    upcoming_interviews?: number;
    missing_documents?: number;
    latest_applications?: Array<{
        id: number;
        status: string;
        job_posting?: {
            title: string;
            company?: { name: string } | null;
        } | null;
    }>;
};
withDefaults(defineProps<{ dashboard?: Dashboard | null }>(), {
    dashboard: null,
});
const { statusLabel } = useStatusLabels();
const { t } = useI18n();
</script>
<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('dashboard.candidate.eyebrow')"
            :title="t('dashboard.candidate.title')"
            :description="t('dashboard.candidate.description')"
        >
            <template #actions
                ><Link
                    href="/candidate/profile"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-accent)] px-4 text-sm font-bold text-slate-950"
                    ><UserRound class="size-4" />
                    {{ t('dashboard.candidate.editProfile') }}</Link
                ></template
            >
        </PageHeader>
        <PlatformAd />
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                :label="t('dashboard.candidate.matchingJobs')"
                :value="dashboard?.matching_jobs ?? 0"
                :icon="Sparkles"
                tone="teal"
            />
            <MetricCard
                :label="t('dashboard.candidate.activeApplications')"
                :value="dashboard?.active_applications ?? 0"
                :icon="BriefcaseBusiness"
            />
            <MetricCard
                :label="t('dashboard.candidate.upcomingInterviews')"
                :value="dashboard?.upcoming_interviews ?? 0"
                :icon="CalendarDays"
                tone="violet"
            />
            <MetricCard
                :label="t('dashboard.candidate.missingDocuments')"
                :value="dashboard?.missing_documents ?? 0"
                :icon="FileWarning"
                tone="orange"
            />
        </section>
        <div class="grid gap-6 xl:grid-cols-[0.7fr_1.3fr]">
            <SectionCard :title="t('dashboard.candidate.profileCompleteness')"
                ><p class="text-4xl font-extrabold">
                    {{ dashboard?.profile_completeness ?? 0 }} %
                </p>
                <ProgressBar
                    class="mt-4"
                    :value="dashboard?.profile_completeness ?? 0"
                    :show-value="false"
                    tone="teal"
                />
                <p class="mt-4 text-xs leading-5 text-slate-500">
                    {{
                        dashboard?.can_apply
                            ? t('dashboard.candidate.applicationsEnabled')
                            : t('dashboard.candidate.applicationsBlocked')
                    }}
                </p></SectionCard
            >
            <SectionCard :title="t('dashboard.candidate.latestApplications')">
                <div
                    v-if="dashboard?.latest_applications?.length"
                    class="divide-y divide-slate-100"
                >
                    <div
                        v-for="application in dashboard.latest_applications"
                        :key="application.id"
                        class="flex items-center gap-3 py-3 first:pt-0 last:pb-0"
                    >
                        <span
                            class="grid size-10 place-items-center rounded-xl bg-blue-50 text-xs font-bold text-[var(--erin-primary)]"
                            >#{{ application.id }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-bold">
                                {{
                                    application.job_posting?.title ||
                                    t('dashboard.candidate.jobFallback')
                                }}
                            </p>
                            <p class="truncate text-xs text-slate-600">
                                {{
                                    application.job_posting?.company?.name ||
                                    t('dashboard.candidate.companyFallback')
                                }}
                            </p>
                        </div>
                        <StatusBadge
                            :label="
                                statusLabel('application', application.status)
                            "
                            tone="blue"
                        />
                    </div>
                </div>
                <p v-else class="py-10 text-center text-sm text-slate-600">
                    {{ t('dashboard.candidate.noApplications') }}
                </p>
            </SectionCard>
        </div>
    </div>
</template>
