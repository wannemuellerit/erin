<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CircleDollarSign,
    CreditCard,
    PencilLine,
    Search,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminBilling from '@/routes/admin/billing';
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

type UsagePeriod = {
    id: number;
    starts_at: string;
    ends_at: string;
    ai_credits_used: number;
    job_boosts_used: number;
    visa_credits_used: number;
};

type BillingCompanyRow = {
    id: number;
    current_plan_id: number | null;
    name: string;
    stripe_id: string | null;
    subscription_status: string | null;
    subscription_started_at: string | null;
    subscription_renews_at: string | null;
    cancel_at_period_end: boolean;
    subscription_ends_at: string | null;
    plan: {
        id: number;
        slug: string;
        name: string;
        price_cents: number | null;
        currency: string;
        term_months: number | null;
    } | null;
    usage_periods: UsagePeriod[];
};

type PlanRow = {
    id: number;
    slug: string;
    name: string;
    description: string | null;
    price_cents: number | null;
    currency: string;
    term_months: number | null;
    active_jobs_limit: number | null;
    seat_limit: number | null;
    ai_credits_monthly: number | null;
    job_boosts_per_term: number | null;
    visa_credits_per_term: number | null;
    is_enterprise: boolean;
    is_active: boolean;
    stripe_product_id: string | null;
    stripe_price_id: string | null;
    features: string[] | null;
    companies_count: number;
};

type BillingFilters = {
    search?: string;
    subscription_status?: string;
    plan_id?: number | string;
};

const props = defineProps<{
    companies: AdminPaginator<BillingCompanyRow>;
    plans: PlanRow[];
    filters: BillingFilters;
    summary: {
        active: number;
        past_due: number;
        cancelling: number;
        contract_value_cents: number;
    };
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    subscription_status: props.filters.subscription_status ?? '',
    plan_id: props.filters.plan_id?.toString() ?? '',
});

const selectedPlanId = ref<number | null>(props.plans[0]?.id ?? null);
const selectedPlan = computed(
    () =>
        props.plans.find((plan) => plan.id === selectedPlanId.value) ??
        props.plans[0] ??
        null,
);

const featureText = ref('');
const planForm = useForm({
    name: '',
    description: '',
    price_cents: '',
    currency: 'EUR',
    term_months: '',
    active_jobs_limit: '',
    seat_limit: '',
    ai_credits_monthly: '',
    job_boosts_per_term: '',
    visa_credits_per_term: '',
    is_active: true,
    stripe_product_id: '',
    stripe_price_id: '',
    features: [] as string[],
});

const firstPlanError = computed(
    () => Object.values(planForm.errors)[0] as string | undefined,
);

watch(
    selectedPlan,
    (plan) => {
        planForm.clearErrors();

        if (!plan) {
            planForm.reset();
            featureText.value = '';

            return;
        }

        planForm.name = plan.name;
        planForm.description = plan.description ?? '';
        planForm.price_cents = plan.price_cents?.toString() ?? '';
        planForm.currency = plan.currency;
        planForm.term_months = plan.term_months?.toString() ?? '';
        planForm.active_jobs_limit = plan.active_jobs_limit?.toString() ?? '';
        planForm.seat_limit = plan.seat_limit?.toString() ?? '';
        planForm.ai_credits_monthly = plan.ai_credits_monthly?.toString() ?? '';
        planForm.job_boosts_per_term =
            plan.job_boosts_per_term?.toString() ?? '';
        planForm.visa_credits_per_term =
            plan.visa_credits_per_term?.toString() ?? '';
        planForm.is_active = plan.is_active;
        planForm.stripe_product_id = plan.stripe_product_id ?? '';
        planForm.stripe_price_id = plan.stripe_price_id ?? '';
        planForm.features = [...(plan.features ?? [])];
        featureText.value = (plan.features ?? []).join('\n');
    },
    { immediate: true },
);

function applyFilters(): void {
    router.get(adminBilling.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminBilling.index.url(), {}, { replace: true });
}

function submitPlan(): void {
    if (!selectedPlan.value) {
        return;
    }

    planForm.features = featureText.value
        .split('\n')
        .map((feature) => feature.trim())
        .filter(Boolean);

    planForm.patch(adminBilling.plans.update.url(selectedPlan.value.id), {
        preserveScroll: true,
    });
}

