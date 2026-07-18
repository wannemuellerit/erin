<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BriefcaseBusiness,
    Clock3,
    Download,
    Languages,
    MapPin,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import MatchScore from '@/components/product/MatchScore.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import { Button } from '@/components/ui/button';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';
import { jobs as jobsIndex } from '@/routes/candidate';
import { apply } from '@/routes/candidate/jobs';
import { show as showCompany } from '@/routes/candidate/companies';

type Job = {
    id: number;
    title: string;
    position?: string | null;
    description?: string | null;
    expected_experience_years?: number | null;
    language_notes?: string | null;
    hours_min?: number | null;
    hours_max?: number | null;
    employment_type?: string | null;
    compensation_min_cents?: number | null;
    compensation_max_cents?: number | null;
    currency?: string | null;
    compensation_interval?: string | null;
    visa_package_available?: boolean;
    already_applied?: boolean;
    match?: {
        score: number;
        factors?: Record<string, unknown>;
        version?: string;
    };
    company: {
        id: number;
        name: string;
        industry?: string | null;
        city?: string | null;
        logo_url?: string | null;
        trust_metrics?: {
            response_rate?: number | null;
            interview_attendance_rate?: number | null;
            contract_compliance_rate?: number | null;
            cases_count: number;
            is_top_company: boolean;
        } | null;
    };
    location?: { name?: string; city?: string; country_code?: string } | null;
    skills?: Array<Record<string, string | number>>;
    languages?: Array<Record<string, unknown>>;
    screening_questions?: Array<{
        id: number;
        question: string;
        is_required: boolean;
    }>;
    media?: Array<{
        id: number;
        name: string;
        mime_type?: string | null;
        size_bytes?: number | null;
        download_url: string;
    }>;
};

const props = defineProps<{
    job: Job;
    can_apply: boolean;
    profile_completeness: number;
}>();
const { t, te } = useI18n();
const { formatCurrency, formatNumber } = useFormatters();
const { localizedField } = useLocalizedField();
const form = useForm({
    cover_letter: '',
    answers: (props.job.screening_questions ?? []).map((question) => ({
        question_id: question.id,
        answer: '',
    })),
});
const applicationError = computed(() => {
    const errors = form.errors as Record<string, string>;

    return errors.profile ?? errors.answers ?? errors.application ?? '';
});
const location = computed(
    () =>
        props.job.location?.city ??
        props.job.location?.name ??
        props.job.company.city ??
        t('candidate.jobs.locationOpen'),
);
const compensation = computed(() => {
    const values = [
        props.job.compensation_min_cents,
        props.job.compensation_max_cents,
    ]
        .filter((value): value is number => value != null)
        .map((value) =>
            formatCurrency(value / 100, props.job.currency ?? 'EUR', {
                maximumFractionDigits: 0,
            }),
        );

    return values.length
        ? values.join(' – ')
        : t('candidate.jobs.compensationOnRequest');
});
const employmentType = computed(() => {
    const value = props.job.employment_type ?? '';
    const key = `candidate.jobs.employmentTypes.${value}`;

    return te(key) ? t(key) : value.replaceAll('_', ' ');
});
const languageRequirements = computed(() => {
    const requirements = (props.job.languages ?? [])
        .map((language) => {
            const name = localizedField(
                language as Record<string, string | number>,
                'name',
                String(language.code ?? ''),
            );
            const level = String(
                language.minimum_level ?? language.level ?? '',
            ).toUpperCase();

            return [name, level].filter(Boolean).join(' · ');
        })
        .filter(Boolean);

    if (props.job.language_notes) {
        requirements.push(props.job.language_notes);
    }

    return requirements;
});
const submit = () =>
    form.post(apply.url(props.job.id), { preserveScroll: true });
</script>

