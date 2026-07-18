<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BriefcaseBusiness,
    Building2,
    CheckCircle2,
    MapPin,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { companies as companiesIndex } from '@/routes/candidate';
import { show as showJob } from '@/routes/candidate/jobs';

type Trust = {
    response_rate?: number | null;
    interview_attendance_rate?: number | null;
    contract_compliance_rate?: number | null;
    cases_count: number;
    is_top_company: boolean;
};
type Company = {
    id: number;
    name: string;
    industry?: string | null;
    description?: string | null;
    employee_count?: number | null;
    city?: string | null;
    country_code?: string | null;
    logo_url?: string | null;
    benefits?: string[] | Record<string, boolean> | null;
    trust_metrics?: Trust | null;
    locations?: Array<{
        id: number;
        name: string;
        city?: string | null;
        country_code?: string | null;
        is_headquarters?: boolean;
    }>;
    media?: Array<{
        id: number;
        type: string;
        name: string;
        url: string | null;
    }>;
};
type Job = {
    id: number;
    title: string;
    position?: string | null;
    employment_type?: string | null;
    location?: { city?: string | null; name?: string | null } | null;
};

const props = defineProps<{ company: Company; jobs: Job[] }>();
const { t, te } = useI18n();
const benefits = Array.isArray(props.company.benefits)
    ? props.company.benefits
    : Object.entries(props.company.benefits ?? {})
          .filter(([, enabled]) => enabled)
          .map(([key]) => key);
const benefitLabel = (key: string) =>
    te(`candidate.companies.benefits.${key}`)
        ? t(`candidate.companies.benefits.${key}`)
        : key.replaceAll('_', ' ');
const percentage = (value?: number | null) =>
    value == null ? '—' : `${Math.round(value)} %`;
</script>

<template>
    <Head :title="company.name" />
    <div class="erin-page">
        <Link
            :href="companiesIndex.url()"
            class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-[var(--erin-primary)]"
        >
            <ArrowLeft class="size-4" />
            {{ t('candidate.companyDetail.back') }}
        </Link>
        <PageHeader
            :eyebrow="
                company.industry || t('candidate.companies.industryMissing')
            "
            :title="company.name"
            :description="
                company.description ||
                t('candidate.companyDetail.descriptionMissing')
            "
            :icon="Building2"
        >
            <template #actions>
                <StatusBadge
                    v-if="company.trust_metrics?.is_top_company"
                    :label="t('candidate.companyDetail.topCompany')"
                    tone="teal"
                />
            </template>
        </PageHeader>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid min-w-0 gap-5">
                <SectionCard :title="t('candidate.companyDetail.trust')">
                    <div
                        v-if="company.trust_metrics"
                        class="grid gap-3 md:grid-cols-3"
                    >
                        <MetricCard
                            :label="t('candidate.companyDetail.responseRate')"
                            :value="
                                percentage(company.trust_metrics.response_rate)
                            "
                            :icon="ShieldCheck"
                            tone="blue"
                        />
                        <MetricCard
                            :label="
                                t('candidate.companyDetail.interviewAttendance')
                            "
                            :value="
                                percentage(
                                    company.trust_metrics
                                        .interview_attendance_rate,
                                )
                            "
                            :icon="Users"
                            tone="teal"
                        />
                        <MetricCard
                            :label="
                                t('candidate.companyDetail.contractCompliance')
                            "
                            :value="
                                percentage(
                                    company.trust_metrics
                                        .contract_compliance_rate,
                                )
                            "
                            :icon="CheckCircle2"
                            tone="orange"
                        />
                    </div>
                    <p v-else class="text-sm leading-6 text-slate-500">
                        {{ t('candidate.companyDetail.trustThreshold') }}
                    </p>
                </SectionCard>

                <SectionCard :title="t('candidate.companyDetail.jobs')">
                    <div class="grid gap-3">
                        <Link
                            v-for="job in jobs"
                            :key="job.id"
                            :href="showJob.url(job.id)"
                            class="flex items-center justify-between gap-4 rounded-xl border border-slate-200 p-4 transition hover:border-blue-300 hover:bg-blue-50/40"
                        >
                            <div>
                                <p class="font-extrabold text-slate-950">
                                    {{ job.title }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ job.position || job.employment_type }}
                                </p>
                            </div>
                            <BriefcaseBusiness
                                class="size-5 shrink-0 text-blue-600"
                            />
                        </Link>
                    </div>
                </SectionCard>

                <SectionCard
                    v-if="company.media?.some((medium) => medium.url)"
                    :title="t('candidate.companyDetail.media')"
                >
                    <div class="grid gap-3 sm:grid-cols-2">
                        <img
                            v-for="medium in company.media.filter(
                                (item) => item.url,
                            )"
                            :key="medium.id"
                            :src="medium.url ?? ''"
                            :alt="medium.name"
                            class="aspect-video w-full rounded-xl object-cover"
                        />
                    </div>
                </SectionCard>
            </div>

            <aside class="grid content-start gap-5">
                <SectionCard :title="t('candidate.companyDetail.facts')">
                    <div class="grid gap-4 text-sm">
                        <div class="flex gap-3">
                            <Users class="mt-0.5 size-4 text-blue-600" />
                            <span>
                                {{
                                    company.employee_count
                                        ? t('candidate.companies.employees', {
                                              count: company.employee_count,
                                          })
                                        : '—'
                                }}
                            </span>
                        </div>
                        <div class="flex gap-3">
                            <MapPin class="mt-0.5 size-4 text-teal-600" />
                            <span
                                >{{ company.city || '—' }},
                                {{ company.country_code }}</span
                            >
                        </div>
                    </div>
                </SectionCard>
                <SectionCard
                    v-if="benefits.length"
                    :title="t('candidate.companyDetail.benefits')"
                >
                    <div class="flex flex-wrap gap-2">
                        <StatusBadge
                            v-for="benefit in benefits"
                            :key="benefit"
                            :label="benefitLabel(benefit)"
                            tone="teal"
                        />
                    </div>
                </SectionCard>
                <SectionCard
                    v-if="company.locations?.length"
                    :title="t('candidate.companyDetail.locations')"
                >
                    <div
                        v-for="location in company.locations"
                        :key="location.id"
                        class="border-b border-slate-100 py-3 text-sm last:border-0"
                    >
                        <p class="font-bold">{{ location.name }}</p>
                        <p class="text-xs text-slate-500">
                            {{ location.city }}, {{ location.country_code }}
                        </p>
                    </div>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
