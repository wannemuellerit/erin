<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BriefcaseBusiness, Clock3, MapPin } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FilterChip from '@/components/product/FilterChip.vue';
import FilterToolbar from '@/components/product/FilterToolbar.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';
import { jobs as jobsIndex } from '@/routes/candidate';
import { apply } from '@/routes/candidate/jobs';
import { show } from '@/routes/candidate/jobs';

type Job = {
    id: number;
    title: string;
    position?: string;
    description?: string;
    employment_type?: string;
    compensation_min_cents?: number | null;
    compensation_max_cents?: number | null;
    compensation_interval?: string;
    currency?: string;
    visa_package_available?: boolean;
    is_remote?: boolean;
    published_at?: string | null;
    already_applied?: boolean;
    boosted_until?: string | null;
    match?: { score?: number; factors?: Record<string, unknown> } | null;
    company?: {
        id: number;
        name: string;
        industry?: string | null;
        city?: string | null;
        benefits?: string[] | null;
    } | null;
    location?: { city?: string; name?: string; country_code?: string } | null;
    skills?: Array<{
        id: number;
        name_de?: string;
        name_en?: string;
        slug?: string;
    }>;
    screening_questions?: Array<{
        id: number;
        question: string;
        is_required?: boolean;
    }>;
};
type Filters = {
    search?: string;
    employment_type?: string;
    visa_support?: boolean;
    remote?: boolean;
};

