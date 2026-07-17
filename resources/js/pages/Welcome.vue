<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BadgeCheck,
    BriefcaseBusiness,
    Check,
    ChevronRight,
    FileCheck2,
    Globe2,
    HeartHandshake,
    Languages,
    Plane,
    ShieldCheck,
    Sparkles,
    UsersRound,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import MatchScore from '@/components/product/MatchScore.vue';
import PublicHeader from '@/components/product/PublicHeader.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { contact, pricing, register } from '@/routes';
import legal from '@/routes/legal';

const { t } = useI18n();

const professions = [
    'public.welcome.professions.electrician',
    'public.welcome.professions.electronicsTechnician',
    'public.welcome.professions.truckDriver',
    'public.welcome.professions.nurse',
    'public.welcome.professions.assistant',
];
const features = [
    {
        icon: Sparkles,
        title: 'public.welcome.platform.features.matching.title',
        text: 'public.welcome.platform.features.matching.text',
        tone: 'bg-blue-50 text-blue-600',
    },
    {
        icon: Plane,
        title: 'public.welcome.platform.features.visa.title',
        text: 'public.welcome.platform.features.visa.text',
        tone: 'bg-teal-50 text-teal-600',
    },
    {
        icon: ShieldCheck,
        title: 'public.welcome.platform.features.trust.title',
        text: 'public.welcome.platform.features.trust.text',
        tone: 'bg-orange-50 text-orange-600',
    },
];

const steps = [
    {
        number: '01',
        title: 'public.welcome.process.steps.profile.title',
        text: 'public.welcome.process.steps.profile.text',
    },
    {
        number: '02',
        title: 'public.welcome.process.steps.discover.title',
        text: 'public.welcome.process.steps.discover.text',
    },
    {
        number: '03',
        title: 'public.welcome.process.steps.start.title',
        text: 'public.welcome.process.steps.start.text',
    },
];
</script>

