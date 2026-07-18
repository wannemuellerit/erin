<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, GripVertical, Kanban, UsersRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FilterToolbar from '@/components/product/FilterToolbar.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { status as updateApplicationStatus } from '@/routes/employer/applications';
import { pipeline as pipelineRoute } from '@/routes/employer';

type Application = {
    id: number;
    status: string;
    pipeline_stage: string;
    match_score?: number | null;
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
const selectedJob = ref<number | null>(props.selected_job);
const { t } = useI18n();
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
]);
const columns = computed(() =>
    stageDefinitions.value
        .map((stage) => ({
            ...stage,
            cards: (props.pipeline[stage.key] ?? []).filter((application) => {
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
        }))
        .filter((stage) => stage.cards.length || stage.key !== 'hired'),
);
const total = computed(() =>
    Object.values(props.pipeline).reduce((sum, items) => sum + items.length, 0),
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
const transition = (application: Application, nextStatus: string) => {
    router.patch(
        updateApplicationStatus.url(application.id),
        { status: nextStatus },
        {
            preserveScroll: true,
        },
    );
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
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700"
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
                    class="erin-focus h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600"
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
                    class="rounded-2xl bg-slate-100/80 p-3"
                >
                    <header class="mb-3 flex items-center gap-2 px-1">
                        <span
                            class="size-2.5 rounded-full"
                            :class="column.color"
                        />
                        <h2
                            class="flex-1 text-xs font-extrabold text-slate-700"
                        >
                            {{ column.title }}
                        </h2>
                        <span
                            class="grid size-6 place-items-center rounded-full bg-white text-[10px] font-bold text-slate-500"
                            >{{ column.cards.length }}</span
                        >
                    </header>
                    <div class="space-y-3">
                        <article
                            v-for="application in column.cards"
                            :key="application.id"
                            class="erin-panel p-4"
                        >
                            <div class="flex items-start gap-2">
                                <GripVertical
                                    class="-ml-1 size-4 shrink-0 text-slate-300"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <p
                                            class="truncate text-sm font-bold text-slate-900"
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
                                        class="mt-1 truncate text-xs font-medium text-slate-600"
                                    >
                                        {{
                                            application.candidate.position ||
                                            t(
                                                'employer.pipeline.positionMissing',
                                            )
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-[10px] text-slate-400"
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
                                        class="mt-2 truncate rounded-md bg-slate-50 px-2 py-1 text-[9px] font-semibold text-slate-500"
                                    >
                                        {{ application.job.title }}
                                    </p>
                                </div>
                            </div>
                            <div class="mt-3 border-t border-slate-100 pt-3">
                                <select
                                    :value="application.status"
                                    class="h-8 w-full rounded-lg border border-slate-200 px-2 text-[10px] font-bold text-slate-600"
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
                                    <option
                                        v-for="status in statuses"
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
                                    v-if="application.applied_at"
                                    class="mt-2 flex items-center gap-1.5 text-[9px] text-slate-400"
                                >
                                    <CalendarDays class="size-3" />
                                    {{ formatDate(application.applied_at) }}
                                </p>
                            </div>
                        </article>
                        <p
                            v-if="column.cards.length === 0"
                            class="rounded-xl border border-dashed border-slate-300 p-5 text-center text-xs text-slate-400"
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
                <UsersRound class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold text-slate-900">
                    {{ t('employer.pipeline.emptyTitle') }}
                </h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    {{ t('employer.pipeline.emptyDescription') }}
                </p>
            </div>
        </div>
    </div>
</template>
