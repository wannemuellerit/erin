<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    CalendarDays,
    Download,
    FileText,
    GraduationCap,
    GripVertical,
    ImagePlus,
    Languages as LanguagesIcon,
    LockKeyhole,
    Plus,
    Save,
    ShieldCheck,
    Trash2,
    Upload,
    UserRound,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useLocalizedField } from '@/composables/useLocalizedField';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { documents, publish, update } from '@/routes/candidate/profile';
import type { StatusTone } from '@/types';

type NamedOption = {
    id: number;
    name_de?: string;
    name_en?: string;
    code?: string;
};
type CandidateExperience = {
    id?: number;
    employer?: string | null;
    position?: string | null;
    country_code?: string | null;
    started_at?: string | null;
    ended_at?: string | null;
    is_current?: boolean;
    description?: string | null;
};
type CandidateEducation = {
    id?: number;
    institution?: string | null;
    qualification?: string | null;
    field?: string | null;
    country_code?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
    description?: string | null;
};
type ProfileFormData = {
    first_name: string;
    last_name: string;
    email: string;
    birth_date: string;
    gender: string;
    nationality_country_code: string;
    current_country_code: string;
    current_city: string;
    phone: string;
    whatsapp: string;
    summary: string;
    occupation_id: number | null;
    current_position: string;
    desired_position: string;
    experience_years: number;
    highest_qualification: string;
    driving_licenses: string[];
    travel_ready: boolean;
    relocation_ready: boolean;
    available_from: string;
    salary_expectation_cents: number | null;
    salary_currency: string;
    employment_preferences: string[];
    weekly_hours: number | null;
    requires_visa: boolean;
    has_work_permit: boolean;
    skills: Array<{
        id: number;
        proficiency: number | null;
        experience_years: number | null;
    }>;
    languages: Array<{ id: number; level: string }>;
    experiences: Array<{
        id?: number;
        employer: string;
        position: string;
        country_code: string;
        started_at: string;
        ended_at: string;
        is_current: boolean;
        description: string;
    }>;
    educations: Array<{
        id?: number;
        institution: string;
        qualification: string;
        field: string;
        country_code: string;
        started_at: string;
        completed_at: string;
        description: string;
    }>;
    availability: Array<{
        weekday: number;
        starts_at: string;
        ends_at: string;
        timezone: string;
    }>;
};
type Profile = {
    id: number;
    first_name?: string | null;
    last_name?: string | null;
    birth_date?: string | null;
    gender?: string | null;
    nationality_country_code?: string | null;
    current_country_code?: string | null;
    current_city?: string | null;
    phone?: string | null;
    whatsapp?: string | null;
    summary?: string | null;
    occupation_id?: number | null;
    current_position?: string | null;
    desired_position?: string | null;
    experience_years?: number | null;
    highest_qualification?: string | null;
    driving_licenses?: string[] | null;
    travel_ready?: boolean;
    relocation_ready?: boolean;
    available_from?: string | null;
    salary_expectation_cents?: number | null;
    salary_currency?: string;
    employment_preferences?: string[] | null;
    weekly_hours?: number | null;
    requires_visa?: boolean;
    has_work_permit?: boolean;
    published_at?: string | null;
    profile_photo_url?: string | null;
    profile_photo_scan_result?: string | null;
    occupation?: NamedOption | null;
    skills?: Array<
        NamedOption & {
            pivot?: {
                proficiency?: number | null;
                experience_years?: number | null;
            };
        }
    >;
    languages?: Array<NamedOption & { pivot?: { level?: string } }>;
    experiences?: CandidateExperience[];
    educations?: CandidateEducation[];
    documents?: Array<{
        id: number;
        type: string;
        title: string;
        original_name: string;
        mime_type?: string;
        size_bytes?: number;
        status: string;
        scan_result?: string | null;
        rejection_reason?: string | null;
        download_url?: string | null;
    }>;
};
type ProfileStatus = {
    percentage: number;
    completed: string[];
    missing: string[];
    can_apply: boolean;
    required_percentage?: number;
};

