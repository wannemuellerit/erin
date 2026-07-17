<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Check, Minus } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import PublicHeader from '@/components/product/PublicHeader.vue';
import { contact, register } from '@/routes';
import legal from '@/routes/legal';

type Plan = {
    id: number;
    slug: string;
    name: string;
    description: string;
    price_cents: number | null;
    currency: string;
    term_months: number | null;
    active_jobs_limit: number | null;
    seat_limit: number | null;
    ai_credits_monthly: number | null;
    job_boosts_per_term: number | null;
    visa_credits_per_term: number | null;
    is_enterprise: boolean;
    features?: Record<string, unknown> | null;
    checkout_available: boolean;
};

withDefaults(defineProps<{ plans?: Plan[] }>(), {
    plans: () => [],
});

const { locale, t, te } = useI18n();

function money(plan: Plan): string {
    if (plan.price_cents === null) {
        return t('public.pricing.individual');
    }

    return new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'de-DE', {
        style: 'currency',
        currency: plan.currency,
        maximumFractionDigits: 0,
    }).format(plan.price_cents / 100);
}

function limit(
    value: number | null,
    singularKey: string,
    pluralKey: string,
): string {
    const item = t(value === 1 ? singularKey : pluralKey);

    return value === null
        ? t('public.pricing.features.unlimited', { item })
        : `${value} ${item}`;
}

function included(plan: Plan): string[] {
    const features = [
        limit(
            plan.active_jobs_limit,
            'public.pricing.features.activeJob',
            'public.pricing.features.activeJobs',
        ),
        limit(
            plan.seat_limit,
            'public.pricing.features.seat',
            'public.pricing.features.seats',
        ),
        plan.features?.job_templates === 'premium'
            ? t('public.pricing.features.premiumTemplates')
            : t('public.pricing.features.standardTemplates'),
        plan.ai_credits_monthly === null
            ? t('public.pricing.features.individualAi')
            : plan.ai_credits_monthly > 0
              ? t('public.pricing.features.aiCredits', {
                    count: plan.ai_credits_monthly,
                })
              : t('public.pricing.features.support'),
    ];

    if (plan.job_boosts_per_term !== 0) {
        features.push(
            limit(
                plan.job_boosts_per_term,
                'public.pricing.features.jobBoost',
                'public.pricing.features.jobBoosts',
            ),
        );
    }

    if (plan.visa_credits_per_term !== 0) {
        features.push(
            limit(
                plan.visa_credits_per_term,
                'public.pricing.features.visaPackage',
                'public.pricing.features.visaPackages',
            ),
        );
    }

    features.push(t('public.pricing.features.applications'));

    return features;
}

function unavailable(plan: Plan): string[] {
    return [
        ...(plan.ai_credits_monthly === 0
            ? [t('public.pricing.features.recruitingAi')]
            : []),
        ...(plan.job_boosts_per_term === 0
            ? [t('public.pricing.features.jobBoost')]
            : []),
        ...(plan.visa_credits_per_term === 0
            ? [t('public.pricing.features.visaPackages')]
            : []),
    ];
}

function planDescription(plan: Plan): string {
    const key = `public.pricing.planDescriptions.${plan.slug}`;

    return te(key) ? t(key) : plan.description;
}

const accents: Record<string, string> = {
    basic: 'slate',
    business: 'blue',
    premium: 'teal',
    enterprise: 'orange',
};
</script>

