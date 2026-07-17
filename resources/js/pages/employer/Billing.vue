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
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
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
        ? 'Auf Anfrage'
        : new Intl.NumberFormat('de-DE', {
              style: 'currency',
              currency,
              maximumFractionDigits: 0,
          }).format(cents / 100);
const changePlan = (plan: Plan) => {
    if (!props.subscription) {
        router.post(checkout.url(plan.id));

        return;
    }

    router.post(change.url(plan.id));
};
</script>

<template>
    <Head title="Paket & Abrechnung" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Abrechnung"
            title="Paket & Abrechnung"
            description="Verwalten Sie Ihr Abonnement, Kontingente und Rechnungsdaten."
            :icon="CreditCard"
        >
            <template #actions>
                <button
                    v-if="subscription"
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700"
                    @click="router.post(portal.url())"
                >
                    Stripe-Portal öffnen <ExternalLink class="size-4" />
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
                            Aktuelles Paket
                        </p>
                        <StatusBadge
                            :label="
                                company?.subscription_status ??
                                'Kein Abonnement'
                            "
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
                        {{ currentPlan?.name ?? 'Noch kein Paket gewählt' }}
                    </h2>
                    <p class="mt-2 text-sm text-blue-100/80">
                        <template v-if="company?.subscription_renews_at"
                            >Verlängerung am
                            {{
                                new Intl.DateTimeFormat('de-DE', {
                                    dateStyle: 'long',
                                }).format(
                                    new Date(company.subscription_renews_at),
                                )
                            }}</template
                        >
                        <template v-else
                            >Wählen Sie unten ein verfügbares Paket.</template
                        >
                    </p>
                </div>
                <div v-if="currentPlan" class="text-left lg:text-right">
                    <p class="text-3xl font-extrabold">
                        {{
                            money(currentPlan.price_cents, currentPlan.currency)
                        }}
                    </p>
                    <p class="mt-1 text-xs text-blue-100">
                        {{ currentPlan.term_months }} Monate Laufzeit
                    </p>
                </div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <SectionCard title="Job-Slots"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.jobs?.used ?? 0 }} /
                    {{ entitlements.jobs?.limit ?? '∞' }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.jobs)"
                    :show-value="false"
                />
                <p class="mt-3 text-xs text-slate-400">
                    {{ entitlements.jobs?.remaining ?? 0 }} verfügbar
                </p></SectionCard
            >
            <SectionCard title="Recruiter-Sitze"
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
                <p class="mt-3 text-xs text-slate-400">
                    {{ entitlements.seats?.additional ?? 0 }} Zusatzsitze
                </p></SectionCard
            >
            <SectionCard title="KI-Credits"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.ai_credits?.remaining ?? 0 }} verfügbar
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.ai_credits)"
                    :show-value="false"
                    tone="orange"
                />
                <p class="mt-3 text-xs text-slate-400">
                    {{ entitlements.ai_credits?.used ?? 0 }} genutzt
                </p></SectionCard
            >
            <SectionCard title="Visa-Pakete"
                ><p class="text-2xl font-extrabold">
                    {{ entitlements.visa_credits?.remaining ?? 0 }}
                </p>
                <ProgressBar
                    class="mt-3"
                    :value="percent(entitlements.visa_credits)"
                    :show-value="false"
                    tone="teal"
                />
                <p class="mt-3 text-xs text-slate-400">
                    {{ entitlements.visa_credits?.purchased ?? 0 }} zusätzlich
                    gekauft
                </p></SectionCard
            >
        </div>

        <SectionCard
            title="Verfügbare Pakete"
            description="Upgrades gelten sofort, Downgrades zum nächsten Laufzeitbeginn"
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
                            label="Aktuell"
                            tone="blue"
                        />
                    </div>
                    <p class="mt-3 text-2xl font-extrabold">
                        {{ money(plan.price_cents, plan.currency) }}
                    </p>
                    <p class="mt-1 text-xs text-slate-400">
                        {{ plan.term_months }} Monate
                    </p>
                    <p class="mt-3 min-h-10 text-xs leading-5 text-slate-500">
                        {{ plan.description }}
                    </p>
                    <ul class="mt-4 space-y-2 text-xs text-slate-600">
                        <li class="flex gap-2">
                            <Check class="size-3.5 text-teal-500" />
                            {{ plan.active_jobs_limit ?? 'Unbegrenzt' }} aktive
                            Jobs
                        </li>
                        <li class="flex gap-2">
                            <Users class="size-3.5 text-teal-500" />
                            {{ plan.seat_limit ?? 'Unbegrenzt' }} Sitze
                        </li>
                        <li class="flex gap-2">
                            <Sparkles class="size-3.5 text-teal-500" />
                            {{ plan.ai_credits_monthly ?? 0 }} KI-Credits
                        </li>
                    </ul>
                    <button
                        v-if="currentPlan?.id !== plan.id"
                        type="button"
                        :disabled="
                            plan.is_enterprise || !plan.checkout_available
                        "
                        class="mt-5 h-10 w-full rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:bg-slate-200 disabled:text-slate-400"
                        @click="changePlan(plan)"
                    >
                        {{
                            plan.is_enterprise
                                ? 'Kontakt aufnehmen'
                                : subscription
                                  ? 'Zu diesem Paket wechseln'
                                  : 'Paket wählen'
                        }}
                    </button>
                </article>
            </div>
            <p v-else class="py-8 text-center text-sm text-slate-400">
                Aktuell sind keine Pakete konfiguriert.
            </p>
        </SectionCard>

        <div class="grid gap-6 xl:grid-cols-[1fr_0.7fr]">
            <SectionCard title="Rechnungsdaten">
                <form
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="
                        billingForm.patch(details.url(), {
                            preserveScroll: true,
                        })
                    "
                >
                    <label class="sm:col-span-2"
                        ><span class="text-xs font-bold text-slate-600"
                            >Rechtlicher Firmenname</span
                        ><input
                            v-model="billingForm.legal_name"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >Rechnungs-E-Mail</span
                        ><input
                            v-model="billingForm.email"
                            required
                            type="email"
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >USt-ID</span
                        ><input
                            v-model="billingForm.vat_id"
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >Straße</span
                        ><input
                            v-model="billingForm.address_line1"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >PLZ</span
                        ><input
                            v-model="billingForm.postal_code"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >Stadt</span
                        ><input
                            v-model="billingForm.city"
                            required
                            class="mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    /></label>
                    <label
                        ><span class="text-xs font-bold text-slate-600"
                            >Land</span
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
                        Rechnungsdaten speichern
                    </button>
                </form>
            </SectionCard>
            <div class="space-y-6">
                <SectionCard title="Zusatzkontingente">
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
                            Recruiter-Sitze hinzufügen
                        </button>
                    </form>
                    <button
                        v-if="add_ons.visa_enabled"
                        type="button"
                        class="mt-3 inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl border border-slate-200 text-xs font-bold"
                        @click="router.post(visaCredits.url(), { quantity: 1 })"
                    >
                        <Plus class="size-4" /> Visumpaket kaufen
                    </button>
                    <p
                        v-if="!add_ons.seat_enabled && !add_ons.visa_enabled"
                        class="text-sm text-slate-400"
                    >
                        Zusatzkäufe sind aktuell nicht konfiguriert.
                    </p>
                </SectionCard>
                <SectionCard v-if="subscription" title="Abonnement">
                    <p class="text-xs leading-5 text-slate-500">
                        Kündigungen werden zum Ende der aktuellen Laufzeit
                        wirksam.
                    </p>
                    <button
                        v-if="!company?.cancel_at_period_end"
                        type="button"
                        class="mt-4 h-10 w-full rounded-xl border border-red-200 text-xs font-bold text-red-600"
                        @click="router.post(cancel.url())"
                    >
                        Zum Laufzeitende kündigen
                    </button>
                    <StatusBadge
                        v-else
                        label="Kündigung vorgemerkt"
                        tone="yellow"
                    />
                </SectionCard>
            </div>
        </div>
    </div>
</template>
