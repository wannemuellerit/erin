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
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
        ? 'Auf Anfrage'
        : new Intl.NumberFormat('de-DE', {
              style: 'currency',
              currency,
              maximumFractionDigits: 0,
          }).format(cents / 100);
</script>

<template>
    <Head title="Konto einrichten" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Willkommen bei Erin"
            :title="
                role === 'company'
                    ? 'Unternehmen einrichten'
                    : 'Fachkräfteprofil starten'
            "
            :description="
                role === 'company'
                    ? 'Paket und Firmendaten bilden die Grundlage für die sichere Stripe-Freischaltung.'
                    : 'Mit diesen Angaben zeigen wir dir passende Stellen und Unternehmen.'
            "
            :icon="role === 'company' ? BriefcaseBusiness : UserRound"
        />

        <section class="erin-panel p-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-emerald-50 p-4">
                    <p class="text-xs font-bold text-emerald-700">1 · Konto</p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        E-Mail bestätigt
                    </p>
                </div>
                <div class="rounded-xl bg-blue-50 p-4">
                    <p class="text-xs font-bold text-blue-700">
                        2 · Einrichtung
                    </p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        Angaben vervollständigen
                    </p>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <p class="text-xs font-bold text-slate-500">3 · Start</p>
                    <p class="mt-1 text-sm font-extrabold text-slate-900">
                        {{
                            role === 'company'
                                ? 'Zahlung bestätigen'
                                : 'Profil ausbauen'
                        }}
                    </p>
                </div>
            </div>
            <ProgressBar
                class="mt-5"
                :value="66"
                label="Einrichtung"
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
                    title="Beruf und Wunschposition"
                    description="Diese Informationen steuern deine passenden Stellen."
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2">
                            <Label for="occupation_id">Berufsfeld *</Label>
                            <select
                                id="occupation_id"
                                v-model.number="candidateForm.occupation_id"
                                required
                                class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3.5 text-sm"
                            >
                                <option :value="null" disabled>
                                    Beruf auswählen
                                </option>
                                <option
                                    v-for="occupation in occupations"
                                    :key="occupation.id"
                                    :value="occupation.id"
                                >
                                    {{ occupation.name_de }}
                                </option>
                            </select>
                            <InputError
                                :message="candidateForm.errors.occupation_id"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="desired_position"
                                >Wunschposition *</Label
                            >
                            <Input
                                id="desired_position"
                                v-model="candidateForm.desired_position"
                                required
                                placeholder="z. B. Elektrikerin"
                            />
                            <InputError
                                :message="candidateForm.errors.desired_position"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="experience_years"
                                >Jahre Berufserfahrung *</Label
                            >
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
                            <Label for="phone">Telefonnummer *</Label>
                            <Input
                                id="phone"
                                v-model="candidateForm.phone"
                                required
                                autocomplete="tel"
                                placeholder="+48 ..."
                            />
                            <InputError :message="candidateForm.errors.phone" />
                        </label>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Wohnort und Wechselbereitschaft"
                    description="Herkunft und geschützte Merkmale werden nicht für das Matching gewichtet."
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2">
                            <Label for="current_country_code"
                                >Aktuelles Land (ISO) *</Label
                            >
                            <Input
                                id="current_country_code"
                                v-model="candidateForm.current_country_code"
                                required
                                maxlength="2"
                                class="uppercase"
                                placeholder="PL"
                            />
                            <InputError
                                :message="
                                    candidateForm.errors.current_country_code
                                "
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="current_city">Aktuelle Stadt *</Label>
                            <Input
                                id="current_city"
                                v-model="candidateForm.current_city"
                                required
                                placeholder="Wrocław"
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
                            Umzugsbereit
                        </label>
                        <label
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"
                        >
                            <input
                                v-model="candidateForm.requires_visa"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            Visum benötigt
                        </label>
                        <label
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 text-sm font-bold"
                        >
                            <input
                                v-model="candidateForm.has_work_permit"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            Arbeitserlaubnis vorhanden
                        </label>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Kurzprofil"
                    description="Mindestens 80 Zeichen helfen Unternehmen, deine Erfahrung einzuordnen."
                >
                    <textarea
                        v-model="candidateForm.summary"
                        required
                        minlength="80"
                        maxlength="5000"
                        rows="6"
                        class="erin-focus w-full rounded-xl border border-slate-200 p-3.5 text-sm leading-6"
                        placeholder="Beschreibe deine Erfahrung, Stärken und gewünschte Tätigkeit."
                    />
                    <div class="mt-2 flex justify-between text-xs">
                        <InputError :message="candidateForm.errors.summary" />
                        <span class="ml-auto text-slate-400">
                            {{ candidateForm.summary.length }} / 5.000
                        </span>
                    </div>
                </SectionCard>
            </div>

            <aside>
                <SectionCard title="Sicherer Start">
                    <div class="space-y-4 text-sm leading-6 text-slate-600">
                        <p class="flex gap-3">
                            <ShieldCheck
                                class="mt-0.5 size-5 shrink-0 text-teal-500"
                            />
                            Dein Profil bleibt anonym, bis du es ausdrücklich
                            veröffentlichst.
                        </p>
                        <p class="flex gap-3">
                            <Check
                                class="mt-0.5 size-5 shrink-0 text-teal-500"
                            />
                            Nach diesem Schritt ergänzt du Skills, Sprachen und
                            Dokumente.
                        </p>
                    </div>
                    <Button
                        type="submit"
                        class="mt-6 h-11 w-full rounded-xl"
                        :disabled="candidateForm.processing"
                    >
                        Einrichtung abschließen
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
                title="Paket auswählen"
                description="Die Zahlung erfolgt erst nach der Eingabe und Prüfung deiner Firmendaten."
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
                        <p class="mt-1 text-xs text-slate-400">
                            {{ plan.term_months }} Monate Laufzeit
                        </p>
                        <p class="mt-4 text-xs leading-5 text-slate-500">
                            {{ plan.description }}
                        </p>
                        <ul class="mt-4 space-y-2 text-xs text-slate-600">
                            <li>{{ plan.active_jobs_limit }} aktive Stellen</li>
                            <li>{{ plan.seat_limit }} Recruiter-Sitze</li>
                            <li>{{ plan.ai_credits_monthly }} KI-Credits</li>
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
                    title="Firmen- und Rechnungsdaten"
                    description="Stripe verwendet diese Angaben für Checkout, Steuerberechnung und Rechnungen."
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-2 sm:col-span-2">
                            <Label for="legal_name"
                                >Rechtlicher Firmenname *</Label
                            >
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
                            <Label for="company_email"
                                >Rechnungs-E-Mail *</Label
                            >
                            <Input
                                id="company_email"
                                v-model="companyForm.email"
                                required
                                type="email"
                            />
                            <InputError :message="companyForm.errors.email" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="website">Webseite</Label>
                            <Input
                                id="website"
                                v-model="companyForm.website"
                                type="url"
                                placeholder="https://..."
                            />
                            <InputError :message="companyForm.errors.website" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="industry">Branche *</Label>
                            <Input
                                id="industry"
                                v-model="companyForm.industry"
                                required
                                placeholder="Elektrotechnik"
                            />
                            <InputError
                                :message="companyForm.errors.industry"
                            />
                        </label>
                        <label class="grid gap-2">
                            <Label for="employee_count">Mitarbeitende *</Label>
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
                            <Label for="address_line1">Straße *</Label>
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
                            <Label for="postal_code">Postleitzahl *</Label>
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
                            <Label for="city">Stadt *</Label>
                            <Input
                                id="city"
                                v-model="companyForm.city"
                                required
                                autocomplete="address-level2"
                            />
                            <InputError :message="companyForm.errors.city" />
                        </label>
                        <label class="grid gap-2">
                            <Label for="country_code">Land (ISO) *</Label>
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
                    <SectionCard title="Nächster Schritt">
                        <CreditCard class="size-8 text-[var(--erin-primary)]" />
                        <p class="mt-4 text-sm leading-6 text-slate-600">
                            Nach dem Speichern prüfst du alle Daten auf der
                            Abrechnungsseite und wirst anschließend zum sicheren
                            Stripe Checkout weitergeleitet.
                        </p>
                        <p class="mt-3 text-xs leading-5 text-slate-400">
                            Das Firmenportal wird ausschließlich durch einen
                            bestätigten Stripe-Webhook freigeschaltet.
                        </p>
                        <Button
                            type="submit"
                            class="mt-6 h-11 w-full rounded-xl"
                            :disabled="companyForm.processing"
                        >
                            Speichern und weiter
                            <ArrowRight class="size-4" />
                        </Button>
                    </SectionCard>
                </aside>
            </div>
        </form>
    </div>
</template>