const props = withDefaults(
    defineProps<{
        profile?: Profile | null;
        profile_status?: ProfileStatus;
        occupations?: NamedOption[];
        skills?: NamedOption[];
        languages?: NamedOption[];
        document_types?: string[];
        account_email?: string;
        availability?: Array<{
            weekday: number;
            starts_at: string;
            ends_at: string;
            timezone: string;
        }>;
    }>(),
    {
        profile: null,
        profile_status: () => ({
            percentage: 0,
            completed: [],
            missing: [],
            can_apply: false,
            required_percentage: 80,
        }),
        occupations: () => [],
        skills: () => [],
        languages: () => [],
        document_types: () => [],
        account_email: '',
        availability: () => [],
    },
);
const { t, te } = useI18n();
const { localizedField } = useLocalizedField();
const { statusLabel } = useStatusLabels();
const active = ref('personal');
const tabs = computed(() => [
    {
        key: 'personal',
        label: t('candidate.profile.tabs.personal'),
        icon: UserRound,
    },
    {
        key: 'profession',
        label: t('candidate.profile.tabs.profession'),
        icon: BriefcaseBusiness,
    },
    {
        key: 'history',
        label: t('candidate.profile.tabs.history'),
        icon: GraduationCap,
    },
    {
        key: 'skills',
        label: t('candidate.profile.tabs.skills'),
        icon: LanguagesIcon,
    },
    {
        key: 'availability',
        label: t('candidate.profile.tabs.availability'),
        icon: CalendarDays,
    },
    {
        key: 'documents',
        label: t('candidate.profile.tabs.documents'),
        icon: FileText,
    },
]);
const form = useForm<ProfileFormData>({
    first_name: props.profile?.first_name ?? '',
    last_name: props.profile?.last_name ?? '',
    email: props.account_email,
    birth_date: props.profile?.birth_date ?? '',
    gender: props.profile?.gender ?? '',
    nationality_country_code: props.profile?.nationality_country_code ?? '',
    current_country_code: props.profile?.current_country_code ?? '',
    current_city: props.profile?.current_city ?? '',
    phone: props.profile?.phone ?? '',
    whatsapp: props.profile?.whatsapp ?? '',
    summary: props.profile?.summary ?? '',
    occupation_id: props.profile?.occupation_id ?? (null as number | null),
    current_position: props.profile?.current_position ?? '',
    desired_position: props.profile?.desired_position ?? '',
    experience_years: props.profile?.experience_years ?? 0,
    highest_qualification: props.profile?.highest_qualification ?? '',
    driving_licenses: props.profile?.driving_licenses ?? ([] as string[]),
    travel_ready: props.profile?.travel_ready ?? false,
    relocation_ready: props.profile?.relocation_ready ?? false,
    available_from: props.profile?.available_from ?? '',
    salary_expectation_cents:
        props.profile?.salary_expectation_cents ?? (null as number | null),
    salary_currency: props.profile?.salary_currency ?? 'EUR',
    employment_preferences:
        props.profile?.employment_preferences ?? ([] as string[]),
    weekly_hours: props.profile?.weekly_hours ?? (null as number | null),
    requires_visa: props.profile?.requires_visa ?? false,
    has_work_permit: props.profile?.has_work_permit ?? false,
    skills:
        props.profile?.skills?.map((skill) => ({
            id: skill.id,
            proficiency: skill.pivot?.proficiency ?? null,
            experience_years: skill.pivot?.experience_years ?? null,
        })) ??
        ([] as Array<{
            id: number;
            proficiency: number | null;
            experience_years: number | null;
        }>),
    languages:
        props.profile?.languages?.map((language) => ({
            id: language.id,
            level: language.pivot?.level ?? 'A1',
        })) ?? ([] as Array<{ id: number; level: string }>),
    experiences:
        props.profile?.experiences?.map((experience) => ({
            id: experience.id,
            employer: experience.employer ?? '',
            position: experience.position ?? '',
            country_code: experience.country_code ?? '',
            started_at: experience.started_at ?? '',
            ended_at: experience.ended_at ?? '',
            is_current: experience.is_current ?? false,
            description: experience.description ?? '',
        })) ?? [],
    educations:
        props.profile?.educations?.map((education) => ({
            id: education.id,
            institution: education.institution ?? '',
            qualification: education.qualification ?? '',
            field: education.field ?? '',
            country_code: education.country_code ?? '',
            started_at: education.started_at ?? '',
            completed_at: education.completed_at ?? '',
            description: education.description ?? '',
        })) ?? [],
    availability: props.availability.map((slot) => ({
        weekday: slot.weekday,
        starts_at: slot.starts_at.slice(0, 5),
        ends_at: slot.ends_at.slice(0, 5),
        timezone: slot.timezone,
    })),
});
const documentForm = useForm({
    type: props.document_types[0] ?? '',
    title: '',
    file: null as File | null,
    expires_at: '',
});
const photoForm = useForm({
    photo: null as File | null,
});
const name = computed(
    () =>
        [props.profile?.first_name, props.profile?.last_name]
            .filter(Boolean)
            .join(' ') || t('candidate.profile.setupFallback'),
);
const input =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm';
const hasSkill = (id: number) => form.skills.some((skill) => skill.id === id);
const toggleSkill = (id: number) => {
    form.skills = hasSkill(id)
        ? form.skills.filter((skill) => skill.id !== id)
        : [...form.skills, { id, proficiency: null, experience_years: null }];
};
const hasLanguage = (id: number) =>
    form.languages.some((language) => language.id === id);
