<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    BriefcaseBusiness,
    Check,
    CreditCard,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import Textarea from '@/components/product/Textarea.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormatters } from '@/composables/useFormatters';
import { useLocalizedField } from '@/composables/useLocalizedField';

type Occupation = {
    id: number;
    slug: string;
    name_de: string;
    name_en: string;
};

type Plan = {
    id: number;
    slug: string;
    name: string;
    description?: string | null;
    price_cents?: number | null;
    currency: string;
    term_months?: number | null;
    active_jobs_limit?: number | null;
    seat_limit?: number | null;
    ai_credits_monthly?: number | null;
    job_boosts_per_term?: number | null;
    visa_credits_per_term?: number | null;
};

type CandidateProfile = {
    occupation_id?: number | null;
    current_country_code?: string | null;
    current_city?: string | null;
    phone?: string | null;
    summary?: string | null;
    desired_position?: string | null;
    experience_years?: number | null;
    relocation_ready?: boolean;
    requires_visa?: boolean;
    has_work_permit?: boolean;
};

type Company = {
    id: number;
    name: string;
    legal_name?: string | null;
    email?: string | null;
    website?: string | null;
    industry?: string | null;
    employee_count?: number | null;
    country_code?: string | null;
    city?: string | null;
    postal_code?: string | null;
    address_line1?: string | null;
    current_plan_id?: number | null;
};

const props = withDefaults(
    defineProps<{
        role: 'candidate' | 'company';
        candidate_profile?: CandidateProfile | null;
        company?: Company | null;
        occupations?: Occupation[];
        plans?: Plan[];
    }>(),
    {
        candidate_profile: null,
        company: null,
        occupations: () => [],
        plans: () => [],
    },
);
const { t, te } = useI18n();
const { formatCurrency, formatNumber } = useFormatters();
const { localizedField } = useLocalizedField();

const selectedPlan = computed(
    () =>
        props.plans.find(
            (plan) => plan.id === props.company?.current_plan_id,
        ) ?? props.plans[0],
);

const candidateForm = useForm({
    occupation_id: props.candidate_profile?.occupation_id ?? null,
    current_country_code: props.candidate_profile?.current_country_code ?? '',
    current_city: props.candidate_profile?.current_city ?? '',
    phone: props.candidate_profile?.phone ?? '',
    summary: props.candidate_profile?.summary ?? '',
    desired_position: props.candidate_profile?.desired_position ?? '',
    experience_years: props.candidate_profile?.experience_years ?? 0,
    relocation_ready: props.candidate_profile?.relocation_ready ?? true,
    requires_visa: props.candidate_profile?.requires_visa ?? true,
    has_work_permit: props.candidate_profile?.has_work_permit ?? false,
});

const companyForm = useForm({
    plan_slug: selectedPlan.value?.slug ?? '',
    legal_name: props.company?.legal_name ?? props.company?.name ?? '',
    email: props.company?.email ?? '',
    website: props.company?.website ?? '',
    industry: props.company?.industry ?? '',
    employee_count: props.company?.employee_count ?? 1,
    country_code: props.company?.country_code ?? 'DE',
    city: props.company?.city ?? '',
    postal_code: props.company?.postal_code ?? '',
    address_line1: props.company?.address_line1 ?? '',
});

const money = (cents?: number | null, currency = 'EUR') =>
    cents == null
        ? t('onboarding.company.onRequest')
        : formatCurrency(cents / 100, currency, {
              maximumFractionDigits: 0,
          });
const planDescription = (plan: Plan) => {
    const key = `public.pricing.planDescriptions.${plan.slug}`;

    return te(key) ? t(key) : (plan.description ?? '');
};
</script>

