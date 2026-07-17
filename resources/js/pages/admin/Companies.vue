<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Search, ShieldAlert, X } from '@lucide/vue';
import { reactive } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminCompanies from '@/routes/admin/companies';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import {
    cleanFilters,
    formatCurrency,
    formatDate,
    humanize,
    statusTone,
} from './_shared';
import type { AdminPaginator } from './_shared';

type CompanyRow = {
    id: number;
    current_plan_id: number | null;
    name: string;
    slug: string;
    legal_name: string | null;
    email: string | null;
    industry: string | null;
    employee_count: number | null;
    country_code: string | null;
    city: string | null;
    status: string;
    subscription_status: string | null;
    subscription_renews_at: string | null;
    last_active_at: string | null;
    created_at: string;
    plan: {
        id: number;
        slug: string;
        name: string;
        price_cents: number | null;
        currency: string;
    } | null;
    memberships_count: number;
    job_postings_count: number;
};

type CompanyFilters = {
    search?: string;
    status?: string;
    subscription_status?: string;
    plan?: string;
    sort?: string;
};

const props = defineProps<{
    companies: AdminPaginator<CompanyRow>;
    filters: CompanyFilters;
    statuses: string[];
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    subscription_status: props.filters.subscription_status ?? '',
    plan: props.filters.plan ?? '',
    sort: props.filters.sort ?? 'newest',
});

const statusForm = useForm({
    status: '',
    reason: '',
});

function applyFilters(): void {
    router.get(adminCompanies.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminCompanies.index.url(), {}, { replace: true });
}

function updateStatus(company: CompanyRow, event: Event): void {
    const select = event.target as HTMLSelectElement;
    const nextStatus = select.value;

    if (nextStatus === company.status) {
        return;
    }

    let reason = '';

    if (['suspended', 'blocked'].includes(nextStatus)) {
        const input = window.prompt(
            'Bitte gib einen nachvollziehbaren Grund an (mindestens 5 Zeichen):',
        );

        if (input === null || input.trim().length < 5) {
            select.value = company.status;

            return;
        }

        reason = input.trim();
    }

    statusForm.status = nextStatus;
    statusForm.reason = reason;
    statusForm.patch(adminCompanies.status.update.url(company.id), {
        preserveScroll: true,
        onError: () => {
            select.value = company.status;
        },
        onFinish: () => statusForm.reset(),
    });
}
</script>

<template>
    <Head title="Unternehmen" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Mandanten"
            title="Unternehmen"
            :description="`${companies.total} Firmenkunden mit echten Paket-, Team- und Aktivitätsdaten.`"
            :icon="Building2"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 xl:grid-cols-[minmax(16rem,1fr)_10rem_12rem_10rem_11rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Unternehmen suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Name, Rechtsname oder E-Mail …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    aria-label="Firmenstatus"
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
                    v-model="filters.subscription_status"
                    type="text"
                    placeholder="Abo-Status"
                    aria-label="Abonnementstatus"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                />
                <input
                    v-model="filters.plan"
                    type="text"
                    placeholder="Paket-Slug"
                    aria-label="Paket"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                />
                <select
                    v-model="filters.sort"
                    aria-label="Sortierung"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="newest">Neueste zuerst</option>
                    <option value="last_active">Zuletzt aktiv</option>
                    <option value="name">Name</option>
                </select>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        Filtern
                    </button>
                    <button
                        type="button"
                        aria-label="Filter zurücksetzen"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div v-if="companies.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">Unternehmen</th>
                            <th class="px-5 py-3">Paket</th>
                            <th class="px-5 py-3">Nutzung</th>
                            <th class="px-5 py-3">Aktivität</th>
                            <th class="px-5 py-3">Zugang</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="company in companies.data"
                            :key="company.id"
                            class="align-top"
                        >
                            <td class="px-5 py-4">
                                <p class="text-sm font-bold text-slate-900">
                                    {{ company.name }}
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{
                                        company.legal_name ??
                                        company.email ??
                                        '—'
                                    }}
                                </p>
                                <p class="mt-2 text-xs text-slate-400">
                                    {{
                                        [
                                            company.city,
                                            company.country_code,
                                            company.industry,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                        'Keine Profildetails'
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{ company.plan?.name ?? 'Kein Paket' }}
                                </p>
                                <p
                                    v-if="company.plan"
                                    class="mt-1 text-xs text-slate-500"
                                >
                                    {{
                                        company.plan.price_cents === null
                                            ? 'Auf Anfrage'
                                            : formatCurrency(
                                                  company.plan.price_cents,
                                                  company.plan.currency,
                                              )
                                    }}
                                </p>
                                <StatusBadge
                                    class="mt-2"
                                    :label="
                                        humanize(company.subscription_status)
                                    "
                                    :tone="
                                        statusTone(company.subscription_status)
                                    "
                                />
                                <p class="mt-1 text-[11px] text-slate-400">
                                    Verlängerung
                                    {{
                                        formatDate(
                                            company.subscription_renews_at,
                                        )
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p>
                                    <strong class="text-slate-800">{{
                                        company.memberships_count
                                    }}</strong>
                                    Teammitglieder
                                </p>
                                <p class="mt-1">
                                    <strong class="text-slate-800">{{
                                        company.job_postings_count
                                    }}</strong>
                                    Stellenanzeigen
                                </p>
                                <p v-if="company.employee_count" class="mt-1">
                                    {{ company.employee_count }} Beschäftigte
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>{{ formatDate(company.last_active_at) }}</p>
                                <p class="mt-1 text-slate-400">
                                    Erstellt
                                    {{ formatDate(company.created_at) }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :label="humanize(company.status)"
                                    :tone="statusTone(company.status)"
                                />
                                <select
                                    :value="company.status"
                                    :disabled="statusForm.processing"
                                    :aria-label="`Status von ${company.name}`"
                                    class="erin-focus mt-2 block h-9 min-w-36 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold disabled:opacity-60"
                                    @change="updateStatus(company, $event)"
                                >
                                    <option
                                        v-for="status in statuses"
                                        :key="status"
                                        :value="status"
                                    >
                                        {{ humanize(status) }}
                                    </option>
                                </select>
                                <p
                                    v-if="
                                        statusForm.errors.status ||
                                        statusForm.errors.reason
                                    "
                                    class="mt-2 max-w-48 text-xs text-red-600"
                                >
                                    <ShieldAlert class="mr-1 inline size-3.5" />
                                    {{
                                        statusForm.errors.status ??
                                        statusForm.errors.reason
                                    }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AdminEmptyState v-else />
            <AdminPagination :paginator="companies" />
        </SectionCard>
    </div>
</template>
