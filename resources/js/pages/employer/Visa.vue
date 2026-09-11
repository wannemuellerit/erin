<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertCircle, Check, ChevronDown, Clock3, Plane } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormatters } from '@/composables/useFormatters';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { steps as updateStep } from '@/routes/employer/visa';
import type { StatusTone } from '@/types';

type VisaStep = {
    id: number;
    key: string;
    title: string;
    description?: string | null;
    status: string;
    due_at?: string | null;
    completed_at?: string | null;
    responsible_user_id?: number | null;
    responsible_user?: { id: number; name: string } | null;
    notes?: string | null;
    blocker?: string | null;
    completion_evidence?: string | null;
    tasks?: Array<{
        id: number;
        title: string;
        status: string;
        due_at?: string | null;
        assignee?: { id: number; name: string } | null;
    }>;
};

type VisaCase = {
    id: number;
    status: string;
    progress: number;
    target_start_date?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    notes?: string | null;
    candidate_profile?: {
        id: number;
        first_name?: string | null;
        last_name?: string | null;
        current_position?: string | null;
    } | null;
    application?: {
        id: number;
        job_posting?: { id: number; title: string } | null;
    } | null;
    steps?: VisaStep[];
    documents?: Array<{
        id: number;
        purpose?: string | null;
        translation_status: string;
        review_status: string;
        document?: {
            id: number;
            type: string;
            status: string;
            scan_result?: string | null;
            expires_at?: string | null;
        };
    }>;
};

const props = withDefaults(
    defineProps<{
        cases?: VisaCase[];
        responsible_users?: Array<{ id: number; name: string }>;
    }>(),
    {
        cases: () => [],
        responsible_users: () => [],
    },
);
const { t } = useI18n();
const { can } = useCapabilities();
const canManageVisa = computed(() => can('visa.manage'));
const { formatDate } = useFormatters();
const { statusLabel } = useStatusLabels();

const expandedCaseId = ref<number | null>(props.cases[0]?.id ?? null);
const activeCases = computed(
    () =>
        props.cases.filter((visaCase) =>
            ['draft', 'active', 'blocked'].includes(visaCase.status),
        ).length,
);
const blockedCases = computed(
    () =>
        props.cases.filter(
            (visaCase) =>
                visaCase.status === 'blocked' ||
                visaCase.steps?.some((step) => step.status === 'blocked'),
        ).length,
);
const onTrackCases = computed(
    () =>
        props.cases.filter(
            (visaCase) =>
                visaCase.status === 'active' &&
                !visaCase.steps?.some((step) => step.status === 'blocked'),
        ).length,
);
const averageDuration = computed(() => {
    const durations = props.cases
        .filter((visaCase) => visaCase.started_at)
        .map((visaCase) => {
            const start = new Date(visaCase.started_at as string).getTime();
            const end = visaCase.completed_at
                ? new Date(visaCase.completed_at).getTime()
                : Date.now();

            return Math.max(0, Math.round((end - start) / 86_400_000));
        });

    if (!durations.length) {
        return '—';
    }

    const days = Math.round(
        durations.reduce((total, days) => total + days, 0) / durations.length,
    );

    return t(
        days === 1
            ? 'employer.visa.durationDays.one'
            : 'employer.visa.durationDays.other',
        { count: days },
    );
});
const candidateName = (visaCase: VisaCase) =>
    [
        visaCase.candidate_profile?.first_name,
        visaCase.candidate_profile?.last_name,
    ]
        .filter(Boolean)
        .join(' ') ||
    t('employer.visa.candidateFallback', {
        id: visaCase.candidate_profile?.id ?? '—',
    });
