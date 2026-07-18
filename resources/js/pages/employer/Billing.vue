<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    CreditCard,
    ExternalLink,
    Plus,
    Sparkles,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import {
    cancel,
    change,
    checkout,
    details,
    portal,
    seats,
    visaCredits,
} from '@/routes/employer/billing';

type Company = {
    id: number;
    name: string;
    legal_name?: string | null;
    email?: string | null;
    vat_id?: string | null;
    country_code?: string | null;
    city?: string | null;
    postal_code?: string | null;
    address_line1?: string | null;
    subscription_status?: string | null;
    subscription_renews_at?: string | null;
    cancel_at_period_end?: boolean;
    pending_plan_effective_at?: string | null;
};
type Usage = {
    used: number;
    limit: number | null;
    remaining: number;
    purchased?: number;
    included?: number | null;
    additional?: number;
};
type Entitlements = {
    plan?: {
        id: number;
        slug: string;
        name: string;
        price_cents?: number | null;
        currency?: string;
        term_months?: number;
    } | null;
    jobs?: Usage;
    seats?: Usage;
    ai_credits?: Usage;
    boosts?: Usage;
    visa_credits?: Usage;
    renews_at?: string | null;
    cancel_at_period_end?: boolean;
    past_due?: boolean;
};
type Plan = {
    id: number;
    slug: string;
    name: string;
    description?: string | null;
    price_cents?: number | null;
    currency?: string;
    term_months?: number;
    active_jobs_limit?: number | null;
    seat_limit?: number | null;
    ai_credits_monthly?: number | null;
    job_boosts_per_term?: number | null;
    visa_credits_per_term?: number | null;
    is_enterprise?: boolean;
    checkout_available?: boolean;
};
type Subscription = {
    id: number;
    type: string;
    stripe_status: string;
    stripe_price?: string;
    ends_at?: string | null;
};
type AddOns = {
    visa_enabled?: boolean;
    seat_enabled?: boolean;
    seat_quantity?: number;
};

const props = withDefaults(
    defineProps<{
        company?: Company | null;
        plans?: Plan[];
        entitlements?: Entitlements;
        subscription?: Subscription | null;
        add_ons?: AddOns;
    }>(),
    {
        company: null,
        plans: () => [],
        entitlements: () => ({}),
        subscription: null,
        add_ons: () => ({}),
    },
);

const billingForm = useForm({
    legal_name: props.company?.legal_name ?? props.company?.name ?? '',
    email: props.company?.email ?? '',
    vat_id: props.company?.vat_id ?? '',
    country_code: props.company?.country_code ?? 'DE',
    city: props.company?.city ?? '',
    postal_code: props.company?.postal_code ?? '',
    address_line1: props.company?.address_line1 ?? '',
});
const { t, te } = useI18n();
const { formatCurrency, formatDate } = useFormatters();
const addonForm = useForm({ quantity: 1 });
const currentPlan = computed(() => props.entitlements.plan);
const percent = (usage?: Usage) => {
    if (!usage?.limit) {
        return 0;
    }

    return Math.min(100, Math.round((usage.used / usage.limit) * 100));
};
const money = (cents?: number | null, currency = 'EUR') =>
    cents == null
        ? t('employer.billing.onRequest')
        : formatCurrency(cents / 100, currency, {
              maximumFractionDigits: 0,
          });
const subscriptionStatusLabel = computed(() => {
    const status = props.company?.subscription_status;

    if (!status) {
        return t('employer.billing.noSubscription');
    }

    return te(`employer.billing.subscriptionStatus.${status}`)
        ? t(`employer.billing.subscriptionStatus.${status}`)
        : status.replaceAll('_', ' ');
});
const changePlan = (plan: Plan) => {
    if (!props.subscription) {
        router.post(checkout.url(plan.id));

        return;
    }

    router.post(change.url(plan.id));
};
</script>