const toggleLanguage = (id: number) => {
    form.languages = hasLanguage(id)
        ? form.languages.filter((language) => language.id !== id)
        : [...form.languages, { id, level: 'A1' }];
};
const languageLevel = (id: number) =>
    form.languages.find((language) => language.id === id)?.level ?? 'A1';
const updateLanguageLevel = (id: number, level: string) => {
    form.languages = form.languages.map((language) =>
        language.id === id ? { ...language, level } : language,
    );
};
const documentTone = (status: string): StatusTone =>
    status === 'verified'
        ? 'green'
        : status === 'rejected'
          ? 'red'
          : status === 'in_review'
            ? 'blue'
            : 'yellow';
const submitDocument = () =>
    documentForm.post(documents.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => documentForm.reset(),
    });
const missingLabel = (missing: string) => {
    const key = `candidate.profile.missing.${missing}`;

    return te(key) ? t(key) : missing.replaceAll('_', ' ');
};
const documentTypeLabel = (type: string) => {
    const key = `candidate.profile.documents.types.${type}`;

    return te(key) ? t(key) : type.replaceAll('_', ' ');
};
const submitPhoto = () => {
    if (!photoForm.photo) {
        return;
    }

    photoForm.post('/candidate/profile/photo', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => photoForm.reset(),
    });
};
const deletePhoto = () =>
    router.delete('/candidate/profile/photo', { preserveScroll: true });
const addExperience = () =>
    form.experiences.push({
        employer: '',
        position: '',
        country_code: '',
        started_at: '',
        ended_at: '',
        is_current: false,
        description: '',
    });
const addEducation = () =>
    form.educations.push({
        institution: '',
        qualification: '',
        field: '',
        country_code: '',
        started_at: '',
        completed_at: '',
        description: '',
    });
const moveItem = <T,>(items: T[], index: number, offset: number) => {
    const destination = index + offset;

    if (destination < 0 || destination >= items.length) {
        return;
    }

    const [item] = items.splice(index, 1);
    items.splice(destination, 0, item);
};
const addAvailability = () =>
    form.availability.push({
        weekday: 1,
        starts_at: '08:00',
        ends_at: '12:00',
        timezone: 'Europe/Berlin',
    });
const toggleArrayValue = (
    field: 'driving_licenses' | 'employment_preferences',
    value: string,
) => {
    const values = form[field];
    form[field] = values.includes(value)
        ? values.filter((entry) => entry !== value)
        : [...values, value];
};
const errorTab = (key: string) => {
    if (
        /^(first_name|last_name|email|birth_date|gender|nationality|current_country|current_city|phone|whatsapp|summary)/.test(
            key,
        )
    ) {
        return 'personal';
    }

    if (/^(experiences|educations)/.test(key)) {
        return 'history';
    }

    if (/^(skills|languages)/.test(key)) {
        return 'skills';
    }

    if (/^availability/.test(key)) {
        return 'availability';
    }

    return 'profession';
};
const submitProfile = () =>
    form.put(update.url(), {
        preserveScroll: true,
        onError: (errors) => {
            const first = Object.keys(errors)[0];

            if (first) {
                active.value = errorTab(first);
            }
        },
    });
