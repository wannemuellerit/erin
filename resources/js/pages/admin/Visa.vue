<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Plane, Search, X } from '@lucide/vue';
import { reactive, ref } from 'vue';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
import type { AdminPaginator } from './_shared';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import adminVisa from '@/routes/admin/visa';

type VisaCaseRow = {
    id: number;
    status: string;
    progress: number;
    target_start_date: string | null;
    started_at: string | null;
    completed_at: string | null;
    notes: string | null;
    created_at: string;
    company: {
        id: number;
        name: string;
        slug: string;
    };
    candidate_profile: {
        id: number;
        user_id: number;
        first_name: string | null;
        last_name: string | null;
        current_position: string | null;
        user: {
            id: number;
            name: string;
            email: string;
        };
    };
    application: {
        id: number;
        job_posting_id: number;
        status: string;
        job_posting: {
            id: number;
            title: string;
        };
    };
    assignee: {
        id: number;
        name: string;
        email: string;
    } | null;
    steps_count: number;
    completed_steps_count: number;
    overdue_steps_count: number;
    version: number;
    blocked_reason?: string | null;
    closed_reason?: string | null;
    credit_status?: string;
    steps?: Array<{
        id: number;
        title: string;
        status: string;
        due_at?: string | null;
        tasks?: Array<{
            id: number;
            title: string;
            status: string;
            due_at?: string | null;
        }>;
    }>;
    documents?: Array<{
        id: number;
        visa_step_id?: number | null;
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

type VisaFilters = {
    search?: string;
    status?: string;
    company_id?: number | string;
    assignee_id?: number | string;
};

const props = defineProps<{
    cases: AdminPaginator<VisaCaseRow>;
    filters: VisaFilters;
    statuses: string[];
    platform_assignees: Array<{ id: number; name: string; email: string }>;
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    company_id: props.filters.company_id?.toString() ?? '',
    assignee_id: props.filters.assignee_id?.toString() ?? '',
});

const { t, formatDate, humanize } = useAdminI18n();
const { can } = useCapabilities();
const canManage = can('platform.manage');
const expandedCaseId = ref<number | null>(null);

function updateCase(
    visaCase: VisaCaseRow,
    action: 'block' | 'resume' | 'not_required' | 'complete',
): void {
    const needsReason = action !== 'resume';
    const reason = needsReason ? window.prompt(t('visa.reasonPrompt')) : null;

    if (needsReason && (!reason || reason.trim().length < 5)) {
        return;
    }

    router.patch(`/admin/visa/${visaCase.id}`, {
        action,
        version: visaCase.version,
        reason,
    });
}

function assignCase(visaCase: VisaCaseRow, assignedTo: string): void {
    router.patch(`/admin/visa/${visaCase.id}`, {
        action: 'assign',
        version: visaCase.version,
        assigned_to: assignedTo ? Number(assignedTo) : null,
    });
}

function createTask(visaCase: VisaCaseRow, stepId: number): void {
    const title = window.prompt(t('visa.taskTitlePrompt'));

    if (!title?.trim()) {
        return;
    }

    const dueAt = window.prompt(t('visa.taskDuePrompt')) || null;

    router.post(`/admin/visa/${visaCase.id}/steps/${stepId}/tasks`, {
        title,
        due_at: dueAt,
        visibility: 'shared',
    });
}

function attachDocument(visaCase: VisaCaseRow): void {
    const documentId = window.prompt(t('visa.documentIdPrompt'));

    if (!documentId) {
        return;
    }

    router.post(`/admin/visa/${visaCase.id}/documents`, {
        candidate_document_id: Number(documentId),
        visibility: 'shared',
        translation_status: 'not_required',
        review_status: 'pending',
    });
}

function applyFilters(): void {
    router.get(adminVisa.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminVisa.index.url(), {}, { replace: true });
}
</script>

<template>
    <Head :title="t('visa.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('visa.eyebrow')"
            :title="t('visa.title')"
            :description="t('visa.description', { count: cases.total })"
            :icon="Plane"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-border p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_10rem_10rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">{{ t('visa.searchLabel') }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('visa.searchPlaceholder')"
                        class="erin-focus h-11 w-full rounded-xl border border-border pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    :aria-label="t('visa.status')"
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
                >
                    <option value="">{{ t('common.allStatuses') }}</option>
                    <option
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ humanize(status) }}
                    </option>
                </select>
                <input
                    v-model="filters.company_id"
                    type="number"
                    min="1"
                    :placeholder="t('common.companyId')"
                    :aria-label="t('common.companyId')"
                    class="erin-focus h-11 rounded-xl border border-border px-3 text-sm"
                />
                <input
                    v-model="filters.assignee_id"
                    type="number"
                    min="1"
                    :placeholder="t('common.assigneeId')"
                    :aria-label="t('common.assigneeId')"
                    class="erin-focus h-11 rounded-xl border border-border px-3 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        {{ t('common.filter') }}
                    </button>
                    <button
                        type="button"
                        :aria-label="t('common.resetFilters')"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-border text-muted-foreground"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="cases.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-left">
                    <thead class="bg-muted/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                        >
                            <th class="px-5 py-3">
                                {{ t('visa.columns.case') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('visa.columns.jobCompany') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('visa.columns.progress') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('visa.columns.responsibility') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('visa.columns.statusDeadline') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('visa.columns.actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <template
                            v-for="visaCase in cases.data"
                            :key="visaCase.id"
                        >
                            <tr
                                class="align-top"
                                :data-test="`admin-visa-case-${visaCase.id}`"
                            >
                                <td class="px-5 py-4">
                                    <p
                                        class="text-sm font-bold text-foreground"
                                    >
                                        {{
                                            visaCase.candidate_profile.user.name
                                        }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            visaCase.candidate_profile
                                                .current_position ??
                                            t('visa.positionMissing')
                                        }}
                                    </p>
                                    <p
                                        class="mt-2 text-[11px] text-muted-foreground"
                                    >
                                        {{
                                            t('visa.caseReference', {
                                                caseId: visaCase.id,
                                                applicationId:
                                                    visaCase.application.id,
                                            })
                                        }}
                                    </p>
                                </td>
                                <td class="px-5 py-4">
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{
                                            visaCase.application.job_posting
                                                .title
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ visaCase.company.name }}
                                    </p>
                                    <StatusBadge
                                        class="mt-2"
                                        :label="
                                            humanize(
                                                visaCase.application.status,
                                            )
                                        "
                                        :tone="
                                            statusTone(
                                                visaCase.application.status,
                                            )
                                        "
                                    />
                                </td>
                                <td class="min-w-56 px-5 py-4">
                                    <ProgressBar
                                        :value="visaCase.progress"
                                        :label="
                                            t('visa.steps', {
                                                completed:
                                                    visaCase.completed_steps_count,
                                                total: visaCase.steps_count,
                                            })
                                        "
                                        tone="teal"
                                    />
                                    <p
                                        v-if="visaCase.overdue_steps_count > 0"
                                        class="mt-2 flex items-center gap-1 text-xs font-semibold text-red-600"
                                    >
                                        <AlertTriangle class="size-3.5" />
                                        {{
                                            t('visa.overdue', {
                                                count: visaCase.overdue_steps_count,
                                            })
                                        }}
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-4 text-xs text-muted-foreground"
                                >
                                    <p class="font-semibold text-foreground">
                                        {{
                                            visaCase.assignee?.name ??
                                            t('common.notAssigned')
                                        }}
                                    </p>
                                    <p
                                        v-if="visaCase.assignee"
                                        class="mt-1 text-muted-foreground"
                                    >
                                        {{ visaCase.assignee.email }}
                                    </p>
                                    <p class="mt-2 text-muted-foreground">
                                        {{
                                            t('visa.start', {
                                                date: formatDate(
                                                    visaCase.started_at,
                                                ),
                                            })
                                        }}
                                    </p>
                                </td>
                                <td class="px-5 py-4">
                                    <StatusBadge
                                        :label="humanize(visaCase.status)"
                                        :tone="statusTone(visaCase.status)"
                                    />
                                    <p
                                        class="mt-2 text-xs whitespace-nowrap text-muted-foreground"
                                    >
                                        {{
                                            t('visa.workStart', {
                                                date: formatDate(
                                                    visaCase.target_start_date,
                                                ),
                                            })
                                        }}
                                    </p>
                                </td>
                                <td class="min-w-64 px-5 py-4">
                                    <div v-if="canManage" class="space-y-2">
                                        <select
                                            :value="visaCase.assignee?.id ?? ''"
                                            :aria-label="t('visa.assign')"
                                            class="h-9 w-full rounded-lg border border-border px-2 text-xs"
                                            @change="
                                                assignCase(
                                                    visaCase,
                                                    (
                                                        $event.target as HTMLSelectElement
                                                    ).value,
                                                )
                                            "
                                        >
                                            <option value="">
                                                {{ t('common.notAssigned') }}
                                            </option>
                                            <option
                                                v-for="assignee in platform_assignees"
                                                :key="assignee.id"
                                                :value="assignee.id"
                                            >
                                                {{ assignee.name }}
                                            </option>
                                        </select>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button
                                                v-if="
                                                    visaCase.status !==
                                                    'blocked'
                                                "
                                                type="button"
                                                :data-test="`admin-visa-block-${visaCase.id}`"
                                                class="rounded-lg border border-red-200 px-2 py-1 text-xs font-bold text-red-700"
                                                @click="
                                                    updateCase(
                                                        visaCase,
                                                        'block',
                                                    )
                                                "
                                            >
                                                {{ t('visa.actions.block') }}
                                            </button>
                                            <button
                                                v-else
                                                type="button"
                                                :data-test="`admin-visa-resume-${visaCase.id}`"
                                                class="rounded-lg border border-blue-200 px-2 py-1 text-xs font-bold text-[var(--erin-primary-text-hover)]"
                                                @click="
                                                    updateCase(
                                                        visaCase,
                                                        'resume',
                                                    )
                                                "
                                            >
                                                {{ t('visa.actions.resume') }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg border border-border px-2 py-1 text-xs font-bold"
                                                @click="
                                                    updateCase(
                                                        visaCase,
                                                        'not_required',
                                                    )
                                                "
                                            >
                                                {{
                                                    t(
                                                        'visa.actions.notRequired',
                                                    )
                                                }}
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg border border-emerald-200 px-2 py-1 text-xs font-bold text-emerald-700"
                                                @click="
                                                    updateCase(
                                                        visaCase,
                                                        'complete',
                                                    )
                                                "
                                            >
                                                {{ t('visa.actions.complete') }}
                                            </button>
                                        </div>
                                    </div>
                                    <div
                                        class="mt-2 flex gap-2 text-xs font-bold"
                                    >
                                        <button
                                            type="button"
                                            :data-test="`admin-visa-details-${visaCase.id}`"
                                            class="text-[var(--erin-primary-text-hover)]"
                                            @click="
                                                expandedCaseId =
                                                    expandedCaseId ===
                                                    visaCase.id
                                                        ? null
                                                        : visaCase.id
                                            "
                                        >
                                            {{ t('visa.details') }}
                                        </button>
                                        <a
                                            v-if="canManage"
                                            :href="`/admin/visa/${visaCase.id}/export`"
                                            class="text-[var(--erin-primary-text-hover)]"
                                            >{{ t('visa.export') }}</a
                                        >
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="expandedCaseId === visaCase.id">
                                <td colspan="6" class="bg-muted px-5 py-4">
                                    <div class="grid gap-4 lg:grid-cols-2">
                                        <section>
                                            <h3
                                                class="text-xs font-extrabold text-muted-foreground uppercase"
                                            >
                                                {{ t('visa.tasksTitle') }}
                                            </h3>
                                            <div
                                                v-for="step in visaCase.steps ??
                                                []"
                                                :key="step.id"
                                                class="mt-2 rounded-xl bg-card p-3 ring-1 ring-border"
                                            >
                                                <div
                                                    class="flex justify-between gap-3"
                                                >
                                                    <span
                                                        class="text-xs font-bold"
                                                        >{{ step.title }}</span
                                                    >
                                                    <button
                                                        v-if="canManage"
                                                        type="button"
                                                        class="text-xs font-bold text-[var(--erin-primary-text-hover)]"
                                                        @click="
                                                            createTask(
                                                                visaCase,
                                                                step.id,
                                                            )
                                                        "
                                                    >
                                                        {{ t('visa.addTask') }}
                                                    </button>
                                                </div>
                                                <p
                                                    v-for="task in step.tasks ??
                                                    []"
                                                    :key="task.id"
                                                    class="mt-2 text-xs text-muted-foreground"
                                                >
                                                    {{ task.title }} ·
                                                    {{ humanize(task.status) }}
                                                    ·
                                                    {{
                                                        formatDate(task.due_at)
                                                    }}
                                                </p>
                                            </div>
                                        </section>
                                        <section>
                                            <div
                                                class="flex justify-between gap-3"
                                            >
                                                <h3
                                                    class="text-xs font-extrabold text-muted-foreground uppercase"
                                                >
                                                    {{
                                                        t('visa.documentsTitle')
                                                    }}
                                                </h3>
                                                <button
                                                    v-if="canManage"
                                                    type="button"
                                                    class="text-xs font-bold text-[var(--erin-primary-text-hover)]"
                                                    @click="
                                                        attachDocument(visaCase)
                                                    "
                                                >
                                                    {{
                                                        t('visa.attachDocument')
                                                    }}
                                                </button>
                                            </div>
                                            <p
                                                v-for="assignment in visaCase.documents ??
                                                []"
                                                :key="assignment.id"
                                                class="mt-2 rounded-xl bg-card p-3 text-xs text-muted-foreground ring-1 ring-border"
                                            >
                                                {{
                                                    humanize(
                                                        assignment.document
                                                            ?.type ?? '',
                                                    )
                                                }}
                                                ·
                                                {{
                                                    humanize(
                                                        assignment.document
                                                            ?.status ?? '',
                                                    )
                                                }}
                                                ·
                                                {{
                                                    humanize(
                                                        assignment.translation_status,
                                                    )
                                                }}
                                            </p>
                                        </section>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                :title="t('common.emptyTitle')"
                :description="t('common.emptyDescription')"
            />
            <AdminPagination :paginator="cases" />
        </SectionCard>
    </div>
</template>