const props = withDefaults(
    defineProps<{
        jobs?: Job[];
        filters?: Filters;
        can_apply?: boolean;
        profile_completeness?: number;
    }>(),
    {
        jobs: () => [],
        filters: () => ({}),
        can_apply: false,
        profile_completeness: 0,
    },
);
const { t, te } = useI18n();
const { formatCurrency, formatDate } = useFormatters();
const { localizedField } = useLocalizedField();
const search = ref(props.filters.search ?? '');
const applyingJobId = ref<number | null>(null);
const applicationForm = useForm({
    cover_letter: '',
    answers: [] as Array<{ question_id: number; answer: string }>,
});
const applicationError = computed(
    () =>
        (applicationForm.errors as Record<string, string>).application ??
        applicationForm.errors.answers,
);
const submitSearch = () =>
    router.get(
        jobsIndex.url(),
        { ...props.filters, search: search.value || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
const beginApplication = (job: Job) => {
    applyingJobId.value = job.id;
    applicationForm.clearErrors();
    applicationForm.cover_letter = '';
    applicationForm.answers = (job.screening_questions ?? []).map(
        (question) => ({
            question_id: question.id,
            answer: '',
        }),
    );
};
const submitApplication = (job: Job) => {
    applicationForm.post(apply.url(job.id), {
        preserveScroll: true,
        onSuccess: () => {
            applicationForm.reset();
            applyingJobId.value = null;
        },
    });
};
const money = (job: Job) => {
    if (
        job.compensation_min_cents == null &&
        job.compensation_max_cents == null
    ) {
        return t('candidate.jobs.compensationOnRequest');
    }

    const options = {
        maximumFractionDigits: 0,
    } satisfies Intl.NumberFormatOptions;
    const minimum =
        job.compensation_min_cents != null
            ? formatCurrency(
                  job.compensation_min_cents / 100,
                  job.currency ?? 'EUR',
                  options,
              )
            : '';
    const maximum =
        job.compensation_max_cents != null
            ? formatCurrency(
                  job.compensation_max_cents / 100,
                  job.currency ?? 'EUR',
                  options,
              )
            : '';

    return [minimum, maximum].filter(Boolean).join(' – ');
};
const employmentTypeLabel = (value: string) => {
    const key = `candidate.jobs.employmentTypes.${value}`;

    return te(key) ? t(key) : value.replaceAll('_', ' ');
};
</script>

<template>
    <Head :title="t('candidate.jobs.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('candidate.jobs.eyebrow')"
            :title="t('candidate.jobs.title')"
            :description="t('candidate.jobs.description')"
            :icon="BriefcaseBusiness"
        >
            <template #actions
                ><StatusBadge
                    :label="t('candidate.jobs.matches', { count: jobs.length })"
                    tone="teal"
            /></template>
        </PageHeader>
        <div
            v-if="!can_apply"
            class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800"
        >
            {{
                t('candidate.jobs.profileIncomplete', {
                    percentage: profile_completeness,
                })
            }}
        </div>
        <FilterToolbar>
            <form @submit.prevent="submitSearch">
                <SearchField
                    v-model="search"
                    :placeholder="t('candidate.jobs.searchPlaceholder')"
                />
            </form>
            <template #actions>
                <button
                    type="button"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white"
                    @click="submitSearch"
                >
                    {{ t('candidate.jobs.search') }}
                </button>
            </template>
            <template v-if="Object.values(filters).some(Boolean)" #filters>
                <FilterChip
                    v-if="filters.employment_type"
                    :label="employmentTypeLabel(filters.employment_type)"
                />
                <FilterChip
                    v-if="filters.visa_support"
                    :label="t('candidate.jobs.visaSupport')"
                />
                <FilterChip
                    v-if="filters.remote"
                    :label="t('candidate.jobs.remote')"
                />
                <button
                    type="button"
                    class="text-xs font-bold text-slate-400"
                    @click="router.get(jobsIndex.url())"
                >
                    {{ t('candidate.jobs.clearFilters') }}
                </button>
            </template>
        </FilterToolbar>

        <div v-if="jobs.length" class="grid gap-4 xl:grid-cols-2">
            <article
                v-for="job in jobs"
                :key="job.id"
                class="erin-panel group p-5 transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-xl"
            >
                <div class="flex items-start gap-4">
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-xl bg-blue-50 font-extrabold text-[var(--erin-primary)]"
                        >{{ job.company?.name?.slice(0, 2) ?? 'ER' }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-base font-extrabold text-slate-950">
                                {{ job.title }}
                            </h2>
                            <StatusBadge
                                v-if="job.boosted_until"
                                :label="t('candidate.jobs.boost')"
                                tone="orange"
                            />
                        </div>
                        <p class="mt-1 text-xs font-medium text-slate-500">
                            {{
                                job.company?.name ??
                                t('candidate.common.companyUnavailable')
                            }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><MapPin
                                    class="size-3.5 text-[var(--erin-secondary)]"
                                />{{
                                    job.location?.city ??
                                    job.company?.city ??
                                    t('candidate.jobs.locationOpen')
                                }}</span
                            >
                            <span
                                v-if="job.published_at"
                                class="inline-flex items-center gap-1"
                                ><Clock3
                                    class="size-3.5 text-[var(--erin-primary)]"
                                />{{
                                    formatDate(job.published_at, {
                                        dateStyle: 'medium',
                                    })
                                }}</span
                            >
                        </div>
                    </div>
                    <div v-if="job.match" class="text-center">
                        <span
                            class="block text-lg font-extrabold text-[var(--erin-secondary)]"
                            >{{ job.match.score ?? 0 }} %</span
                        ><span
                            class="block text-[9px] font-bold text-slate-400 uppercase"
                            >{{ t('candidate.jobs.match') }}</span
                        >
                    </div>
                </div>
                <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">
                    {{
                        job.description ||
                        t('candidate.jobs.descriptionMissing')
                    }}
                </p>
                <div class="mt-4 flex flex-wrap gap-1.5">
                    <span
                        v-for="skill in job.skills ?? []"
                        :key="skill.id"
                        class="rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-600"
                        >{{ localizedField(skill, 'name', skill.slug) }}</span
                    >
                    <span
                        v-if="job.visa_package_available"
                        class="rounded-lg bg-teal-50 px-2.5 py-1 text-[10px] font-bold text-teal-700"
                        >{{ t('candidate.jobs.visaSupportShort') }}</span
                    >
                </div>
                <div
                    class="mt-5 flex items-center justify-between gap-3 border-t border-slate-100 pt-4"
                >
                    <p class="text-sm font-extrabold text-slate-800">
                        {{ money(job) }}
                    </p>
                    <button
                        v-if="!job.already_applied"
                        type="button"
                        :disabled="!can_apply"
                        class="h-10 rounded-xl bg-[var(--erin-primary)] px-4 text-xs font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-200"
                        @click="beginApplication(job)"
                    >
                        {{
                            job.screening_questions?.length
                                ? t('candidate.jobs.startApplication')
                                : t('candidate.jobs.applyNow')
                        }}
                    </button>
                    <StatusBadge
                        v-else
                        :label="t('candidate.jobs.alreadyApplied')"
                        tone="green"
                    />
                    <Link
                        :href="show.url(job.id)"
                        class="h-10 rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-700 hover:border-blue-300 hover:text-blue-700"
                    >
                        {{ t('candidate.jobDetail.overview') }}
                    </Link>
                </div>
                <form
                    v-if="applyingJobId === job.id"
                    class="mt-4 space-y-4 rounded-xl border border-blue-100 bg-blue-50/40 p-4"
                    @submit.prevent="submitApplication(job)"
                >
                    <div>
                        <label
                            :for="`cover-letter-${job.id}`"
                            class="text-xs font-bold text-slate-700"
                        >
                            {{ t('candidate.jobs.coverLetter') }}
                        </label>
                        <textarea
                            :id="`cover-letter-${job.id}`"
                            v-model="applicationForm.cover_letter"
                            rows="4"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm"
                            :placeholder="
                                t('candidate.jobs.coverLetterPlaceholder')
                            "
                        />
                    </div>
                    <div
                        v-for="(question, index) in job.screening_questions ??
                        []"
                        :key="question.id"
                    >
                        <label
                            :for="`screening-${job.id}-${question.id}`"
                            class="text-xs font-bold text-slate-700"
                        >
                            {{ question.question }}
                            <span
                                v-if="question.is_required"
                                aria-hidden="true"
                            >
                                *
                            </span>
                        </label>
                        <textarea
                            :id="`screening-${job.id}-${question.id}`"
                            v-model="applicationForm.answers[index].answer"
                            :required="question.is_required"
                            rows="3"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm"
                        />
                    </div>
                    <p
                        v-if="applicationError"
                        class="text-xs font-semibold text-red-600"
                    >
                        {{ applicationError }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="h-9 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600"
                            @click="applyingJobId = null"
                        >
                            {{ t('candidate.jobs.cancel') }}
                        </button>
                        <button
                            type="submit"
                            :disabled="applicationForm.processing"
                            class="h-9 rounded-xl bg-[var(--erin-primary)] px-4 text-xs font-bold text-white disabled:opacity-50"
                        >
                            {{ t('candidate.jobs.submit') }}
                        </button>
                    </div>
                </form>
            </article>
        </div>
        <div
            v-else
            class="erin-panel grid min-h-80 place-items-center p-8 text-center"
        >
            <div>
                <BriefcaseBusiness class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">
                    {{ t('candidate.jobs.emptyTitle') }}
                </h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    {{ t('candidate.jobs.emptyDescription') }}
                </p>
            </div>
        </div>
    </div>
</template>