const caseTone = (status: string): StatusTone => {
    if (status === 'completed') {
        return 'green';
    }

    if (status === 'blocked' || status === 'cancelled') {
        return 'red';
    }

    if (status === 'active') {
        return 'blue';
    }

    return 'slate';
};
const caseLabel = (status: string) => statusLabel('visaCase', status);
const stepLabel = (status: string) => statusLabel('visaStep', status);
const updateStepStatus = (step: VisaStep, status: string) => {
    router.patch(
        updateStep.url(step.id),
        {
            status,
            due_at: step.due_at ?? null,
            responsible_user_id: step.responsible_user_id ?? null,
            notes: step.notes ?? null,
            blocker: step.blocker ?? null,
            completion_evidence: step.completion_evidence ?? null,
        },
        { preserveScroll: true },
    );
};
const updateStepDeadline = (step: VisaStep, dueAt: string) => {
    router.patch(
        updateStep.url(step.id),
        {
            status: step.status,
            due_at: dueAt || null,
            responsible_user_id: step.responsible_user_id ?? null,
            notes: step.notes ?? null,
            blocker: step.blocker ?? null,
            completion_evidence: step.completion_evidence ?? null,
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head :title="t('employer.visa.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.visa.eyebrow')"
            :title="t('employer.visa.title')"
            :description="t('employer.visa.description')"
            :icon="Plane"
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                :label="t('employer.visa.metrics.activeCases')"
                :value="activeCases"
                :icon="Plane"
            />
            <MetricCard
                :label="t('employer.visa.metrics.onTrack')"
                :value="onTrackCases"
                :icon="Check"
                tone="teal"
            />
            <MetricCard
                :label="t('employer.visa.metrics.actionRequired')"
                :value="blockedCases"
                :icon="AlertCircle"
                tone="orange"
            />
            <MetricCard
                :label="t('employer.visa.metrics.averageDuration')"
                :value="averageDuration"
                :icon="Clock3"
                tone="violet"
            />
        </div>

        <SectionCard
            :title="t('employer.visa.casesTitle')"
            :description="t('employer.visa.casesDescription')"
        >
            <div v-if="cases.length" class="space-y-3">
                <article
                    v-for="visaCase in cases"
                    :key="visaCase.id"
                    :data-test="`employer-visa-case-${visaCase.id}`"
                    class="overflow-hidden rounded-xl border border-border"
                >
                    <button
                        type="button"
                        :data-test="`employer-visa-details-${visaCase.id}`"
                        class="grid w-full gap-4 p-4 text-left hover:bg-muted lg:grid-cols-[1.1fr_1fr_0.75fr_auto] lg:items-center"
                        :aria-expanded="expandedCaseId === visaCase.id"
                        :aria-label="
                            t('employer.visa.toggleCase', {
                                name: candidateName(visaCase),
                            })
                        "
                        @click="
                            expandedCaseId =
                                expandedCaseId === visaCase.id
                                    ? null
                                    : visaCase.id
                        "
                    >
                        <span class="flex items-center gap-3">
                            <span
                                class="grid size-11 place-items-center rounded-xl bg-blue-50 text-xs font-extrabold text-[var(--erin-primary-text)]"
                            >
                                {{
                                    candidateName(visaCase)
                                        .slice(0, 2)
                                        .toUpperCase()
                                }}
                            </span>
                            <span>
                                <span
                                    class="block text-sm font-bold text-foreground"
                                >
                                    {{ candidateName(visaCase) }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    #VI-{{ visaCase.id }} ·
                                    {{
                                        visaCase.candidate_profile
                                            ?.current_position ??
                                        visaCase.application?.job_posting
                                            ?.title ??
                                        t('employer.visa.positionMissing')
                                    }}
                                </span>
                            </span>
                        </span>
                        <span>
                            <span class="mb-2 flex justify-between text-xs">
                                <span class="font-bold text-muted-foreground">
                                    {{ t('employer.visa.progress') }}
                                </span>
                                <span class="text-muted-foreground">
                                    {{ visaCase.progress }} %
                                </span>
                            </span>
                            <ProgressBar
                                :value="visaCase.progress"
                                :show-value="false"
                                tone="teal"
                            />
                        </span>
                        <span>
                            <StatusBadge
                                :label="caseLabel(visaCase.status)"
                                :tone="caseTone(visaCase.status)"
                            />
                            <span
                                v-if="visaCase.target_start_date"
                                class="mt-1.5 block text-[10px] text-muted-foreground"
                            >
                                {{
                                    t('employer.visa.targetDate', {
                                        date: formatDate(
                                            visaCase.target_start_date,
                                        ),
                                    })
                                }}
                            </span>
                        </span>
                        <ChevronDown
                            class="size-4 text-muted-foreground transition"
                            :class="{
                                'rotate-180': expandedCaseId === visaCase.id,
                            }"
                        />
                    </button>

                    <div
                        v-if="expandedCaseId === visaCase.id"
                        class="border-t border-border bg-muted p-4"
                    >
                        <div v-if="visaCase.steps?.length" class="space-y-2">
                            <div
                                v-for="step in visaCase.steps"
                                :key="step.id"
                                class="grid gap-3 rounded-xl bg-card p-3 ring-1 ring-border sm:grid-cols-2 lg:grid-cols-[1fr_11rem_10rem_12rem]"
                            >
                                <div>
                                    <p
                                        class="text-xs font-bold text-foreground"
                                    >
                                        {{ step.title }}
                                    </p>
                                    <p
                                        v-if="step.responsible_user"
                                        class="mt-1 text-[10px] text-muted-foreground"
                                    >
                                        {{
                                            t('employer.visa.responsible', {
                                                name: step.responsible_user
                                                    .name,
                                            })
                                        }}
                                    </p>
                                </div>
                                <select
                                    v-if="canManageVisa"
                                    :value="step.status"
                                    :aria-label="`${step.title}: ${stepLabel(step.status)}`"
                                    class="h-9 rounded-lg border border-border px-2 text-xs"
                                    @change="
                                        updateStepStatus(
                                            step,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option value="open">
                                        {{ stepLabel('open') }}
                                    </option>
                                    <option value="in_progress">
                                        {{ stepLabel('in_progress') }}
                                    </option>
                                    <option value="blocked">
                                        {{ stepLabel('blocked') }}
                                    </option>
                                    <option value="completed">
                                        {{ stepLabel('completed') }}
                                    </option>
                                    <option value="not_required">
                                        {{ stepLabel('not_required') }}
                                    </option>
                                </select>
                                <StatusBadge
                                    v-else
                                    :label="stepLabel(step.status)"
                                    :tone="
                                        step.status === 'completed'
                                            ? 'green'
                                            : step.status === 'blocked'
                                              ? 'red'
                                              : 'slate'
                                    "
                                />
                                <input
                                    v-if="canManageVisa"
                                    :value="step.due_at?.slice(0, 10) ?? ''"
                                    type="date"
                                    class="h-9 rounded-lg border border-border px-2 text-xs"
                                    :aria-label="t('employer.visa.deadline')"
                                    @change="
                                        updateStepDeadline(
                                            step,
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <span
                                    v-else
                                    class="self-center text-xs text-muted-foreground"
                                >
                                    {{
                                        step.due_at
                                            ? formatDate(step.due_at)
                                            : '—'
                                    }}
                                </span>
                                <select
                                    v-if="canManageVisa"
                                    v-model.number="step.responsible_user_id"
                                    :aria-label="
                                        t('employer.visa.responsiblePerson')
                                    "
                                    class="h-9 rounded-lg border border-border px-2 text-xs"
                                    @change="
                                        updateStepStatus(step, step.status)
                                    "
                                >
                                    <option :value="null">—</option>
                                    <option
                                        v-for="user in responsible_users"
                                        :key="user.id"
                                        :value="user.id"
                                    >
                                        {{ user.name }}
                                    </option>
                                </select>
                                <input
                                    v-if="canManageVisa"
                                    v-model="step.completion_evidence"
                                    :placeholder="
                                        t('employer.visa.completionEvidence')
                                    "
                                    class="h-9 rounded-lg border border-border px-2 text-xs sm:col-span-2 lg:col-span-2"
                                    @change="
                                        updateStepStatus(step, step.status)
                                    "
                                />
                                <input
                                    v-if="canManageVisa"
                                    v-model="step.blocker"
                                    :placeholder="t('employer.visa.blocker')"
                                    class="h-9 rounded-lg border border-border px-2 text-xs sm:col-span-2 lg:col-span-2"
                                    @change="
                                        updateStepStatus(step, step.status)
                                    "
                                />
                                <div
                                    v-if="step.tasks?.length"
                                    class="space-y-1 sm:col-span-2 lg:col-span-4"
                                >
                                    <p
                                        class="text-[10px] font-extrabold text-muted-foreground uppercase"
                                    >
                                        {{ t('employer.visa.tasksTitle') }}
                                    </p>
                                    <p
                                        v-for="task in step.tasks"
                                        :key="task.id"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ task.title }} ·
                                        {{ stepLabel(task.status) }} ·
                                        {{
                                            task.due_at
                                                ? formatDate(task.due_at)
                                                : '—'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div
                            v-if="visaCase.documents?.length"
                            class="mt-4 rounded-xl bg-card p-3 ring-1 ring-border"
                        >
                            <p
                                class="text-[10px] font-extrabold text-muted-foreground uppercase"
                            >
                                {{ t('employer.visa.documentsTitle') }}
                            </p>
                            <p
                                v-for="assignment in visaCase.documents"
                                :key="assignment.id"
                                class="mt-2 text-xs text-muted-foreground"
                            >
                                {{ assignment.document?.type }} ·
                                {{ assignment.document?.status }} ·
                                {{ assignment.document?.scan_result ?? '—' }} ·
                                {{ assignment.translation_status }}
                            </p>
                        </div>
                    </div>
                </article>
            </div>
            <div v-else class="py-14 text-center">
                <Plane class="mx-auto size-9 text-muted-foreground" />
                <h2 class="mt-4 font-bold">
                    {{ t('employer.visa.emptyTitle') }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ t('employer.visa.emptyDescription') }}
                </p>
            </div>
        </SectionCard>

        <SectionCard
            v-if="cases.some((visaCase) => visaCase.steps?.length)"
            :title="t('employer.visa.workflowTitle')"
            :description="t('employer.visa.workflowDescription')"
        >
            <div class="overflow-x-auto pb-2">
                <div
                    v-for="visaCase in cases.filter(
                        (item) => item.steps?.length,
                    )"
                    :key="visaCase.id"
                    class="mb-5 min-w-[760px] last:mb-0"
                >
                    <p class="mb-3 text-xs font-bold text-muted-foreground">
                        {{ candidateName(visaCase) }}
                    </p>
                    <div class="flex items-center">
                        <div
                            v-for="(step, index) in visaCase.steps"
                            :key="step.id"
                            class="flex flex-1 items-center"
                        >
                            <div class="text-center">
                                <span
                                    class="mx-auto grid size-8 place-items-center rounded-full text-xs font-bold"
                                    :class="
                                        ['completed', 'not_required'].includes(
                                            step.status,
                                        )
                                            ? 'bg-teal-500 text-[#0f172a]'
                                            : step.status === 'in_progress'
                                              ? 'bg-[var(--erin-primary)] text-[var(--erin-primary-foreground)] ring-4 ring-blue-100'
                                              : step.status === 'blocked'
                                                ? 'bg-red-500 text-white'
                                                : 'bg-muted text-muted-foreground'
                                    "
                                >
                                    <Check
                                        v-if="
                                            [
                                                'completed',
                                                'not_required',
                                            ].includes(step.status)
                                        "
                                        class="size-4"
                                    />
                                    <span v-else>{{ index + 1 }}</span>
                                </span>
                                <p
                                    class="mt-2 max-w-24 text-[9px] font-bold text-muted-foreground"
                                >
                                    {{ step.title }}
                                </p>
                            </div>
                            <div
                                v-if="index < (visaCase.steps?.length ?? 0) - 1"
                                class="mb-5 h-px flex-1 bg-border"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </SectionCard>
    </div>
</template>
