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
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import ProgressBar from '@/components/product/ProgressBar.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useCapabilities } from '@/composables/useCapabilities';
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

const { t, te } = useI18n();
const { can } = useCapabilities();
const canManageCompany = computed(() => can('company.manage'));
const benefitLabel = (benefit: string) =>
    te(`employer.companyProfile.benefits.${benefit}`)
        ? t(`employer.companyProfile.benefits.${benefit}`)
        : benefit;

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
    return te(`employer.companyProfile.scanStatus.${scanResult}`)
        ? t(`employer.companyProfile.scanStatus.${scanResult}`)
        : t('employer.companyProfile.scanStatus.pending');
};
const selectedMediaLabel = computed(() => {
    const count = form.media.length;

    return t(
        count === 1
            ? 'employer.companyProfile.selectedMedia.one'
            : 'employer.companyProfile.selectedMedia.other',
        { count },
    );
});
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
    <Head :title="t('employer.companyProfile.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.companyProfile.eyebrow')"
            :title="t('employer.companyProfile.title')"
            :description="t('employer.companyProfile.description')"
            :icon="Building2"
        >
            <template v-if="canManageCompany" #actions>
                <button
                    type="button"
                    :disabled="form.processing || !company"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--erin-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                    @click="submit"
                >
                    <Save class="size-4" />
                    {{ t('employer.companyProfile.saveChanges') }}
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
                    {{ t('employer.companyProfile.emptyTitle') }}
                </h2>
                <p class="mt-2 text-sm text-slate-500">
                    {{ t('employer.companyProfile.emptyDescription') }}
                </p>
            </div>
        </div>

        <form
            v-else
            :inert="!canManageCompany"
            :aria-disabled="!canManageCompany"
            class="grid gap-6 xl:grid-cols-[1fr_20rem]"
            :class="{ 'opacity-80': !canManageCompany }"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <SectionCard
                    :title="t('employer.companyProfile.mediaTitle')"
                    :description="t('employer.companyProfile.mediaDescription')"
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
                                {{ t('employer.companyProfile.noLogo') }}
                            </p>
                        </div>
                        <label
                            class="inline-flex h-10 cursor-pointer items-center justify-center gap-2 rounded-xl bg-white px-4 text-xs font-bold text-slate-800"
                        >
                            <Upload class="size-4" />
                            {{ t('employer.companyProfile.chooseLogo') }}
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
                        {{
                            t('employer.companyProfile.newLogo', {
                                name: form.logo.name,
                            })
                        }}
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
                                :aria-label="
                                    t('employer.companyProfile.downloadMedia', {
                                        name: medium.original_name,
                                    })
                                "
                            >
                                <Download class="size-4" />
                            </a>
                        </article>
                    </div>
                    <p
                        v-else
                        class="mt-5 rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-400"
                    >
                        {{ t('employer.companyProfile.noMedia') }}
                    </p>

                    <label
                        class="mt-4 flex cursor-pointer items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-200 p-5 text-sm font-bold text-slate-500 hover:border-blue-300"
                    >
                        <Upload class="size-4" />
                        {{ t('employer.companyProfile.chooseMedia') }}
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
                        {{ selectedMediaLabel }}
                    </p>
                </SectionCard>

                <SectionCard
                    :title="t('employer.companyProfile.companyDataTitle')"
                >
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{ t('employer.companyProfile.fields.name') }} *
                            </span>
                            <input
                                v-model="form.name"
                                required
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{
                                    t(
                                        'employer.companyProfile.fields.legalName',
                                    )
                                }}
                            </span>
                            <input v-model="form.legal_name" :class="input" />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{
                                    t('employer.companyProfile.fields.industry')
                                }}
                                *
                            </span>
                            <input
                                v-model="form.industry"
                                required
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{
                                    t(
                                        'employer.companyProfile.fields.employeeCount',
                                    )
                                }}
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
                                {{
                                    t('employer.companyProfile.fields.website')
                                }}
                            </span>
                            <input
                                v-model="form.website"
                                type="url"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{ t('employer.companyProfile.fields.phone') }}
                            </span>
                            <input
                                v-model="form.phone"
                                type="tel"
                                :class="input"
                            />
                        </label>
                        <label>
                            <span class="text-sm font-bold text-slate-700">
                                {{
                                    t('employer.companyProfile.fields.country')
                                }}
                                *
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
                                {{ t('employer.companyProfile.fields.city') }} *
                            </span>
                            <input
                                v-model="form.city"
                                required
                                :class="input"
                            />
                        </label>
                    </div>
                </SectionCard>

                <SectionCard
                    :title="t('employer.companyProfile.whyWorkHereTitle')"
                >
                    <textarea
                        v-model="form.description"
                        required
                        rows="7"
                        class="erin-focus w-full rounded-xl border border-slate-200 p-4 text-sm leading-6"
                    />
                </SectionCard>

                <SectionCard
                    :title="t('employer.companyProfile.benefitsTitle')"
                >
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
                            {{ benefitLabel(benefit) }}
                        </label>
                    </div>
                    <p v-else class="text-sm text-slate-400">
                        {{ t('employer.companyProfile.noBenefits') }}
                    </p>
                </SectionCard>

                <SectionCard
                    :title="t('employer.companyProfile.locationsTitle')"
                    :description="
                        t('employer.companyProfile.locationsDescription')
                    "
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
                                        {{
                                            t(
                                                'employer.companyProfile.fields.locationName',
                                            )
                                        }}
                                        *
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
                                        {{
                                            t(
                                                'employer.companyProfile.fields.city',
                                            )
                                        }}
                                        *
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
                                        {{
                                            t(
                                                'employer.companyProfile.fields.country',
                                            )
                                        }}
                                        *
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
                                        {{
                                            t(
                                                'employer.companyProfile.fields.postalCode',
                                            )
                                        }}
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
                                        {{
                                            t(
                                                'employer.companyProfile.fields.address',
                                            )
                                        }}
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
                                    {{
                                        t(
                                            'employer.companyProfile.headquarters',
                                        )
                                    }}
                                </label>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs font-bold text-red-600"
                                    @click="form.locations.splice(index, 1)"
                                >
                                    <Trash2 class="size-3.5" />
                                    {{ t('employer.companyProfile.remove') }}
                                </button>
                            </div>
                        </article>
                    </div>
                    <p
                        v-else
                        class="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-400"
                    >
                        {{ t('employer.companyProfile.noLocations') }}
                    </p>
                    <button
                        type="button"
                        class="mt-4 inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700"
                        @click="addLocation"
                    >
                        <Plus class="size-4" />
                        {{ t('employer.companyProfile.addLocation') }}
                    </button>
                </SectionCard>
            </div>

            <aside class="space-y-6 xl:sticky xl:top-24 xl:self-start">
                <SectionCard
                    :title="t('employer.companyProfile.completenessTitle')"
                >
                    <div class="flex items-end justify-between">
                        <p class="text-3xl font-extrabold">
                            {{ profileCompleteness }} %
                        </p>
                        <span class="text-xs font-bold text-teal-600">
                            {{ t('employer.companyProfile.calculatedLive') }}
                        </span>
                    </div>
                    <ProgressBar
                        class="mt-4"
                        :value="profileCompleteness"
                        :show-value="false"
                        tone="teal"
                    />
                </SectionCard>
                <SectionCard
                    :title="t('employer.companyProfile.secureMediaTitle')"
                >
                    <div class="space-y-3 text-xs leading-5 text-slate-500">
                        <p class="flex gap-2">
                            <ShieldCheck
                                class="size-4 shrink-0 text-[var(--erin-secondary)]"
                            />
                            {{
                                t(
                                    'employer.companyProfile.privateStorageNotice',
                                )
                            }}
                        </p>
                        <p class="flex gap-2">
                            <MapPin
                                class="size-4 shrink-0 text-[var(--erin-primary)]"
                            />
                            {{ t('employer.companyProfile.signedLinksNotice') }}
                        </p>
                    </div>
                </SectionCard>
                <button
                    v-if="canManageCompany"
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-[var(--erin-primary)] text-sm font-bold text-white disabled:opacity-50"
                >
                    <Save class="size-4" />
                    {{ t('employer.companyProfile.saveProfile') }}
                </button>
            </aside>
        </form>
    </div>
</template>
