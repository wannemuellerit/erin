<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    FilePlus2,
    ImagePlus,
    Save,
    Sparkles,
    WandSparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
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
    occupation_id?: number | null;
    location_id?: number | null;
    expected_experience_years?: number | null;
    language_notes?: string | null;
    hours_min?: number | null;
    hours_max?: number | null;
    employment_type?: string;
    compensation_min_cents?: number | null;
    compensation_max_cents?: number | null;
    currency?: string;
    compensation_interval?: string;
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
const { statusLabel } = useStatusLabels();
const mode = computed(() => (props.job ? 'edit' : 'create'));
const screeningQuestions = ref(
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
    description: props.job?.description ?? '',
    occupation_id: props.job?.occupation_id ?? (null as number | null),
    location_id: props.job?.location_id ?? (null as number | null),
    expected_experience_years:
        props.job?.expected_experience_years ?? (null as number | null),
    language_notes: props.job?.language_notes ?? '',
    hours_min: props.job?.hours_min ?? (null as number | null),
    hours_max: props.job?.hours_max ?? (null as number | null),
    employment_type: props.job?.employment_type ?? 'full_time',
    compensation_min_cents:
        props.job?.compensation_min_cents ?? (null as number | null),
    compensation_max_cents:
        props.job?.compensation_max_cents ?? (null as number | null),
    currency: props.job?.currency ?? 'EUR',
    compensation_interval: props.job?.compensation_interval ?? 'year',
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
const addQuestion = () => {
    if (screeningQuestions.value.length < 5) {
        screeningQuestions.value.push({
            question: '',
            type: 'text',
            is_required: false,
            options: [],
        });
    }
};
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
const setMedia = (event: Event) => {
    form.media = Array.from((event.target as HTMLInputElement).files ?? []);
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
                    'Die KI-Anfrage konnte nicht abgeschlossen werden.',
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
                : 'Die KI-Anfrage konnte nicht abgeschlossen werden.';
    } finally {
        aiRunning.value = null;
    }
};
const fieldClass =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 bg-white px-3.5 text-sm text-slate-900 placeholder:text-slate-400';
</script>

