<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Bot,
    CheckCircle2,
    FileText,
    Languages,
    MessageCircleQuestion,
    ShieldCheck,
    Sparkles,
    WandSparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { run } from '@/routes/ai';
import {
    destroy as withdrawConsent,
    store as grantConsent,
} from '@/routes/ai/consents';

type Credits = {
    used: number;
    limit: number;
    remaining: number;
};

type Consent = {
    id: number;
    purpose: string;
    data_categories?: string[];
    granted_at?: string | null;
    withdrawn_at?: string | null;
};

type AiResult = {
    title: string;
    content: string;
    suggestions: string[];
    caveats: string[];
};

type AiRun = {
    id: number;
    purpose: string;
    status: string;
    model?: string | null;
    output?: AiResult | null;
    created_at?: string | null;
    completed_at?: string | null;
    consent?: Consent | null;
};

type ToolDefinition = {
    task: string;
    title: string;
    text: string;
    placeholder: string;
    icon: typeof FileText;
    tone: string;
    requiresConsent?: boolean;
};

const props = withDefaults(
    defineProps<{
        credits?: Credits;
        tasks?: string[];
        consents?: Array<Consent | AiRun>;
        runs?: AiRun[];
        document_ai_enabled?: boolean;
    }>(),
    {
        credits: () => ({ used: 0, limit: 20, remaining: 20 }),
        tasks: () => [],
        consents: () => [],
        runs: () => [],
        document_ai_enabled: false,
    },
);

const toolDefinitions: ToolDefinition[] = [
    {
        task: 'cv_improve',
        title: 'Lebenslauf verbessern',
        text: 'Struktur und Formulierungen für deutsche Arbeitgeber verbessern.',
        placeholder: 'Füge den Text deines Lebenslaufs ein …',
        icon: FileText,
        tone: 'bg-blue-50 text-blue-600',
        requiresConsent: true,
    },
    {
        task: 'cover_letter',
        title: 'Anschreiben erstellen',
        text: 'Einen individuellen Entwurf für eine konkrete Stelle erzeugen.',
        placeholder:
            'Beschreibe die Stelle und deine wichtigsten Qualifikationen …',
        icon: WandSparkles,
        tone: 'bg-violet-50 text-violet-600',
    },
    {
        task: 'profile_improve',
        title: 'Profil optimieren',
        text: 'Konkrete, nachvollziehbare Verbesserungsvorschläge erhalten.',
        placeholder: 'Füge deinen Profiltext und deine Skills ein …',
        icon: Sparkles,
        tone: 'bg-teal-50 text-teal-600',
        requiresConsent: true,
    },
    {
        task: 'translate',
        title: 'Texte übersetzen',
        text: 'Texte vollständig und sinngemäß übersetzen.',
        placeholder: 'Text und gewünschte Zielsprache eingeben …',
        icon: Languages,
        tone: 'bg-orange-50 text-orange-600',
    },
    {
        task: 'interview_training',
        title: 'Interviewtraining',
        text: 'Realistische Fragen und Hinweise zur Vorbereitung erhalten.',
        placeholder: 'Beschreibe Beruf, Stelle und Themen für das Training …',
        icon: MessageCircleQuestion,
        tone: 'bg-rose-50 text-rose-600',
    },
];

const availableTools = computed(() =>
    toolDefinitions.filter((tool) => props.tasks.includes(tool.task)),
);
const normalizedRuns = computed<AiRun[]>(() => {
    if (props.runs.length) {
        return props.runs;
    }

    return props.consents.filter((entry): entry is AiRun => 'status' in entry);
});
const normalizedConsents = computed<Consent[]>(() => {
    const direct = props.consents.filter(
        (entry): entry is Consent => !('status' in entry),
    );
    const nested = normalizedRuns.value
        .map((aiRun) => aiRun.consent)
        .filter((consent): consent is Consent => Boolean(consent));

    return [...direct, ...nested].filter(
        (consent, index, all) =>
            all.findIndex((candidate) => candidate.id === consent.id) === index,
    );
});

const selectedTask = ref<string | null>(availableTools.value[0]?.task ?? null);
const inputText = ref('');
const running = ref(false);
const error = ref('');
const result = ref<AiResult | null>(null);
const resultModel = ref('');
const localCreditsUsed = ref(0);
const selectedTool = computed(
    () =>
        availableTools.value.find((tool) => tool.task === selectedTask.value) ??
        null,
);
const activeConsent = computed(() =>
    normalizedConsents.value.find(
        (consent) =>
            consent.purpose === selectedTask.value && !consent.withdrawn_at,
    ),
);
const usedCredits = computed(() => props.credits.used + localCreditsUsed.value);
const remainingCredits = computed(() =>
    Math.max(0, props.credits.limit - usedCredits.value),
);
const creditProgress = computed(() =>
    props.credits.limit
        ? Math.round((usedCredits.value / props.credits.limit) * 100)
        : 0,
);

