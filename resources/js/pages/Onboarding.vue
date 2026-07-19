<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    BriefcaseBusiness,
    Check,
    FileCheck2,
    GraduationCap,
    Languages,
    ShieldCheck,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import { Button } from '@/components/ui/button';
import { useLocalizedField } from '@/composables/useLocalizedField';

type NamedOption = {
    id: number;
    slug?: string;
    code?: string;
    name_de?: string;
    name_en?: string;
};
type Plan = NamedOption & {
    name: string;
    price_cents?: number | null;
    term_months?: number | null;
    active_jobs_limit?: number | null;
    seat_limit?: number | null;
};
type Experience = {
    employer: string;
    position: string;
    country_code: string;
    started_at: string;
    ended_at: string;
    is_current: boolean;
    description: string;
};
type Education = {
    institution: string;
    qualification: string;
    field: string;
    country_code: string;
    started_at: string;
    completed_at: string;
};
type AvailabilitySlot = {
    weekday: number;
    starts_at: string;
    ends_at: string;
    timezone: string;
};
type CandidateProfile = {
    first_name?: string | null;
    last_name?: string | null;
    occupation_id?: number | null;
    current_country_code?: string | null;
    current_city?: string | null;
    phone?: string | null;
    whatsapp?: string | null;
    summary?: string | null;
    current_position?: string | null;
    desired_position?: string | null;
    experience_years?: number | null;
    relocation_ready?: boolean;
    requires_visa?: boolean;
    has_work_permit?: boolean;
    experiences?: Partial<Experience>[];
    educations?: Partial<Education>[];
    skills?: Array<{ id: number }>;
    languages?: Array<{ id: number; level: string }>;
    availability?: AvailabilitySlot[];
};
type Company = {
    name?: string;
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
        onboarding: {
            current_step: number;
            total_steps: number;
            saved_data?: Record<string, unknown>;
        };
        candidate_profile?: CandidateProfile | null;
        company?: Company | null;
        occupations?: NamedOption[];
        skills?: NamedOption[];
        languages?: NamedOption[];
        plans?: Plan[];
        document_types?: string[];
        publication_threshold?: number;
    }>(),
    {
        candidate_profile: null,
        company: null,
        occupations: () => [],
        skills: () => [],
        languages: () => [],
        plans: () => [],
        document_types: () => [],
        publication_threshold: 80,
    },
);
const { t } = useI18n();
const { localizedField } = useLocalizedField();
const currentStep = ref(props.onboarding.current_step);
const maximumReachable = ref(props.onboarding.current_step);
const progress = computed(() =>
    Math.round((currentStep.value / props.onboarding.total_steps) * 100),
);
const input =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm';
const textarea =
    'erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3.5 text-sm';
const candidateSteps = [
    { step: 2, icon: UserRound, label: 'onboarding.wizard.contact' },
    { step: 3, icon: BriefcaseBusiness, label: 'onboarding.wizard.profession' },
    { step: 4, icon: GraduationCap, label: 'onboarding.wizard.history' },
    { step: 5, icon: Languages, label: 'onboarding.wizard.skills' },
    { step: 6, icon: ShieldCheck, label: 'onboarding.wizard.uploads' },
    { step: 7, icon: FileCheck2, label: 'onboarding.wizard.finish' },
];
const companySteps = [
    { step: 2, icon: BriefcaseBusiness, label: 'onboarding.wizard.plan' },
    { step: 3, icon: FileCheck2, label: 'onboarding.wizard.companyData' },
];
const steps = computed(() =>
    props.role === 'candidate' ? candidateSteps : companySteps,
);
const advance = (step: number) => {
    maximumReachable.value = Math.max(maximumReachable.value, step + 1);
    currentStep.value = Math.min(props.onboarding.total_steps, step + 1);
};