<template>
    <Head
        :title="
            mode === 'edit'
                ? 'Stellenanzeige bearbeiten'
                : 'Stellenanzeige erstellen'
        "
    />
    <div class="erin-page">
        <Link
            :href="jobsIndex()"
            class="inline-flex w-fit items-center gap-2 text-xs font-bold text-slate-500 hover:text-[var(--erin-primary)]"
            ><ArrowLeft class="size-4" /> Zurück zu Stellenanzeigen</Link
        >
        <PageHeader
            eyebrow="Stellenanzeige"
            :title="
                mode === 'edit'
                    ? 'Stellenanzeige bearbeiten'
                    : 'Neue Stellenanzeige erstellen'
            "
            description="Beschreiben Sie die Position möglichst konkret – Erin übernimmt den Rest."
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
                            ? 'KI arbeitet …'
                            : 'Mit KI erstellen'
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
                    title="Grundinformationen"
                    description="Die wichtigsten Eckdaten zur offenen Position"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="sm:col-span-2"
                            ><span class="text-sm font-bold text-slate-700"
                                >Titel der Stellenanzeige *</span
                            ><input
                                v-model="form.title"
                                required
                                :class="fieldClass"
                                placeholder="z. B. Elektroniker Betriebstechnik"
                            /><span
                                v-if="form.errors.title"
                                class="mt-1 block text-xs text-red-600"
                                >{{ form.errors.title }}</span
                            ></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Position *</span
                            ><input
                                v-model="form.position"
                                required
                                :class="fieldClass"
                                placeholder="z. B. Elektroniker"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Berufsfeld</span
                            ><select
                                v-model="form.occupation_id"
                                :class="fieldClass"
                            >
                                <option :value="null">Bitte wählen</option>
                                <option
                                    v-for="occupation in occupations"
                                    :key="occupation.id"
                                    :value="occupation.id"
                                >
                                    {{
                                        occupation.name_de ?? occupation.name_en
                                    }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Erwartete Erfahrung (Jahre)</span
                            ><input
                                v-model.number="form.expected_experience_years"
                                :class="fieldClass"
                                type="number"
                                min="0"
                                max="60"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Sprachanforderung</span
                            ><input
                                v-model="form.language_notes"
                                :class="fieldClass"
                                placeholder="z. B. Deutsch B1"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Stunden von</span
                            ><input
                                v-model.number="form.hours_min"
                                :class="fieldClass"
                                type="number"
                                min="1"
                                max="80"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Stunden bis</span
                            ><input
                                v-model.number="form.hours_max"
                                :class="fieldClass"
                                type="number"
                                min="1"
                                max="80"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Anstellungsart</span
                            ><select
                                v-model="form.employment_type"
                                :class="fieldClass"
                            >
                                <option value="full_time">Vollzeit</option>
                                <option value="part_time">Teilzeit</option>
                                <option value="temporary">Befristet</option>
                                <option value="permanent">Unbefristet</option>
                            </select></label
                        >
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Standort</span
                            ><select
                                v-model="form.location_id"
                                :class="fieldClass"
                            >
                                <option :value="null">Kein Standort</option>
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
                                >Vergütung von (Cent)</span
                            ><input
                                v-model.number="form.compensation_min_cents"
                                :class="fieldClass"
                                type="number"
                                min="0"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Vergütung bis (Cent)</span
                            ><input
                                v-model.number="form.compensation_max_cents"
                                :class="fieldClass"
                                type="number"
                                min="0"
                        /></label>
                    </div>
                </SectionCard>
                <SectionCard
                    title="Beschreibung"
                    description="Aufgaben, Anforderungen und was Ihr Unternehmen bietet"
                >
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
                                        ? 'KI arbeitet …'
                                        : 'Mit KI verbessern'
                                }}
                            </button>
                        </div>
                        <textarea
                            v-model="form.description"
                            required
                            rows="10"
                            class="erin-focus w-full resize-y border-0 p-4 text-sm leading-6 outline-none"
                            placeholder="Beschreiben Sie die Position …"
                        />
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
                            Hinweise für Ihre Prüfung
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
                    title="Screening-Fragen"
                    :description="`${screeningQuestions.length} von maximal 5 Fragen`"
                >
                    <div class="space-y-3">
                        <div
                            v-for="(question, index) in screeningQuestions"
                            :key="index"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500"
                                >{{ index + 1 }}</span
                            ><input
                                v-model="question.question"
                                required
                                class="erin-focus h-10 flex-1 rounded-xl border border-slate-200 px-3 text-sm"
                            /><select
                                v-model="question.type"
                                class="h-10 rounded-xl border border-slate-200 px-2 text-xs"
                            >
                                <option value="text">Text</option>
                                <option value="yes_no">Ja/Nein</option>
                                <option value="choice">Auswahl</option></select
                            ><button
                                type="button"
                                class="text-xs font-bold text-red-500"
                                @click="screeningQuestions.splice(index, 1)"
                            >
                                Entfernen
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="screeningQuestions.length < 5"
                        type="button"
                        class="mt-4 inline-flex h-9 items-center gap-2 rounded-lg border border-dashed border-slate-300 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50"
                        @click="addQuestion"
                    >
                        <FilePlus2 class="size-4" /> Frage hinzufügen
                    </button>
                </SectionCard>
                <SectionCard title="Medien & Dokumente">
                    <label
                        class="flex min-h-32 w-full cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 bg-slate-50 text-center hover:border-blue-300 hover:bg-blue-50/40"
                        ><ImagePlus
                            class="size-6 text-[var(--erin-primary)]" /><span
                            class="mt-2 text-sm font-bold text-slate-700"
                            >Dateien hier auswählen</span
                        ><span class="mt-1 text-xs text-slate-400"
                            >JPG, PNG, GIF, PDF, DOC oder DOCX · max. 10
                            MB</span
                        ><input
                            class="sr-only"
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx"
                            @change="setMedia"
                    /></label>
                </SectionCard>
            </div>
            <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
                <SectionCard title="Veröffentlichung">
                    <div class="space-y-3 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-500">Status</span
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
                                    ? 'Änderungen speichern'
                                    : 'Als Entwurf speichern'
                            }}
                        </button>
                        <Link
                            :href="jobsIndex()"
                            class="inline-flex h-10 items-center justify-center rounded-xl border border-slate-200 text-xs font-bold text-slate-600"
                            >Abbrechen</Link
                        >
                    </div>
                </SectionCard>
                <SectionCard title="Visumspaket">
                    <label class="flex cursor-pointer items-start gap-3"
                        ><input
                            v-model="form.visa_package_available"
                            type="checkbox"
                            class="mt-1 size-4 rounded border-slate-300 text-[var(--erin-primary)]"
                        /><span
                            ><span
                                class="block text-sm font-bold text-slate-700"
                                >Visa-Unterstützung anbieten</span
                            ><span
                                class="mt-1 block text-xs leading-5 text-slate-500"
                                >Fachkräfte sehen, dass Sie den Prozess
                                unterstützen.</span
                            ></span
                        ></label
                    >
                </SectionCard>
            </aside>
        </form>
    </div>
</template>
