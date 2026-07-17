<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, Plane, Search, X } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminVisa from '@/routes/admin/visa';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import { cleanFilters, formatDate, humanize, statusTone } from './_shared';
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
    <Head title="Visa-Fälle" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Relocation Operations"
            title="Visa-Fälle"
            :description="`${cases.total} Fälle mit realem Fortschritt, Verantwortlichen und Fristen.`"
            :icon="Plane"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_10rem_10rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Visa-Fälle suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Fachkraft oder Unternehmen …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    aria-label="Visa-Status"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Status</option>
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
                    placeholder="Firma-ID"
                    aria-label="Firma-ID"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <input
                    v-model="filters.assignee_id"
                    type="number"
                    min="1"
                    placeholder="Zuständig-ID"
                    aria-label="Zuständig-ID"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        Filtern
                    </button>
                    <button
                        type="button"
                        aria-label="Filter zurücksetzen"
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
                            <th class="px-5 py-3">Fall</th>
                            <th class="px-5 py-3">Stelle & Firma</th>
                            <th class="px-5 py-3">Fortschritt</th>
                            <th class="px-5 py-3">Zuständigkeit</th>
                            <th class="px-5 py-3">Status & Frist</th>
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
                                        'Position nicht hinterlegt'
                                    }}
                                </p>
                                <p class="mt-2 text-[11px] text-slate-400">
                                    Visa-Fall #{{ visaCase.id }} · Bewerbung #{{
                                        visaCase.application.id
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
                                    :label="`${visaCase.completed_steps_count} von ${visaCase.steps_count} Schritten`"
                                    tone="teal"
                                />
                                <p
                                    v-if="visaCase.overdue_steps_count > 0"
                                    class="mt-2 flex items-center gap-1 text-xs font-semibold text-red-600"
                                >
                                    <AlertTriangle class="size-3.5" />
                                    {{ visaCase.overdue_steps_count }}
                                    überfällig
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p class="font-semibold text-slate-800">
                                    {{
                                        visaCase.assignee?.name ??
                                        'Nicht zugewiesen'
                                    }}
                                </p>
                                <p
                                    v-if="visaCase.assignee"
                                    class="mt-1 text-slate-400"
                                >
                                    {{ visaCase.assignee.email }}
                                </p>
                                <p class="mt-2 text-slate-400">
                                    Start {{ formatDate(visaCase.started_at) }}
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
                                    Arbeitsstart
                                    {{ formatDate(visaCase.target_start_date) }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminEmptyState v-else />
            <AdminPagination :paginator="cases" />
        </SectionCard>
    </div>
</template>
