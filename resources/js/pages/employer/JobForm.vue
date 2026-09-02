<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save, Sparkles, WandSparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import JobScreeningQuestions from '@/components/employer/JobScreeningQuestions.vue';
import type { ScreeningQuestionDraft } from '@/components/employer/JobScreeningQuestions.vue';
import { Button } from '@/components/ui/button';
import { useLocalizedField } from '@/composables/useLocalizedField';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { run as runAi } from '@/routes/ai';
import { index as jobsIndex, store, update } from '@/routes/employer/jobs';

type Option = {
    id: number;
    name?: string;
    name_de?: string;
    name_en?: string;
    city?: string;
};
type Job = {
    id: number;
    title?: string;
    status?: string;
    position?: string;
    description?: string;
    summary?: string | null;
    responsibilities?: string | null;
    requirements?: string | null;
    benefits?: string | null;
    occupation_id?: number | null;
    location_id?: number | null;
    expected_experience_years?: number | null;
    language_notes?: string | null;
    application_deadline?: string | null;
    start_date?: string | null;
    vacancies?: number;
    contact_name?: string | null;
    contact_email?: string | null;
    hours_min?: number | null;
    hours_max?: number | null;
    employment_type?: string;
    compensation_min_cents?: number | null;
    compensation_max_cents?: number | null;
    currency?: string;
    compensation_interval?: string;
    salary_visible?: boolean;
    is_remote?: boolean;
    visa_package_available?: boolean;
    skills?: Array<{
        id: number;
        pivot?: {
            importance?: number;
            minimum_experience_years?: number | null;
        };
    }>;
    languages?: Array<{
        id: number;
        pivot?: { minimum_level?: string; is_required?: boolean };
    }>;
    screening_questions?: Array<{
        question?: string;
        type?: string;
        is_required?: boolean;
        options?: string[] | null;
    }>;
    media?: Array<{
        id: number;
        original_name: string;
        size_bytes?: number | null;
        scan_result?: string | null;
        download_url?: string | null;
    }>;
};

