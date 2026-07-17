<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Download,
    FileText,
    Languages as LanguagesIcon,
    LockKeyhole,
    Save,
    ShieldCheck,
    Upload,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
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
    employer?: string | null;
    position?: string | null;
    country_code?: string | null;
    started_at?: string | null;
    ended_at?: string | null;
    is_current?: boolean;
    description?: string | null;
};
type CandidateEducation = {
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
        employer: string;
        position: string;
        country_code: string;
        started_at: string;
        ended_at: string;
        is_current: boolean;
        description: string;
    }>;
    educations: Array<{
        institution: string;
        qualification: string;
        field: string;
        country_code: string;
        started_at: string;
        completed_at: string;
        description: string;
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
};

const props = withDefaults(
    defineProps<{
        profile?: Profile | null;
        profile_status?: ProfileStatus;
        occupations?: NamedOption[];
        skills?: NamedOption[];
        languages?: NamedOption[];
        document_types?: string[];
    }>(),
    {
        profile: null,
        profile_status: () => ({
            percentage: 0,
            completed: [],
            missing: [],
            can_apply: false,
        }),
        occupations: () => [],
        skills: () => [],
        languages: () => [],
        document_types: () => [],
    },
);
const { statusLabel } = useStatusLabels();
const active = ref('personal');
const tabs = [
    { key: 'personal', label: 'Persönliche Daten', icon: UserRound },
    { key: 'profession', label: 'Beruf & Mobilität', icon: BriefcaseBusiness },
    { key: 'skills', label: 'Skills & Sprachen', icon: LanguagesIcon },
    { key: 'documents', label: 'Dokumente', icon: FileText },
];
const form = useForm<ProfileFormData>({
    first_name: props.profile?.first_name ?? '',
    last_name: props.profile?.last_name ?? '',
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
            institution: education.institution ?? '',
            qualification: education.qualification ?? '',
            field: education.field ?? '',
            country_code: education.country_code ?? '',
            started_at: education.started_at ?? '',
            completed_at: education.completed_at ?? '',
            description: education.description ?? '',
        })) ?? [],
});
const documentForm = useForm({
    type: props.document_types[0] ?? '',
    title: '',
    file: null as File | null,
    expires_at: '',
});
const name = computed(
    () =>
        [props.profile?.first_name, props.profile?.last_name]
            .filter(Boolean)
            .join(' ') || 'Profil einrichten',
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
</script>

<template>
    <Head title="Mein Profil" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Kandidatenprofil"
            title="Mein Profil"
            description="Vollständige und verifizierte Profile erhalten mehr passende Einladungen."
            :icon="UserRound"
        >
            <template #actions>
                <button
                    :disabled="form.processing || !profile"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                    @click="form.put(update.url(), { preserveScroll: true })"
                >
                    <Save class="size-4" /> Speichern
                </button>
            </template>
        </PageHeader>

        <div
            v-if="!profile"
            class="erin-panel grid min-h-72 place-items-center p-8 text-center"
        >
            <div>
                <UserRound class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">Profil wird vorbereitet</h2>
                <p class="mt-2 text-sm text-slate-500">
                    Für dieses Konto ist noch kein Kandidatenprofil verfügbar.
                </p>
            </div>
        </div>

        <template v-else>
            <section class="erin-panel p-5">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                    <div
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
                                        ? 'Veröffentlicht'
                                        : 'Nicht veröffentlicht'
                                "
                                :tone="profile.published_at ? 'green' : 'slate'"
                            />
                        </div>
                        <p class="mt-1 text-sm text-slate-500">
                            {{
                                profile.desired_position ||
                                profile.current_position ||
                                'Wunschposition ergänzen'
                            }}<template v-if="profile.current_country_code">
                                · {{ profile.current_country_code }}</template
                            >
                        </p>
                        <div class="mt-3 max-w-xl">
                            <ProgressBar
                                :value="profile_status.percentage"
                                label="Profil vollständig"
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
                                ? 'Veröffentlichung stoppen'
                                : 'Profil veröffentlichen'
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
                    title="Persönliche Daten"
                    description="Diese Angaben werden erst nach deiner Freigabe sichtbar"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Vorname *</span
                            ><input
                                v-model="form.first_name"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Nachname *</span
                            ><input
                                v-model="form.last_name"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Geburtsdatum</span
                            ><input
                                v-model="form.birth_date"
                                type="date"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Geschlecht</span
                            ><input v-model="form.gender" :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Staatsangehörigkeit (ISO)</span
                            ><input
                                v-model="form.nationality_country_code"
                                maxlength="2"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Aktuelles Land (ISO) *</span
                            ><input
                                v-model="form.current_country_code"
                                required
                                maxlength="2"
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Aktuelle Stadt *</span
                            ><input
                                v-model="form.current_city"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >Telefonnummer *</span
                            ><input
                                v-model="form.phone"
                                required
                                :class="input"
                        /></label>
                        <label
                            ><span class="text-sm font-bold text-slate-700"
                                >WhatsApp</span
                            ><input v-model="form.whatsapp" :class="input"
                        /></label>
                    </div>
                    <label class="mt-5 block"
                        ><span class="text-sm font-bold text-slate-700"
                            >Kurztext über dich *</span
                        ><textarea
                            v-model="form.summary"
                            required
                            rows="5"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3.5 text-sm leading-6"
                        />
                    </label>
                </SectionCard>
                <aside class="space-y-4">
                    <SectionCard title="Datenschutz"
                        ><div
                            class="space-y-3 text-xs leading-5 text-slate-500"
                        >
                            <p class="flex gap-2">
                                <ShieldCheck
                                    class="size-4 shrink-0 text-[var(--erin-secondary)]"
                                />
                                Unternehmen sehen dein Profil zunächst anonym.
                            </p>
                            <p class="flex gap-2">
                                <LockKeyhole
                                    class="size-4 shrink-0 text-[var(--erin-primary)]"
                                />
                                Kontaktdaten nur nach Bewerbung oder
                                angenommener Einladung.
                            </p>
                        </div></SectionCard
                    >
                    <SectionCard title="Noch offen"
                        ><div
                            v-if="profile_status.missing.length"
                            class="space-y-2"
                        >
                            <div
                                v-for="missing in profile_status.missing"
                                :key="missing"
                                class="rounded-lg bg-orange-50 p-2.5 text-xs font-bold text-orange-700"
                            >
                                {{ missing }}
                            </div>
                        </div>
                        <p v-else class="text-xs text-teal-600">
                            Alle Profilbereiche vollständig.
                        </p></SectionCard
                    >
                </aside>
            </div>

            <SectionCard
                v-else-if="active === 'profession'"
                title="Beruf & Mobilität"
            >
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Berufsfeld *</span
                        ><select
                            v-model="form.occupation_id"
                            required
                            :class="input"
                        >
                            <option :value="null">Bitte wählen</option>
                            <option
                                v-for="occupation in occupations"
                                :key="occupation.id"
                                :value="occupation.id"
                            >
                                {{ occupation.name_de ?? occupation.name_en }}
                            </option>
                        </select></label
                    >
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Aktuelle Position</span
                        ><input v-model="form.current_position" :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Wunschposition *</span
                        ><input
                            v-model="form.desired_position"
                            required
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Berufserfahrung *</span
                        ><input
                            v-model.number="form.experience_years"
                            required
                            min="0"
                            max="60"
                            type="number"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Höchster Abschluss</span
                        ><input
                            v-model="form.highest_qualification"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Frühester Eintritt</span
                        ><input
                            v-model="form.available_from"
                            type="date"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Gehaltsvorstellung (Cent)</span
                        ><input
                            v-model.number="form.salary_expectation_cents"
                            min="0"
                            type="number"
                            :class="input"
                    /></label>
                    <label
                        ><span class="text-sm font-bold text-slate-700"
                            >Wochenstunden</span
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
                        Reisebereit</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input
                            v-model="form.relocation_ready"
                            type="checkbox"
                        />
                        Umzugsbereit</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input v-model="form.requires_visa" type="checkbox" />
                        Visum benötigt</label
                    >
                    <label
                        class="flex items-center gap-2 rounded-xl border border-slate-200 p-3 text-sm"
                        ><input
                            v-model="form.has_work_permit"
                            type="checkbox"
                        />
                        Arbeitserlaubnis</label
                    >
                </div>
            </SectionCard>

            <div
                v-else-if="active === 'skills'"
                class="grid gap-6 lg:grid-cols-2"
            >
                <SectionCard title="Skills"
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
                            {{ skill.name_de ?? skill.name_en }}
                        </button>
                    </div>
                    <p v-else class="text-sm text-slate-400">
                        Keine Skills konfiguriert.
                    </p></SectionCard
                >
                <SectionCard title="Sprachen"
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
                                language.name_de ??
                                language.name_en ??
                                language.code
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
                        Keine Sprachen konfiguriert.
                    </p></SectionCard
                >
            </div>

            <div v-else class="space-y-6">
                <SectionCard
                    title="Dokumente"
                    description="Private, verschlüsselte Ablage mit separater Freigabe"
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
                                class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"
                                ><Download class="size-4"
                            /></a>
                        </article>
                    </div>
                    <p v-else class="py-6 text-center text-sm text-slate-400">
                        Noch keine Dokumente hochgeladen.
                    </p>
                </SectionCard>
                <SectionCard title="Dokument hochladen">
                    <form
                        class="grid gap-4 sm:grid-cols-2"
                        @submit.prevent="submitDocument"
                    >
                        <label
                            ><span class="text-xs font-bold text-slate-600"
                                >Dokumenttyp</span
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
                                    {{ type.replaceAll('_', ' ') }}
                                </option>
                            </select></label
                        >
                        <label
                            ><span class="text-xs font-bold text-slate-600"
                                >Titel</span
                            ><input
                                v-model="documentForm.title"
                                required
                                :class="input"
                        /></label>
                        <label class="sm:col-span-2"
                            ><span class="text-xs font-bold text-slate-600"
                                >Datei</span
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
                            <Upload class="size-4" /> Sicher hochladen
                        </button>
                    </form>
                </SectionCard>
            </div>
        </template>
    </div>
</template>
