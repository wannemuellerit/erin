<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    FileText,
    GripVertical,
    Kanban,
    MessageCircle,
    UsersRound,
    Video,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import FilterToolbar from '@/components/product/FilterToolbar.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormatters } from '@/composables/useFormatters';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { status as updateApplicationStatus } from '@/routes/employer/applications';
import { pipeline as pipelineRoute } from '@/routes/employer';

type Application = {
    id: number;
    status: string;
    pipeline_stage: string;
    updated_at: string;
    allowed_statuses: ApplicationStatus[];
    match_score?: number | null;
    match_breakdown?: Record<string, unknown> | null;
    cover_letter?: string | null;
    applied_at?: string;
    job?: { id: number; title: string } | null;
    candidate: {
        id: number;
        name?: string;
        country?: string | null;
        position?: string | null;
        experience_years?: number | null;
        identity_revealed?: boolean;
    };
    screening_answers: Array<{
        question?: string | null;
        answer?: string | null;
        required?: boolean;
    }>;
    documents: Array<{
        id: number;
        type: string;
        name: string;
        status: string;
        scan_result?: string | null;
    }>;
    internal_reviews: Array<{
        metrics: Record<string, boolean>;
        notes?: string | null;
        reviewer?: string | null;
    }>;
    timeline: Array<{
        type: string;
        event: string;
        actor?: string | null;
        occurred_at: string;
        data: Record<string, unknown>;
    }>;
};
type Job = { id: number; title: string; status: string };
type ApplicationStatus = { value: string; pipeline_stage: string };

const props = withDefaults(
    defineProps<{
        pipeline?: Record<string, Application[]>;
        jobs?: Job[];
        statuses?: ApplicationStatus[];
        selected_job?: number | null;
    }>(),
    {
        pipeline: () => ({}),
        jobs: () => [],
        statuses: () => [],
        selected_job: null,
    },
);

const search = ref('');
const clonePipeline = (
    pipeline: Record<string, Application[]>,
): Record<string, Application[]> =>
    JSON.parse(JSON.stringify(pipeline)) as Record<string, Application[]>;
