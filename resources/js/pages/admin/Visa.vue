<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Plane, Search, X } from '@lucide/vue';
import { reactive } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminVisa from '@/routes/admin/visa';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
import type { AdminPaginator } from './_shared';

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
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    company_id: props.filters.company_id?.toString() ?? '',
    assignee_id: props.filters.assignee_id?.toString() ?? '',
});

const { t, formatDate, humanize } = useAdminI18n();

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
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_10rem_10rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">{{ t('visa.searchLabel') }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('visa.searchPlaceholder')"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    :aria-label="t('visa.status')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
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
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <input
                    v-model="filters.assignee_id"
                    type="number"
                    min="1"
                    :placeholder="t('common.assigneeId')"
                    :aria-label="t('common.assigneeId')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
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
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="cases.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="visaCase in cases.data"
                            :key="visaCase.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p class="text-sm font-bold text-slate-900">
                                    {{ visaCase.candidate_profile.user.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        visaCase.candidate_profile
                                            .current_position ??
                                        t('visa.positionMissing')
                                    }}
                                </p>
                                <p class="mt-2 text-[11px] text-slate-600">
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
                                <p class="text-sm font-semibold text-slate-800">
                                    {{ visaCase.application.job_posting.title }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ visaCase.company.name }}
                                </p>
                                <StatusBadge
                                    class="mt-2"
                                    :label="
                                        humanize(visaCase.application.status)
                                    "
                                    :tone="
                                        statusTone(visaCase.application.status)
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
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p class="font-semibold text-slate-800">
                                    {{
                                        visaCase.assignee?.name ??
                                        t('common.notAssigned')
                                    }}
                                </p>
                                <p
                                    v-if="visaCase.assignee"
                                    class="mt-1 text-slate-600"
                                >
                                    {{ visaCase.assignee.email }}
                                </p>
                                <p class="mt-2 text-slate-600">
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
                                    class="mt-2 text-xs whitespace-nowrap text-slate-500"
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
                        </tr>
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
