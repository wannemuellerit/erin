<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BadgeCheck,
    BriefcaseBusiness,
    CalendarPlus,
    Check,
    GraduationCap,
    Heart,
    Languages,
    LockKeyhole,
    MapPin,
    Plane,
    Star,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MatchScore from '@/components/product/MatchScore.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';
import {
    index as candidatesIndex,
    invite,
    talentList,
} from '@/routes/employer/candidates';

type NamedOption = {
    id: number;
    name_de?: string;
    name_en?: string;
    slug?: string;
};

type Candidate = {
    id: number;
    label?: string;
    current_country_code?: string | null;
    summary?: string | null;
    current_position?: string | null;
    desired_position?: string | null;
    experience_years?: number | null;
    highest_qualification?: string | null;
    available_from?: string | null;
    relocation_ready?: boolean;
    requires_visa?: boolean;
    has_work_permit?: boolean;
    skills?: NamedOption[];
    languages?: Array<
        NamedOption & {
            code?: string;
            level?: string;
            verified?: boolean;
        }
    >;
    match?: {
        score?: number;
        factors?: Record<string, { score?: number } | number>;
    } | null;
    identity_revealed?: boolean;
    identity?: {
        first_name?: string;
        last_name?: string;
        email?: string;
        city?: string;
        phone?: string;
        whatsapp?: string;
    } | null;
    experiences?: Array<{
        position?: string;
        employer?: string;
        country_code?: string;
        started_at?: string;
        ended_at?: string | null;
        is_current?: boolean;
        description?: string | null;
    }>;
    educations?: Array<{
        qualification?: string;
        field?: string;
        country_code?: string;
        completed_at?: string | null;
    }>;
};

type Job = { id: number; title: string; status: string };
type TalentList = { id: number; name: string; members_count?: number };

const props = withDefaults(
    defineProps<{
        candidate?: Candidate | null;
        jobs?: Job[];
        talent_lists?: TalentList[];
    }>(),
    {
        candidate: null,
        jobs: () => [],
        talent_lists: () => [],
    },
);

const selectedJob = ref<number | null>(props.jobs[0]?.id ?? null);
const selectedList = ref<number | null>(props.talent_lists[0]?.id ?? null);
const { t, te } = useI18n();
const { can } = useCapabilities();
const canManageCandidates = computed(() => can('candidates.manage'));
const { formatDate } = useFormatters();
const { localizedField } = useLocalizedField();
const inviteForm = useForm({ job_posting_id: selectedJob.value, message: '' });
const listForm = useForm({
    talent_list_id: selectedList.value,
    list_name: '',
    note: '',
});

const position = computed(
    () =>
        props.candidate?.desired_position ??
        props.candidate?.current_position ??
        t('employer.candidateShow.candidateFallback'),
);
const identityName = computed(() => {
    const identity = props.candidate?.identity;

    return identity
        ? [identity.first_name, identity.last_name].filter(Boolean).join(' ')
        : (props.candidate?.label ?? `#ER-${props.candidate?.id ?? ''}`);
});
const score = computed(() => props.candidate?.match?.score ?? 0);
const factors = computed(() =>
    Object.entries(props.candidate?.match?.factors ?? {}).map(
        ([label, raw]) => ({
            label: te(`employer.candidateShow.matchFactors.${label}`)
                ? t(`employer.candidateShow.matchFactors.${label}`)
                : label.replaceAll('_', ' '),
            value: typeof raw === 'number' ? raw : (raw.score ?? 0),
        }),
    ),
);
const overviewItems = computed(() => {
    const candidate = props.candidate;

    if (!candidate) {
        return [];
    }

    const years = candidate.experience_years;
    const languages = candidate.languages
        ?.map((language) =>
            [
                localizedField(
                    language,
                    'name',
                    language.code ?? t('employer.candidateShow.notSpecified'),
                ),
                language.level,
            ]
                .filter(Boolean)
                .join(' '),
        )
        .join(', ');

    return [
        {
            icon: BriefcaseBusiness,
            label: t('employer.candidateShow.overview.currentPosition'),
            value:
                candidate.current_position ??
                t('employer.candidateShow.notSpecified'),
        },
        {
            icon: Star,
            label: t('employer.candidateShow.overview.experience'),
            value:
                years != null
                    ? t(
                          years === 1
                              ? 'employer.candidateShow.experienceYears.one'
                              : 'employer.candidateShow.experienceYears.other',
                          { count: years },
                      )
                    : t('employer.candidateShow.notSpecified'),
        },
        {
            icon: GraduationCap,
            label: t('employer.candidateShow.overview.qualification'),
            value:
                candidate.highest_qualification ??
                t('employer.candidateShow.notSpecified'),
        },
        {
            icon: Plane,
            label: t('employer.candidateShow.overview.relocation'),
            value: candidate.relocation_ready
                ? t('employer.common.yes')
                : t('employer.candidateShow.notSpecified'),
        },
        {
            icon: Languages,
            label: t('employer.candidateShow.overview.languages'),
            value: languages || t('employer.candidateShow.notSpecified'),
        },
        {
            icon: CalendarPlus,
            label: t('employer.candidateShow.overview.availableFrom'),
            value: formatDate(candidate.available_from),
        },
    ];
});

