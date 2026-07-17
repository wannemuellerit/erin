<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    Building2,
    Download,
    FileText,
    ImagePlus,
    MapPin,
    Plus,
    Save,
    ShieldCheck,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { update } from '@/routes/employer/company';
import type { StatusTone } from '@/types';

type Location = {
    id?: number;
    name?: string | null;
    country_code?: string | null;
    city?: string | null;
    postal_code?: string | null;
    address_line1?: string | null;
    is_headquarters?: boolean;
};

type CompanyMedia = {
    id: number;
    type: string;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    scan_result: string;
    is_logo?: boolean;
    download_url?: string | null;
};

type Company = {
    id: number;
    name: string;
    legal_name?: string | null;
    website?: string | null;
    phone?: string | null;
    industry?: string | null;
    employee_count?: number | null;
    country_code?: string | null;
    city?: string | null;
    description?: string | null;
    benefits?: Record<string, boolean> | null;
    locations?: Location[];
    media?: CompanyMedia[];
};

const props = withDefaults(
    defineProps<{
        company?: Company | null;
        benefit_options?: string[];
    }>(),
    {
        company: null,
        benefit_options: () => [],
    },
);

const benefitLabels: Record<string, string> = {
    accommodation: 'Unterkunft vorhanden',
    german_course: 'Deutschkurs',
    visa_support: 'Visa-Unterstützung',
    canteen: 'Kantine',
    work_clothing: 'Arbeitskleidung',
    company_vehicle: 'Firmenfahrzeug',
};

const form = useForm({
    name: props.company?.name ?? '',
    legal_name: props.company?.legal_name ?? '',
    website: props.company?.website ?? '',
    phone: props.company?.phone ?? '',
    industry: props.company?.industry ?? '',
    employee_count: props.company?.employee_count ?? (null as number | null),
    country_code: props.company?.country_code ?? 'DE',
    city: props.company?.city ?? '',
    description: props.company?.description ?? '',
    benefits: Object.fromEntries(
        props.benefit_options.map((benefit) => [
            benefit,
            Boolean(props.company?.benefits?.[benefit]),
        ]),
    ) as Record<string, boolean>,
    locations:
        props.company?.locations?.map((location) => ({
            name: location.name ?? '',
            country_code: location.country_code ?? 'DE',
            city: location.city ?? '',
            postal_code: location.postal_code ?? '',
            address_line1: location.address_line1 ?? '',
            is_headquarters: location.is_headquarters ?? false,
        })) ?? [],
    logo: null as File | null,
    media: [] as File[],
});

const input =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm';
const logo = computed(() =>
    props.company?.media?.find((medium) => medium.is_logo),
);
const profileCompleteness = computed(() => {
    const checks = [
        form.name,
        form.industry,
        form.country_code,
        form.city,
        form.description,
        form.website,
        form.employee_count,
        logo.value || form.logo,
        form.locations.length,
        Object.values(form.benefits).some(Boolean),
    ];

    return Math.round(
        (checks.filter((value) => Boolean(value)).length / checks.length) * 100,
    );
});
const mediaTone = (scanResult: string): StatusTone => {
    if (scanResult === 'clean') {
        return 'green';
    }

    if (scanResult === 'infected' || scanResult === 'failed') {
        return 'red';
    }

    return 'yellow';
};
const mediaLabel = (scanResult: string) => {
    if (scanResult === 'clean') {
        return 'Geprüft';
    }

    if (scanResult === 'infected') {
        return 'Abgelehnt';
    }

    if (scanResult === 'failed') {
        return 'Prüfung fehlgeschlagen';
    }

    return 'Prüfung läuft';
};
const addLocation = () => {
    form.locations.push({
        name: '',
        country_code: 'DE',
        city: '',
        postal_code: '',
        address_line1: '',
        is_headquarters: form.locations.length === 0,
    });
};
const submit = () => {
    form.post(update.form().action, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.logo = null;
            form.media = [];
        },
    });
};
</script>

