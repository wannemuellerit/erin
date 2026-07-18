<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Search, ShieldAlert, X } from '@lucide/vue';
import { reactive } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminCompanies from '@/routes/admin/companies';
import AdminPagination from './_components/AdminPagination.vue';
import { useAdminI18n } from './_i18n';
import { cleanFilters, statusTone } from './_shared';
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

const { t, formatCurrency, formatDate, humanize } = useAdminI18n();

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
        const input = window.prompt(t('common.reasonPrompt'));

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
    <Head :title="t('companies.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('companies.eyebrow')"
            :title="t('companies.title')"
            :description="
                t('companies.description', { count: companies.total })
            "
            :icon="Building2"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 xl:grid-cols-[minmax(16rem,1fr)_10rem_12rem_10rem_11rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">{{
                        t('companies.searchLabel')
                    }}</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        :placeholder="t('companies.searchPlaceholder')"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    :aria-label="t('companies.companyStatus')"
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
                    v-model="filters.subscription_status"
                    type="text"
                    :placeholder="t('companies.subscriptionPlaceholder')"
                    :aria-label="t('companies.subscriptionStatus')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                />
                <input
                    v-model="filters.plan"
                    type="text"
                    :placeholder="t('companies.planPlaceholder')"
                    :aria-label="t('companies.plan')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                />
                <select
                    v-model="filters.sort"
                    :aria-label="t('companies.sorting')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="newest">{{ t('companies.newest') }}</option>
                    <option value="last_active">
                        {{ t('companies.lastActive') }}
                    </option>
                    <option value="name">{{ t('companies.name') }}</option>
                </select>
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        {{ t('common.filter') }}
                    </button>
                    <button
                        type="button"
                        :aria-label="t('common.resetFilters')"
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
                            <th class="px-5 py-3">
                                {{ t('companies.columns.company') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('companies.columns.plan') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('companies.columns.usage') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('companies.columns.activity') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('companies.columns.access') }}
                            </th>
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
                                <p class="mt-2 text-xs text-slate-600">
                                    {{
                                        [
                                            company.city,
                                            company.country_code,
                                            company.industry,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') ||
                                        t('companies.noProfileDetails')
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{
                                        company.plan?.name ??
                                        t('companies.noPlan')
                                    }}
                                </p>
                                <p
                                    v-if="company.plan"
                                    class="mt-1 text-xs text-slate-500"
                                >
                                    {{
                                        company.plan.price_cents === null
                                            ? t('common.onRequest')
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
                                <p class="mt-1 text-[11px] text-slate-600">
                                    {{
                                        t('companies.renewal', {
                                            date: formatDate(
                                                company.subscription_renews_at,
                                            ),
                                        })
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <p>
                                    {{
                                        t(
                                            'companies.teamMembers',
                                            company.memberships_count,
                                        )
                                    }}
                                </p>
                                <p class="mt-1">
                                    {{
                                        t(
                                            'companies.jobPostings',
                                            company.job_postings_count,
                                        )
                                    }}
                                </p>
                                <p v-if="company.employee_count" class="mt-1">
                                    {{
                                        t(
                                            'companies.employees',
                                            company.employee_count,
                                        )
                                    }}
                                </p>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>{{ formatDate(company.last_active_at) }}</p>
                                <p class="mt-1 text-slate-600">
                                    {{
                                        t('users.createdAt', {
                                            date: formatDate(
                                                company.created_at,
                                            ),
                                        })
                                    }}
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
                                    :aria-label="
                                        t('common.statusFor', {
                                            name: company.name,
                                        })
                                    "
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
            <EmptyState
                v-else
                :title="t('common.emptyTitle')"
                :description="t('common.emptyDescription')"
            />
            <AdminPagination :paginator="companies" />
        </SectionCard>
    </div>
</template>