<template>
    <Head :title="t('public.pricing.metaTitle')">
        <meta name="description" :content="t('public.pricing.description')" />
    </Head>
    <div class="min-h-screen bg-slate-50 text-slate-950">
        <PublicHeader />
        <main>
            <section
                class="relative overflow-hidden border-b border-slate-200 bg-white px-5 py-16 text-center sm:px-6 lg:py-20"
            >
                <div class="erin-grid absolute inset-0 opacity-40" />
                <div class="relative mx-auto max-w-3xl">
                    <p
                        class="text-xs font-bold tracking-[0.15em] text-blue-600 uppercase"
                    >
                        {{ t('public.pricing.eyebrow') }}
                    </p>
                    <h1
                        class="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl"
                    >
                        {{ t('public.pricing.title') }}
                    </h1>
                    <p
                        class="mx-auto mt-5 max-w-2xl text-base leading-7 text-slate-500"
                    >
                        {{ t('public.pricing.description') }}
                    </p>
                </div>
            </section>
            <section
                class="mx-auto max-w-[1500px] px-5 py-14 sm:px-6 lg:px-8 lg:py-20"
            >
                <div
                    v-if="plans.length > 0"
                    class="grid items-stretch gap-5 md:grid-cols-2 xl:grid-cols-4"
                >
                    <article
                        v-for="plan in plans"
                        :key="plan.id"
                        class="relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm"
                        :class="
                            plan.slug === 'business'
                                ? 'border-blue-500 shadow-xl ring-2 shadow-blue-950/10 ring-blue-500'
                                : 'border-slate-200'
                        "
                    >
                        <span
                            v-if="plan.slug === 'business'"
                            class="absolute -top-3 left-1/2 -translate-x-1/2 rounded-full bg-blue-600 px-3 py-1 text-[10px] font-bold tracking-wider whitespace-nowrap text-white uppercase"
                        >
                            {{ t('public.pricing.popular') }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span
                                class="size-2.5 rounded-full"
                                :class="{
                                    'bg-slate-500':
                                        accents[plan.slug] === 'slate',
                                    'bg-blue-600':
                                        accents[plan.slug] === 'blue',
                                    'bg-teal-500':
                                        accents[plan.slug] === 'teal',
                                    'bg-orange-500':
                                        accents[plan.slug] === 'orange',
                                }"
                            />
                            <h2 class="text-lg font-extrabold">
                                {{ plan.name }}
                            </h2>
                        </div>
                        <p class="mt-4 text-3xl font-extrabold tracking-tight">
                            {{ money(plan) }}
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{
                                plan.term_months
                                    ? t('public.pricing.term', {
                                          count: plan.term_months,
                                      })
                                    : t('public.pricing.onRequest')
                            }}
                        </p>
                        <p
                            class="mt-4 min-h-12 text-sm leading-6 text-slate-500"
                        >
                            {{ planDescription(plan) }}
                        </p>
                        <Link
                            v-if="plan.is_enterprise"
                            :href="contact()"
                            class="erin-focus mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-300 text-sm font-bold text-slate-700 hover:bg-slate-50"
                        >
                            {{ t('public.pricing.contact') }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <Link
                            v-else-if="plan.checkout_available"
                            :href="`${register().url}?role=company&plan=${plan.slug}`"
                            class="erin-focus mt-6 inline-flex h-11 items-center justify-center gap-2 rounded-xl text-sm font-bold"
                            :class="
                                plan.slug === 'business'
                                    ? 'bg-blue-600 text-white hover:bg-blue-700'
                                    : 'border border-slate-300 text-slate-700 hover:bg-slate-50'
                            "
                        >
                            {{ t('public.pricing.select') }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <div
                            v-else
                            class="mt-6 inline-flex h-11 items-center justify-center rounded-xl bg-slate-100 px-4 text-sm font-bold text-slate-400"
                        >
                            {{ t('public.pricing.notAvailable') }}
                        </div>
                        <div class="my-6 h-px bg-slate-100" />
                        <p
                            class="mb-3 text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                        >
                            {{ t('public.pricing.included') }}
                        </p>
                        <ul class="space-y-3 text-sm text-slate-600">
                            <li
                                v-for="feature in included(plan)"
                                :key="feature"
                                class="flex items-start gap-2.5"
                            >
                                <span
                                    class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full bg-teal-50 text-teal-600"
                                >
                                    <Check class="size-3" />
                                </span>
                                {{ feature }}
                            </li>
                            <li
                                v-for="feature in unavailable(plan)"
                                :key="feature"
                                class="flex items-start gap-2.5 text-slate-400"
                            >
                                <span
                                    class="mt-0.5 grid size-4 shrink-0 place-items-center rounded-full bg-slate-100"
                                >
                                    <Minus class="size-3" />
                                </span>
                                {{ feature }}
                            </li>
                        </ul>
                    </article>
                </div>
                <div
                    v-else
                    class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm"
                >
                    <h2 class="text-lg font-extrabold text-slate-900">
                        {{ t('public.pricing.emptyTitle') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        {{ t('public.pricing.emptyText') }}
                    </p>
                    <Link
                        :href="contact()"
                        class="erin-focus mt-5 inline-flex h-11 items-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700"
                    >
                        {{ t('public.pricing.contact') }}
                        <ArrowRight class="size-4" />
                    </Link>
                </div>
                <p class="mt-8 text-center text-xs leading-5 text-slate-400">
                    {{ t('public.pricing.renewal') }}
                </p>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
            >
                <p>
                    {{
                        t('public.common.copyright', {
                            year: new Date().getFullYear(),
                        })
                    }}
                </p>
                <nav
                    class="flex flex-wrap gap-x-5 gap-y-2"
                    :aria-label="t('public.legal.eyebrow')"
                >
                    <Link
                        :href="legal.privacy()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.privacy') }}
                    </Link>
                    <Link
                        :href="legal.imprint()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.imprint') }}
                    </Link>
                    <Link
                        :href="legal.terms()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.terms') }}
                    </Link>
                    <Link
                        :href="contact()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.contact') }}
                    </Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
