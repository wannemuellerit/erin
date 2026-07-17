<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertCircle, Check, ChevronDown, Clock3, Plane } from '@lucide/vue';
import { computed, ref } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
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
};

const props = withDefaults(defineProps<{ cases?: VisaCase[] }>(), {
    cases: () => [],
});
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

    return `${Math.round(
        durations.reduce((total, days) => total + days, 0) / durations.length,
    )} Tage`;
});
const candidateName = (visaCase: VisaCase) =>
    [
        visaCase.candidate_profile?.first_name,
        visaCase.candidate_profile?.last_name,
    ]
        .filter(Boolean)
        .join(' ') || `Fachkraft #${visaCase.candidate_profile?.id ?? '—'}`;
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
        },
        { preserveScroll: true },
    );
};
</script>

<template>
    <Head title="Visa & Relocation" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Relocation Management"
            title="Visa & Relocation"
            description="Steuern Sie alle Schritte vom Dokumentencheck bis zum erfolgreichen Arbeitsbeginn."
            :icon="Plane"
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                label="Aktive Visa-Fälle"
                :value="activeCases"
                :icon="Plane"
            />
            <MetricCard
                label="Planmäßig"
                :value="onTrackCases"
                :icon="Check"
                tone="teal"
            />
            <MetricCard
                label="Handlungsbedarf"
                :value="blockedCases"
                :icon="AlertCircle"
                tone="orange"
            />
            <MetricCard
                label="Ø Bearbeitungszeit"
                :value="averageDuration"
                :icon="Clock3"
                tone="violet"
            />
        </div>

        <SectionCard
            title="Visa-Fälle"
            description="Alle laufenden Relocation-Prozesse"
        >
            <div v-if="cases.length" class="space-y-3">
                <article
                    v-for="visaCase in cases"
                    :key="visaCase.id"
                    class="overflow-hidden rounded-xl border border-slate-200"
                >
                    <button
                        type="button"
                        class="grid w-full gap-4 p-4 text-left hover:bg-slate-50 lg:grid-cols-[1.1fr_1fr_0.75fr_auto] lg:items-center"
                        :aria-expanded="expandedCaseId === visaCase.id"
                        @click="
                            expandedCaseId =
                                expandedCaseId === visaCase.id
                                    ? null
                                    : visaCase.id
                        "
                    >
                        <span class="flex items-center gap-3">
                            <span
                                class="grid size-11 place-items-center rounded-xl bg-blue-50 text-xs font-extrabold text-[var(--erin-primary)]"
                            >
                                {{
                                    candidateName(visaCase)
                                        .slice(0, 2)
                                        .toUpperCase()
                                }}
                            </span>
                            <span>
                                <span
                                    class="block text-sm font-bold text-slate-900"
                                >
                                    {{ candidateName(visaCase) }}
                                </span>
                                <span class="text-xs text-slate-400">
                                    #VI-{{ visaCase.id }} ·
                                    {{
                                        visaCase.candidate_profile
                                            ?.current_position ??
                                        visaCase.application?.job_posting
                                            ?.title ??
                                        'Position nicht angegeben'
                                    }}
                                </span>
                            </span>
                        </span>
                        <span>
                            <span class="mb-2 flex justify-between text-xs">
                                <span class="font-bold text-slate-600">
                                    Fortschritt
                                </span>
                                <span class="text-slate-400">
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
                                class="mt-1.5 block text-[10px] text-slate-400"
                            >
                                Ziel:
                                {{
                                    new Intl.DateTimeFormat('de-DE', {
                                        dateStyle: 'medium',
                                    }).format(
                                        new Date(visaCase.target_start_date),
                                    )
                                }}
                            </span>
                        </span>
                        <ChevronDown
                            class="size-4 text-slate-400 transition"
                            :class="{
                                'rotate-180': expandedCaseId === visaCase.id,
                            }"
                        />
                    </button>

                    <div
                        v-if="expandedCaseId === visaCase.id"
                        class="border-t border-slate-200 bg-slate-50 p-4"
                    >
                        <div v-if="visaCase.steps?.length" class="space-y-2">
                            <div
                                v-for="step in visaCase.steps"
                                :key="step.id"
                                class="grid gap-3 rounded-xl bg-white p-3 ring-1 ring-slate-200 sm:grid-cols-[1fr_12rem_10rem]"
                            >
                                <div>
                                    <p class="text-xs font-bold text-slate-800">
                                        {{ step.title }}
                                    </p>
                                    <p
                                        v-if="step.responsible_user"
                                        class="mt-1 text-[10px] text-slate-400"
                                    >
                                        Verantwortlich:
                                        {{ step.responsible_user.name }}
                                    </p>
                                </div>
                                <select
                                    :value="step.status"
                                    class="h-9 rounded-lg border border-slate-200 px-2 text-xs"
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
                                <input
                                    :value="step.due_at?.slice(0, 10) ?? ''"
                                    type="date"
                                    class="h-9 rounded-lg border border-slate-200 px-2 text-xs"
                                    aria-label="Frist"
                                    @change="
                                        updateStepDeadline(
                                            step,
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                            </div>
                        </div>
                        <p
                            v-else
                            class="py-6 text-center text-sm text-slate-400"
                        >
                            Für diesen Fall sind noch keine Schritte angelegt.
                        </p>
                    </div>
                </article>
            </div>
            <div v-else class="py-14 text-center">
                <Plane class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">Noch keine Visa-Fälle</h2>
                <p class="mt-2 text-sm text-slate-500">
                    Ein Visa-Workflow entsteht, sobald eine Bewerbung in „Visa
                    in Bearbeitung“ verschoben wird.
                </p>
            </div>
        </SectionCard>

        <SectionCard
            v-if="cases.some((visaCase) => visaCase.steps?.length)"
            title="Visa-Workflow"
            description="Transparente Schritte bis zum Arbeitsbeginn"
        >
            <div class="overflow-x-auto pb-2">
                <div
                    v-for="visaCase in cases.filter(
                        (item) => item.steps?.length,
                    )"
                    :key="visaCase.id"
                    class="mb-5 min-w-[760px] last:mb-0"
                >
                    <p class="mb-3 text-xs font-bold text-slate-700">
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
                                            ? 'bg-teal-500 text-white'
                                            : step.status === 'in_progress'
                                              ? 'bg-[var(--erin-primary)] text-white ring-4 ring-blue-100'
                                              : step.status === 'blocked'
                                                ? 'bg-red-500 text-white'
                                                : 'bg-slate-100 text-slate-400'
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
                                    class="mt-2 max-w-24 text-[9px] font-bold text-slate-500"
                                >
                                    {{ step.title }}
                                </p>
                            </div>
                            <div
                                v-if="index < (visaCase.steps?.length ?? 0) - 1"
                                class="mb-5 h-px flex-1 bg-slate-200"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </SectionCard>
    </div>
</template>