type ContactData = {
    first_name: string;
    last_name: string;
    current_country_code: string;
    current_city: string;
    phone: string;
    whatsapp: string;
};
const contactForm = useForm<ContactData>({
    first_name: props.candidate_profile?.first_name ?? '',
    last_name: props.candidate_profile?.last_name ?? '',
    current_country_code: props.candidate_profile?.current_country_code ?? '',
    current_city: props.candidate_profile?.current_city ?? '',
    phone: props.candidate_profile?.phone ?? '',
    whatsapp: props.candidate_profile?.whatsapp ?? '',
});
type ContactField = keyof ContactData;
const contactFields: ContactField[] = [
    'first_name',
    'last_name',
    'current_country_code',
    'current_city',
    'phone',
    'whatsapp',
];
const setContactValue = (field: ContactField, event: Event) => {
    contactForm[field] = (event.target as HTMLInputElement).value;
};
const professionForm = useForm({
    occupation_id: props.candidate_profile?.occupation_id ?? null,
    current_position: props.candidate_profile?.current_position ?? '',
    desired_position: props.candidate_profile?.desired_position ?? '',
    experience_years: props.candidate_profile?.experience_years ?? 0,
    summary: props.candidate_profile?.summary ?? '',
    relocation_ready: props.candidate_profile?.relocation_ready ?? true,
    requires_visa: props.candidate_profile?.requires_visa ?? true,
    has_work_permit: props.candidate_profile?.has_work_permit ?? false,
});
const emptyExperience = (): Experience => ({
    employer: '',
    position: '',
    country_code: '',
    started_at: '',
    ended_at: '',
    is_current: false,
    description: '',
});
const emptyEducation = (): Education => ({
    institution: '',
    qualification: '',
    field: '',
    country_code: '',
    started_at: '',
    completed_at: '',
});
const savedExperiences = props.candidate_profile?.experiences?.map((item) => ({
    ...emptyExperience(),
    ...item,
}));
const historyForm = useForm({
    experiences: savedExperiences?.length
        ? savedExperiences
        : [emptyExperience()],
    educations:
        props.candidate_profile?.educations?.map((item) => ({
            ...emptyEducation(),
            ...item,
        })) ?? [],
});
const skillForm = useForm({
    skills: props.candidate_profile?.skills ?? ([] as Array<{ id: number }>),
    languages:
        props.candidate_profile?.languages ??
        ([] as Array<{ id: number; level: string }>),
});
const uploadForm = useForm({ acknowledged_private_uploads: false });
const photoForm = useForm<{ photo: File | null }>({ photo: null });
const documentForm = useForm<{
    type: string;
    title: string;
    file: File | null;
}>({
    type: props.document_types[0] ?? '',
    title: '',
    file: null,
});
const emptyAvailability = (): AvailabilitySlot => ({
    weekday: 1,
    starts_at: '08:00',
    ends_at: '12:00',
    timezone: 'Europe/Berlin',
});
const savedAvailability = props.candidate_profile?.availability?.map(
    (slot) => ({
        ...slot,
        starts_at: slot.starts_at.slice(0, 5),
        ends_at: slot.ends_at.slice(0, 5),
    }),
);
const finishForm = useForm({
    availability: savedAvailability?.length
        ? savedAvailability
        : [emptyAvailability()],
    publish_profile: false,
});
const selectedPlan =
    props.plans.find((plan) => plan.id === props.company?.current_plan_id) ??
    props.plans[0];