const localPipeline = ref<Record<string, Application[]>>(
    clonePipeline(props.pipeline),
);
const pendingApplicationId = ref<number | null>(null);
const draggedApplicationId = ref<number | null>(null);
const selectedApplicationId = ref<number | null>(null);
const timelineFilter = ref('all');
const transitionError = ref('');
watch(
    () => props.pipeline,
    (pipeline) => {
        if (pendingApplicationId.value === null) {
            localPipeline.value = clonePipeline(pipeline);
        }
    },
    { deep: true },
);
const selectedJob = ref<number | null>(props.selected_job);
const { t } = useI18n();
const { can } = useCapabilities();
const canManageApplications = computed(() => can('applications.manage'));
const { formatDate } = useFormatters();
const { statusLabel } = useStatusLabels();
const stageDefinitions = computed(() => [
    {
        key: 'new',
        title: t('employer.pipeline.stages.new'),
        color: 'bg-blue-500',
    },
    {
        key: 'interesting',
        title: t('employer.pipeline.stages.interesting'),
        color: 'bg-teal-500',
    },
    {
        key: 'interview',
        title: t('employer.pipeline.stages.interview'),
        color: 'bg-violet-500',
    },
    {
        key: 'final_selection',
        title: t('employer.pipeline.stages.finalSelection'),
        color: 'bg-orange-500',
    },
    {
        key: 'accepted',
        title: t('employer.pipeline.stages.accepted'),
        color: 'bg-emerald-500',
    },
    {
        key: 'hired',
        title: t('employer.pipeline.stages.hired'),
        color: 'bg-green-700',
    },
    {
        key: 'closed',
        title: t('employer.pipeline.stages.closed'),
        color: 'bg-muted0',
    },
]);
const columns = computed(() =>
    stageDefinitions.value.map((stage) => ({
        ...stage,
        cards: (localPipeline.value[stage.key] ?? []).filter((application) => {
            const needle = search.value.trim().toLowerCase();

            return (
                !needle ||
                [
                    application.candidate.name,
                    application.candidate.position,
                    application.candidate.country,
                    application.job?.title,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase()
                    .includes(needle)
            );
        }),
    })),
);
const total = computed(() =>
    Object.values(localPipeline.value).reduce(
        (sum, items) => sum + items.length,
        0,
    ),
);
const activeApplicationsLabel = computed(() =>
    t(
        total.value === 1
            ? 'employer.pipeline.activeApplications.one'
            : 'employer.pipeline.activeApplications.other',
        { count: total.value },
    ),
);

const filterJob = () => {
    router.get(
        pipelineRoute.url(),
        selectedJob.value ? { job: selectedJob.value } : {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};
const findApplication = (id: number) =>
    Object.values(localPipeline.value)
        .flat()
        .find((application) => application.id === id);
const selectedApplication = computed(() =>
    selectedApplicationId.value === null
        ? null
        : findApplication(selectedApplicationId.value),
);
const filteredTimeline = computed(() =>
    (selectedApplication.value?.timeline ?? []).filter(
        (event) =>
            timelineFilter.value === 'all' ||
            event.type === timelineFilter.value,
    ),
);
const moveLocally = (
    application: Application,
    nextStatus: string,
    nextStage: string,
) => {
    for (const stage of Object.keys(localPipeline.value)) {
        localPipeline.value[stage] = localPipeline.value[stage].filter(
            (candidate) => candidate.id !== application.id,
        );
    }

    application.status = nextStatus;
    application.pipeline_stage = nextStage;
    localPipeline.value[nextStage] ??= [];
    localPipeline.value[nextStage].unshift(application);
};
const transition = (application: Application, nextStatus: string) => {
    const allowed = application.allowed_statuses.find(
        (status) => status.value === nextStatus,
    );

    if (!allowed || pendingApplicationId.value !== null) {
        return;
    }

    const snapshot = clonePipeline(localPipeline.value);
    const fromStatus = application.status;
    transitionError.value = '';
    pendingApplicationId.value = application.id;
    moveLocally(application, allowed.value, allowed.pipeline_stage);
    router.patch(
        updateApplicationStatus.url(application.id),
        {
            status: nextStatus,
            from_status: fromStatus,
            version: application.updated_at,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => {
                localPipeline.value = snapshot;
                transitionError.value =
                    errors.status ?? t('employer.pipeline.transitionFailed');
            },
            onFinish: () => {
                pendingApplicationId.value = null;
            },
        },
    );
};
const dropOnStage = (stage: string) => {
    if (draggedApplicationId.value === null) {
        return;
    }

    const application = findApplication(draggedApplicationId.value);
    draggedApplicationId.value = null;

    if (!application || application.pipeline_stage === stage) {
        return;
    }

    const target = application.allowed_statuses.find(
        (status) => status.pipeline_stage === stage,
    );

    if (target) {
        transition(application, target.value);
    }
};
</script>

<template>
    <Head :title="t('employer.pipeline.metaTitle')" />
    <div class="erin-page max-w-none">
        <PageHeader
            :eyebrow="t('employer.pipeline.eyebrow')"
            :title="t('employer.pipeline.title')"
            :description="t('employer.pipeline.description')"
            :icon="Kanban"
        >
            <template #actions>
                <span
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-[var(--erin-primary-text-hover)]"
                    >{{ activeApplicationsLabel }}</span
                >
            </template>
        </PageHeader>
        <FilterToolbar>
            <SearchField
                v-model="search"
                size="sm"
                :placeholder="t('employer.pipeline.searchPlaceholder')"
            />
            <template #actions>
                <select
                    v-model="selectedJob"
                    class="erin-focus h-10 rounded-xl border border-border bg-card px-3 text-xs font-bold text-muted-foreground"
                    @change="filterJob"
                >
                    <option :value="null">
                        {{ t('employer.pipeline.allJobs') }}
                    </option>
                    <option v-for="job in jobs" :key="job.id" :value="job.id">
                        {{ job.title }}
                    </option>
                </select>
            </template>
        </FilterToolbar>
        <p
            class="sr-only"
            aria-live="assertive"
            data-testid="pipeline-transition-error"
        >
            {{ transitionError }}
        </p>

        <div v-if="total" class="overflow-x-auto pb-3">
            <div
                class="grid min-w-[1280px] gap-4"
                :style="{
                    gridTemplateColumns: `repeat(${columns.length}, minmax(230px, 1fr))`,
                }"
            >
                <section
                    v-for="column in columns"
                    :key="column.key"
                    class="rounded-2xl bg-muted/80 p-3"
                    :aria-label="column.title"
                    @dragover.prevent
                    @drop="dropOnStage(column.key)"
                >
                    <header class="mb-3 flex items-center gap-2 px-1">
                        <span
                            class="size-2.5 rounded-full"
                            :class="column.color"
                        />
                        <h2
                            class="flex-1 text-xs font-extrabold text-muted-foreground"
                        >
                            {{ column.title }}
                        </h2>
                        <span
                            class="grid size-6 place-items-center rounded-full bg-card text-[10px] font-bold text-muted-foreground"
                            >{{ column.cards.length }}</span
                        >
                    </header>
                    <div class="space-y-3">
                        <article
                            v-for="application in column.cards"
                            :key="application.id"
                            class="erin-panel p-4"
                            :class="{
                                'opacity-60':
                                    pendingApplicationId === application.id,
                            }"
                            draggable="true"
                            @dragstart="draggedApplicationId = application.id"
                            @dragend="draggedApplicationId = null"
                        >
                            <div class="flex items-start gap-2">
                                <GripVertical
                                    class="-ml-1 size-4 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <p
                                            class="truncate text-sm font-bold text-foreground"
                                        >
                                            {{
                                                application.candidate.name ||
                                                `#ER-${application.candidate.id}`
                                            }}
                                        </p>
                                        <span
                                            v-if="
                                                application.match_score != null
                                            "
                                            class="rounded-full bg-teal-50 px-2 py-0.5 text-[10px] font-extrabold text-teal-700"
                                            >{{
                                                application.match_score
                                            }}
                                            %</span
                                        >
                                    </div>
                                    <p
                                        class="mt-1 truncate text-xs font-medium text-muted-foreground"
                                    >
                                        {{
                                            application.candidate.position ||
                                            t(
                                                'employer.pipeline.positionMissing',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-muted-foreground"
                                    >
                                        {{
                                            application.candidate.country ||
                                            t(
                                                'employer.pipeline.countryMissing',
                                            )
                                        }}
                                    </p>
                                    <p
                                        v-if="application.job"
                                        class="mt-2 truncate rounded-md bg-muted px-2 py-1 text-[9px] font-semibold text-muted-foreground"
                                    >
                                        {{ application.job.title }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-3 border-t border-border pt-3">
                                <button
                                    type="button"
                                    class="mb-2 w-full rounded-lg border border-border px-2 py-1.5 text-[10px] font-bold text-[var(--erin-primary-text-hover)] hover:bg-blue-50"
                                    @click="
                                        selectedApplicationId = application.id
                                    "
                                >
                                    {{ t('employer.pipeline.openDetails') }}
                                </button>
                                <select
                                    v-if="canManageApplications"
                                    :value="application.status"
                                    :disabled="
                                        pendingApplicationId === application.id
                                    "
                                    class="h-8 w-full rounded-lg border border-border px-2 text-[10px] font-bold text-muted-foreground"
                                    :aria-label="
                                        t(
                                            'employer.pipeline.changeApplicationStatus',
                                        )
                                    "
                                    @change="
                                        transition(
                                            application,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option :value="application.status">
                                        {{
                                            statusLabel(
                                                'application',
                                                application.status,
                                            )
                                        }}
                                    </option>
                                    <option
                                        v-for="status in application.allowed_statuses"
                                        :key="status.value"
                                        :value="status.value"
                                    >
                                        {{
                                            statusLabel(
                                                'application',
                                                status.value,
                                            )
                                        }}
                                    </option>
                                </select>
                                <p
                                    v-else
                                    class="rounded-lg bg-muted px-2 py-2 text-[10px] font-bold text-muted-foreground"
                                >
                                    {{
                                        statusLabel(
                                            'application',
                                            application.status,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="application.applied_at"
                                    class="mt-2 flex items-center gap-1.5 text-[9px] text-muted-foreground"
                                >
                                    <CalendarDays class="size-3" />
                                    {{ formatDate(application.applied_at) }}
                                </p>
                            </div>
                        </article>
                        <p
                            v-if="column.cards.length === 0"
                            class="rounded-xl border border-dashed border-border p-5 text-center text-xs text-muted-foreground"
                        >
                            {{ t('employer.pipeline.noApplicationsInStage') }}
                        </p>
                    </div>
                </section>
            </div>
        </div>
        <div
            v-else
            class="erin-panel grid min-h-80 place-items-center p-8 text-center"
        >
            <div>
                <UsersRound class="mx-auto size-9 text-muted-foreground" />
                <h2 class="mt-4 font-bold text-foreground">
                    {{ t('employer.pipeline.emptyTitle') }}
                </h2>
                <p class="mt-2 max-w-md text-sm text-muted-foreground">
                    {{ t('employer.pipeline.emptyDescription') }}
                </p>
            </div>
        </div>
        <div
            v-if="selectedApplication"
            class="fixed inset-0 z-50 flex justify-end bg-slate-950/40"
            role="dialog"
            aria-modal="true"
            :aria-label="t('employer.pipeline.detailsTitle')"
            @click.self="selectedApplicationId = null"
        >
            <aside
                class="h-full w-full max-w-2xl overflow-y-auto bg-card p-6 shadow-2xl"
            >
                <header class="flex items-start justify-between gap-4">
                    <div>
                        <p
                            class="text-xs font-bold text-[var(--erin-primary-text-hover)]"
                        >
                            {{ selectedApplication.job?.title }}
                        </p>
                        <h2 class="mt-1 text-xl font-extrabold text-foreground">
                            {{ selectedApplication.candidate.name }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-xl hover:bg-muted"
                        :aria-label="t('employer.pipeline.closeDetails')"
                        @click="selectedApplicationId = null"
                    >
                        <X class="size-5" />
                    </button>
                </header>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <section class="rounded-2xl border border-border p-4">
                        <h3 class="text-sm font-extrabold text-foreground">
                            {{ t('employer.pipeline.coverLetter') }}
                        </h3>
                        <p
                            class="mt-2 text-sm whitespace-pre-wrap text-muted-foreground"
                        >
                            {{
                                selectedApplication.cover_letter ||
                                t('employer.pipeline.notAvailable')
                            }}
                        </p>
                    </section>
                    <section class="rounded-2xl border border-border p-4">
                        <h3 class="text-sm font-extrabold text-foreground">
                            {{ t('employer.pipeline.matchBreakdown') }}
                        </h3>
                        <dl
                            class="mt-2 space-y-1 text-xs text-muted-foreground"
                        >
                            <div
                                v-for="(
                                    value, key
                                ) in selectedApplication.match_breakdown"
                                :key="key"
                                class="flex justify-between gap-3"
                            >
                                <dt>{{ key }}</dt>
                                <dd class="font-bold">{{ value }}</dd>
                            </div>
                        </dl>
                    </section>
                </div>

                <section class="mt-4 rounded-2xl border border-border p-4">
                    <h3 class="text-sm font-extrabold text-foreground">
                        {{ t('employer.pipeline.screeningAnswers') }}
                    </h3>
                    <dl class="mt-3 space-y-3">
                        <div
                            v-for="answer in selectedApplication.screening_answers"
                            :key="answer.question ?? ''"
                        >
                            <dt class="text-xs font-bold text-muted-foreground">
                                {{ answer.question }}
                            </dt>
                            <dd class="mt-1 text-sm text-muted-foreground">
                                {{
                                    answer.answer ||
                                    t('employer.pipeline.notAvailable')
                                }}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="mt-4 rounded-2xl border border-border p-4">
                    <h3 class="text-sm font-extrabold text-foreground">
                        {{ t('employer.pipeline.sharedDocuments') }}
                    </h3>
                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="document in selectedApplication.documents"
                            :key="document.id"
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <FileText class="size-4" /> {{ document.name }} ·
                            {{ document.status }}
                        </li>
                    </ul>
                    <p
                        v-if="!selectedApplication.documents.length"
                        class="mt-2 text-sm text-muted-foreground"
                    >
                        {{ t('employer.pipeline.noSharedDocuments') }}
                    </p>
                </section>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a
                        :href="`/messages?application=${selectedApplication.id}`"
                        class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold"
                        ><MessageCircle class="size-4" />{{
                            t('employer.pipeline.message')
                        }}</a
                    >
                    <a
                        :href="`/employer/interviews?application=${selectedApplication.id}`"
                        class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-bold"
                        ><Video class="size-4" />{{
                            t('employer.pipeline.interview')
                        }}</a
                    >
                </div>

                <section class="mt-6">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-extrabold text-foreground">
                            {{ t('employer.pipeline.timeline') }}
                        </h3>
                        <select
                            v-model="timelineFilter"
                            class="rounded-lg border border-border px-2 py-1 text-xs"
                            :aria-label="t('employer.pipeline.timelineFilter')"
                        >
                            <option value="all">
                                {{ t('employer.pipeline.allEvents') }}
                            </option>
                            <option value="status">
                                {{
                                    t(
                                        'employer.pipeline.timelineCategories.status',
                                    )
                                }}
                            </option>
                            <option value="message">
                                {{
                                    t(
                                        'employer.pipeline.timelineCategories.messages',
                                    )
                                }}
                            </option>
                            <option value="interview">
                                {{
                                    t(
                                        'employer.pipeline.timelineCategories.interviews',
                                    )
                                }}
                            </option>
                            <option value="visa">
                                {{
                                    t(
                                        'employer.pipeline.timelineCategories.visa',
                                    )
                                }}
                            </option>
                            <option value="internal">
                                {{
                                    t(
                                        'employer.pipeline.timelineCategories.internal',
                                    )
                                }}
                            </option>
                        </select>
                    </div>
                    <ol class="mt-3 space-y-3">
                        <li
                            v-for="event in filteredTimeline"
                            :key="`${event.event}-${event.occurred_at}`"
                            class="rounded-xl border border-border p-3"
                        >
                            <p class="text-xs font-bold text-foreground">
                                {{ event.event }}
                            </p>
                            <p class="mt-1 text-[11px] text-muted-foreground">
                                {{ formatDate(event.occurred_at) }}
                                <template v-if="event.actor">
                                    · {{ event.actor }}</template
                                >
                            </p>
                        </li>
                    </ol>
                </section>
            </aside>
        </div>
    </div>
</template>
