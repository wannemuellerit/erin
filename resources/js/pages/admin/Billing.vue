<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    CircleDollarSign,
    CreditCard,
    PencilLine,
    ShieldCheck,
    TriangleAlert,
    Webhook,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/product/EmptyState.vue';
import FormField from '@/components/product/FormField.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import adminBilling from '@/routes/admin/billing';
import { useAdminI18n } from './_i18n';
import AdminPagination from './_components/AdminPagination.vue';
import { cleanFilters, statusTone } from './_shared';
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
    features: Record<string, string | number | boolean> | string[] | null;
    companies_count: number;
};

type BillingFilters = {
    search?: string;
    subscription_status?: string;
    plan_id?: number | string;
};

type StripeConfiguration = {
    mode: 'test' | 'live' | 'unknown';
    publishable_key: boolean;
    secret_key: boolean;
    webhook_secret: boolean;
    seat_price: boolean;
    visa_price: boolean;
    launch_prices_configured: number;
    launch_prices_total: number;
    ready: boolean;
    plans: Array<{
        slug: string;
        name: string;
        product: boolean;
        price: boolean;
    }>;
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
    stripe_configuration: StripeConfiguration;
}>();
const { t, formatCurrency, formatDate, formatNumber, humanize } =
    useAdminI18n();

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
    features: {} as Record<string, string>,
});

const firstPlanError = computed(
    () => Object.values(planForm.errors)[0] as string | undefined,
);

const featureLines = (features: PlanRow['features']): string[] => {
    if (!features) {
        return [];
    }

    if (Array.isArray(features)) {
        return features;
    }

    return Object.entries(features).map(
        ([key, value]) => `${key}=${String(value)}`,
    );
};

const parseFeatureLines = (value: string): Record<string, string> =>
    Object.fromEntries(
        value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean)
            .map((line) => {
                const separator = line.indexOf('=');

                if (separator < 0) {
                    return [line, 'true'];
                }

                return [
                    line.slice(0, separator).trim(),
                    line.slice(separator + 1).trim(),
                ];
            })
            .filter(([key]) => key !== ''),
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
        const lines = featureLines(plan.features);

        planForm.features = parseFeatureLines(lines.join('\n'));
        featureText.value = lines.join('\n');
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

    planForm.features = parseFeatureLines(featureText.value);

    planForm.patch(adminBilling.plans.update.url(selectedPlan.value.id), {
        preserveScroll: true,
    });
}

function quota(value: number | null): string {
    return value === null ? t('billing.unlimited') : formatNumber(value);
}

const configurationItems = computed(() => [
    {
        label: t('billing.stripe.publishableKey'),
        configured: props.stripe_configuration.publishable_key,
    },
    {
        label: t('billing.stripe.secretKey'),
        configured: props.stripe_configuration.secret_key,
    },
    {
        label: t('billing.stripe.webhookSecret'),
        configured: props.stripe_configuration.webhook_secret,
    },
    {
        label: t('billing.stripe.seatPrice'),
        configured: props.stripe_configuration.seat_price,
    },
    {
        label: t('billing.stripe.visaPrice'),
        configured: props.stripe_configuration.visa_price,
    },
]);
</script>

