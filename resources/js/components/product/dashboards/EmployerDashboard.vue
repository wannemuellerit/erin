<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    CalendarDays,
    Plus,
    UserRoundSearch,
    Users,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useStatusLabels } from '@/composables/useStatusLabels';

type Usage = { used: number; limit: number | null; remaining: number };
type Dashboard = {
    kind: 'company';
    requires_company?: boolean;
    company?: {
        id: number;
        name: string;
        status?: string;
        subscription_status?: string;
    } | null;
    entitlements?: {
        plan?: { name?: string } | null;
        jobs?: Usage;
        seats?: Usage;
        ai_credits?: Usage;
        visa_credits?: Usage;
    };
    active_jobs?: number;
    new_applications?: number;
    open_interviews?: number;
    dashboard_notice?: {
        enabled: boolean;
        title: string;
        body: string;
        url?: string | null;
    };
    recent_applications?: Array<{
        id: number;
        status: string;
        job_posting?: { id: number; title: string } | null;
        candidate_profile?: {
            id: number;
            current_position?: string | null;
            current_country_code?: string | null;
        } | null;
    }>;
};
withDefaults(defineProps<{ dashboard?: Dashboard | null }>(), {
    dashboard: null,
});
const progress = (usage?: Usage) =>
    usage?.limit ? Math.round((usage.used / usage.limit) * 100) : 0;
const { statusLabel } = useStatusLabels();
const { t } = useI18n();
</script>

<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('dashboard.employer.eyebrow')"
            :title="
                dashboard?.company
                    ? t('dashboard.employer.welcome', {
                          company: dashboard.company.name,
                      })
                    : t('dashboard.employer.titleFallback')
            "
            :description="t('dashboard.employer.description')"
        >
            <template #actions>
                <Link
                    href="/employer/candidates"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700"
                    ><UserRoundSearch
                        class="size-4 text-[var(--erin-secondary)]"
                    />
                    {{ t('dashboard.employer.findCandidates') }}</Link
                >
                <Link
                    href="/employer/jobs/create"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white"
                    ><Plus class="size-4" />
                    {{ t('dashboard.employer.createJob') }}</Link
                >
            </template>
        </PageHeader>
        <div
            v-if="dashboard?.requires_company"
            class="erin-panel p-8 text-center"
        >
            <BriefcaseBusiness class="mx-auto size-9 text-slate-300" />
            <h2 class="mt-4 font-bold">
                {{ t('dashboard.employer.noCompanyTitle') }}
            </h2>
            <p class="mt-2 text-sm text-slate-500">
                {{ t('dashboard.employer.noCompanyDescription') }}
            </p>
        </div>
        <template v-else>
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    :label="t('dashboard.employer.activeJobs')"
                    :value="dashboard?.active_jobs ?? 0"
                    :icon="BriefcaseBusiness"
                />
                <MetricCard
                    :label="t('dashboard.employer.newApplications')"
                    :value="dashboard?.new_applications ?? 0"
                    :icon="Users"
                    tone="teal"
                />
                <MetricCard
                    :label="t('dashboard.employer.openInterviews')"
                    :value="dashboard?.open_interviews ?? 0"
                    :icon="CalendarDays"
                    tone="violet"
                />
                <MetricCard
                    :label="t('dashboard.employer.visaCredits')"
                    :value="
                        dashboard?.entitlements?.visa_credits?.remaining ?? 0
                    "
                    :icon="BriefcaseBusiness"
                    tone="orange"
                />
            </section>
            <div class="grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
                <SectionCard
                    :title="t('dashboard.employer.recentApplications')"
                    :description="
                        t('dashboard.employer.recentApplicationsDescription')
                    "
                >
                    <div
                        v-if="dashboard?.recent_applications?.length"
                        class="divide-y divide-slate-100"
                    >
                        <div
                            v-for="application in dashboard.recent_applications"
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
                                        application.candidate_profile
                                            ?.current_position ||
                                        t(
                                            'dashboard.employer.professionalFallback',
                                        )
                                    }}
                                </p>
                                <p class="truncate text-xs text-slate-600">
                                    {{
                                        application.job_posting?.title ||
                                        t('dashboard.employer.jobFallback')
                                    }}<template
                                        v-if="
                                            application.candidate_profile
                                                ?.current_country_code
                                        "
                                    >
                                        ·
                                        {{
                                            application.candidate_profile
                                                .current_country_code
                                        }}</template
                                    >
                                </p>
                            </div>
                            <StatusBadge
                                :label="
                                    statusLabel(
                                        'application',
                                        application.status,
                                    )
                                "
                                tone="blue"
                            />
                        </div>
                    </div>
                    <p v-else class="py-10 text-center text-sm text-slate-600">
                        {{ t('dashboard.employer.noApplications') }}
                    </p>
                </SectionCard>
                <SectionCard
                    :title="
                        dashboard?.entitlements?.plan?.name ??
                        t('dashboard.employer.quotaFallback')
                    "
                >
                    <div class="space-y-5">
                        <ProgressBar
                            :label="t('dashboard.employer.jobSlots')"
                            :value="progress(dashboard?.entitlements?.jobs)"
                        />
                        <ProgressBar
                            :label="t('dashboard.employer.recruiterSeats')"
                            :value="progress(dashboard?.entitlements?.seats)"
                            tone="teal"
                        />
                        <ProgressBar
                            :label="t('dashboard.employer.aiCredits')"
                            :value="
                                progress(dashboard?.entitlements?.ai_credits)
                            "
                            tone="orange"
                        />
                    </div>
                    <Link
                        href="/employer/billing"
                        class="mt-5 block text-center text-xs font-bold text-[var(--erin-primary)]"
                        >{{ t('dashboard.employer.managePlan') }}</Link
                    >
                </SectionCard>
            </div>
            <section
                v-if="dashboard?.dashboard_notice?.enabled"
                class="erin-panel bg-slate-900 p-6 text-white"
            >
                <h2 class="font-extrabold">
                    {{ dashboard.dashboard_notice.title }}
                </h2>
                <p class="mt-1 text-sm text-slate-300">
                    {{ dashboard.dashboard_notice.body }}
                </p>
                <a
                    v-if="dashboard.dashboard_notice.url"
                    :href="dashboard.dashboard_notice.url"
                    class="mt-3 inline-block text-xs font-bold text-teal-300 hover:text-teal-200"
                    >{{ t('dashboard.employer.learnMore') }}</a
                >
            </section>
        </template>
    </div>
</template>