const missingTab: Record<string, string> = {
    personal: 'personal',
    photo: 'personal',
    profession: 'profession',
    experience: 'history',
    skills: 'skills',
    languages: 'skills',
    education: 'history',
    cv: 'documents',
    certificates: 'documents',
    availability: 'availability',
};
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (!form.isDirty) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
};
let removeNavigationGuard: (() => void) | undefined;
onMounted(() => {
    window.addEventListener('beforeunload', beforeUnload);
    removeNavigationGuard = router.on('before', (event) => {
        if (
            form.isDirty &&
            !window.confirm(t('candidate.profile.unsavedWarning'))
        ) {
            event.preventDefault();
        }
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', beforeUnload);
    removeNavigationGuard?.();
});
</script>

<template>
    <Head :title="t('candidate.profile.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('candidate.profile.eyebrow')"
            :title="t('candidate.profile.title')"
            :description="t('candidate.profile.description')"
            :icon="UserRound"
        >
            <template #actions>
                <button
                    :disabled="form.processing || !profile"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                    @click="submitProfile"
                >
                    <Save class="size-4" />
                    {{ t('candidate.profile.save') }}
                </button>
            </template>
        </PageHeader>

        <div
            v-if="!profile"
            class="erin-panel grid min-h-72 place-items-center p-8 text-center"
        >
            <div>
                <UserRound class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">
                    {{ t('candidate.profile.preparingTitle') }}
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    {{ t('candidate.profile.preparingDescription') }}
                </p>
            </div>
        </div>

        <template v-else>
            <section class="erin-panel p-5">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <img
                        v-if="profile.profile_photo_url"
                        :src="profile.profile_photo_url"
                        :alt="name"
                        class="size-20 shrink-0 rounded-2xl object-cover"
                    />
                    <div
                        v-else
                        class="grid size-20 shrink-0 place-items-center rounded-2xl bg-blue-50 text-xl font-extrabold text-[var(--erin-primary)]"
                    >
                        {{
                            name
                                .split(' ')
                                .map((part) => part[0])
                                .join('')
                                .slice(0, 2)
                        }}
                    </div>
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-xl font-extrabold text-slate-950">
                                {{ name }}
                            </h2>
                            <BadgeCheck
                                v-if="profile_status.can_apply"
                                class="size-5 text-[var(--erin-secondary)]"
                            /><StatusBadge
                                :label="
                                    profile.published_at
                                        ? t('candidate.profile.published')
                                        : t('candidate.profile.unpublished')
                                "
                                :tone="profile.published_at ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{
                                profile.desired_position ||
                                profile.current_position ||
                                t('candidate.profile.desiredPositionMissing')
                            }}<template v-if="profile.current_country_code">
                                · {{ profile.current_country_code }}</template
                            >
                        </p>
                        <div class="mt-3 max-w-xl">
                            <ProgressBar
                                :value="profile_status.percentage"
                                :label="t('candidate.profile.completeness')"
                                tone="teal"
                            />
                        </div>
                    </div>
                    <button
                        class="h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700"
                        @click="router.post(publish.url())"
                    >
                        {{
                            profile.published_at
                                ? t('candidate.profile.stopPublishing')
                                : t('candidate.profile.publish')
                        }}
                    </button>
                </div>
            </section>

            <div class="overflow-x-auto">
                <div class="flex min-w-max gap-1 rounded-xl bg-slate-100 p-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2.5 text-xs font-bold"
                        :class="
                            active === tab.key
                                ? 'bg-white text-[var(--erin-primary)] shadow-sm'
                                : 'text-slate-500'
                        "
                        @click="active = tab.key"
                    >
                        <component :is="tab.icon" class="size-3.5" />{{
                            tab.label
                        }}
                    </button>
                </div>
            </div>

            <div
                v-if="active === 'personal'"
                class="grid gap-6 xl:grid-cols-[1fr_20rem]"
            >
                <SectionCard
                    :title="t('candidate.profile.personal.title')"
                    :description="t('candidate.profile.personal.description')"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.firstName')
                            }}</span
                            ><input
                                v-model="form.first_name"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.lastName')
                            }}</span
                            ><input
                                v-model="form.last_name"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.birthDate')
                            }}</span
                            ><input
                                v-model="form.birth_date"
                                type="date"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.gender')
                            }}</span
                            ><input v-model="form.gender" :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.nationality')
                            }}</span
                            ><input
                                v-model="form.nationality_country_code"
                                maxlength="2"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.currentCountry')
                            }}</span
                            ><input
                                v-model="form.current_country_code"
                                required
                                maxlength="2"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.currentCity')
                            }}</span
                            ><input
                                v-model="form.current_city"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.phone')
                            }}</span
                            ><input
                                v-model="form.phone"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.whatsapp')
                            }}</span
                            ><input v-model="form.whatsapp" :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700">{{
                                t('candidate.profile.personal.email')
                            }}</span
                            ><input
                                v-model="form.email"
                                required
                                type="email"
                                :class="input"
                        /></label>
                    </div>
                    <label class="mt-5 block"
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.personal.summary')
                        }}</span
                        ><textarea
                            v-model="form.summary"
                            required
                            rows="5"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3.5 text-sm leading-6"
                        />
                    </label>
                </SectionCard>
                <aside class="space-y-4">
                    <SectionCard
                        :title="t('candidate.profile.photo.title')"
                        :description="t('candidate.profile.photo.description')"
                    >
                        <label
                            class="erin-focus flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 px-4 py-4 text-sm font-bold text-slate-600 hover:border-blue-400 hover:text-blue-700"
                        >
                            <ImagePlus class="size-4" />
                            {{
                                photoForm.photo?.name ||
                                t('candidate.profile.photo.choose')
                            }}
                            <input
                                type="file"
                                class="sr-only"
                                accept="image/jpeg,image/png"
                                @change="
                                    photoForm.photo =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                        </label>
                        <p
                            v-if="
                                profile.profile_photo_scan_result === 'pending'
                            "
                            class="mt-3 text-xs font-bold text-orange-700"
                        >
                            {{ t('candidate.profile.photo.pending') }}
                        </p>
                        <p
                            v-else-if="
                                ['infected', 'scan_failed'].includes(
                                    profile.profile_photo_scan_result ?? '',
                                )
                            "
                            class="mt-3 text-xs font-bold text-red-700"
                        >
                            {{ t('candidate.profile.photo.failed') }}
                        </p>
                        <div class="mt-3 grid gap-2">
                            <Button
                                type="button"
                                :disabled="
                                    !photoForm.photo || photoForm.processing
                                "
                                @click="submitPhoto"
                            >
                                <Upload class="size-4" />
                                {{ t('candidate.profile.photo.upload') }}
                            </Button>
                            <Button
                                v-if="
                                    profile.profile_photo_url ||
                                    profile.profile_photo_scan_result ===
                                        'pending'
                                "
                                type="button"
                                variant="outline"
                                @click="deletePhoto"
                            >
                                <Trash2 class="size-4" />
                                {{ t('candidate.profile.photo.delete') }}
                            </Button>
                        </div>
                        <p
                            v-if="photoForm.errors.photo"
                            class="mt-2 text-xs font-bold text-red-600"
                        >
                            {{ photoForm.errors.photo }}
                        </p>
                    </SectionCard>
                    <SectionCard :title="t('candidate.profile.privacy.title')"
                        ><div
                            class="space-y-3 text-xs leading-5 text-slate-500"
                        >
                            <p class="flex gap-2">
                                <ShieldCheck
                                    class="size-4 shrink-0 text-[var(--erin-secondary)]"
                                />
                                {{ t('candidate.profile.privacy.anonymous') }}
                            </p>
                            <p class="flex gap-2">
                                <LockKeyhole
                                    class="size-4 shrink-0 text-[var(--erin-primary)]"
                                />
                                {{ t('candidate.profile.privacy.contact') }}
                            </p>
                        </div></SectionCard
                    >
                    <SectionCard :title="t('candidate.profile.missing.title')"
                        ><div
                            v-if="profile_status.missing.length"
                            class="space-y-2"
                        >
                            <button
                                v-for="missing in profile_status.missing"
                                :key="missing"
                                type="button"
                                class="rounded-lg bg-orange-50 p-2.5 text-xs font-bold text-orange-700"
                                @click="
                                    active = missingTab[missing] || 'personal'
                                "
                            >
                                {{ missingLabel(missing) }}
                            </button>
                        </div>
                        <p v-else class="text-xs text-teal-600">
                            {{ t('candidate.profile.missing.complete') }}
                        </p></SectionCard
                    >
                </aside>
            </div>

            <SectionCard
                v-else-if="active === 'profession'"
                :title="t('candidate.profile.profession.title')"
            >
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.occupation')
                        }}</span
                        ><select
                            v-model="form.occupation_id"
                            required
                            :class="input"
                        >
                            <option :value="null">
                                {{ t('candidate.profile.profession.choose') }}
                            </option>
                            <option
                                v-for="occupation in occupations"
                                :key="occupation.id"
                                :value="occupation.id"
                            >
                                {{ localizedField(occupation) }}
                            </option>
                        </select></label
                    >
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.currentPosition')
                        }}</span
                        ><input v-model="form.current_position" :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.desiredPosition')
                        }}</span
                        ><input
                            v-model="form.desired_position"
                            required
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.experience')
                        }}</span
                        ><input
                            v-model.number="form.experience_years"
                            required
                            min="0"
                            max="60"
                            type="number"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.qualification')
                        }}</span
                        ><input
                            v-model="form.highest_qualification"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.availableFrom')
                        }}</span
                        ><input
                            v-model="form.available_from"
                            type="date"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.salary')
                        }}</span
                        ><input
                            v-model.number="form.salary_expectation_cents"
                            min="0"
                            type="number"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700">{{
                            t('candidate.profile.profession.weeklyHours')
                        }}</span
                        ><input
                            v-model.number="form.weekly_hours"
                            min="1"
                            max="80"
                            type="number"
                            :class="input"
                    /></label>
                </div>
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input v-model="form.travel_ready" type="checkbox" />
                        {{
                            t('candidate.profile.profession.travelReady')
                        }}</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input
                            v-model="form.relocation_ready"
                            type="checkbox"
                        />
                        {{
                            t('candidate.profile.profession.relocationReady')
                        }}</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input v-model="form.requires_visa" type="checkbox" />
                        {{
                            t('candidate.profile.profession.requiresVisa')
                        }}</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input
                            v-model="form.has_work_permit"
                            type="checkbox"
                        />
                        {{
                            t('candidate.profile.profession.workPermit')
                        }}</label
                    >
                </div>
                <div class="mt-6 grid gap-6 lg:grid-cols-2">
                    <div>
                        <p class="text-sm font-extrabold text-slate-800">
                            {{ t('candidate.profile.profession.licenses') }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button
                                v-for="license in [
                                    'B',
                                    'BE',
                                    'C',
                                    'CE',
                                    'D',
                                    'DE',
                                    'ADR',
                                ]"
                                :key="license"
                                type="button"
                                class="rounded-lg border px-3 py-2 text-xs font-bold"
                                :class="
                                    form.driving_licenses.includes(license)
                                        ? 'border-blue-300 bg-blue-50 text-blue-700'
                                        : 'border-slate-200 text-slate-500'
                                "
                                @click="
                                    toggleArrayValue(
                                        'driving_licenses',
                                        license,
                                    )
                                "
                            >
                                {{ license }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm font-extrabold text-slate-800">
                            {{
                                t(
                                    'candidate.profile.profession.employmentPreferences',
                                )
                            }}
                        </p>
                        <div class="mt-2 grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="preference in [
                                    'full_time',
                                    'part_time',
                                    'temporary',
                                    'permanent',
                                ]"
                                :key="preference"
                                class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    :checked="
                                        form.employment_preferences.includes(
                                            preference,
                                        )
                                    "
                                    @change="
                                        toggleArrayValue(
                                            'employment_preferences',
                                            preference,
                                        )
                                    "
                                />
                                {{
                                    t(
                                        `candidate.profile.profession.employment.${preference}`,
                                    )
                                }}
                            </label>
                        </div>
                    </div>
                </div>
            </SectionCard>

            <div v-else-if="active === 'history'" class="space-y-6">
                <SectionCard
                    :title="t('candidate.profile.history.experienceTitle')"
                    :description="
                        t('candidate.profile.history.experienceDescription')
                    "
                >
                    <div class="space-y-4">
                        <article
                            v-for="(experience, index) in form.experiences"
                            :key="experience.id ?? `new-experience-${index}`"
                            class="rounded-2xl border border-slate-200 p-4"
                        >
                            <div class="mb-4 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <GripVertical
                                        class="size-4 text-slate-300"
                                    />
                                    <strong class="text-sm">{{
                                        t(
                                            'candidate.profile.history.experienceNumber',
                                            { number: index + 1 },
                                        )
                                    }}</strong>
                                </div>
                                <div class="flex gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :disabled="index === 0"
                                        @click="
                                            moveItem(
                                                form.experiences,
                                                index,
                                                -1,
                                            )
                                        "
                                        >↑</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :disabled="
                                            index ===
                                            form.experiences.length - 1
                                        "
                                        @click="
                                            moveItem(form.experiences, index, 1)
                                        "
                                        >↓</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="
                                            form.experiences.splice(index, 1)
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>
                            <div
                                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t('candidate.profile.history.employer')
                                    }}
                                    <input
                                        v-model="experience.employer"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t('candidate.profile.history.position')
                                    }}
                                    <input
                                        v-model="experience.position"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{ t('candidate.profile.history.country') }}
                                    <input
                                        v-model="experience.country_code"
                                        maxlength="2"
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t('candidate.profile.history.startedAt')
                                    }}
                                    <input
                                        v-model="experience.started_at"
                                        required
                                        type="date"
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{ t('candidate.profile.history.endedAt') }}
                                    <input
                                        v-model="experience.ended_at"
                                        type="date"
                                        :disabled="experience.is_current"
                                        :class="input"
                                    />
                                </label>
                                <label
                                    class="flex items-center gap-2 pt-6 text-sm"
                                >
                                    <input
                                        v-model="experience.is_current"
                                        type="checkbox"
                                    />
                                    {{ t('candidate.profile.history.current') }}
                                </label>
                            </div>
                            <label
                                class="mt-4 block text-xs font-bold text-slate-600"
                            >
                                {{ t('candidate.profile.history.description') }}
                                <textarea
                                    v-model="experience.description"
                                    rows="3"
                                    class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                                />
                            </label>
                        </article>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        class="mt-4"
                        @click="addExperience"
                    >
                        <Plus class="size-4" />
                        {{ t('candidate.profile.history.addExperience') }}
                    </Button>
                </SectionCard>

                <SectionCard
                    :title="t('candidate.profile.history.educationTitle')"
                >
                    <div class="space-y-4">
                        <article
                            v-for="(education, index) in form.educations"
                            :key="education.id ?? `new-education-${index}`"
                            class="rounded-2xl border border-slate-200 p-4"
                        >
                            <div class="mb-4 flex items-center justify-between">
                                <strong class="text-sm">{{
                                    t(
                                        'candidate.profile.history.educationNumber',
                                        { number: index + 1 },
                                    )
                                }}</strong>
                                <div class="flex gap-1">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :disabled="index === 0"
                                        @click="
                                            moveItem(form.educations, index, -1)
                                        "
                                        >↑</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        :disabled="
                                            index === form.educations.length - 1
                                        "
                                        @click="
                                            moveItem(form.educations, index, 1)
                                        "
                                        >↓</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="
                                            form.educations.splice(index, 1)
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>
                            <div
                                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                            >
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t(
                                            'candidate.profile.history.institution',
                                        )
                                    }}
                                    <input
                                        v-model="education.institution"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t(
                                            'candidate.profile.history.qualification',
                                        )
                                    }}
                                    <input
                                        v-model="education.qualification"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{ t('candidate.profile.history.field') }}
                                    <input
                                        v-model="education.field"
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{ t('candidate.profile.history.country') }}
                                    <input
                                        v-model="education.country_code"
                                        maxlength="2"
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t('candidate.profile.history.startedAt')
                                    }}
                                    <input
                                        v-model="education.started_at"
                                        type="date"
                                        :class="input"
                                    />
                                </label>
                                <label class="text-xs font-bold text-slate-600">
                                    {{
                                        t(
                                            'candidate.profile.history.completedAt',
                                        )
                                    }}
                                    <input
                                        v-model="education.completed_at"
                                        type="date"
                                        :class="input"
                                    />
                                </label>
                            </div>
                        </article>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        class="mt-4"
                        @click="addEducation"
                    >
                        <Plus class="size-4" />
                        {{ t('candidate.profile.history.addEducation') }}
                    </Button>
                </SectionCard>
            </div>

            <div
                v-else-if="active === 'skills'"
                class="grid gap-6 lg:grid-cols-2"
            >
                <SectionCard :title="t('candidate.profile.skills.title')"
                    ><div v-if="skills.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="skill in skills"
                            :key="skill.id"
                            type="button"
                            class="rounded-lg border px-3 py-2 text-xs font-bold"
                            :class="
                                hasSkill(skill.id)
                                    ? 'border-teal-300 bg-teal-50 text-teal-700'
                                    : 'border-slate-200 text-slate-500'
                            "
                            @click="toggleSkill(skill.id)"
                        >
                            {{ localizedField(skill) }}
                        </button>
                    </div>
                    <p v-else class="text-sm text-slate-400">
                        {{ t('candidate.profile.skills.empty') }}
                    </p></SectionCard
                >
                <SectionCard
                    :title="t('candidate.profile.skills.languagesTitle')"
                    ><div v-if="languages.length" class="space-y-2">
                        <div
                            v-for="language in languages"
                            :key="language.id"
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-3"
                        >
                            <input
                                :checked="hasLanguage(language.id)"
                                type="checkbox"
                                @change="toggleLanguage(language.id)"
                            /><span class="flex-1 text-sm font-bold">{{
                                localizedField(language, 'name', language.code)
                            }}</span
                            ><select
                                v-if="hasLanguage(language.id)"
                                :value="languageLevel(language.id)"
                                class="h-8 rounded-lg border border-slate-200 px-2 text-xs"
                                @change="
                                    updateLanguageLevel(
                                        language.id,
                                        ($event.target as HTMLSelectElement)
                                            .value,
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
                    </div>
                    <p v-else class="text-sm text-slate-400">
                        {{ t('candidate.profile.skills.languagesEmpty') }}
                    </p></SectionCard
                >
            </div>

            <SectionCard
                v-else-if="active === 'availability'"
                :title="t('candidate.profile.availability.title')"
                :description="t('candidate.profile.availability.description')"
            >
                <div class="space-y-3">
                    <div
                        v-for="(slot, index) in form.availability"
                        :key="`${slot.weekday}-${index}`"
                        class="grid items-end gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-[1fr_1fr_1fr_auto]"
                    >
                        <label class="text-xs font-bold text-slate-600">
                            {{ t('candidate.profile.availability.weekday') }}
                            <select v-model="slot.weekday" :class="input">
                                <option
                                    v-for="day in 7"
                                    :key="day"
                                    :value="day"
                                >
                                    {{
                                        t(
                                            `candidate.profile.availability.days.${day}`,
                                        )
                                    }}
                                </option>
                            </select>
                        </label>
                        <label class="text-xs font-bold text-slate-600">
                            {{ t('candidate.profile.availability.from') }}
                            <input
                                v-model="slot.starts_at"
                                type="time"
                                :class="input"
                            />
                        </label>
                        <label class="text-xs font-bold text-slate-600">
                            {{ t('candidate.profile.availability.until') }}
                            <input
                                v-model="slot.ends_at"
                                type="time"
                                :class="input"
                            />
                        </label>
                        <Button
                            type="button"
                            variant="ghost"
                            @click="form.availability.splice(index, 1)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    class="mt-4"
                    @click="addAvailability"
                >
                    <Plus class="size-4" />
                    {{ t('candidate.profile.availability.add') }}
                </Button>
            </SectionCard>

            <div v-else class="space-y-6">
                <SectionCard
                    :title="t('candidate.profile.documents.title')"
                    :description="t('candidate.profile.documents.description')"
                >
                    <div
                        v-if="profile.documents?.length"
                        class="grid gap-3 md:grid-cols-2"
                    >
                        <article
                            v-for="document in profile.documents"
                            :key="document.id"
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-4"
                        >
                            <span
                                class="grid size-10 place-items-center rounded-xl bg-blue-50 text-[var(--erin-primary)]"
                                ><FileText class="size-4"
                            /></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-bold">
                                    {{ document.title }}
                                </p>
                                <p class="truncate text-xs text-slate-400">
                                    {{ document.original_name }}
                                </p>
                                <p
                                    v-if="document.rejection_reason"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ document.rejection_reason }}
                                </p>
                            </div>
                            <StatusBadge
                                :label="
                                    statusLabel('document', document.status)
                                "
                                :tone="documentTone(document.status)"
                            />
                            <a
                                v-if="document.download_url"
                                :href="document.download_url"
                                :aria-label="
                                    t('candidate.profile.documents.download', {
                                        title: document.title,
                                    })
                                "
                                class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"
                                ><Download class="size-4"
                            /></a>
                        </article>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-slate-400">
                        {{ t('candidate.profile.documents.empty') }}
                    </p>
                </SectionCard>
                <SectionCard
                    :title="t('candidate.profile.documents.uploadTitle')"
                >
                    <form
                        class="grid gap-4 sm:grid-cols-2"
                        @submit.prevent="submitDocument"
                    >
                        <label
                            ><span class="text-xs font-bold text-slate-600">{{
                                t('candidate.profile.documents.type')
                            }}</span
                            ><select
                                v-model="documentForm.type"
                                required
                                :class="input"
                            >
                                <option
                                    v-for="type in document_types"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ documentTypeLabel(type) }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-xs font-bold text-slate-600">{{
                                t('candidate.profile.documents.documentTitle')
                            }}</span
                            ><input
                                v-model="documentForm.title"
                                required
                                :class="input"
                        /></label>
                        <label class="sm:col-span-2"
                            ><span class="text-xs font-bold text-slate-600">{{
                                t('candidate.profile.documents.file')
                            }}</span
                            ><input
                                required
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                class="mt-2 block w-full text-sm"
                                @change="
                                    documentForm.file =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                        /></label>
                        <button
                            :disabled="documentForm.processing"
                            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-white disabled:opacity-50 sm:col-span-2"
                        >
                            <Upload class="size-4" />
                            {{ t('candidate.profile.documents.upload') }}
                        </button>
                    </form>
                </SectionCard>
            </div>
        </template>
    </div>
</template>