function quota(value: number | null): string {
    return value === null ? 'Unbegrenzt' : value.toLocaleString('de-DE');
}
</script>

<template>
    <Head title="Abrechnung" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Billing Operations"
            title="Abrechnung & Stripe"
            :description="`${companies.total} Firmenabrechnungen und ${plans.length} konfigurierte Pakete.`"
            :icon="CreditCard"
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                label="Aktive Abonnements"
                :value="summary.active"
                :icon="CreditCard"
            />
            <MetricCard
                label="Zahlung überfällig"
                :value="summary.past_due"
                :icon="TriangleAlert"
                tone="orange"
            />
            <MetricCard
                label="Vorgemerkte Kündigungen"
                :value="summary.cancelling"
                :icon="TriangleAlert"
                tone="violet"
            />
            <MetricCard
                label="Vertragswert"
                :value="formatCurrency(summary.contract_value_cents)"
                hint="aktive und überfällige Pakete"
                :icon="CircleDollarSign"
                tone="teal"
            />
        </div>

        <SectionCard
            title="Firmenabrechnung"
            description="Abonnementstatus, Verlängerung und aktuelle Kontingentnutzung."
            flush
        >
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_13rem_13rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Firma suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Unternehmen suchen …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <input
                    v-model="filters.subscription_status"
                    type="text"
                    placeholder="Abonnementstatus"
                    aria-label="Abonnementstatus"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <select
                    v-model="filters.plan_id"
                    aria-label="Paket"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Pakete</option>
                    <option
                        v-for="plan in plans"
                        :key="plan.id"
                        :value="plan.id"
                    >
                        {{ plan.name }}
                    </option>
                </select>
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

            <div v-if="companies.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">Unternehmen</th>
                            <th class="px-5 py-3">Paket</th>
                            <th class="px-5 py-3">Abonnement</th>
                            <th class="px-5 py-3">Aktuelle Nutzung</th>
                            <th class="px-5 py-3">Zeitraum</th>
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
                                <p
                                    class="mt-1 font-mono text-[11px] text-slate-400"
                                >
                                    {{ company.stripe_id ?? 'Keine Stripe-ID' }}
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
                                    ·
                                    {{
                                        company.plan.term_months
                                            ? `${company.plan.term_months} Monate`
                                            : 'individuell'
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <StatusBadge
                                    :label="
                                        humanize(company.subscription_status)
                                    "
                                    :tone="
                                        statusTone(company.subscription_status)
                                    "
                                />
                                <p
                                    v-if="company.cancel_at_period_end"
                                    class="mt-2 text-xs font-semibold text-orange-600"
                                >
                                    Kündigung vorgemerkt
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <template v-if="company.usage_periods[0]">
                                    <p>
                                        KI:
                                        {{
                                            company.usage_periods[0]
                                                .ai_credits_used
                                        }}
                                    </p>
                                    <p class="mt-1">
                                        Boosts:
                                        {{
                                            company.usage_periods[0]
                                                .job_boosts_used
                                        }}
                                    </p>
                                    <p class="mt-1">
                                        Visa:
                                        {{
                                            company.usage_periods[0]
                                                .visa_credits_used
                                        }}
                                    </p>
                                </template>
                                <span v-else class="text-slate-400">
                                    Kein Nutzungszeitraum
                                </span>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>
                                    Start
                                    {{
                                        formatDate(
                                            company.subscription_started_at,
                                        )
                                    }}
                                </p>
                                <p class="mt-1">
                                    Verlängerung
                                    {{
                                        formatDate(
                                            company.subscription_renews_at,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="company.subscription_ends_at"
                                    class="mt-1"
                                >
                                    Ende
                                    {{
                                        formatDate(company.subscription_ends_at)
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

        <SectionCard
            title="Paketverwaltung"
            description="Änderungen werden an das vorhandene Plan-Update übergeben und auditiert."
            flush
        >
            <div
                v-if="plans.length > 0"
                class="grid xl:grid-cols-[20rem_minmax(0,1fr)]"
            >
                <aside
                    class="border-b border-slate-200 p-3 xl:border-r xl:border-b-0"
                >
                    <button
                        v-for="plan in plans"
                        :key="plan.id"
                        type="button"
                        class="mb-2 w-full rounded-xl border p-4 text-left transition last:mb-0"
                        :class="
                            selectedPlan?.id === plan.id
                                ? 'border-blue-200 bg-blue-50'
                                : 'border-slate-200 hover:bg-slate-50'
                        "
                        @click="selectedPlanId = plan.id"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-bold text-slate-900">
                                    {{ plan.name }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{
                                        plan.price_cents === null
                                            ? 'Auf Anfrage'
                                            : formatCurrency(
                                                  plan.price_cents,
                                                  plan.currency,
                                              )
                                    }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="plan.is_active ? 'Aktiv' : 'Inaktiv'"
                                :tone="plan.is_active ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-3 text-[11px] text-slate-400">
                            {{ plan.companies_count }} Unternehmen ·
                            {{ plan.slug }}
                        </p>
                    </button>
                </aside>

                <form
                    v-if="selectedPlan"
                    class="p-5 sm:p-6"
                    @submit.prevent="submitPlan"
                >
                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:justify-between"
                    >
                        <div>
                            <p class="text-xs font-bold text-blue-600">
                                Paket #{{ selectedPlan.id }} ·
                                {{ selectedPlan.slug }}
                            </p>
                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                {{ selectedPlan.name }} bearbeiten
                            </h3>
                        </div>
                        <div
                            class="flex items-center gap-2 text-xs font-semibold text-slate-500"
                        >
                            <PencilLine class="size-4" />
                            {{
                                selectedPlan.is_enterprise
                                    ? 'Enterprise-Paket'
                                    : 'Standardpaket'
                            }}
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <label class="xl:col-span-2">
                            <span class="text-xs font-bold text-slate-600"
                                >Name</span
                            >
                            <input
                                v-model="planForm.name"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600"
                                >Währung</span
                            >
                            <input
                                v-model="planForm.currency"
                                maxlength="3"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm uppercase"
                            />
                        </label>
                        <label class="md:col-span-2 xl:col-span-3">
                            <span class="text-xs font-bold text-slate-600">
                                Beschreibung
                            </span>
                            <textarea
                                v-model="planForm.description"
                                rows="3"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Preis in Cent
                            </span>
                            <input
                                v-model="planForm.price_cents"
                                type="number"
                                min="0"
                                placeholder="Leer = auf Anfrage"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Laufzeit Monate
                            </span>
                            <input
                                v-model="planForm.term_months"
                                type="number"
                                min="1"
                                placeholder="Unbegrenzt"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Aktive Jobs
                            </span>
                            <input
                                v-model="planForm.active_jobs_limit"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600"
                                >Sitze</span
                            >
                            <input
                                v-model="planForm.seat_limit"
                                type="number"
                                min="1"
                                :placeholder="quota(null)"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                KI-Credits / Monat
                            </span>
                            <input
                                v-model="planForm.ai_credits_monthly"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Boosts / Laufzeit
                            </span>
                            <input
                                v-model="planForm.job_boosts_per_term"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Visa-Credits / Laufzeit
                            </span>
                            <input
                                v-model="planForm.visa_credits_per_term"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Stripe Product-ID
                            </span>
                            <input
                                v-model="planForm.stripe_product_id"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </label>
                        <label>
                            <span class="text-xs font-bold text-slate-600">
                                Stripe Price-ID
                            </span>
                            <input
                                v-model="planForm.stripe_price_id"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </label>
                        <label class="md:col-span-2 xl:col-span-3">
                            <span class="text-xs font-bold text-slate-600">
                                Features, eine Zeile pro Eintrag
                            </span>
                            <textarea
                                v-model="featureText"
                                rows="5"
                                class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                            />
                        </label>
                    </div>

                    <div
                        class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <label
                            class="flex items-center gap-2 text-sm font-semibold text-slate-700"
                        >
                            <input
                                v-model="planForm.is_active"
                                type="checkbox"
                                class="size-4 rounded border-slate-300 text-blue-600"
                            />
                            Paket aktiv
                        </label>
                        <div class="text-right">
                            <p
                                v-if="firstPlanError"
                                class="mb-2 text-xs text-red-600"
                            >
                                {{ firstPlanError }}
                            </p>
                            <button
                                type="submit"
                                :disabled="planForm.processing"
                                class="erin-focus h-10 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white disabled:opacity-50"
                            >
                                Paket speichern
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <AdminEmptyState
                v-else
                title="Keine Pakete konfiguriert"
                description="Es wurden noch keine Tarifdatensätze angelegt."
            />
        </SectionCard>
    </div>
</template>