<template>
    <Head :title="t('billing.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('billing.eyebrow')"
            :title="t('billing.title')"
            :description="
                t('billing.description', {
                    companies: companies.total,
                    plans: plans.length,
                })
            "
            :icon="CreditCard"
        />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                :label="t('billing.metrics.active')"
                :value="summary.active"
                :icon="CreditCard"
            />
            <MetricCard
                :label="t('billing.metrics.pastDue')"
                :value="summary.past_due"
                :icon="TriangleAlert"
                tone="orange"
            />
            <MetricCard
                :label="t('billing.metrics.cancelling')"
                :value="summary.cancelling"
                :icon="TriangleAlert"
                tone="violet"
            />
            <MetricCard
                :label="t('billing.metrics.contractValue')"
                :value="formatCurrency(summary.contract_value_cents)"
                :hint="t('billing.metrics.contractValueHint')"
                :icon="CircleDollarSign"
                tone="teal"
            />
        </div>

        <SectionCard
            :title="t('billing.stripe.title')"
            :description="t('billing.stripe.description')"
        >
            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex items-start gap-3">
                    <div
                        class="grid size-11 shrink-0 place-items-center rounded-xl"
                        :class="
                            stripe_configuration.ready
                                ? 'bg-emerald-50 text-emerald-600'
                                : 'bg-orange-50 text-orange-600'
                        "
                    >
                        <ShieldCheck class="size-5" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-bold text-slate-950">
                                {{
                                    stripe_configuration.ready
                                        ? t('billing.stripe.ready')
                                        : t('billing.stripe.incomplete')
                                }}
                            </h3>
                            <StatusBadge
                                :label="
                                    t(
                                        `billing.stripe.mode.${stripe_configuration.mode}`,
                                    )
                                "
                                :tone="
                                    stripe_configuration.mode === 'live'
                                        ? 'green'
                                        : stripe_configuration.mode === 'test'
                                          ? 'blue'
                                          : 'slate'
                                "
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{
                                t('billing.stripe.planCoverage', {
                                    configured:
                                        stripe_configuration.launch_prices_configured,
                                    total: stripe_configuration.launch_prices_total,
                                })
                            }}
                        </p>
                    </div>
                </div>
                <p
                    class="max-w-xl rounded-xl bg-slate-50 px-4 py-3 text-xs leading-5 text-slate-500"
                >
                    {{ t('billing.stripe.noSecrets') }}
                </p>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div
                    v-for="item in configurationItems"
                    :key="item.label"
                    class="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-3 text-xs font-semibold"
                    :class="
                        item.configured ? 'text-emerald-700' : 'text-orange-700'
                    "
                >
                    <BadgeCheck v-if="item.configured" class="size-4" />
                    <TriangleAlert v-else class="size-4" />
                    {{ item.label }}
                </div>
            </div>

            <div
                class="mt-5 overflow-x-auto rounded-xl border border-slate-200"
            >
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-4 py-3">
                                {{ t('billing.stripe.plan') }}
                            </th>
                            <th class="px-4 py-3">
                                {{ t('billing.stripe.product') }}
                            </th>
                            <th class="px-4 py-3">
                                {{ t('billing.stripe.price') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr
                            v-for="plan in stripe_configuration.plans"
                            :key="plan.slug"
                        >
                            <td class="px-4 py-3 text-sm font-semibold">
                                {{ plan.name }}
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge
                                    :label="
                                        plan.product
                                            ? t('billing.stripe.configured')
                                            : t('billing.stripe.missing')
                                    "
                                    :tone="plan.product ? 'green' : 'orange'"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <StatusBadge
                                    :label="
                                        plan.price
                                            ? t('billing.stripe.configured')
                                            : t('billing.stripe.missing')
                                    "
                                    :tone="plan.price ? 'green' : 'orange'"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                class="mt-5 flex items-start gap-3 rounded-xl border border-blue-100 bg-blue-50/70 p-4"
            >
                <Webhook class="mt-0.5 size-4 shrink-0 text-blue-600" />
                <p class="text-xs leading-5 text-blue-900">
                    {{ t('billing.stripe.commandHint') }}
                </p>
            </div>
        </SectionCard>

        <SectionCard
            :title="t('billing.companies.title')"
            :description="t('billing.companies.description')"
            flush
        >
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_13rem_13rem_auto]"
                @submit.prevent="applyFilters"
            >
                <SearchField
                    v-model="filters.search"
                    :label="t('billing.companies.searchLabel')"
                    :placeholder="t('billing.companies.searchPlaceholder')"
                />
                <input
                    v-model="filters.subscription_status"
                    type="text"
                    :placeholder="t('billing.companies.subscriptionStatus')"
                    :aria-label="t('billing.companies.subscriptionStatus')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <select
                    v-model="filters.plan_id"
                    :aria-label="t('billing.companies.plan')"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">
                        {{ t('billing.companies.allPlans') }}
                    </option>
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

            <div v-if="companies.data.length > 0" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-left">
                    <thead class="bg-slate-50/80">
                        <tr
                            class="text-[11px] font-bold tracking-wide text-slate-500 uppercase"
                        >
                            <th class="px-5 py-3">
                                {{ t('billing.companies.columns.company') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('billing.companies.columns.plan') }}
                            </th>
                            <th class="px-5 py-3">
                                {{
                                    t('billing.companies.columns.subscription')
                                }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('billing.companies.columns.usage') }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t('billing.companies.columns.period') }}
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
                                <p
                                    class="mt-1 font-mono text-[11px] text-slate-600"
                                >
                                    {{
                                        company.stripe_id ??
                                        t('billing.companies.noStripeId')
                                    }}
                                </p>
                            </td>
                            <td class="px-5 py-4">
                                <p class="text-sm font-semibold text-slate-800">
                                    {{
                                        company.plan?.name ??
                                        t('billing.companies.noPlan')
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
                                    ·
                                    {{
                                        company.plan.term_months
                                            ? t('billing.months', {
                                                  count: company.plan
                                                      .term_months,
                                              })
                                            : t('billing.individual')
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
                                    {{ t('billing.companies.cancelScheduled') }}
                                </p>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-600">
                                <template v-if="company.usage_periods[0]">
                                    <p>
                                        {{ t('billing.companies.usage.ai') }}:
                                        {{
                                            company.usage_periods[0]
                                                .ai_credits_used
                                        }}
                                    </p>
                                    <p class="mt-1">
                                        {{
                                            t('billing.companies.usage.boosts')
                                        }}:
                                        {{
                                            company.usage_periods[0]
                                                .job_boosts_used
                                        }}
                                    </p>
                                    <p class="mt-1">
                                        {{ t('billing.companies.usage.visa') }}:
                                        {{
                                            company.usage_periods[0]
                                                .visa_credits_used
                                        }}
                                    </p>
                                </template>
                                <span v-else class="text-slate-600">
                                    {{ t('billing.companies.noUsagePeriod') }}
                                </span>
                            </td>
                            <td
                                class="px-5 py-4 text-xs whitespace-nowrap text-slate-500"
                            >
                                <p>
                                    {{
                                        t('billing.companies.period.start', {
                                            date: formatDate(
                                                company.subscription_started_at,
                                            ),
                                        })
                                    }}
                                </p>
                                <p class="mt-1">
                                    {{
                                        t('billing.companies.period.renewal', {
                                            date: formatDate(
                                                company.subscription_renews_at,
                                            ),
                                        })
                                    }}
                                </p>
                                <p
                                    v-if="company.subscription_ends_at"
                                    class="mt-1"
                                >
                                    {{
                                        t('billing.companies.period.end', {
                                            date: formatDate(
                                                company.subscription_ends_at,
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
                compact
                :title="t('common.emptyTitle')"
                :description="t('common.emptyDescription')"
            />
            <AdminPagination :paginator="companies" />
        </SectionCard>

        <SectionCard
            :title="t('billing.plans.title')"
            :description="t('billing.plans.description')"
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
                                <p class="mt-1 text-xs text-slate-700">
                                    {{
                                        plan.price_cents === null
                                            ? t('common.onRequest')
                                            : formatCurrency(
                                                  plan.price_cents,
                                                  plan.currency,
                                              )
                                    }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="
                                    plan.is_active
                                        ? t('common.active')
                                        : t('common.inactive')
                                "
                                :tone="plan.is_active ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-3 text-[11px] text-slate-600">
                            {{
                                t('billing.plans.companyCount', {
                                    count: plan.companies_count,
                                })
                            }}
                            · {{ plan.slug }}
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
                                {{
                                    t('billing.plans.reference', {
                                        id: selectedPlan.id,
                                        slug: selectedPlan.slug,
                                    })
                                }}
                            </p>
                            <h3 class="mt-1 text-lg font-bold text-slate-950">
                                {{
                                    t('billing.plans.edit', {
                                        name: selectedPlan.name,
                                    })
                                }}
                            </h3>
                        </div>
                        <div
                            class="flex items-center gap-2 text-xs font-semibold text-slate-500"
                        >
                            <PencilLine class="size-4" />
                            {{
                                selectedPlan.is_enterprise
                                    ? t('billing.plans.enterprise')
                                    : t('billing.plans.standard')
                            }}
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <FormField
                            id="plan-name"
                            class="xl:col-span-2"
                            :label="t('billing.plans.fields.name')"
                            :error="planForm.errors.name"
                        >
                            <input
                                id="plan-name"
                                v-model="planForm.name"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-currency"
                            :label="t('billing.plans.fields.currency')"
                            :error="planForm.errors.currency"
                        >
                            <input
                                id="plan-currency"
                                v-model="planForm.currency"
                                maxlength="3"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm uppercase"
                            />
                        </FormField>
                        <FormField
                            id="plan-description"
                            class="md:col-span-2 xl:col-span-3"
                            :label="t('billing.plans.fields.description')"
                            :error="planForm.errors.description"
                        >
                            <Textarea
                                id="plan-description"
                                v-model="planForm.description"
                                rows="3"
                                class="min-h-24"
                            />
                        </FormField>
                        <FormField
                            id="plan-price"
                            :label="t('billing.plans.fields.price')"
                            :error="planForm.errors.price_cents"
                        >
                            <input
                                id="plan-price"
                                v-model="planForm.price_cents"
                                type="number"
                                min="0"
                                :placeholder="
                                    t('billing.plans.fields.pricePlaceholder')
                                "
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-term"
                            :label="t('billing.plans.fields.term')"
                            :error="planForm.errors.term_months"
                        >
                            <input
                                id="plan-term"
                                v-model="planForm.term_months"
                                type="number"
                                min="1"
                                :placeholder="t('billing.unlimited')"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-active-jobs"
                            :label="t('billing.plans.fields.activeJobs')"
                            :error="planForm.errors.active_jobs_limit"
                        >
                            <input
                                id="plan-active-jobs"
                                v-model="planForm.active_jobs_limit"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-seats"
                            :label="t('billing.plans.fields.seats')"
                            :error="planForm.errors.seat_limit"
                        >
                            <input
                                id="plan-seats"
                                v-model="planForm.seat_limit"
                                type="number"
                                min="1"
                                :placeholder="quota(null)"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-ai-credits"
                            :label="t('billing.plans.fields.aiCredits')"
                            :error="planForm.errors.ai_credits_monthly"
                        >
                            <input
                                id="plan-ai-credits"
                                v-model="planForm.ai_credits_monthly"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-boosts"
                            :label="t('billing.plans.fields.boosts')"
                            :error="planForm.errors.job_boosts_per_term"
                        >
                            <input
                                id="plan-boosts"
                                v-model="planForm.job_boosts_per_term"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-visa-credits"
                            :label="t('billing.plans.fields.visaCredits')"
                            :error="planForm.errors.visa_credits_per_term"
                        >
                            <input
                                id="plan-visa-credits"
                                v-model="planForm.visa_credits_per_term"
                                type="number"
                                min="0"
                                :placeholder="quota(null)"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </FormField>
                        <FormField
                            id="plan-stripe-product"
                            :label="t('billing.plans.fields.stripeProduct')"
                            :error="planForm.errors.stripe_product_id"
                        >
                            <input
                                id="plan-stripe-product"
                                v-model="planForm.stripe_product_id"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </FormField>
                        <FormField
                            id="plan-stripe-price"
                            :label="t('billing.plans.fields.stripePrice')"
                            :error="planForm.errors.stripe_price_id"
                        >
                            <input
                                id="plan-stripe-price"
                                v-model="planForm.stripe_price_id"
                                class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 font-mono text-xs"
                            />
                        </FormField>
                        <FormField
                            id="plan-features"
                            class="md:col-span-2 xl:col-span-3"
                            :label="t('billing.plans.fields.features')"
                            :error="planForm.errors.features"
                        >
                            <Textarea
                                id="plan-features"
                                v-model="featureText"
                                rows="5"
                            />
                        </FormField>
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
                            {{ t('billing.plans.active') }}
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
                                {{ t('billing.plans.save') }}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <EmptyState
                v-else
                compact
                :title="t('billing.plans.emptyTitle')"
                :description="t('billing.plans.emptyDescription')"
            />
        </SectionCard>
    </div>
</template>