<template>
    <Head :title="t('employer.billing.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.billing.eyebrow')"
            :title="t('employer.billing.title')"
            :description="t('employer.billing.description')"
            :icon="CreditCard"
        >
            <template #actions>
                <button
                    v-if="subscription"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700"
                    @click="router.post(portal.url())"
                >
                    {{ t('employer.billing.openStripePortal') }}
                    <ExternalLink class="size-4" />
                </button>
            </template>
        </PageHeader>

        <section
            class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-[var(--erin-primary)] to-blue-900 p-6 text-white shadow-xl"
        >
            <div class="erin-grid absolute inset-0 opacity-15" />
            <div
                class="relative flex flex-col gap-6 lg:flex-row lg:items-center"
            >
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <p
                            class="text-xs font-bold tracking-wider text-blue-100 uppercase"
                        >
                            {{ t('employer.billing.currentPlan') }}
                        </p>
                        <StatusBadge
                            :label="subscriptionStatusLabel"
                            :tone="
                                subscription
                                    ? entitlements.past_due
                                        ? 'yellow'
                                        : 'green'
                                    : 'slate'
                            "
                        />
                    </div>
                    <h2 class="mt-2 text-3xl font-extrabold">
                        {{
                            currentPlan?.name ??
                            t('employer.billing.noPlanSelected')
                        }}
                    </h2>
                    <p class="mt-2 text-sm text-blue-100/80">
                        <template v-if="company?.subscription_renews_at">
                            {{
                                t('employer.billing.renewsOn', {
                                    date: formatDate(
                                        company.subscription_renews_at,
                                        { dateStyle: 'long' },
                                    ),
                                })
                            }}</template
                        >
                        <template v-else>
                            {{ t('employer.billing.choosePlanHint') }}
                        </template>
                    </p>
                </div>
                <div v-if="currentPlan" class="text-left lg:text-right">
                    <p class="text-3xl font-extrabold">
                        {{
                            money(currentPlan.price_cents, currentPlan.currency)
                        }}
                    </p>
                    <p class="mt-1 text-xs text-blue-100">
                        {{
                            t('employer.billing.termMonths', {
                                count: currentPlan.term_months ?? 0,
                            })
                        }}
                    </p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <SectionCard :title="t('employer.billing.usage.jobSlots')"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.jobs?.used ?? 0 }} /
                    {{ entitlements.jobs?.limit ?? '∞' }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.jobs)"
                    :show-value="false"
                />
                <p class="mt-3 text-xs text-slate-600">
                    {{
                        t('employer.billing.usage.available', {
                            count: entitlements.jobs?.remaining ?? 0,
                        })
                    }}
                </p></SectionCard
            >
            <SectionCard :title="t('employer.billing.usage.recruiterSeats')"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.seats?.used ?? 0 }} /
                    {{ entitlements.seats?.limit ?? '∞' }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.seats)"
                    :show-value="false"
                    tone="teal"
                />
                <p class="mt-3 text-xs text-slate-600">
                    {{
                        t('employer.billing.usage.additionalSeats', {
                            count: entitlements.seats?.additional ?? 0,
                        })
                    }}
                </p></SectionCard
            >
            <SectionCard :title="t('employer.billing.usage.aiCredits')"
                ><p class="text-2xl font-extrabold">
                    {{
                        t('employer.billing.usage.available', {
                            count: entitlements.ai_credits?.remaining ?? 0,
                        })
                    }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.ai_credits)"
                    :show-value="false"
                    tone="orange"
                />
                <p class="mt-3 text-xs text-slate-600">
                    {{
                        t('employer.billing.usage.used', {
                            count: entitlements.ai_credits?.used ?? 0,
                        })
                    }}
                </p></SectionCard
            >
            <SectionCard :title="t('employer.billing.usage.visaPackages')"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.visa_credits?.remaining ?? 0 }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.visa_credits)"
                    :show-value="false"
                    tone="teal"
                />
                <p class="mt-3 text-xs text-slate-600">
                    {{
                        t('employer.billing.usage.additionallyPurchased', {
                            count: entitlements.visa_credits?.purchased ?? 0,
                        })
                    }}
                </p></SectionCard
            >
        </div>

        <SectionCard
            :title="t('employer.billing.plansTitle')"
            :description="t('employer.billing.plansDescription')"
        >
            <div
                v-if="plans.length"
                class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"
            >
                <article
                    v-for="plan in plans"
                    :key="plan.id"
                    class="rounded-2xl border p-5"
                    :class="
                        currentPlan?.id === plan.id
                            ? 'border-[var(--erin-primary)] bg-blue-50/50 ring-1 ring-[var(--erin-primary)]'
                            : 'border-slate-200'
                    "
                >
                    <div class="flex items-center justify-between">
                        <h3 class="font-extrabold">{{ plan.name }}</h3>
                        <StatusBadge
                            v-if="currentPlan?.id === plan.id"
                            :label="t('employer.billing.current')"
                            tone="blue"
                        />
                    </div>
                    <p class="mt-3 text-2xl font-extrabold">
                        {{ money(plan.price_cents, plan.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-600">
                        {{
                            t('employer.billing.months', {
                                count: plan.term_months ?? 0,
                            })
                        }}
                    </p>
                    <p class="mt-3 min-h-10 text-xs leading-5 text-slate-500">
                        {{ plan.description }}
                    </p>
                    <ul class="mt-4 space-y-2 text-xs text-slate-600">
                        <li class="flex gap-2">
                            <Check class="size-3.5 text-teal-500" />
                            {{
                                t('employer.billing.planFeatures.activeJobs', {
                                    count:
                                        plan.active_jobs_limit ??
                                        t('employer.billing.unlimited'),
                                })
                            }}
                        </li>
                        <li class="flex gap-2">
                            <Users class="size-3.5 text-teal-500" />
                            {{
                                t('employer.billing.planFeatures.seats', {
                                    count:
                                        plan.seat_limit ??
                                        t('employer.billing.unlimited'),
                                })
                            }}
                        </li>
                        <li class="flex gap-2">
                            <Sparkles class="size-3.5 text-teal-500" />
                            {{
                                t('employer.billing.planFeatures.aiCredits', {
                                    count: plan.ai_credits_monthly ?? 0,
                                })
                            }}
                        </li>
                    </ul>
                    <button
                        v-if="currentPlan?.id !== plan.id"
                        type="button"
                        :disabled="
                            plan.is_enterprise || !plan.checkout_available
                        "
                        class="mt-5 h-10 w-full rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:bg-slate-200 disabled:text-slate-600"
                        @click="changePlan(plan)"
                    >
                        {{
                            plan.is_enterprise
                                ? t('employer.billing.contactUs')
                                : subscription
                                  ? t('employer.billing.switchPlan')
                                  : t('employer.billing.choosePlan')
                        }}
                    </button>
                </article>
            </div>
            <p v-else class="py-8 text-center text-sm text-slate-600">
                {{ t('employer.billing.noPlans') }}
            </p>
        </SectionCard>

        <div class="grid gap-6 xl:grid-cols-[1fr_0.7fr]">
            <SectionCard :title="t('employer.billing.billingDetailsTitle')">
                <form
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="
                        billingForm.patch(details.url(), {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="sm:col-span-2"
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.legalName')
                        }}</span
                        ><input
                            v-model="billingForm.legal_name"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.email')
                        }}</span
                        ><input
                            v-model="billingForm.email"
                            required
                            type="email"
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.vatId')
                        }}</span
                        ><input
                            v-model="billingForm.vat_id"
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.street')
                        }}</span
                        ><input
                            v-model="billingForm.address_line1"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.postalCode')
                        }}</span
                        ><input
                            v-model="billingForm.postal_code"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.city')
                        }}</span
                        ><input
                            v-model="billingForm.city"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600">{{
                            t('employer.billing.fields.country')
                        }}</span
                        ><input
                            v-model="billingForm.country_code"
                            required
                            maxlength="2"
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm uppercase"
                    /></label>
                    <button
                        type="submit"
                        :disabled="billingForm.processing"
                        class="h-10 rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:opacity-50 sm:col-span-2"
                    >
                        {{ t('employer.billing.saveBillingDetails') }}
                    </button>
                </form>
            </SectionCard>
            <div class="space-y-6">
                <SectionCard :title="t('employer.billing.addOnsTitle')">
                    <form
                        v-if="add_ons.seat_enabled"
                        class="flex items-center gap-2"
                        @submit.prevent="
                            addonForm.post(seats.url(), {
                                preserveScroll: true,
                            })
                        "
                    >
                        <input
                            v-model.number="addonForm.quantity"
                            min="1"
                            max="100"
                            type="number"
                            class="h-10 w-20 rounded-xl border border-slate-200 px-3 text-sm"
                        />
                        <button
                            type="submit"
                            class="h-10 flex-1 rounded-xl border border-slate-200 text-xs font-bold"
                        >
                            {{ t('employer.billing.addRecruiterSeats') }}
                        </button>
                    </form>
                    <button
                        v-if="add_ons.visa_enabled"
                        type="button"
                        class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 text-xs font-bold"
                        @click="router.post(visaCredits.url(), { credits: 1 })"
                    >
                        <Plus class="size-4" />
                        {{ t('employer.billing.buyVisaPackage') }}
                    </button>
                    <p
                        v-if="!add_ons.seat_enabled && !add_ons.visa_enabled"
                        class="text-sm text-slate-600"
                    >
                        {{ t('employer.billing.addOnsUnavailable') }}
                    </p>
                </SectionCard>
                <SectionCard
                    v-if="subscription"
                    :title="t('employer.billing.subscriptionTitle')"
                >
                    <p class="text-xs leading-5 text-slate-500">
                        {{ t('employer.billing.cancellationDescription') }}
                    </p>
                    <button
                        v-if="!company?.cancel_at_period_end"
                        type="button"
                        class="mt-4 h-10 w-full rounded-xl border border-red-200 text-xs font-bold text-red-600"
                        @click="router.post(cancel.url())"
                    >
                        {{ t('employer.billing.cancelAtPeriodEnd') }}
                    </button>
                    <StatusBadge
                        v-else
                        :label="t('employer.billing.cancellationScheduled')"
                        tone="yellow"
                    />
                </SectionCard>
            </div>
        </div>
    </div>
</template>