<template>
    <Head :title="job.title" />
    <div class="erin-page">
        <Link
            :href="jobsIndex.url()"
            class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-[var(--erin-primary)]"
        >
            <ArrowLeft class="size-4" />
            {{ t('candidate.jobDetail.back') }}
        </Link>
        <PageHeader
            :eyebrow="job.company.name"
            :title="job.title"
            :description="job.position || employmentType"
            :icon="BriefcaseBusiness"
        >
            <template #actions>
                <MatchScore v-if="job.match" :score="job.match.score" />
            </template>
        </PageHeader>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="grid min-w-0 gap-5">
                <SectionCard :title="t('candidate.jobDetail.overview')">
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="rounded-xl bg-slate-50 p-4">
                            <MapPin class="size-4 text-teal-600" />
                            <p class="mt-2 text-xs text-slate-500">
                                {{ t('candidate.jobDetail.location') }}
                            </p>
                            <p class="font-bold">{{ location }}</p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <Clock3 class="size-4 text-blue-600" />
                            <p class="mt-2 text-xs text-slate-500">
                                {{ t('candidate.jobDetail.hours') }}
                            </p>
                            <p class="font-bold">
                                {{ job.hours_min ?? '—' }}–{{
                                    job.hours_max ?? '—'
                                }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <Languages class="size-4 text-orange-500" />
                            <p class="mt-2 text-xs text-slate-500">
                                {{ t('candidate.jobDetail.experience') }}
                            </p>
                            <p class="font-bold">
                                {{
                                    job.expected_experience_years != null
                                        ? t('candidate.jobDetail.years', {
                                              count: job.expected_experience_years,
                                          })
                                        : '—'
                                }}
                            </p>
                        </div>
                    </div>
                    <p
                        class="mt-5 text-sm leading-7 whitespace-pre-line text-slate-700"
                    >
                        {{
                            job.description ||
                            t('candidate.jobs.descriptionMissing')
                        }}
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <StatusBadge :label="employmentType" tone="blue" />
                        <StatusBadge
                            v-if="job.visa_package_available"
                            :label="t('candidate.jobs.visaSupport')"
                            tone="teal"
                        />
                        <span
                            v-for="skill in job.skills ?? []"
                            :key="Number(skill.id)"
                            class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-600"
                        >
                            {{
                                localizedField(
                                    skill,
                                    'name',
                                    String(skill.slug ?? ''),
                                )
                            }}
                        </span>
                    </div>
                    <div class="mt-5 border-t border-slate-100 pt-5">
                        <p class="text-xs font-bold text-slate-500">
                            {{ t('candidate.jobDetail.languages') }}
                        </p>
                        <div
                            v-if="languageRequirements.length"
                            class="mt-2 flex flex-wrap gap-2"
                        >
                            <StatusBadge
                                v-for="requirement in languageRequirements"
                                :key="requirement"
                                :label="requirement"
                                tone="teal"
                            />
                        </div>
                        <p v-else class="mt-2 text-sm text-slate-600">
                            {{ t('candidate.jobDetail.languageOpen') }}
                        </p>
                    </div>
                </SectionCard>

                <SectionCard
                    v-if="job.media?.length"
                    :title="t('candidate.jobDetail.media')"
                >
                    <a
                        v-for="medium in job.media"
                        :key="medium.id"
                        :href="medium.download_url"
                        class="flex items-center justify-between gap-3 border-b border-slate-100 py-3 text-sm font-bold text-slate-700 last:border-0"
                    >
                        <span class="min-w-0 truncate">{{ medium.name }}</span>
                        <span
                            class="flex shrink-0 items-center gap-2 text-xs text-blue-600"
                        >
                            <template v-if="medium.size_bytes">
                                {{
                                    formatNumber(
                                        Math.ceil(medium.size_bytes / 1024),
                                    )
                                }}
                                KB
                            </template>
                            <Download class="size-4" />
                        </span>
                    </a>
                </SectionCard>
            </div>

            <aside class="grid content-start gap-5">
                <SectionCard :title="t('candidate.jobDetail.company')">
                    <div class="flex items-center gap-3">
                        <img
                            v-if="job.company.logo_url"
                            :src="job.company.logo_url"
                            :alt="job.company.name"
                            class="size-12 rounded-xl object-cover"
                        />
                        <div>
                            <p class="font-extrabold">{{ job.company.name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ job.company.industry }}
                            </p>
                        </div>
                    </div>
                    <Link
                        :href="showCompany.url(job.company.id)"
                        class="mt-4 block text-sm font-bold text-blue-600"
                    >
                        {{ t('candidate.jobDetail.companyProfile') }}
                    </Link>
                </SectionCard>

                <SectionCard :title="t('candidate.jobDetail.application')">
                    <p class="mb-4 text-lg font-extrabold text-slate-950">
                        {{ compensation }}
                    </p>
                    <StatusBadge
                        v-if="job.already_applied"
                        :label="t('candidate.jobs.alreadyApplied')"
                        tone="teal"
                    />
                    <template v-else>
                        <div
                            v-if="!can_apply"
                            class="mb-4 rounded-xl bg-orange-50 p-3 text-xs leading-5 text-orange-800"
                        >
                            {{
                                t('candidate.jobs.profileIncomplete', {
                                    percentage: profile_completeness,
                                })
                            }}
                        </div>
                        <Textarea
                            v-model="form.cover_letter"
                            :placeholder="
                                t('candidate.jobs.coverLetterPlaceholder')
                            "
                            rows="5"
                        />
                        <div
                            v-for="(
                                question, index
                            ) in job.screening_questions ?? []"
                            :key="question.id"
                            class="mt-4"
                        >
                            <label
                                class="mb-2 block text-xs font-bold text-slate-700"
                            >
                                {{ question.question }}
                                <span v-if="question.is_required">*</span>
                            </label>
                            <Textarea
                                v-model="form.answers[index].answer"
                                rows="3"
                            />
                        </div>
                        <p
                            v-if="applicationError"
                            class="mt-3 text-xs font-bold text-red-600"
                        >
                            {{ applicationError }}
                        </p>
                        <Button
                            class="mt-4 w-full"
                            :disabled="!can_apply || form.processing"
                            @click="submit"
                        >
                            {{ t('candidate.jobs.submit') }}
                        </Button>
                    </template>
                </SectionCard>
            </aside>
        </div>
    </div>
</template>