const props = withDefaults(
    defineProps<{
        job?: Job | null;
        occupations?: Option[];
        skills?: Option[];
        languages?: Option[];
        locations?: Option[];
    }>(),
    {
        job: null,
        occupations: () => [],
        skills: () => [],
        languages: () => [],
        locations: () => [],
    },
);
const { t } = useI18n();
const { localizedField } = useLocalizedField();
const { statusLabel } = useStatusLabels();
const mode = computed(() => (props.job ? 'edit' : 'create'));
const pageTitle = computed(() =>
    mode.value === 'edit'
        ? t('employer.jobForm.editMetaTitle')
        : t('employer.jobForm.createMetaTitle'),
);
const headerTitle = computed(() =>
    mode.value === 'edit'
        ? t('employer.jobForm.editTitle')
        : t('employer.jobForm.createTitle'),
);
const screeningQuestions = ref<ScreeningQuestionDraft[]>(
    props.job?.screening_questions?.map((question) => ({
        question: question.question ?? '',
        type: question.type ?? 'text',
        is_required: question.is_required ?? false,
        options: question.options ?? [],
    })) ?? [],
);
const form = useForm({
    title: props.job?.title ?? '',
    position: props.job?.position ?? '',
    summary: props.job?.summary ?? '',
    description: props.job?.description ?? '',
    responsibilities: props.job?.responsibilities ?? '',
    requirements: props.job?.requirements ?? '',
    benefits: props.job?.benefits ?? '',
    occupation_id: props.job?.occupation_id ?? (null as number | null),
    location_id: props.job?.location_id ?? (null as number | null),
    expected_experience_years:
        props.job?.expected_experience_years ?? (null as number | null),
    language_notes: props.job?.language_notes ?? '',
    application_deadline: props.job?.application_deadline?.slice(0, 10) ?? '',
    start_date: props.job?.start_date?.slice(0, 10) ?? '',
    vacancies: props.job?.vacancies ?? 1,
    contact_name: props.job?.contact_name ?? '',
    contact_email: props.job?.contact_email ?? '',
    hours_min: props.job?.hours_min ?? (null as number | null),
    hours_max: props.job?.hours_max ?? (null as number | null),
    employment_type: props.job?.employment_type ?? 'full_time',
    compensation_min_cents:
        props.job?.compensation_min_cents ?? (null as number | null),
    compensation_max_cents:
        props.job?.compensation_max_cents ?? (null as number | null),
    currency: props.job?.currency ?? 'EUR',
    compensation_interval: props.job?.compensation_interval ?? 'year',
    salary_visible: props.job?.salary_visible ?? true,
    is_remote: props.job?.is_remote ?? false,
    visa_package_available: props.job?.visa_package_available ?? false,
    skills:
        props.job?.skills?.map((skill) => ({
            id: skill.id,
            importance: skill.pivot?.importance ?? 1,
            minimum_experience_years:
                skill.pivot?.minimum_experience_years ?? null,
        })) ?? [],
    languages:
        props.job?.languages?.map((language) => ({
            id: language.id,
            minimum_level: language.pivot?.minimum_level ?? 'B1',
            is_required: language.pivot?.is_required ?? true,
        })) ?? [],
    screening_questions: screeningQuestions.value,
    media: [] as File[],
});
const submit = () => {
    form.screening_questions = screeningQuestions.value;

    if (props.job) {
        form.put(update.url(props.job.id), {
            forceFormData: true,
            preserveScroll: true,
        });

        return;
    }

    form.post(store.url(), { forceFormData: true });
};
const aiRunning = ref<'job_create' | 'job_improve' | null>(null);
const aiError = ref('');
const aiSuggestions = ref<string[]>([]);
const useAi = async (task: 'job_create' | 'job_improve') => {
    aiRunning.value = task;
    aiError.value = '';
    aiSuggestions.value = [];

    try {
        const csrfToken =
            document
                .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        const response = await fetch(runAi.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                task,
                input: {
                    title: form.title,
                    position: form.position,
                    description: form.description,
                    experience_years: form.expected_experience_years,
                    language_requirements: form.language_notes,
                    employment_type: form.employment_type,
                    weekly_hours: [form.hours_min, form.hours_max],
                },
            }),
        });
        const payload = (await response.json()) as {
            result?: {
                title: string;
                content: string;
                suggestions: string[];
                caveats: string[];
            };
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!response.ok || !payload.result) {
            throw new Error(
                payload.message ??
                    Object.values(payload.errors ?? {})[0]?.[0] ??
                    t('employer.jobForm.aiError'),
            );
        }

        if (task === 'job_create' || !form.title.trim()) {
            form.title = payload.result.title;
        }

        form.description = payload.result.content;
        aiSuggestions.value = [
            ...payload.result.suggestions,
            ...payload.result.caveats,
        ];
    } catch (exception) {
        aiError.value =
            exception instanceof Error
                ? exception.message
                : t('employer.jobForm.aiError');
    } finally {
        aiRunning.value = null;
    }
};
const fieldClass =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm text-slate-900 placeholder:text-slate-400';
</script>