<template>
    <Head title="Unternehmensprofil" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Employer Branding"
            title="Unternehmensprofil"
            description="Zeigen Sie Fachkräften, was Ihr Unternehmen besonders macht."
            :icon="Building2"
        >
            <template #actions>
                <button
                    type="button"
                    :disabled="form.processing || !company"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                    @click="submit"
                >
                    <Save class="size-4" />
                    Änderungen speichern
                </button>
            </template>
        </PageHeader>

        <div
            v-if="!company"
            class="erin-panel grid min-h-72 place-items-center p-8 text-center"
        >
            <div>
                <Building2 class="mx-auto size-9 text-slate-300" />
                <h2 class="mt-4 font-bold">
                    Kein Unternehmensprofil verfügbar
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    Sobald ein Unternehmen zugeordnet ist, können Sie das Profil
                    hier bearbeiten.
                </p>
            </div>
        </div>

        <form
            v-else
            class="grid gap-6 xl:grid-cols-[1fr_20rem]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <SectionCard
                    title="Logo & Medien"
                    description="Uploads werden privat gespeichert und vor der Freigabe geprüft."
                >
                    <div
                        class="flex flex-col gap-4 rounded-2xl bg-slate-950 p-5 text-white sm:flex-row sm:items-center"
                    >
                        <span
                            class="grid size-20 shrink-0 place-items-center rounded-2xl bg-white text-xl font-extrabold text-[var(--erin-primary)]"
                        >
                            {{ company.name.slice(0, 2).toUpperCase() }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">{{ company.name }}</p>
                            <p
                                v-if="logo"
                                class="mt-1 truncate text-xs text-slate-300"
                            >
                                {{ logo.original_name }}
                            </p>
                            <p v-else class="mt-1 text-xs text-slate-400">
                                Noch kein Logo hochgeladen
                            </p>
                        </div>
                        <label
                            class="inline-flex h-10 cursor-pointer items-center justify-center gap-2 rounded-xl bg-white px-4 text-xs font-bold text-slate-800"
                        >
                            <Upload class="size-4" />
                            Logo wählen
                            <input
                                type="file"
                                accept=".jpg,.jpeg,.png,.gif,.webp"
                                class="sr-only"
                                @change="
                                    form.logo =
                                        ($event.target as HTMLInputElement)
                                            .files?.[0] ?? null
                                "
                            />
                        </label>
                    </div>

                    <p
                        v-if="form.logo"
                        class="mt-3 text-xs font-semibold text-teal-700"
                    >
                        Neues Logo: {{ form.logo.name }}
                    </p>

                    <div
                        v-if="company.media?.length"
                        class="mt-5 grid gap-3 sm:grid-cols-2"
                    >
                        <article
                            v-for="medium in company.media"
                            :key="medium.id"
                            class="flex items-center gap-3 rounded-xl border border-slate-200 p-3"
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-slate-500"
                            >
                                <ImagePlus
                                    v-if="
                                        medium.type === 'image' ||
                                        medium.is_logo
                                    "
                                    class="size-4"
                                />
                                <FileText v-else class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-xs font-bold">
                                    {{ medium.original_name }}
                                </p>
                                <StatusBadge
                                    class="mt-1"
                                    :label="mediaLabel(medium.scan_result)"
                                    :tone="mediaTone(medium.scan_result)"
                                />
                            </div>
                            <a
                                v-if="medium.download_url"
                                :href="medium.download_url"
                                class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-100"
                                :aria-label="`${medium.original_name} herunterladen`"
                            >
                                <Download class="size-4" />
                            </a>
                        </article>
                    </div>
                    <p
                        v-else
                        class="mt-5 rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-400"
                    >
                        Noch keine Medien hochgeladen.
                    </p>

                    <label
                        class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 p-5 text-sm font-bold text-slate-500 hover:border-blue-300"
                    >
                        <Upload class="size-4" />
                        Bilder, Videos oder PDF auswählen
                        <input
                            type="file"
                            multiple
                            accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf"
                            class="sr-only"
                            @change="
                                form.media = Array.from(
                                    ($event.target as HTMLInputElement).files ??
                                        [],
                                )
                            "
                        />
                    </label>
                    <p
                        v-if="form.media.length"
                        class="mt-2 text-xs text-slate-500"
                    >
                        {{ form.media.length }} Datei(en) zum Upload ausgewählt.
                    </p>
                </SectionCard>

                <SectionCard title="Unternehmensdaten">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Firmenname *
                            </span>
                            <input
                                v-model="form.name"
                                required
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Rechtlicher Name
                            </span>
                            <input v-model="form.legal_name" :class="input" />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Branche *
                            </span>
                            <input
                                v-model="form.industry"
                                required
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Mitarbeiterzahl
                            </span>
                            <input
                                v-model.number="form.employee_count"
                                type="number"
                                min="1"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Webseite
                            </span>
                            <input
                                v-model="form.website"
                                type="url"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Telefon
                            </span>
                            <input
                                v-model="form.phone"
                                type="tel"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Land (ISO) *
                            </span>
                            <input
                                v-model="form.country_code"
                                required
                                maxlength="2"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                Stadt *
                            </span>
                            <input
                                v-model="form.city"
                                required
                                :class="input"
                            />
                        </label>
                    </div>
                </SectionCard>

                <SectionCard title="Warum bei uns arbeiten?">
                    <textarea
                        v-model="form.description"
                        required
                        rows="7"
                        class="erin-focus w-full rounded-xl border border-slate-200 p-4 text-sm leading-6"
                    />
                </SectionCard>

                <SectionCard title="Benefits & Unterstützung">
                    <div
                        v-if="benefit_options.length"
                        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <label
                            v-for="benefit in benefit_options"
                            :key="benefit"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3 text-sm font-semibold text-slate-700 hover:border-teal-300"
                        >
                            <input
                                v-model="form.benefits[benefit]"
                                type="checkbox"
                                class="size-4 rounded border-slate-300"
                            />
                            {{ benefitLabels[benefit] ?? benefit }}
                        </label>
                    </div>
                    <p v-else class="text-sm text-slate-400">
                        Keine Benefits konfiguriert.
                    </p>
                </SectionCard>

                <SectionCard
                    title="Standorte"
                    description="Mehrere Recruiting-Standorte können separat gepflegt werden."
                >
                    <div v-if="form.locations.length" class="space-y-4">
                        <article
                            v-for="(location, index) in form.locations"
                            :key="index"
                            class="rounded-xl border border-slate-200 p-4"
                        >
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Bezeichnung *
                                    </span>
                                    <input
                                        v-model="location.name"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Stadt *
                                    </span>
                                    <input
                                        v-model="location.city"
                                        required
                                        :class="input"
                                    />
                                </label>
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Land (ISO) *
                                    </span>
                                    <input
                                        v-model="location.country_code"
                                        required
                                        maxlength="2"
                                        :class="input"
                                    />
                                </label>
                                <label>
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        PLZ
                                    </span>
                                    <input
                                        v-model="location.postal_code"
                                        :class="input"
                                    />
                                </label>
                                <label class="sm:col-span-2">
                                    <span
                                        class="text-xs font-bold text-slate-600"
                                    >
                                        Anschrift
                                    </span>
                                    <input
                                        v-model="location.address_line1"
                                        :class="input"
                                    />
                                </label>
                            </div>
                            <div
                                class="mt-3 flex items-center justify-between gap-3"
                            >
                                <label
                                    class="flex items-center gap-2 text-xs font-semibold text-slate-600"
                                >
                                    <input
                                        v-model="location.is_headquarters"
                                        type="checkbox"
                                    />
                                    Hauptstandort
                                </label>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs font-bold text-red-600"
                                    @click="form.locations.splice(index, 1)"
                                >
                                    <Trash2 class="size-3.5" />
                                    Entfernen
                                </button>
                            </div>
                        </article>
                    </div>
                    <p
                        v-else
                        class="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-400"
                    >
                        Noch keine Standorte angelegt.
                    </p>
                    <button
                        type="button"
                        class="mt-4 inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700"
                        @click="addLocation"
                    >
                        <Plus class="size-4" />
                        Standort hinzufügen
                    </button>
                </SectionCard>
            </div>

            <aside class="space-y-6 xl:sticky xl:top-24 xl:self-start">
                <SectionCard title="Profil-Vollständigkeit">
                    <div class="flex items-end justify-between">
                        <p class="text-3xl font-extrabold">
                            {{ profileCompleteness }} %
                        </p>
                        <span class="text-xs font-bold text-teal-600">
                            Live berechnet
                        </span>
                    </div>
                    <ProgressBar
                        class="mt-4"
                        :value="profileCompleteness"
                        :show-value="false"
                        tone="teal"
                    />
                </SectionCard>
                <SectionCard title="Sichere Medien">
                    <div class="space-y-3 text-xs leading-5 text-slate-500">
                        <p class="flex gap-2">
                            <ShieldCheck
                                class="size-4 shrink-0 text-[var(--erin-secondary)]"
                            />
                            Dateien werden privat gespeichert und auf
                            Schadsoftware geprüft.
                        </p>
                        <p class="flex gap-2">
                            <MapPin
                                class="size-4 shrink-0 text-[var(--erin-primary)]"
                            />
                            Freigegebene Dateien werden nur über zeitlich
                            begrenzte Links ausgeliefert.
                        </p>
                    </div>
                </SectionCard>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] text-sm font-bold text-white disabled:opacity-50"
                >
                    <Save class="size-4" />
                    Profil speichern
                </button>
            </aside>
        </form>
    </div>
</template>