<template>
    <Head :title="t('public.welcome.metaTitle')">
        <meta
            name="description"
            :content="t('public.welcome.metaDescription')"
        />
    </Head>

    <div class="min-h-screen bg-white text-slate-950">
        <PublicHeader />

        <main>
            <section
                class="relative overflow-hidden border-b border-slate-100 bg-gradient-to-b from-blue-50/70 via-white to-white"
            >
                <div class="erin-grid absolute inset-0 opacity-45" />
                <div
                    class="absolute -top-32 left-1/2 size-[34rem] -translate-x-1/2 rounded-full bg-blue-300/20 blur-3xl"
                />
                <div
                    class="relative mx-auto grid max-w-7xl items-center gap-14 px-5 py-16 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:px-8 lg:py-24"
                >
                    <div>
                        <div
                            class="mb-5 inline-flex items-center gap-2 rounded-full border border-blue-200 bg-white px-3 py-1.5 text-xs font-bold text-blue-700 shadow-sm"
                        >
                            <Globe2 class="size-3.5 text-teal-500" />
                            {{ t('public.welcome.hero.badge') }}
                        </div>
                        <h1
                            class="max-w-3xl text-4xl leading-[1.08] font-extrabold tracking-[-0.035em] sm:text-5xl lg:text-6xl"
                        >
                            {{ t('public.welcome.hero.titleLead') }}
                            <span class="relative text-blue-600">
                                {{ t('public.welcome.hero.titleAccent') }}
                                <svg
                                    class="absolute right-0 -bottom-2 h-2 w-4/5 text-orange-400"
                                    viewBox="0 0 260 10"
                                    fill="none"
                                    aria-hidden="true"
                                >
                                    <path
                                        d="M2 8C65 1 176 1 258 6"
                                        stroke="currentColor"
                                        stroke-width="4"
                                        stroke-linecap="round"
                                    />
                                </svg>
                            </span>
                        </h1>
                        <p
                            class="mt-7 max-w-2xl text-lg leading-8 text-slate-600"
                        >
                            {{ t('public.welcome.hero.description') }}
                        </p>
                        <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                            <Link
                                :href="`${register().url}?role=company`"
                                class="erin-focus inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-blue-600 px-6 text-sm font-bold text-white shadow-xl shadow-blue-600/20 hover:bg-blue-700"
                            >
                                {{ t('public.welcome.hero.companyCta') }}
                                <ArrowRight class="size-4" />
                            </Link>
                            <Link
                                :href="`${register().url}?role=candidate`"
                                class="erin-focus inline-flex h-12 items-center justify-center rounded-xl border border-slate-300 bg-white px-6 text-sm font-bold text-slate-700 hover:bg-slate-50"
                            >
                                {{ t('public.welcome.hero.candidateCta') }}
                            </Link>
                        </div>
                        <div
                            class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3 text-xs font-semibold text-slate-500"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <Check class="size-4 text-teal-500" />
                                {{ t('public.welcome.hero.freeCandidates') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Check class="size-4 text-teal-500" />
                                {{
                                    t('public.welcome.hero.verifiableDocuments')
                                }}
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <Check class="size-4 text-teal-500" />
                                {{ t('public.welcome.hero.privacyOriented') }}
                            </span>
                        </div>
                    </div>

                    <div class="relative mx-auto w-full max-w-xl">
                        <div
                            class="absolute -top-5 -right-5 z-20 rounded-2xl bg-white p-3 shadow-xl ring-1 ring-slate-200"
                        >
                            <div class="flex items-center gap-2">
                                <span
                                    class="grid size-8 place-items-center rounded-lg bg-teal-50 text-teal-600"
                                >
                                    <BadgeCheck class="size-4" />
                                </span>
                                <div>
                                    <p class="text-[10px] text-slate-400">
                                        {{
                                            t(
                                                'public.welcome.preview.documentStatus',
                                            )
                                        }}
                                    </p>
                                    <p class="text-xs font-bold text-slate-800">
                                        {{
                                            t(
                                                'public.welcome.preview.fullyReviewed',
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div
                            class="erin-panel relative overflow-hidden border-0 p-5 shadow-2xl ring-1 shadow-blue-950/10 ring-slate-200 sm:p-6"
                        >
                            <div
                                class="flex items-center justify-between border-b border-slate-100 pb-5"
                            >
                                <div>
                                    <p
                                        class="text-[10px] font-bold tracking-wider text-slate-400 uppercase"
                                    >
                                        {{
                                            t('public.welcome.preview.example')
                                        }}
                                        ·
                                        {{
                                            t('public.welcome.preview.topMatch')
                                        }}
                                    </p>
                                    <h2 class="mt-1 text-lg font-extrabold">
                                        {{
                                            t('public.welcome.preview.position')
                                        }}
                                    </h2>
                                </div>
                                <StatusBadge
                                    :label="t('public.welcome.preview.active')"
                                    tone="green"
                                />
                            </div>
                            <div class="mt-5 flex items-start gap-4">
                                <div
                                    class="grid size-14 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-blue-100 to-teal-50 font-extrabold text-blue-700"
                                    aria-hidden="true"
                                >
                                    ER
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold">
                                            {{
                                                t(
                                                    'public.welcome.preview.candidate',
                                                )
                                            }}
                                        </p>
                                        <BadgeCheck
                                            class="size-4 text-teal-500"
                                        />
                                    </div>
                                    <p class="mt-1 text-xs text-slate-500">
                                        {{
                                            t(
                                                'public.welcome.preview.locationExperience',
                                            )
                                        }}
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-1.5">
                                        <span
                                            v-for="skill in [
                                                'SPS',
                                                'Siemens',
                                                t(
                                                    'public.welcome.preview.skills.industry',
                                                ),
                                                t(
                                                    'public.welcome.preview.skills.german',
                                                ),
                                            ]"
                                            :key="skill"
                                            class="rounded-md bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-600"
                                        >
                                            {{ skill }}
                                        </span>
                                    </div>
                                </div>
                                <MatchScore :score="94" size="lg" />
                            </div>
                            <div
                                class="mt-5 grid gap-2 rounded-xl bg-slate-50 p-4 text-xs text-slate-600 sm:grid-cols-2"
                            >
                                <span
                                    v-for="reason in [
                                        'profession',
                                        'language',
                                        'relocation',
                                        'documents',
                                    ]"
                                    :key="reason"
                                    class="inline-flex items-center gap-2"
                                >
                                    <Check class="size-3.5 text-teal-500" />
                                    {{
                                        t(
                                            `public.welcome.preview.reasons.${reason}`,
                                        )
                                    }}
                                </span>
                            </div>
                            <Link
                                :href="`${register().url}?role=company`"
                                class="erin-focus mt-5 inline-flex h-11 w-full items-center justify-center rounded-xl bg-blue-600 text-sm font-bold text-white hover:bg-blue-700"
                            >
                                {{ t('public.welcome.preview.profileCta') }}
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <section class="border-b border-slate-100 bg-white py-8">
                <div class="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                    <p
                        class="text-center text-[11px] font-bold tracking-[0.15em] text-slate-400 uppercase"
                    >
                        {{ t('public.welcome.professions.eyebrow') }}
                    </p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <span
                            v-for="profession in professions"
                            :key="profession"
                            class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold text-slate-600"
                        >
                            {{ t(profession) }}
                        </span>
                    </div>
                </div>
            </section>

            <section
                id="unternehmen"
                class="mx-auto max-w-7xl px-5 py-20 sm:px-6 lg:px-8 lg:py-28"
            >
                <div class="mx-auto max-w-2xl text-center">
                    <p
                        class="text-xs font-bold tracking-[0.15em] text-teal-600 uppercase"
                    >
                        {{ t('public.welcome.platform.eyebrow') }}
                    </p>
                    <h2
                        class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl"
                    >
                        {{ t('public.welcome.platform.title') }}
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-500">
                        {{ t('public.welcome.platform.description') }}
                    </p>
                </div>
                <div class="mt-12 grid gap-5 md:grid-cols-3">
                    <article
                        v-for="feature in features"
                        :key="feature.title"
                        class="erin-panel p-6"
                    >
                        <span
                            class="grid size-12 place-items-center rounded-2xl"
                            :class="feature.tone"
                        >
                            <component :is="feature.icon" class="size-6" />
                        </span>
                        <h3 class="mt-5 text-lg font-bold">
                            {{ t(feature.title) }}
                        </h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            {{ t(feature.text) }}
                        </p>
                        <Link
                            :href="pricing()"
                            class="erin-focus mt-5 inline-flex items-center gap-1 rounded-md text-sm font-bold text-blue-600 hover:text-blue-700"
                        >
                            {{ t('public.welcome.platform.learnMore') }}
                            <ChevronRight class="size-4" />
                        </Link>
                    </article>
                </div>
            </section>

            <section
                id="fachkraefte"
                class="overflow-hidden bg-[#0F2854] py-20 text-white lg:py-24"
            >
                <div
                    class="mx-auto grid max-w-7xl items-center gap-14 px-5 sm:px-6 lg:grid-cols-2 lg:px-8"
                >
                    <div>
                        <p
                            class="text-xs font-bold tracking-[0.15em] text-teal-300 uppercase"
                        >
                            {{ t('public.welcome.candidates.eyebrow') }}
                        </p>
                        <h2
                            class="mt-3 text-3xl font-extrabold tracking-tight sm:text-4xl"
                        >
                            {{ t('public.welcome.candidates.title') }}
                        </h2>
                        <p
                            class="mt-5 max-w-xl text-base leading-7 text-blue-100/75"
                        >
                            {{ t('public.welcome.candidates.description') }}
                        </p>
                        <div class="mt-7 grid gap-3 sm:grid-cols-2">
                            <span class="flex items-center gap-2 text-sm">
                                <Languages class="size-4 text-teal-300" />
                                {{
                                    t(
                                        'public.welcome.candidates.benefits.multilingual',
                                    )
                                }}
                            </span>
                            <span class="flex items-center gap-2 text-sm">
                                <FileCheck2 class="size-4 text-teal-300" />
                                {{
                                    t(
                                        'public.welcome.candidates.benefits.documents',
                                    )
                                }}
                            </span>
                            <span class="flex items-center gap-2 text-sm">
                                <HeartHandshake class="size-4 text-teal-300" />
                                {{
                                    t(
                                        'public.welcome.candidates.benefits.guidance',
                                    )
                                }}
                            </span>
                            <span class="flex items-center gap-2 text-sm">
                                <BriefcaseBusiness
                                    class="size-4 text-teal-300"
                                />
                                {{
                                    t(
                                        'public.welcome.candidates.benefits.employers',
                                    )
                                }}
                            </span>
                        </div>
                        <Link
                            :href="`${register().url}?role=candidate`"
                            class="erin-focus mt-8 inline-flex h-12 items-center gap-2 rounded-xl bg-orange-500 px-6 text-sm font-bold text-white hover:bg-orange-600"
                        >
                            {{ t('public.welcome.candidates.cta') }}
                            <ArrowRight class="size-4" />
                        </Link>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div
                            class="mt-10 rounded-2xl bg-white/10 p-5 ring-1 ring-white/10 backdrop-blur"
                        >
                            <UsersRound class="size-6 text-teal-300" />
                            <p class="mt-7 text-2xl font-extrabold">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.anonymous',
                                    )
                                }}
                            </p>
                            <p class="mt-1 text-xs text-blue-200">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.anonymousText',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl bg-white p-5 text-slate-950 shadow-2xl"
                        >
                            <BadgeCheck class="size-6 text-orange-400" />
                            <p class="mt-7 text-2xl font-extrabold">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.measurable',
                                    )
                                }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.measurableText',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="rounded-2xl bg-teal-500 p-5 text-white shadow-2xl"
                        >
                            <Plane class="size-6" />
                            <p class="mt-7 text-2xl font-extrabold">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.relocation',
                                    )
                                }}
                            </p>
                            <p class="mt-1 text-xs text-teal-50">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.relocationText',
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            class="-mt-10 rounded-2xl bg-white/10 p-5 ring-1 ring-white/10 backdrop-blur"
                        >
                            <BadgeCheck class="size-6 text-orange-300" />
                            <p class="mt-7 text-2xl font-extrabold">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.explainable',
                                    )
                                }}
                            </p>
                            <p class="mt-1 text-xs text-blue-200">
                                {{
                                    t(
                                        'public.welcome.candidates.cards.explainableText',
                                    )
                                }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="ablauf"
                class="mx-auto max-w-7xl px-5 py-20 sm:px-6 lg:px-8 lg:py-28"
            >
                <div class="text-center">
                    <p
                        class="text-xs font-bold tracking-[0.15em] text-blue-600 uppercase"
                    >
                        {{ t('public.welcome.process.eyebrow') }}
                    </p>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight">
                        {{ t('public.welcome.process.title') }}
                    </h2>
                </div>
                <div class="relative mt-12 grid gap-5 md:grid-cols-3">
                    <div
                        class="absolute top-9 right-[17%] left-[17%] hidden h-px bg-gradient-to-r from-blue-300 via-teal-300 to-orange-300 md:block"
                    />
                    <article
                        v-for="step in steps"
                        :key="step.number"
                        class="relative rounded-2xl border border-slate-200 bg-white p-6 text-center"
                    >
                        <span
                            class="relative mx-auto grid size-[4.5rem] place-items-center rounded-2xl bg-slate-950 text-lg font-extrabold text-white ring-8 ring-white"
                        >
                            {{ step.number }}
                        </span>
                        <h3 class="mt-6 font-bold">{{ t(step.title) }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-500">
                            {{ t(step.text) }}
                        </p>
                    </article>
                </div>
            </section>

            <section id="contact" class="px-5 pb-20 sm:px-6 lg:px-8">
                <div
                    class="mx-auto max-w-7xl overflow-hidden rounded-3xl bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-12 text-center text-white shadow-2xl shadow-blue-900/15 sm:px-12"
                >
                    <h2 class="text-3xl font-extrabold tracking-tight">
                        {{ t('public.welcome.finalCta.title') }}
                    </h2>
                    <p
                        class="mx-auto mt-3 max-w-xl text-sm leading-6 text-blue-100"
                    >
                        {{ t('public.welcome.finalCta.description') }}
                    </p>
                    <div
                        class="mt-7 flex flex-col justify-center gap-3 sm:flex-row"
                    >
                        <Link
                            :href="`${register().url}?role=candidate`"
                            class="erin-focus inline-flex h-12 items-center justify-center rounded-xl bg-white px-6 text-sm font-bold text-blue-700 hover:bg-blue-50"
                        >
                            {{ t('public.welcome.finalCta.candidate') }}
                        </Link>
                        <Link
                            :href="pricing()"
                            class="erin-focus inline-flex h-12 items-center justify-center rounded-xl border border-white/30 px-6 text-sm font-bold text-white hover:bg-white/10"
                        >
                            {{ t('public.welcome.finalCta.plans') }}
                        </Link>
                        <Link
                            :href="contact()"
                            class="erin-focus inline-flex h-12 items-center justify-center rounded-xl border border-white/30 px-6 text-sm font-bold text-white hover:bg-white/10"
                        >
                            {{ t('public.common.contact') }}
                        </Link>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-200 bg-slate-50">
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