const sendInvite = () => {
    if (!props.candidate || !selectedJob.value) {
        return;
    }

    inviteForm.job_posting_id = selectedJob.value;
    inviteForm.post(invite.url(props.candidate.id), { preserveScroll: true });
};

const saveCandidate = () => {
    if (!props.candidate) {
        return;
    }

    listForm.talent_list_id = selectedList.value;
    listForm.post(talentList.url(props.candidate.id), { preserveScroll: true });
};
</script>

<template>
    <Head
        :title="
            candidate
                ? t('employer.candidateShow.metaTitleWithCandidate', {
                      candidate: candidate.label ?? candidate.id,
                  })
                : t('employer.candidateShow.metaTitle')
        "
    />
    <div class="erin-page">
        <Link
            :href="candidatesIndex()"
            class="inline-flex w-fit items-center gap-2 text-xs font-bold text-slate-500 hover:text-[var(--erin-primary)]"
        >
            <ArrowLeft class="size-4" />
            {{ t('employer.candidateShow.backToCandidates') }}
        </Link>

        <div
            v-if="!candidate"
            class="erin-panel grid min-h-80 place-items-center p-8 text-center"
        >
            <div>
                <LockKeyhole class="mx-auto size-8 text-slate-300" />
                <h1 class="mt-4 font-bold text-slate-900">
                    {{ t('employer.candidateShow.unavailableTitle') }}
                </h1>
                <p class="mt-2 text-sm text-slate-500">
                    {{ t('employer.candidateShow.unavailableDescription') }}
                </p>
            </div>
        </div>

        <template v-else>
            <section class="erin-panel relative overflow-hidden p-6">
                <div
                    class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-[var(--erin-primary)] via-[var(--erin-secondary)] to-[var(--erin-accent)]"
                />
                <div class="flex flex-col gap-6 lg:flex-row lg:items-center">
                    <div
                        class="grid size-20 shrink-0 place-items-center rounded-2xl bg-blue-50 text-xl font-extrabold text-[var(--erin-primary)]"
                    >
                        {{
                            (candidate.label ?? String(candidate.id)).slice(-2)
                        }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-2xl font-extrabold">
                                {{ position }}
                            </h1>
                            <BadgeCheck
                                v-if="
                                    candidate.languages?.some(
                                        (language) => language.verified,
                                    )
                                "
                                class="size-5 text-[var(--erin-secondary)]"
                            />
                            <StatusBadge
                                :label="
                                    candidate.identity_revealed
                                        ? t(
                                              'employer.candidateShow.identityRevealed',
                                          )
                                        : t('employer.candidateShow.anonymized')
                                "
                                :tone="
                                    candidate.identity_revealed
                                        ? 'green'
                                        : 'slate'
                                "
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{ identityName }}
                            <span
                                v-if="candidate.current_country_code"
                                class="ml-2 inline-flex items-center gap-1"
                                ><MapPin class="size-3.5" />
                                {{ candidate.current_country_code }}</span
                            >
                        </p>
                        <p
                            class="mt-3 max-w-3xl text-sm leading-6 text-slate-600"
                        >
                            {{
                                candidate.summary ||
                                t('employer.candidateShow.noSummary')
                            }}
                        </p>
                    </div>
                    <MatchScore :score="score" size="lg" />
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
                <div class="space-y-6">
                    <SectionCard
                        :title="t('employer.candidateShow.overviewTitle')"
                    >
                        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="item in overviewItems"
                                :key="item.label"
                                class="flex gap-3"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-xl bg-slate-100 text-[var(--erin-primary)]"
                                    ><component :is="item.icon" class="size-4"
                                /></span>
                                <div>
                                    <p
                                        class="text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                                    >
                                        {{ item.label }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm font-semibold text-slate-800"
                                    >
                                        {{ item.value }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </SectionCard>

                    <SectionCard
                        :title="t('employer.candidateShow.skillsTitle')"
                    >
                        <div
                            v-if="candidate.skills?.length"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="skill in candidate.skills"
                                :key="skill.id"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-teal-50 px-3 py-2 text-xs font-bold text-teal-700"
                            >
                                <Check class="size-3.5" />
                                {{
                                    localizedField(
                                        skill,
                                        'name',
                                        skill.slug ?? '',
                                    )
                                }}
                            </span>
                        </div>
                        <p v-else class="text-sm text-slate-400">
                            {{ t('employer.candidateShow.noSkills') }}
                        </p>
                    </SectionCard>

                    <SectionCard
                        :title="t('employer.candidateShow.experienceTitle')"
                    >
                        <div
                            v-if="candidate.experiences?.length"
                            class="relative space-y-6 pl-7 before:absolute before:top-2 before:bottom-2 before:left-2 before:w-px before:bg-slate-200"
                        >
                            <div
                                v-for="(
                                    experience, index
                                ) in candidate.experiences"
                                :key="`${experience.position}-${index}`"
                                class="relative"
                            >
                                <span
                                    class="absolute top-1 -left-[1.62rem] size-3 rounded-full border-2 border-white bg-[var(--erin-primary)] ring-1 ring-blue-200"
                                />
                                <p
                                    class="text-[10px] font-bold text-[var(--erin-primary)]"
                                >
                                    {{ formatDate(experience.started_at) }} –
                                    {{
                                        experience.is_current
                                            ? t('employer.candidateShow.today')
                                            : formatDate(
                                                  experience.ended_at,
                                                  undefined,
                                                  t(
                                                      'employer.candidateShow.open',
                                                  ),
                                              )
                                    }}
                                </p>
                                <h3 class="mt-1 text-sm font-bold">
                                    {{ experience.position }}
                                </h3>
                                <p class="text-xs text-slate-500">
                                    {{ experience.employer
                                    }}<span v-if="experience.country_code">
                                        · {{ experience.country_code }}</span
                                    >
                                </p>
                                <p
                                    v-if="experience.description"
                                    class="mt-2 text-sm leading-6 text-slate-600"
                                >
                                    {{ experience.description }}
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-400">
                            {{ t('employer.candidateShow.noExperience') }}
                        </p>
                    </SectionCard>

                    <SectionCard
                        :title="t('employer.candidateShow.educationTitle')"
                    >
                        <div
                            v-if="candidate.educations?.length"
                            class="space-y-3"
                        >
                            <div
                                v-for="(
                                    education, index
                                ) in candidate.educations"
                                :key="`${education.qualification}-${index}`"
                                class="rounded-xl border border-slate-200 p-4"
                            >
                                <p class="text-sm font-bold">
                                    {{ education.qualification }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{
                                        education.field ||
                                        t(
                                            'employer.candidateShow.educationFieldMissing',
                                        )
                                    }}<span v-if="education.country_code">
                                        · {{ education.country_code }}</span
                                    >
                                </p>
                            </div>
                        </div>
                        <p v-else class="text-sm text-slate-400">
                            {{ t('employer.candidateShow.noEducation') }}
                        </p>
                    </SectionCard>
                </div>

                <aside class="space-y-6">
                    <SectionCard
                        :title="t('employer.candidateShow.matchTitle')"
                    >
                        <template v-if="candidate.match">
                            <div
                                class="mb-5 flex items-center justify-between rounded-xl bg-gradient-to-r from-teal-50 to-blue-50 p-4"
                            >
                                <div>
                                    <p class="text-xs text-slate-500">
                                        {{
                                            t(
                                                'employer.candidateShow.overallScore',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xl font-extrabold text-teal-700"
                                    >
                                        {{ score }} %
                                    </p>
                                </div>
                                <MatchScore :score="score" size="sm" />
                            </div>
                            <div v-if="factors.length" class="space-y-4">
                                <ProgressBar
                                    v-for="factor in factors"
                                    :key="factor.label"
                                    :label="factor.label"
                                    :value="factor.value"
                                    tone="teal"
                                />
                            </div>
                        </template>
                        <p v-else class="text-sm text-slate-400">
                            {{ t('employer.candidateShow.noMatch') }}
                        </p>
                    </SectionCard>

                    <SectionCard
                        v-if="canManageCandidates"
                        :title="t('employer.candidateShow.inviteTitle')"
                    >
                        <form class="space-y-3" @submit.prevent="sendInvite">
                            <select
                                v-model="selectedJob"
                                class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"
                                required
                            >
                                <option :value="null" disabled>
                                    {{ t('employer.candidateShow.chooseJob') }}
                                </option>
                                <option
                                    v-for="job in jobs"
                                    :key="job.id"
                                    :value="job.id"
                                >
                                    {{ job.title }}
                                </option>
                            </select>
                            <textarea
                                v-model="inviteForm.message"
                                rows="3"
                                class="w-full rounded-xl border border-slate-200 p-3 text-xs"
                                :placeholder="
                                    t(
                                        'employer.candidateShow.messagePlaceholder',
                                    )
                                "
                            />
                            <button
                                :disabled="
                                    inviteForm.processing || !selectedJob
                                "
                                type="submit"
                                class="h-10 w-full rounded-xl bg-[var(--erin-primary)] text-sm font-bold text-white disabled:opacity-50"
                            >
                                {{ t('employer.candidateShow.sendInvitation') }}
                            </button>
                            <p
                                v-if="inviteForm.errors.job_posting_id"
                                class="text-xs text-red-600"
                            >
                                {{ inviteForm.errors.job_posting_id }}
                            </p>
                        </form>
                    </SectionCard>

                    <SectionCard
                        v-if="canManageCandidates"
                        :title="t('employer.candidateShow.talentPoolTitle')"
                    >
                        <form class="space-y-3" @submit.prevent="saveCandidate">
                            <select
                                v-if="talent_lists.length"
                                v-model="selectedList"
                                class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"
                            >
                                <option
                                    v-for="list in talent_lists"
                                    :key="list.id"
                                    :value="list.id"
                                >
                                    {{ list.name }} ({{
                                        list.members_count ?? 0
                                    }})
                                </option>
                            </select>
                            <input
                                v-else
                                v-model="listForm.list_name"
                                required
                                class="h-10 w-full rounded-xl border border-slate-200 px-3 text-xs"
                                :placeholder="
                                    t(
                                        'employer.candidateShow.newListPlaceholder',
                                    )
                                "
                            />
                            <button
                                :disabled="listForm.processing"
                                type="submit"
                                class="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 text-xs font-bold text-slate-700"
                            >
                                <Heart
                                    class="size-4 text-[var(--erin-accent)]"
                                />
                                {{ t('employer.candidateShow.save') }}
                            </button>
                        </form>
                    </SectionCard>

                    <SectionCard
                        v-if="candidate.identity_revealed"
                        :title="t('employer.candidateShow.contactTitle')"
                    >
                        <dl class="space-y-2 text-xs">
                            <div
                                v-if="candidate.identity?.email"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-slate-400">
                                    {{ t('employer.candidateShow.email') }}
                                </dt>
                                <dd class="font-bold">
                                    {{ candidate.identity.email }}
                                </dd>
                            </div>
                            <div
                                v-if="candidate.identity?.phone"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-slate-400">
                                    {{ t('employer.candidateShow.phone') }}
                                </dt>
                                <dd class="font-bold">
                                    {{ candidate.identity.phone }}
                                </dd>
                            </div>
                            <div
                                v-if="candidate.identity?.whatsapp"
                                class="flex justify-between gap-3"
                            >
                                <dt class="text-slate-400">
                                    {{ t('employer.candidateShow.whatsapp') }}
                                </dt>
                                <dd class="font-bold">
                                    {{ candidate.identity.whatsapp }}
                                </dd>
                            </div>
                        </dl>
                    </SectionCard>
                </aside>
            </div>
        </template>
    </div>
</template>