<template>
    <Head :title="t('onboarding.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('onboarding.eyebrow')"
            :title="
                role === 'company'
                    ? t('onboarding.company.title')
                    : t('onboarding.candidate.title')
            "
            :description="
                role === 'company'
                    ? t('onboarding.company.description')
                    : t('onboarding.candidate.description')
            "
            :icon="role === 'company' ? BriefcaseBusiness : UserRound"
        />

        <section class="erin-panel p-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-emerald-50 p-4">
                    <p class="text-xs font-bold text-emerald-700">
                        {{ t('onboarding.steps.account.label') }}
                    </p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        {{ t('onboarding.steps.account.description') }}
                    </p>
                </div>
                <div class="rounded-xl bg-blue-50 p-4">
                    <p class="text-xs font-bold text-blue-700">
                        {{ t('onboarding.steps.setup.label') }}
                    </p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        {{ t('onboarding.steps.setup.description') }}
                    </p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">
                        {{ t('onboarding.steps.start.label') }}
                    </p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        {{
                            role === 'company'
                                ? t('onboarding.steps.start.companyDescription')
                                : t(
                                      'onboarding.steps.start.candidateDescription',
                                  )
                        }}
                    </p>
                </div>
            </div>
            <ProgressBar
                class="mt-5"
                :value="66"
                :label="t('onboarding.progress')"
                :show-value="false"
            />
        </section>

        <form
            v-if="role === 'candidate'"
            class="grid gap-6 xl:grid-cols-[1fr_20rem]"
            data-test="candidate-onboarding"
            @submit.prevent="candidateForm.put('/onboarding/candidate')"
        >
            <div class="space-y-6">
                <SectionCard
                    :title="t('onboarding.candidate.professionTitle')"
                    :description="
                        t('onboarding.candidate.professionDescription')
                    "
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2">
                            <Label for="occupation_id">
                                {{ t('onboarding.candidate.occupation') }} *
                            </Label>
                            <select
                                id="occupation_id"
                                v-model.number="candidateForm.occupation_id"
                                required
                                class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                            >
                                <option :value="null" disabled>
                                    {{
                                        t(
                                            'onboarding.candidate.selectOccupation',
                                        )
                                    }}
                                </option>
                                <option
                                    v-for="occupation in occupations"
                                    :key="occupation.id"
                                    :value="occupation.id"
                                >
                                    {{
                                        localizedField(
                                            occupation,
                                            'name',
                                            occupation.slug,
                                        )
                                    }}
                                </option>
                            </select>
                            <InputError
                                :message="candidateForm.errors.occupation_id"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="desired_position">
                                {{ t('onboarding.candidate.desiredPosition') }}
                                *
                            </Label>
                            <Input
                                id="desired_position"
                                v-model="candidateForm.desired_position"
                                required
                                :placeholder="
                                    t(
                                        'onboarding.candidate.desiredPositionPlaceholder',
                                    )
                                "
                            />
                            <InputError
                                :message="candidateForm.errors.desired_position"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="experience_years">
                                {{ t('onboarding.candidate.experienceYears') }}
                                *
                            </Label>
                            <Input
                                id="experience_years"
                                v-model.number="candidateForm.experience_years"
                                required
                                min="0"
                                max="60"
                                step="0.5"
                                type="number"
                            />
                            <InputError
                                :message="candidateForm.errors.experience_years"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="phone">
                                {{ t('onboarding.candidate.phone') }} *
                            </Label>
                            <Input
                                id="phone"
                                v-model="candidateForm.phone"
                                required
                                autocomplete="tel"
                                :placeholder="
                                    t('onboarding.candidate.phonePlaceholder')
                                "
                            />
                            <InputError :message="candidateForm.errors.phone" />
                        </label>
                    </div>
                </SectionCard>

                <SectionCard
                    :title="t('onboarding.candidate.locationTitle')"
                    :description="t('onboarding.candidate.locationDescription')"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2">
                            <Label for="current_country_code">
                                {{ t('onboarding.candidate.currentCountry') }} *
                            </Label>
                            <Input
                                id="current_country_code"
                                v-model="candidateForm.current_country_code"
                                required
                                maxlength="2"
                                class="uppercase"
                                :placeholder="
                                    t(
                                        'onboarding.candidate.currentCountryPlaceholder',
                                    )
                                "
                            />
                            <InputError
                                :message="
                                    candidateForm.errors.current_country_code
                                "
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="current_city">
                                {{ t('onboarding.candidate.currentCity') }} *
                            </Label>
                            <Input
                                id="current_city"
                                v-model="candidateForm.current_city"
                                required
                                :placeholder="
                                    t(
                                        'onboarding.candidate.currentCityPlaceholder',
                                    )
                                "
                            />
                            <InputError
                                :message="candidateForm.errors.current_city"
                            />
                        </label>
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <label
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"
                        >
                            <input
                                v-model="candidateForm.relocation_ready"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            {{ t('onboarding.candidate.relocationReady') }}
                        </label>
                        <label
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"
                        >
                            <input
                                v-model="candidateForm.requires_visa"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            {{ t('onboarding.candidate.requiresVisa') }}
                        </label>
                        <label
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"
                        >
                            <input
                                v-model="candidateForm.has_work_permit"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            {{ t('onboarding.candidate.hasWorkPermit') }}
                        </label>
                    </div>
                </SectionCard>

                <SectionCard
                    :title="t('onboarding.candidate.summaryTitle')"
                    :description="t('onboarding.candidate.summaryDescription')"
                >
                    <Textarea
                        v-model="candidateForm.summary"
                        required
                        minlength="80"
                        maxlength="5000"
                        rows="6"
                        class="p-3.5"
                        :placeholder="
                            t('onboarding.candidate.summaryPlaceholder')
                        "
                    />
                    <div class="mt-2 flex justify-between text-xs">
                        <InputError :message="candidateForm.errors.summary" />
                        <span class="ml-auto text-slate-600">
                            {{ formatNumber(candidateForm.summary.length) }} /
                            {{ formatNumber(5000) }}
                        </span>
                    </div>
                </SectionCard>
            </div>

            <aside>
                <SectionCard
                    :title="t('onboarding.candidate.secureStartTitle')"
                >
                    <div class="space-y-4 text-sm leading-6 text-slate-600">
                        <p class="flex gap-3">
                            <ShieldCheck
                                class="mt-0.5 size-5 shrink-0 text-teal-500"
                            />
                            {{
                                t('onboarding.candidate.anonymousProfileNotice')
                            }}
                        </p>
                        <p class="flex gap-3">
                            <Check
                                class="mt-0.5 size-5 shrink-0 text-teal-500"
                            />
                            {{ t('onboarding.candidate.nextProfileSteps') }}
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="mt-6 h-11 w-full rounded-xl"
                        :disabled="candidateForm.processing"
                    >
                        {{ t('onboarding.candidate.complete') }}
                        <ArrowRight class="size-4" />
                    </Button>
                </SectionCard>
            </aside>
        </form>

        <form
            v-else
            class="space-y-6"
            data-test="company-onboarding"
            @submit.prevent="companyForm.put('/onboarding/company')"
        >
            <SectionCard
                :title="t('onboarding.company.planTitle')"
                :description="t('onboarding.company.planDescription')"
            >
                <div class="grid gap-4 md:grid-cols-3">
                    <button
                        v-for="plan in plans"
                        :key="plan.id"
                        type="button"
                        class="relative rounded-2xl border p-5 text-left transition"
                        :class="
                            companyForm.plan_slug === plan.slug
                                ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500'
                                : 'border-slate-200 hover:border-slate-300'
                        "
                        @click="companyForm.plan_slug = plan.slug"
                    >
                        <Check
                            v-if="companyForm.plan_slug === plan.slug"
                            class="absolute top-4 right-4 size-5 text-blue-600"
                        />
                        <p class="font-extrabold text-slate-950">
                            {{ plan.name }}
                        </p>
                        <p class="mt-3 text-2xl font-extrabold">
                            {{ money(plan.price_cents, plan.currency) }}
                        </p>
                        <p class="mt-1 text-xs text-slate-600">
                            {{
                                t('onboarding.company.termMonths', {
                                    count: plan.term_months ?? 0,
                                })
                            }}
                        </p>
                        <p class="mt-4 text-xs leading-5 text-slate-700">
                            {{ planDescription(plan) }}
                        </p>
                        <ul class="mt-4 space-y-2 text-xs text-slate-600">
                            <li>
                                {{
                                    t('onboarding.company.activeJobs', {
                                        count:
                                            plan.active_jobs_limit ??
                                            t('onboarding.company.unlimited'),
                                    })
                                }}
                            </li>
                            <li>
                                {{
                                    t('onboarding.company.recruiterSeats', {
                                        count:
                                            plan.seat_limit ??
                                            t('onboarding.company.unlimited'),
                                    })
                                }}
                            </li>
                            <li>
                                {{
                                    t('onboarding.company.aiCredits', {
                                        count: plan.ai_credits_monthly ?? 0,
                                    })
                                }}
                            </li>
                        </ul>
                    </button>
                </div>
                <InputError
                    class="mt-3"
                    :message="companyForm.errors.plan_slug"
                />
            </SectionCard>

            <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
                <SectionCard
                    :title="t('onboarding.company.billingDetailsTitle')"
                    :description="
                        t('onboarding.company.billingDetailsDescription')
                    "
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 sm:col-span-2">
                            <Label for="legal_name">
                                {{ t('onboarding.company.legalName') }} *
                            </Label>
                            <Input
                                id="legal_name"
                                v-model="companyForm.legal_name"
                                required
                                autocomplete="organization"
                            />
                            <InputError
                                :message="companyForm.errors.legal_name"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="company_email">
                                {{ t('onboarding.company.billingEmail') }} *
                            </Label>
                            <Input
                                id="company_email"
                                v-model="companyForm.email"
                                required
                                type="email"
                            />
                            <InputError :message="companyForm.errors.email" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="website">
                                {{ t('onboarding.company.website') }}
                            </Label>
                            <Input
                                id="website"
                                v-model="companyForm.website"
                                type="url"
                                :placeholder="
                                    t('onboarding.company.websitePlaceholder')
                                "
                            />
                            <InputError :message="companyForm.errors.website" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="industry">
                                {{ t('onboarding.company.industry') }} *
                            </Label>
                            <Input
                                id="industry"
                                v-model="companyForm.industry"
                                required
                                :placeholder="
                                    t('onboarding.company.industryPlaceholder')
                                "
                            />
                            <InputError
                                :message="companyForm.errors.industry"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="employee_count">
                                {{ t('onboarding.company.employees') }} *
                            </Label>
                            <Input
                                id="employee_count"
                                v-model.number="companyForm.employee_count"
                                required
                                min="1"
                                type="number"
                            />
                            <InputError
                                :message="companyForm.errors.employee_count"
                            />
                        </label>
                        <label class="grid gap-2 sm:col-span-2">
                            <Label for="address_line1">
                                {{ t('onboarding.company.street') }} *
                            </Label>
                            <Input
                                id="address_line1"
                                v-model="companyForm.address_line1"
                                required
                                autocomplete="street-address"
                            />
                            <InputError
                                :message="companyForm.errors.address_line1"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="postal_code">
                                {{ t('onboarding.company.postalCode') }} *
                            </Label>
                            <Input
                                id="postal_code"
                                v-model="companyForm.postal_code"
                                required
                                autocomplete="postal-code"
                            />
                            <InputError
                                :message="companyForm.errors.postal_code"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="city">
                                {{ t('onboarding.company.city') }} *
                            </Label>
                            <Input
                                id="city"
                                v-model="companyForm.city"
                                required
                                autocomplete="address-level2"
                            />
                            <InputError :message="companyForm.errors.city" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="country_code">
                                {{ t('onboarding.company.country') }} *
                            </Label>
                            <Input
                                id="country_code"
                                v-model="companyForm.country_code"
                                required
                                maxlength="2"
                                class="uppercase"
                            />
                            <InputError
                                :message="companyForm.errors.country_code"
                            />
                        </label>
                    </div>
                </SectionCard>

                <aside>
                    <SectionCard :title="t('onboarding.company.nextStepTitle')">
                        <CreditCard class="size-8 text-[var(--erin-primary)]" />
                        <p class="mt-4 text-sm leading-6 text-slate-600">
                            {{ t('onboarding.company.checkoutDescription') }}
                        </p>
                        <p class="mt-3 text-xs leading-5 text-slate-600">
                            {{ t('onboarding.company.webhookNotice') }}
                        </p>
                        <Button
                            type="submit"
                            class="mt-6 h-11 w-full rounded-xl"
                            :disabled="companyForm.processing"
                        >
                            {{ t('onboarding.company.saveAndContinue') }}
                            <ArrowRight class="size-4" />
                        </Button>
                    </SectionCard>
                </aside>
            </div>
        </form>
    </div>
</template>