const consentForm = useForm({
    purpose: 'cv_improve',
    data_categories: ['profile_text'],
});

const selectTool = (tool: ToolDefinition) => {
    selectedTask.value = tool.task;
    result.value = null;
    error.value = '';
};
const requestConsent = () => {
    if (!selectedTask.value) {
        return;
    }

    consentForm.purpose = selectedTask.value;
    consentForm.data_categories =
        selectedTask.value === 'cv_improve' ? ['cv_text'] : ['profile_text'];
    consentForm.post(grantConsent.url(), { preserveScroll: true });
};
const revokeConsent = (consent: Consent) => {
    router.delete(withdrawConsent.url(consent.id), { preserveScroll: true });
};
const runTask = async () => {
    if (!selectedTask.value || !inputText.value.trim()) {
        return;
    }

    running.value = true;
    error.value = '';
    result.value = null;

    try {
        const csrfToken =
            document
                .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        const response = await fetch(run.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({
                task: selectedTask.value,
                input: { text: inputText.value },
                consent_id: activeConsent.value?.id ?? null,
            }),
        });
        const payload = (await response.json()) as {
            result?: AiResult;
            model?: string;
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

        result.value = payload.result;
        resultModel.value = payload.model ?? '';
        localCreditsUsed.value += 1;
    } catch (exception) {
        error.value =
            exception instanceof Error
                ? exception.message
                : 'Die KI-Anfrage konnte nicht abgeschlossen werden.';
    } finally {
        running.value = false;
    }
};
</script>