const planForm = useForm({ plan_slug: selectedPlan?.slug ?? '' });
type CompanyData = {
    legal_name: string;
    email: string;
    website: string;
    industry: string;
    employee_count: number;
    country_code: string;
    city: string;
    postal_code: string;
    address_line1: string;
};
const companyForm = useForm<CompanyData>({
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
type CompanyField = keyof CompanyData;
const companyFields: CompanyField[] = [
    'legal_name',
    'email',
    'website',
    'industry',
    'employee_count',
    'country_code',
    'city',
    'postal_code',
    'address_line1',
];
const setCompanyValue = (field: CompanyField, event: Event) => {
    const value = (event.target as HTMLInputElement).value;

    if (field === 'employee_count') {
        companyForm.employee_count = Number(value);
    } else {
        Object.assign(companyForm, { [field]: value });
    }
};
const hasSkill = (id: number) =>
    skillForm.skills.some((skill) => skill.id === id);
const toggleSkill = (id: number) => {
    skillForm.skills = hasSkill(id)
        ? skillForm.skills.filter((skill) => skill.id !== id)
        : [...skillForm.skills, { id }];
};
const language = (id: number) =>
    skillForm.languages.find((item) => item.id === id);
const toggleLanguage = (id: number) => {
    skillForm.languages = language(id)
        ? skillForm.languages.filter((item) => item.id !== id)
        : [...skillForm.languages, { id, level: 'A1' }];
};
const setLanguageLevel = (id: number, level: string) => {
    skillForm.languages = skillForm.languages.map((item) =>
        item.id === id ? { ...item, level } : item,
    );
};
</script>

<template>
    <Head :title="t('onboarding.metaTitle')" />
    <div
        class="erin-page"
        :data-test="
            role === 'company' ? 'company-onboarding' : 'candidate-onboarding'
        "
    >
        <PageHeader
            :eyebrow="t('onboarding.eyebrow')"
            :title="
                role === 'company'
                    ? t('onboarding.company.title')
                    : t('onboarding.candidate.title')
            "
            :description="t('onboarding.wizard.resumable')"
            :icon="role === 'company' ? BriefcaseBusiness : UserRound"
        />

        <section class="erin-panel p-5">
            <ProgressBar
                :value="progress"
                :label="
                    t('onboarding.wizard.progress', {
                        current: currentStep,
                        total: onboarding.total_steps,
                    })
                "
            />
            <div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="item in steps"
                    :key="item.step"
                    type="button"
                    :disabled="item.step > maximumReachable"
                    class="erin-focus flex items-center gap-3 rounded-xl border p-3 text-left disabled:cursor-not-allowed disabled:opacity-45"
                    :class="
                        item.step === currentStep
                            ? 'border-blue-300 bg-blue-50 text-blue-800'
                            : item.step < maximumReachable
                              ? 'border-emerald-200 bg-emerald-50'
                              : 'border-slate-200'
                    "
                    @click="currentStep = item.step"
                >
                    <span
                        class="grid size-8 place-items-center rounded-lg bg-white"
                    >
                        <Check
                            v-if="item.step < maximumReachable"
                            class="size-4 text-emerald-600"
                        />
                        <component :is="item.icon" v-else class="size-4" />
                    </span>
                    <span class="text-sm font-bold">{{ t(item.label) }}</span>
                </button>
            </div>
        </section>

        <form
            v-if="role === 'candidate' && currentStep === 2"
            @submit.prevent="
                contactForm.put('/onboarding/candidate/steps/2', {
                    preserveScroll: true,
                    onSuccess: () => advance(2),
                })
            "
        >
            <SectionCard :title="t('onboarding.wizard.contact')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label v-for="field in contactFields" :key="field">
                        <span class="text-sm font-bold text-slate-700">{{
                            t(`onboarding.wizard.fields.${field}`)
                        }}</span>
                        <input
                            :value="contactForm[field]"
                            :required="field !== 'whatsapp'"
                            :maxlength="
                                field === 'current_country_code' ? 2 : undefined
                            "
                            :class="input"
                            @input="setContactValue(field, $event)"
                        />
                        <InputError :message="contactForm.errors[field]" />
                    </label>
                </div>
                <Button
                    class="mt-5"
                    type="submit"
                    :disabled="contactForm.processing"
                >
                    {{ t('onboarding.wizard.saveContinue') }}
                    <ArrowRight class="size-4" />
                </Button>
            </SectionCard>
        </form>

        <form
            v-else-if="role === 'candidate' && currentStep === 3"
            @submit.prevent="
                professionForm.put('/onboarding/candidate/steps/3', {
                    preserveScroll: true,
                    onSuccess: () => advance(3),
                })
            "
        >
            <SectionCard :title="t('onboarding.wizard.profession')">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label>
                        <span class="text-sm font-bold">{{
                            t('onboarding.candidate.occupation')
                        }}</span>
                        <select
                            v-model="professionForm.occupation_id"
                            required
                            :class="input"
                        >
                            <option :value="null">
                                {{ t('onboarding.candidate.selectOccupation') }}
                            </option>
                            <option
                                v-for="occupation in occupations"
                                :key="occupation.id"
                                :value="occupation.id"
                            >
                                {{ localizedField(occupation) }}
                            </option>
                        </select>
                    </label>
                    <label>
                        <span class="text-sm font-bold">{{
                            t('onboarding.wizard.fields.current_position')
                        }}</span>
                        <input
                            v-model="professionForm.current_position"
                            :class="input"
                        />
                    </label>
                    <label>
                        <span class="text-sm font-bold">{{
                            t('onboarding.candidate.desiredPosition')
                        }}</span>
                        <input
                            v-model="professionForm.desired_position"
                            required
                            :class="input"
                        />
                    </label>
                    <label>
                        <span class="text-sm font-bold">{{
                            t('onboarding.candidate.experienceYears')
                        }}</span>
                        <input
                            v-model.number="professionForm.experience_years"
                            required
                            type="number"
                            min="0"
                            max="60"
                            :class="input"
                        />
                    </label>
                </div>
                <label class="mt-4 block">
                    <span class="text-sm font-bold">{{
                        t('onboarding.candidate.summaryTitle')
                    }}</span>
                    <textarea
                        v-model="professionForm.summary"
                        required
                        minlength="80"
                        rows="5"
                        :class="textarea"
                    />
                </label>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <label class="rounded-xl border p-3 text-sm"
                        ><input
                            v-model="professionForm.relocation_ready"
                            type="checkbox"
                            class="mr-2"
                        />{{ t('onboarding.candidate.relocationReady') }}</label
                    >
                    <label class="rounded-xl border p-3 text-sm"
                        ><input
                            v-model="professionForm.requires_visa"
                            type="checkbox"
                            class="mr-2"
                        />{{ t('onboarding.candidate.requiresVisa') }}</label
                    >
                    <label class="rounded-xl border p-3 text-sm"
                        ><input
                            v-model="professionForm.has_work_permit"
                            type="checkbox"
                            class="mr-2"
                        />{{ t('onboarding.candidate.hasWorkPermit') }}</label
                    >
                </div>
                <div class="mt-5 flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="currentStep = 2"
                        ><ArrowLeft class="size-4" />{{
                            t('onboarding.wizard.back')
                        }}</Button
                    >
                    <Button type="submit"
                        >{{ t('onboarding.wizard.saveContinue')
                        }}<ArrowRight class="size-4"
                    /></Button>
                </div>
            </SectionCard>
        </form>

        <form
            v-else-if="role === 'candidate' && currentStep === 4"
            @submit.prevent="
                historyForm.put('/onboarding/candidate/steps/4', {
                    preserveScroll: true,
                    onSuccess: () => advance(4),
                })
            "
        >
            <SectionCard :title="t('onboarding.wizard.history')">
                <article
                    v-for="(experience, index) in historyForm.experiences"
                    :key="index"
                    class="mb-4 rounded-xl border border-slate-200 p-4"
                >
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <input
                            v-model="experience.employer"
                            required
                            :aria-label="
                                t('candidate.profile.history.employer')
                            "
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.employer')
                            "
                        />
                        <input
                            v-model="experience.position"
                            required
                            :aria-label="
                                t('candidate.profile.history.position')
                            "
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.position')
                            "
                        />
                        <input
                            v-model="experience.country_code"
                            maxlength="2"
                            :aria-label="t('candidate.profile.history.country')"
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.country')
                            "
                        />
                        <input
                            v-model="experience.started_at"
                            required
                            type="date"
                            :aria-label="
                                t('candidate.profile.history.startedAt')
                            "
                            :class="input"
                        />
                        <input
                            v-model="experience.ended_at"
                            type="date"
                            :disabled="experience.is_current"
                            :aria-label="t('candidate.profile.history.endedAt')"
                            :class="input"
                        />
                        <label class="flex items-center gap-2 pt-3 text-sm"
                            ><input
                                v-model="experience.is_current"
                                type="checkbox"
                            />{{
                                t('candidate.profile.history.current')
                            }}</label
                        >
                    </div>
                    <Button
                        v-if="historyForm.experiences.length > 1"
                        class="mt-3"
                        type="button"
                        variant="ghost"
                        @click="historyForm.experiences.splice(index, 1)"
                    >
                        {{ t('onboarding.wizard.remove') }}
                    </Button>
                </article>
                <Button
                    type="button"
                    variant="outline"
                    @click="historyForm.experiences.push(emptyExperience())"
                >
                    {{ t('candidate.profile.history.addExperience') }}
                </Button>
                <h3 class="mt-7 text-sm font-extrabold text-slate-900">
                    {{ t('candidate.profile.history.educationTitle') }}
                </h3>
                <article
                    v-for="(education, index) in historyForm.educations"
                    :key="`education-${index}`"
                    class="mt-3 rounded-xl border border-slate-200 p-4"
                >
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <input
                            v-model="education.institution"
                            required
                            :aria-label="
                                t('candidate.profile.history.institution')
                            "
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.institution')
                            "
                        />
                        <input
                            v-model="education.qualification"
                            required
                            :aria-label="
                                t('candidate.profile.history.qualification')
                            "
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.qualification')
                            "
                        />
                        <input
                            v-model="education.field"
                            :aria-label="t('candidate.profile.history.field')"
                            :class="input"
                            :placeholder="t('candidate.profile.history.field')"
                        />
                        <input
                            v-model="education.country_code"
                            maxlength="2"
                            :aria-label="t('candidate.profile.history.country')"
                            :class="input"
                            :placeholder="
                                t('candidate.profile.history.country')
                            "
                        />
                        <input
                            v-model="education.started_at"
                            type="date"
                            :aria-label="
                                t('candidate.profile.history.startedAt')
                            "
                            :class="input"
                        />
                        <input
                            v-model="education.completed_at"
                            type="date"
                            :aria-label="
                                t('candidate.profile.history.completedAt')
                            "
                            :class="input"
                        />
                    </div>
                    <Button
                        class="mt-3"
                        type="button"
                        variant="ghost"
                        @click="historyForm.educations.splice(index, 1)"
                    >
                        {{ t('onboarding.wizard.remove') }}
                    </Button>
                </article>
                <Button
                    class="mt-3"
                    type="button"
                    variant="outline"
                    @click="historyForm.educations.push(emptyEducation())"
                >
                    {{ t('candidate.profile.history.addEducation') }}
                </Button>
                <div class="mt-5 flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="currentStep = 3"
                        ><ArrowLeft class="size-4" />{{
                            t('onboarding.wizard.back')
                        }}</Button
                    >
                    <Button type="submit"
                        >{{ t('onboarding.wizard.saveContinue')
                        }}<ArrowRight class="size-4"
                    /></Button>
                </div>
            </SectionCard>
        </form>

        <form
            v-else-if="role === 'candidate' && currentStep === 5"
            @submit.prevent="
                skillForm.put('/onboarding/candidate/steps/5', {
                    preserveScroll: true,
                    onSuccess: () => advance(5),
                })
            "
        >
            <div class="grid gap-6 lg:grid-cols-2">
                <SectionCard :title="t('candidate.profile.skills.title')">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="skill in skills"
                            :key="skill.id"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-xs font-bold"
                            :class="
                                hasSkill(skill.id)
                                    ? 'border-teal-300 bg-teal-50 text-teal-700'
                                    : 'border-slate-200'
                            "
                            @click="toggleSkill(skill.id)"
                        >
                            {{ localizedField(skill) }}
                        </button>
                    </div>
                </SectionCard>
                <SectionCard
                    :title="t('candidate.profile.skills.languagesTitle')"
                >
                    <div
                        v-for="item in languages"
                        :key="item.id"
                        class="mb-2 flex items-center gap-3 rounded-xl border p-3"
                    >
                        <input
                            :checked="!!language(item.id)"
                            type="checkbox"
                            :aria-label="
                                localizedField(item, 'name', item.code)
                            "
                            @change="toggleLanguage(item.id)"
                        />
                        <span class="flex-1 text-sm font-bold">{{
                            localizedField(item, 'name', item.code)
                        }}</span>
                        <select
                            v-if="language(item.id)"
                            :value="language(item.id)?.level"
                            :aria-label="`${localizedField(item, 'name', item.code)} CEFR`"
                            class="rounded-lg border px-2 py-1"
                            @change="
                                setLanguageLevel(
                                    item.id,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option
                                v-for="level in [
                                    'A1',
                                    'A2',
                                    'B1',
                                    'B2',
                                    'C1',
                                    'C2',
                                ]"
                                :key="level"
                            >
                                {{ level }}
                            </option>
                        </select>
                    </div>
                </SectionCard>
            </div>
            <div class="flex gap-2">
                <Button type="button" variant="outline" @click="currentStep = 4"
                    ><ArrowLeft class="size-4" />{{
                        t('onboarding.wizard.back')
                    }}</Button
                >
                <Button type="submit"
                    >{{ t('onboarding.wizard.saveContinue')
                    }}<ArrowRight class="size-4"
                /></Button>
            </div>
        </form>

        <div v-else-if="role === 'candidate' && currentStep === 6">
            <SectionCard :title="t('onboarding.wizard.uploads')">
                <p class="text-sm leading-6 text-slate-600">
                    {{ t('onboarding.wizard.uploadNotice') }}
                </p>
                <div class="mt-5 grid gap-4 lg:grid-cols-2">
                    <form
                        class="rounded-xl border border-slate-200 p-4"
                        @submit.prevent="
                            photoForm.post('/onboarding/candidate/photo', {
                                forceFormData: true,
                                preserveScroll: true,
                                onSuccess: () => photoForm.reset(),
                            })
                        "
                    >
                        <p class="text-sm font-extrabold text-slate-900">
                            {{ t('onboarding.wizard.profilePhoto') }}
                        </p>
                        <input
                            type="file"
                            accept="image/jpeg,image/png"
                            required
                            :aria-label="t('onboarding.wizard.profilePhoto')"
                            class="erin-focus mt-3 block w-full rounded-xl border border-slate-200 p-2 text-sm"
                            @change="
                                photoForm.photo =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                        <InputError :message="photoForm.errors.photo" />
                        <Button
                            class="mt-3"
                            type="submit"
                            :disabled="photoForm.processing || !photoForm.photo"
                        >
                            {{ t('onboarding.wizard.uploadPhoto') }}
                        </Button>
                    </form>
                    <form
                        class="rounded-xl border border-slate-200 p-4"
                        @submit.prevent="
                            documentForm.post(
                                '/onboarding/candidate/documents',
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        documentForm.reset('title', 'file'),
                                },
                            )
                        "
                    >
                        <p class="text-sm font-extrabold text-slate-900">
                            {{ t('candidate.profile.documents.uploadTitle') }}
                        </p>
                        <div class="mt-3 grid gap-3">
                            <select
                                v-model="documentForm.type"
                                required
                                :class="input"
                            >
                                <option
                                    v-for="type in document_types"
                                    :key="type"
                                    :value="type"
                                >
                                    {{
                                        t(
                                            `candidate.profile.documents.types.${type}`,
                                        )
                                    }}
                                </option>
                            </select>
                            <input
                                v-model="documentForm.title"
                                required
                                :class="input"
                                :placeholder="
                                    t(
                                        'candidate.profile.documents.documentTitle',
                                    )
                                "
                            />
                            <input
                                type="file"
                                required
                                :aria-label="
                                    t('candidate.profile.documents.file')
                                "
                                class="erin-focus block w-full rounded-xl border border-slate-200 p-2 text-sm"
                                @change="
                                    documentForm.file =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                        </div>
                        <InputError :message="documentForm.errors.file" />
                        <Button
                            class="mt-3"
                            type="submit"
                            :disabled="
                                documentForm.processing || !documentForm.file
                            "
                        >
                            {{ t('candidate.profile.documents.upload') }}
                        </Button>
                    </form>
                </div>
                <form
                    class="mt-4"
                    @submit.prevent="
                        uploadForm.put('/onboarding/candidate/steps/6', {
                            preserveScroll: true,
                            onSuccess: () => advance(6),
                        })
                    "
                >
                    <label
                        class="flex items-start gap-3 rounded-xl bg-blue-50 p-4 text-sm"
                    >
                        <input
                            v-model="uploadForm.acknowledged_private_uploads"
                            required
                            type="checkbox"
                            class="mt-1"
                        />
                        {{ t('onboarding.wizard.uploadAcknowledge') }}
                    </label>
                    <div class="mt-5 flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="currentStep = 5"
                            ><ArrowLeft class="size-4" />{{
                                t('onboarding.wizard.back')
                            }}</Button
                        >
                        <Button type="submit"
                            >{{ t('onboarding.wizard.saveContinue')
                            }}<ArrowRight class="size-4"
                        /></Button>
                    </div>
                </form>
            </SectionCard>
        </div>

        <form
            v-else-if="role === 'candidate' && currentStep === 7"
            @submit.prevent="finishForm.put('/onboarding/candidate/steps/7')"
        >
            <SectionCard :title="t('onboarding.wizard.finish')">
                <div class="mb-5">
                    <h3 class="text-sm font-extrabold text-slate-900">
                        {{ t('candidate.profile.availability.title') }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ t('candidate.profile.availability.description') }}
                    </p>
                    <article
                        v-for="(slot, index) in finishForm.availability"
                        :key="index"
                        class="mt-3 grid gap-3 rounded-xl border border-slate-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                    >
                        <select
                            v-model="slot.weekday"
                            :aria-label="
                                t('candidate.profile.availability.weekday')
                            "
                            :class="input"
                        >
                            <option
                                v-for="weekday in [1, 2, 3, 4, 5, 6, 7]"
                                :key="weekday"
                                :value="weekday"
                            >
                                {{
                                    t(
                                        `candidate.profile.availability.days.${weekday}`,
                                    )
                                }}
                            </option>
                        </select>
                        <input
                            v-model="slot.starts_at"
                            required
                            type="time"
                            :aria-label="
                                t('candidate.profile.availability.from')
                            "
                            :class="input"
                        />
                        <input
                            v-model="slot.ends_at"
                            required
                            type="time"
                            :aria-label="
                                t('candidate.profile.availability.until')
                            "
                            :class="input"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="finishForm.availability.length === 1"
                            @click="finishForm.availability.splice(index, 1)"
                        >
                            {{ t('onboarding.wizard.remove') }}
                        </Button>
                    </article>
                    <Button
                        class="mt-3"
                        type="button"
                        variant="outline"
                        @click="
                            finishForm.availability.push(emptyAvailability())
                        "
                    >
                        {{ t('candidate.profile.availability.add') }}
                    </Button>
                    <InputError :message="finishForm.errors.availability" />
                </div>
                <p class="text-sm leading-6 text-slate-600">
                    {{ t('onboarding.wizard.publishNotice') }}
                </p>
                <label
                    class="mt-4 flex items-start gap-3 rounded-xl border border-slate-200 p-4 text-sm"
                >
                    <input
                        v-model="finishForm.publish_profile"
                        type="checkbox"
                        class="mt-1"
                    />
                    {{
                        t('onboarding.wizard.publishNow', {
                            percentage: publication_threshold,
                        })
                    }}
                </label>
                <InputError :message="finishForm.errors.publish_profile" />
                <div class="mt-5 flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="currentStep = 6"
                        ><ArrowLeft class="size-4" />{{
                            t('onboarding.wizard.back')
                        }}</Button
                    >
                    <Button type="submit">{{
                        t('onboarding.candidate.complete')
                    }}</Button>
                </div>
            </SectionCard>
        </form>

        <form
            v-else-if="role === 'company' && currentStep === 2"
            @submit.prevent="
                planForm.put('/onboarding/company/steps/2', {
                    preserveScroll: true,
                    onSuccess: () => advance(2),
                })
            "
        >
            <SectionCard :title="t('onboarding.company.planTitle')">
                <div class="grid gap-4 md:grid-cols-3">
                    <label
                        v-for="plan in plans"
                        :key="plan.id"
                        class="cursor-pointer rounded-2xl border p-5"
                        :class="
                            planForm.plan_slug === plan.slug
                                ? 'border-blue-400 bg-blue-50'
                                : 'border-slate-200'
                        "
                    >
                        <input
                            v-model="planForm.plan_slug"
                            type="radio"
                            :value="plan.slug"
                            class="sr-only"
                        />
                        <strong>{{ plan.name }}</strong>
                        <p class="mt-2 text-2xl font-extrabold">
                            {{
                                plan.price_cents
                                    ? `${(plan.price_cents / 100).toLocaleString('de-DE')} €`
                                    : '–'
                            }}
                        </p>
                        <p class="mt-2 text-xs text-slate-600">
                            {{ plan.term_months }}
                            {{ t('onboarding.wizard.months') }}
                        </p>
                    </label>
                </div>
                <Button class="mt-5" type="submit"
                    >{{ t('onboarding.wizard.saveContinue')
                    }}<ArrowRight class="size-4"
                /></Button>
            </SectionCard>
        </form>

        <form
            v-else-if="role === 'company' && currentStep === 3"
            @submit.prevent="companyForm.put('/onboarding/company/steps/3')"
        >
            <SectionCard :title="t('onboarding.company.billingDetailsTitle')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label v-for="field in companyFields" :key="field">
                        <span class="text-sm font-bold">{{
                            t(`onboarding.wizard.fields.${field}`)
                        }}</span>
                        <input
                            :value="companyForm[field]"
                            :type="
                                field === 'email'
                                    ? 'email'
                                    : field === 'employee_count'
                                      ? 'number'
                                      : 'text'
                            "
                            :required="field !== 'website'"
                            :class="input"
                            @input="setCompanyValue(field, $event)"
                        />
                        <InputError :message="companyForm.errors[field]" />
                    </label>
                </div>
                <div class="mt-5 flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="currentStep = 2"
                        ><ArrowLeft class="size-4" />{{
                            t('onboarding.wizard.back')
                        }}</Button
                    >
                    <Button type="submit"
                        >{{ t('onboarding.company.saveAndContinue')
                        }}<ArrowRight class="size-4"
                    /></Button>
                </div>
            </SectionCard>
        </form>
    </div>
</template>
