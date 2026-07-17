<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { BriefcaseBusiness, Clock3, MapPin, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { jobs as jobsIndex } from '@/routes/candidate';
import { apply } from '@/routes/candidate/jobs';

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
        return 'Vergütung auf Anfrage';
    }

    const formatter = new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: job.currency ?? 'EUR',
        maximumFractionDigits: 0,
    });
    const minimum =
        job.compensation_min_cents != null
            ? formatter.format(job.compensation_min_cents / 100)
            : '';
    const maximum =
        job.compensation_max_cents != null
            ? formatter.format(job.compensation_max_cents / 100)
            : '';

    return [minimum, maximum].filter(Boolean).join(' – ');
};
</script>

<template>
    <Head title="Passende Jobs" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Job Discovery"
            title="Passende Jobs für dich"
            description="Diese Stellen passen zu deinen Skills, deiner Sprache und deinen persönlichen Wünschen."
            :icon="BriefcaseBusiness"
        >
            <template #actions
                ><StatusBadge :label="`${jobs.length} Matches`" tone="teal"
            /></template>
        </PageHeader>
        <div
            v-if="!can_apply"
            class="rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 text-sm text-orange-800"
        >
            Dein Profil ist zu {{ profile_completeness }} % vollständig. Für
            Bewerbungen muss es veröffentlicht und mindestens zu 80 %
            vollständig sein.
        </div>
        <section class="erin-panel p-4">
            <form
                class="flex flex-col gap-3 lg:flex-row"
                @submit.prevent="submitSearch"
            >
                <div class="relative flex-1">
                    <Search
                        class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    /><input
                        v-model="search"
                        type="search"
                        placeholder="Beruf, Unternehmen oder Ort suchen …"
                        class="h-11 w-full rounded-xl border border-slate-200 pl-10 text-sm"
                    />
                </div>
                <button
                    type="submit"
                    class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white"
                >
                    Suchen
                </button>
            </form>
            <div
                v-if="Object.values(filters).some(Boolean)"
                class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3"
            >
                <span
                    v-if="filters.employment_type"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700"
                    >{{ filters.employment_type }}</span
                >
                <span
                    v-if="filters.visa_support"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700"
                    >Visa-Unterstützung</span
                >
                <span
                    v-if="filters.remote"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700"
                    >Remote</span
                >
                <button
                    type="button"
                    class="text-xs font-bold text-slate-400"
                    @click="router.get(jobsIndex.url())"
                >
                    Filter löschen
                </button>
            </div>
        </section>

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
                                label="Boost"
                                tone="orange"
                            />
                        </div>
                        <p class="mt-1 text-xs font-medium text-slate-500">
                            {{
                                job.company?.name ??
                                'Unternehmen nicht verfügbar'
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
                                    'Standort offen'
                                }}</span
                            >
                            <span
                                v-if="job.published_at"
                                class="inline-flex items-center gap-1"
                                ><Clock3
                                    class="size-3.5 text-[var(--erin-primary)]"
                                />{{
                                    new Intl.DateTimeFormat('de-DE', {
                                        dateStyle: 'medium',
                                    }).format(new Date(job.published_at))
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
                            >Match</span
                        >
                    </div>
                </div>
                <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">
                    {{ job.description || 'Keine Beschreibung hinterlegt.' }}
                </p>
                <div class="mt-4 flex flex-wrap gap-1.5">
                    <span
                        v-for="skill in job.skills ?? []"
                        :key="skill.id"
                        class="rounded-lg bg-slate-100 px-2.5 py-1 text-[10px] font-semibold text-slate-600"
                        >{{
                            skill.name_de ?? skill.name_en ?? skill.slug
                        }}</span
                    >
                    <span
                        v-if="job.visa_package_available"
                        class="rounded-lg bg-teal-50 px-2.5 py-1 text-[10px] font-bold text-teal-700"
                        >Visa-Support</span
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
                                ? 'Bewerbung starten'
                                : 'Jetzt bewerben'
                        }}
                    </button>
                    <StatusBadge v-else label="Bereits beworben" tone="green" />
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
                            Anschreiben (optional)
                        </label>
                        <textarea
                            :id="`cover-letter-${job.id}`"
                            v-model="applicationForm.cover_letter"
                            rows="4"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm"
                            placeholder="Warum passt diese Stelle zu dir?"
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
                            Abbrechen
                        </button>
                        <button
                            type="submit"
                            :disabled="applicationForm.processing"
                            class="h-9 rounded-xl bg-[var(--erin-primary)] px-4 text-xs font-bold text-white disabled:opacity-50"
                        >
                            Bewerbung absenden
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
                <h2 class="mt-4 font-bold">Keine passenden Jobs gefunden</h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    Passe deine Suche an oder ergänze dein Profil, um genauere
                    Matches zu erhalten.
                </p>
            </div>
        </div>
    </div>
</template>