<template>
    <Head title="KI Studio" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Dein persönlicher Assistent"
            title="Erin KI Studio"
            description="Verbessere dein Profil, deine Unterlagen und deine Interviewvorbereitung."
            :icon="Bot"
        >
            <template #actions>
                <span
                    class="rounded-full bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700"
                >
                    {{ remainingCredits }} von {{ credits.limit }} Credits
                </span>
            </template>
        </PageHeader>

        <div
            v-if="!document_ai_enabled"
            class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
        >
            <ShieldCheck class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-bold">Dokument-KI ist geschützt deaktiviert</p>
                <p class="mt-1 text-xs leading-5 text-amber-800">
                    Direkte Dokumentverarbeitung bleibt gesperrt, bis
                    EU-Endpunkt und Aufbewahrungskontrollen aktiviert sind.
                    Texte können nur zweckgebunden verarbeitet werden.
                </p>
            </div>
        </div>

        <div
            v-if="availableTools.length"
            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <button
                v-for="tool in availableTools"
                :key="tool.task"
                type="button"
                class="erin-panel flex flex-col p-5 text-left transition hover:-translate-y-0.5"
                :class="{
                    'ring-2 ring-violet-400': selectedTask === tool.task,
                }"
                @click="selectTool(tool)"
            >
                <span
                    class="grid size-11 place-items-center rounded-xl"
                    :class="tool.tone"
                >
                    <component :is="tool.icon" class="size-5" />
                </span>
                <h2 class="mt-4 font-extrabold">{{ tool.title }}</h2>
                <p class="mt-2 flex-1 text-sm leading-6 text-slate-500">
                    {{ tool.text }}
                </p>
                <span
                    class="mt-5 flex h-10 items-center justify-between rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-700"
                >
                    <span>1 KI-Credit</span>
                    <ArrowRight class="size-4 text-violet-500" />
                </span>
            </button>
        </div>
        <div
            v-else
            class="erin-panel grid min-h-64 place-items-center p-8 text-center"
        >
            <div>
                <Bot class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">
                    Keine KI-Werkzeuge freigeschaltet
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    Verfügbare Werkzeuge werden hier automatisch angezeigt.
                </p>
            </div>
        </div>

        <div v-if="selectedTool" class="grid gap-6 xl:grid-cols-[1fr_22rem]">
            <SectionCard
                :title="selectedTool.title"
                description="Das Ergebnis ist ein Vorschlag und erfordert immer deine Prüfung."
            >
                <div
                    v-if="selectedTool.requiresConsent && !activeConsent"
                    class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4"
                >
                    <p class="text-xs font-bold text-amber-900">
                        Zweckgebundene Einwilligung erforderlich
                    </p>
                    <p class="mt-1 text-xs leading-5 text-amber-800">
                        Erin protokolliert Zweck, Datenkategorien, Promptversion
                        und Ergebnis. Die Einwilligung kann jederzeit widerrufen
                        werden.
                    </p>
                    <button
                        type="button"
                        :disabled="consentForm.processing"
                        class="mt-3 h-9 rounded-xl bg-amber-900 px-3 text-xs font-bold text-white disabled:opacity-50"
                        @click="requestConsent"
                    >
                        Einwilligung erteilen
                    </button>
                </div>

                <textarea
                    v-model="inputText"
                    rows="10"
                    :placeholder="selectedTool.placeholder"
                    class="erin-focus w-full rounded-xl border border-slate-200 p-4 text-sm leading-6"
                />
                <p v-if="error" class="mt-3 text-xs font-bold text-red-600">
                    {{ error }}
                </p>
                <button
                    type="button"
                    :disabled="
                        running ||
                        !inputText.trim() ||
                        remainingCredits <= 0 ||
                        (selectedTool.requiresConsent && !activeConsent)
                    "
                    class="mt-4 inline-flex h-11 items-center gap-2 rounded-xl bg-violet-600 px-5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                    @click="runTask"
                >
                    <Sparkles class="size-4" />
                    {{ running ? 'KI arbeitet …' : 'Vorschlag erzeugen' }}
                </button>
            </SectionCard>

            <SectionCard title="Credit-Verbrauch">
                <div class="flex items-end justify-between">
                    <div>
                        <p class="text-2xl font-extrabold">
                            {{ usedCredits }} / {{ credits.limit }}
                        </p>
                        <p class="text-xs text-slate-400">
                            Credits in diesem Monat verbraucht
                        </p>
                    </div>
                </div>
                <ProgressBar
                    class="mt-4"
                    :value="creditProgress"
                    :show-value="false"
                    tone="orange"
                />
                <p class="mt-4 text-xs leading-5 text-slate-500">
                    Nicht verbrauchte Credits verfallen am Monatsende.
                    KI-Ergebnisse werden nie automatisch an Unternehmen
                    gesendet.
                </p>
                <div v-if="activeConsent" class="mt-4 border-t pt-4">
                    <p class="text-xs font-bold text-slate-700">
                        Aktive Einwilligung
                    </p>
                    <button
                        type="button"
                        class="mt-2 text-xs font-bold text-red-600"
                        @click="revokeConsent(activeConsent)"
                    >
                        Einwilligung widerrufen
                    </button>
                </div>
            </SectionCard>
        </div>

        <SectionCard
            v-if="result"
            title="KI-Vorschlag"
            description="Bitte vor jeder Verwendung fachlich und inhaltlich prüfen."
        >
            <div class="flex flex-wrap items-center gap-2">
                <CheckCircle2 class="size-5 text-teal-500" />
                <h2 class="font-extrabold">{{ result.title }}</h2>
                <StatusBadge
                    v-if="resultModel"
                    :label="resultModel"
                    tone="violet"
                />
            </div>
            <div
                class="mt-4 rounded-xl bg-slate-50 p-4 text-sm leading-7 whitespace-pre-wrap text-slate-700"
            >
                {{ result.content }}
            </div>
            <div
                v-if="result.suggestions.length"
                class="mt-5 grid gap-2 sm:grid-cols-2"
            >
                <div
                    v-for="suggestion in result.suggestions"
                    :key="suggestion"
                    class="rounded-xl border border-teal-100 bg-teal-50 p-3 text-xs leading-5 text-teal-900"
                >
                    {{ suggestion }}
                </div>
            </div>
            <div
                v-if="result.caveats.length"
                class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4"
            >
                <p
                    class="flex items-center gap-2 text-xs font-bold text-amber-900"
                >
                    <AlertTriangle class="size-4" />
                    Hinweise für die menschliche Prüfung
                </p>
                <ul
                    class="mt-2 list-disc space-y-1 pl-5 text-xs leading-5 text-amber-800"
                >
                    <li v-for="caveat in result.caveats" :key="caveat">
                        {{ caveat }}
                    </li>
                </ul>
            </div>
        </SectionCard>

        <SectionCard
            title="Letzte KI-Aktivitäten"
            description="Auditierbare Historie deiner Verarbeitungsläufe"
        >
            <div v-if="normalizedRuns.length" class="space-y-3">
                <article
                    v-for="aiRun in normalizedRuns"
                    :key="aiRun.id"
                    class="flex items-center gap-3 rounded-xl bg-slate-50 p-3"
                >
                    <Sparkles class="size-4 text-violet-500" />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs font-bold text-slate-700">
                            {{
                                toolDefinitions.find(
                                    (tool) => tool.task === aiRun.purpose,
                                )?.title ?? aiRun.purpose
                            }}
                        </p>
                        <p
                            v-if="aiRun.created_at"
                            class="mt-1 text-[10px] text-slate-400"
                        >
                            {{
                                new Intl.DateTimeFormat('de-DE', {
                                    dateStyle: 'medium',
                                    timeStyle: 'short',
                                }).format(new Date(aiRun.created_at))
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        :label="aiRun.status"
                        :tone="
                            aiRun.status === 'completed'
                                ? 'green'
                                : aiRun.status === 'failed'
                                  ? 'red'
                                  : 'blue'
                        "
                    />
                </article>
            </div>
            <p v-else class="py-8 text-center text-sm text-slate-400">
                Noch keine KI-Aktivitäten vorhanden.
            </p>
        </SectionCard>
    </div>
</template>