<template>
    <Head :title="pageTitle" />
    <div class="erin-page">
        <Link
            :href="jobsIndex()"
            class="inline-flex w-fit items-center gap-2 text-xs font-bold text-slate-500 hover:text-[var(--erin-primary)]"
            ><ArrowLeft class="size-4" />
            {{ t('employer.jobForm.backToJobs') }}</Link
        >
        <PageHeader
            :eyebrow="t('employer.jobForm.eyebrow')"
            :title="headerTitle"
            :description="t('employer.jobForm.description')"
        >
            <template #actions
                ><button
                    type="button"
                    :disabled="Boolean(aiRunning)"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 text-sm font-bold text-violet-700 hover:bg-violet-100 disabled:opacity-50"
                    @click="useAi('job_create')"
                >
                    <WandSparkles class="size-4" />
                    {{
                        aiRunning === 'job_create'
                            ? t('employer.jobForm.aiWorking')
                            : t('employer.jobForm.createWithAi')
                    }}
                </button></template
            >
        </PageHeader>
        <form
            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_21rem]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <SectionCard
                    :title="t('employer.jobForm.basicsTitle')"
                    :description="t('employer.jobForm.basicsDescription')"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="sm:col-span-2"
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.title')
                                }}
                                *</span
                            ><input
                                v-model="form.title"
                                required
                                :class="fieldClass"
                                :placeholder="
                                    t('employer.jobForm.placeholders.title')
                                "
                            /><span
                                v-if="form.errors.title"
                                class="mt-1 block text-xs text-red-600"
                                >{{ form.errors.title }}</span
                            ></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.position')
                                }}
                                *</span
                            ><input
                                v-model="form.position"
                                required
                                :class="fieldClass"
                                :placeholder="
                                    t('employer.jobForm.placeholders.position')
                                "
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.occupation')
                                }}
                                *</span
                            ><select
                                v-model="form.occupation_id"
                                :class="fieldClass"
                            >
                                <option :value="null">
                                    {{ t('employer.jobForm.selectOption') }}
                                </option>
                                <option
                                    v-for="occupation in occupations"
                                    :key="occupation.id"
                                    :value="occupation.id"
                                >
                                    {{
                                        localizedField(
                                            occupation,
                                            'name',
                                            occupation.name ?? '',
                                        )
                                    }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t(
                                        'employer.jobForm.fields.expectedExperience',
                                    )
                                }}
                                *</span
                            ><input
                                v-model.number="form.expected_experience_years"
                                :class="fieldClass"
                                type="number"
                                min="0"
                                max="60"
                        /></label>
                        <label
                            class="flex items-center gap-2 pt-7 text-sm font-semibold text-slate-700"
                        >
                            <input v-model="form.is_remote" type="checkbox" />
                            {{ t('employer.jobForm.fields.remote') }}
                        </label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.languageRequirement')
                            }}</span
                            ><input
                                v-model="form.language_notes"
                                :class="fieldClass"
                                :placeholder="
                                    t('employer.jobForm.placeholders.language')
                                "
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.hoursFrom')
                                }}
                                *</span
                            ><input
                                v-model.number="form.hours_min"
                                :class="fieldClass"
                                type="number"
                                min="1"
                                max="80"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.hoursTo')
                                }}
                                *</span
                            ><input
                                v-model.number="form.hours_max"
                                :class="fieldClass"
                                type="number"
                                min="1"
                                max="80"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.employmentType')
                            }}</span
                            ><select
                                v-model="form.employment_type"
                                :class="fieldClass"
                            >
                                <option value="full_time">
                                    {{
                                        t(
                                            'employer.common.employmentTypes.full_time',
                                        )
                                    }}
                                </option>
                                <option value="part_time">
                                    {{
                                        t(
                                            'employer.common.employmentTypes.part_time',
                                        )
                                    }}
                                </option>
                                <option value="temporary">
                                    {{
                                        t(
                                            'employer.common.employmentTypes.temporary',
                                        )
                                    }}
                                </option>
                                <option value="permanent">
                                    {{
                                        t(
                                            'employer.common.employmentTypes.permanent',
                                        )
                                    }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{ t('employer.jobForm.fields.location') }}
                                <template v-if="!form.is_remote"
                                    >*</template
                                ></span
                            ><select
                                v-model="form.location_id"
                                :class="fieldClass"
                            >
                                <option :value="null">
                                    {{ t('employer.jobForm.noLocation') }}
                                </option>
                                <option
                                    v-for="location in locations"
                                    :key="location.id"
                                    :value="location.id"
                                >
                                    {{ location.name
                                    }}<template v-if="location.city">
                                        · {{ location.city }}</template
                                    >
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t(
                                        'employer.jobForm.fields.compensationFrom',
                                    )
                                }}
                                *</span
                            ><input
                                v-model.number="form.compensation_min_cents"
                                :class="fieldClass"
                                type="number"
                                min="0"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t(
                                        'employer.jobForm.fields.compensationInterval',
                                    )
                                }}
                                *</span
                            ><select
                                v-model="form.compensation_interval"
                                :class="fieldClass"
                            >
                                <option value="hour">
                                    {{ t('employer.jobForm.intervals.hour') }}
                                </option>
                                <option value="month">
                                    {{ t('employer.jobForm.intervals.month') }}
                                </option>
                                <option value="year">
                                    {{ t('employer.jobForm.intervals.year') }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.fields.vacancies')
                                }}
                                *</span
                            ><input
                                v-model.number="form.vacancies"
                                :class="fieldClass"
                                type="number"
                                min="1"
                                max="500"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.applicationDeadline')
                            }}</span
                            ><input
                                v-model="form.application_deadline"
                                :class="fieldClass"
                                type="date"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.startDate')
                            }}</span
                            ><input
                                v-model="form.start_date"
                                :class="fieldClass"
                                type="date"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.contactName')
                            }}</span
                            ><input
                                v-model="form.contact_name"
                                :class="fieldClass"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.contactEmail')
                            }}</span
                            ><input
                                v-model="form.contact_email"
                                :class="fieldClass"
                                type="email"
                        /></label>
                        <label
                            class="flex items-center gap-2 text-sm font-semibold text-slate-700 sm:col-span-2"
                            ><input
                                v-model="form.salary_visible"
                                type="checkbox"
                            />{{
                                t('employer.jobForm.fields.salaryVisible')
                            }}</label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('employer.jobForm.fields.compensationTo')
                            }}</span
                            ><input
                                v-model.number="form.compensation_max_cents"
                                :class="fieldClass"
                                type="number"
                                min="0"
                        /></label>
                    </div>
                </SectionCard>
                <SectionCard
                    :title="t('employer.jobForm.jobDescriptionTitle')"
                    :description="
                        t('employer.jobForm.jobDescriptionDescription')
                    "
                >
                    <label class="mb-5 block text-sm font-bold text-slate-700">
                        {{ t('employer.jobForm.fields.summary') }} *
                        <Textarea
                            v-model="form.summary"
                            required
                            rows="3"
                            class="mt-1.5"
                            :placeholder="
                                t('employer.jobForm.placeholders.summary')
                            "
                        />
                    </label>
                    <div class="rounded-xl border border-slate-200">
                        <div
                            class="flex justify-end border-b border-slate-200 bg-slate-50 p-2"
                        >
                            <button
                                type="button"
                                :disabled="
                                    Boolean(aiRunning) ||
                                    !form.description.trim()
                                "
                                class="inline-flex items-center gap-1 rounded-md bg-violet-50 px-2 py-1 text-xs font-bold text-violet-700 disabled:opacity-50"
                                @click="useAi('job_improve')"
                            >
                                <Sparkles class="size-3" />
                                {{
                                    aiRunning === 'job_improve'
                                        ? t('employer.jobForm.aiWorking')
                                        : t('employer.jobForm.improveWithAi')
                                }}
                            </button>
                        </div>
                        <Textarea
                            v-model="form.description"
                            required
                            rows="10"
                            class="resize-y rounded-none border-0 p-4 outline-none"
                            :placeholder="
                                t('employer.jobForm.placeholders.description')
                            "
                        />
                    </div>
                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <label class="text-sm font-bold text-slate-700"
                            >{{
                                t('employer.jobForm.fields.responsibilities')
                            }}
                            *<Textarea
                                v-model="form.responsibilities"
                                required
                                rows="7"
                                class="mt-1.5"
                        /></label>
                        <label class="text-sm font-bold text-slate-700"
                            >{{
                                t('employer.jobForm.fields.requirements')
                            }}
                            *<Textarea
                                v-model="form.requirements"
                                required
                                rows="7"
                                class="mt-1.5"
                        /></label>
                        <label
                            class="text-sm font-bold text-slate-700 lg:col-span-2"
                            >{{ t('employer.jobForm.fields.benefits')
                            }}<Textarea
                                v-model="form.benefits"
                                rows="5"
                                class="mt-1.5"
                        /></label>
                    </div>
                    <p
                        v-if="aiError"
                        class="mt-3 text-xs font-bold text-red-600"
                    >
                        {{ aiError }}
                    </p>
                    <div
                        v-if="aiSuggestions.length"
                        class="mt-3 rounded-xl border border-violet-100 bg-violet-50 p-4"
                    >
                        <p class="text-xs font-bold text-violet-900">
                            {{ t('employer.jobForm.aiReviewHints') }}
                        </p>
                        <ul
                            class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5 text-violet-800"
                        >
                            <li
                                v-for="suggestion in aiSuggestions"
                                :key="suggestion"
                            >
                                {{ suggestion }}
                            </li>
                        </ul>
                    </div>
                </SectionCard>
                <SectionCard
                    :title="t('employer.jobForm.qualificationsTitle')"
                    :description="
                        t('employer.jobForm.qualificationsDescription')
                    "
                >
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div>
                            <p class="mb-3 text-sm font-bold text-slate-700">
                                {{ t('employer.jobForm.skills') }} *
                            </p>
                            <div class="space-y-2">
                                <label
                                    v-for="skill in skills"
                                    :key="skill.id"
                                    class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="
                                            form.skills.some(
                                                (item) => item.id === skill.id,
                                            )
                                        "
                                        @change="
                                            form.skills = form.skills.some(
                                                (item) => item.id === skill.id,
                                            )
                                                ? form.skills.filter(
                                                      (item) =>
                                                          item.id !== skill.id,
                                                  )
                                                : [
                                                      ...form.skills,
                                                      {
                                                          id: skill.id,
                                                          importance: 3,
                                                          minimum_experience_years:
                                                              null,
                                                      },
                                                  ]
                                        "
                                    />
                                    <span class="flex-1 font-semibold">{{
                                        localizedField(
                                            skill,
                                            'name',
                                            skill.name ?? '',
                                        )
                                    }}</span>
                                    <select
                                        v-if="
                                            form.skills.some(
                                                (item) => item.id === skill.id,
                                            )
                                        "
                                        v-model.number="
                                            form.skills.find(
                                                (item) => item.id === skill.id,
                                            )!.importance
                                        "
                                        class="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                                    >
                                        <option
                                            v-for="importance in 5"
                                            :key="importance"
                                            :value="importance"
                                        >
                                            {{
                                                t(
                                                    'employer.jobForm.importance',
                                                    { value: importance },
                                                )
                                            }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                        </div>
                        <div>
                            <p class="mb-3 text-sm font-bold text-slate-700">
                                {{ t('employer.jobForm.languages') }} *
                            </p>
                            <div class="space-y-2">
                                <label
                                    v-for="language in languages"
                                    :key="language.id"
                                    class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="
                                            form.languages.some(
                                                (item) =>
                                                    item.id === language.id,
                                            )
                                        "
                                        @change="
                                            form.languages =
                                                form.languages.some(
                                                    (item) =>
                                                        item.id === language.id,
                                                )
                                                    ? form.languages.filter(
                                                          (item) =>
                                                              item.id !==
                                                              language.id,
                                                      )
                                                    : [
                                                          ...form.languages,
                                                          {
                                                              id: language.id,
                                                              minimum_level:
                                                                  'B1',
                                                              is_required: true,
                                                          },
                                                      ]
                                        "
                                    />
                                    <span class="flex-1 font-semibold">{{
                                        localizedField(
                                            language,
                                            'name',
                                            language.name ?? '',
                                        )
                                    }}</span>
                                    <select
                                        v-if="
                                            form.languages.some(
                                                (item) =>
                                                    item.id === language.id,
                                            )
                                        "
                                        v-model="
                                            form.languages.find(
                                                (item) =>
                                                    item.id === language.id,
                                            )!.minimum_level
                                        "
                                        class="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                                    >
                                        <option
                                            v-for="level in [
                                                'A1',
                                                'A2',
                                                'B1',
                                                'B2',
                                                'C1',
                                                'C2',
                                            ]"
                                            :key="level"
                                        >
                                            {{ level }}
                                        </option>
                                    </select>
                                </label>
                            </div>
                        </div>
                    </div>
                </SectionCard>
                <SectionCard
                    :title="t('employer.jobForm.screeningTitle')"
                    :description="
                        t('employer.jobForm.screeningDescription', {
                            count: screeningQuestions.length,
                            max: 5,
                        })
                    "
                >
                    <JobScreeningQuestions v-model="screeningQuestions" />
                </SectionCard>
                <SectionCard :title="t('employer.jobForm.mediaTitle')">
                    <div v-if="job?.media?.length" class="mb-4 space-y-2">
                        <div
                            v-for="medium in job.media"
                            :key="medium.id"
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm"
                        >
                            <span
                                class="min-w-0 flex-1 truncate font-semibold"
                                >{{ medium.original_name }}</span
                            >
                            <StatusBadge
                                :label="medium.scan_result ?? 'pending'"
                                :tone="
                                    medium.scan_result === 'clean'
                                        ? 'green'
                                        : 'yellow'
                                "
                            />
                            <a
                                v-if="medium.download_url"
                                :href="medium.download_url"
                                class="text-xs font-bold text-blue-600"
                                >{{ t('employer.jobForm.download') }}</a
                            >
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                class="text-red-600"
                                @click="
                                    router.delete(
                                        `/employer/jobs/${job!.id}/media/${medium.id}`,
                                        { preserveScroll: true },
                                    )
                                "
                                >{{ t('employer.jobForm.remove') }}</Button
                            >
                        </div>
                    </div>
                    <FileAttachmentPicker
                        id="job-media"
                        v-model="form.media"
                        :max-files="10"
                        accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx"
                        :label="t('employer.jobForm.chooseFiles')"
                        :remove-label="t('employer.jobForm.removeFile')"
                    />
                    <p class="mt-2 text-xs text-slate-400">
                        {{ t('employer.jobForm.fileRequirements') }}
                    </p>
                </SectionCard>
            </div>
            <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
                <SectionCard :title="t('employer.jobForm.publishingTitle')">
                    <div class="space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-500">{{
                                t('employer.jobForm.status')
                            }}</span
                            ><span class="font-bold text-slate-700">{{
                                statusLabel('job', job?.status ?? 'draft')
                            }}</span>
                        </div>
                    </div>
                    <div class="mt-5 grid gap-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] text-sm font-bold text-white hover:bg-[var(--erin-primary-hover)] disabled:opacity-50"
                        >
                            <Save class="size-4" />
                            {{
                                mode === 'edit'
                                    ? t('employer.jobForm.saveChanges')
                                    : t('employer.jobForm.saveDraft')
                            }}
                        </button>
                        <Link
                            :href="jobsIndex()"
                            class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 text-xs font-bold text-slate-600"
                            >{{ t('employer.jobForm.cancel') }}</Link
                        >
                    </div>
                </SectionCard>
                <SectionCard :title="t('employer.jobForm.visaPackageTitle')">
                    <label class="flex cursor-pointer items-start gap-3"
                        ><input
                            v-model="form.visa_package_available"
                            type="checkbox"
                            class="mt-1 size-4 rounded border-slate-300 text-[var(--erin-primary)]"
                        /><span
                            ><span
                                class="block text-sm font-bold text-slate-700"
                                >{{
                                    t('employer.jobForm.offerVisaSupport')
                                }}</span
                            ><span
                                class="mt-1 block text-xs leading-5 text-slate-500"
                                >{{
                                    t('employer.jobForm.visaSupportDescription')
                                }}</span
                            ></span
                        ></label
                    >
                </SectionCard>
            </aside>
        </form>
    </div>
</template>
