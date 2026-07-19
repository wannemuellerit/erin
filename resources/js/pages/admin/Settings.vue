<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    CircleDollarSign,
    HardDrive,
    Megaphone,
    RotateCcw,
    Save,
    Settings2,
} from '@lucide/vue';
import { computed } from 'vue';
import FormField from '@/components/product/FormField.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import Textarea from '@/components/product/Textarea.vue';
import adminSettings from '@/routes/admin/settings';
import { useAdminI18n } from './_i18n';

type DashboardNotice = {
    enabled: boolean;
    title_de: string;
    title_en: string;
    body_de: string;
    body_en: string;
    url: string | null;
};

type BillingSettings = {
    visa_credit_enabled: boolean;
    visa_credit_price_cents: number | null;
    seat_addon_enabled: boolean;
    seat_addon_price_cents: number | null;
    referral_commission_cents: number | null;
};

type UploadSettings = {
    max_file_size_mb: number;
    user_quota_mb: number;
};

type RetentionSettings = {
    rejected_document_days: number;
    message_attachment_days: number;
    support_attachment_days: number;
    audit_log_days: number;
    orphan_grace_hours: number;
};

type DashboardAd = {
    campaign_id: number | null;
    campaign_name: string;
    enabled: boolean;
    audience: 'all' | 'candidate' | 'company';
    title_de: string;
    title_en: string;
    body_de: string;
    body_en: string;
    cta_label_de: string;
    cta_label_en: string;
    url: string | null;
    starts_at: string | null;
    ends_at: string | null;
};

const props = defineProps<{
    colors: Record<string, string>;
    defaults: Record<string, string>;
    dashboard_notice: DashboardNotice;
    billing: BillingSettings;
    uploads: UploadSettings;
    retention: RetentionSettings;
    dashboard_ad: DashboardAd;
    dashboard_ad_stats: { impressions: number; clicks: number; ctr: number };
    dashboard_ad_media_url: string | null;
}>();

const colorKeys = Object.keys(props.defaults);

const themeForm = useForm({
    colors: { ...props.colors },
});

const platformForm = useForm({
    dashboard_notice: {
        enabled: props.dashboard_notice.enabled,
        title_de: props.dashboard_notice.title_de,
        title_en: props.dashboard_notice.title_en,
        body_de: props.dashboard_notice.body_de,
        body_en: props.dashboard_notice.body_en,
        url: props.dashboard_notice.url ?? '',
    },
    billing: {
        visa_credit_enabled: props.billing.visa_credit_enabled,
        visa_credit_price_cents:
            props.billing.visa_credit_price_cents?.toString() ?? '',
        seat_addon_enabled: props.billing.seat_addon_enabled,
        seat_addon_price_cents:
            props.billing.seat_addon_price_cents?.toString() ?? '',
        referral_commission_cents:
            props.billing.referral_commission_cents?.toString() ?? '',
    },
    uploads: {
        max_file_size_mb: props.uploads.max_file_size_mb.toString(),
        user_quota_mb: props.uploads.user_quota_mb.toString(),
    },
    retention: {
        rejected_document_days:
            props.retention.rejected_document_days.toString(),
        message_attachment_days:
            props.retention.message_attachment_days.toString(),
        support_attachment_days:
            props.retention.support_attachment_days.toString(),
        audit_log_days: props.retention.audit_log_days.toString(),
        orphan_grace_hours: props.retention.orphan_grace_hours.toString(),
    },
    dashboard_ad: {
        ...props.dashboard_ad,
        url: props.dashboard_ad.url ?? '',
        starts_at: props.dashboard_ad.starts_at?.slice(0, 16) ?? '',
        ends_at: props.dashboard_ad.ends_at?.slice(0, 16) ?? '',
    },
});

const adMediaForm = useForm<{ media: File | null }>({ media: null });

const firstThemeError = computed(
    () => Object.values(themeForm.errors)[0] as string | undefined,
);
const firstPlatformError = computed(
    () => Object.values(platformForm.errors)[0] as string | undefined,
);

const { t, humanize } = useAdminI18n();

function resetColors(): void {
    themeForm.colors = { ...props.defaults };
    themeForm.clearErrors();
}

function saveTheme(): void {
    themeForm.patch(adminSettings.theme.update.url(), {
        preserveScroll: true,
    });
}

function savePlatform(): void {
    platformForm.patch(adminSettings.platform.update.url(), {
        preserveScroll: true,
    });
}

function uploadAdMedia(event: Event): void {
    const input = event.target as HTMLInputElement;
    adMediaForm.media = input.files?.[0] ?? null;

    if (!adMediaForm.media || !props.dashboard_ad.campaign_id) {
        return;
    }

    adMediaForm.post(
        adminSettings.ads.media.store.url(props.dashboard_ad.campaign_id),
        { preserveScroll: true, forceFormData: true },
    );
}

function deleteAdMedia(): void {
    if (!props.dashboard_ad.campaign_id) {
        return;
    }

    useForm({}).delete(
        adminSettings.ads.media.destroy.url(props.dashboard_ad.campaign_id),
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head :title="t('settings.metaTitle')" />

    <div class="erin-page">
        <PageHeader
            :eyebrow="t('settings.eyebrow')"
            :title="t('settings.title')"
            :description="t('settings.description')"
            :icon="Settings2"
        />

        <SectionCard
            :title="t('settings.designTitle')"
            :description="t('settings.designDescription')"
        >
            <form @submit.prevent="saveTheme">
                <div
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <label
                        v-for="key in colorKeys"
                        :key="key"
                        class="rounded-xl border border-slate-200 p-3"
                    >
                        <span class="text-xs font-bold text-slate-600">
                            {{ humanize(key) }}
                        </span>
                        <div class="mt-2 flex items-center gap-2">
                            <input
                                v-model="themeForm.colors[key]"
                                type="color"
                                class="size-10 cursor-pointer rounded-lg border-0 bg-transparent p-0"
                            />
                            <input
                                v-model="themeForm.colors[key]"
                                type="text"
                                maxlength="7"
                                pattern="^#[0-9A-Fa-f]{6}$"
                                class="erin-focus h-10 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 font-mono text-xs uppercase"
                            />
                        </div>
                        <p
                            v-if="themeForm.errors[`colors.${key}`]"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ themeForm.errors[`colors.${key}`] }}
                        </p>
                    </label>
                </div>

                <div
                    class="mt-5 rounded-2xl border border-slate-200 p-5"
                    :style="{
                        backgroundColor: themeForm.colors.background,
                        color: themeForm.colors.text,
                        borderColor: themeForm.colors.border,
                    }"
                >
                    <p class="text-sm font-bold">
                        {{ t('settings.previewTitle') }}
                    </p>
                    <p
                        class="mt-1 text-xs"
                        :style="{ color: themeForm.colors.text_muted }"
                    >
                        {{ t('settings.previewDescription') }}
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.primary,
                            }"
                        >
                            {{ t('settings.primaryPreview') }}
                        </span>
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.secondary,
                            }"
                        >
                            {{ t('settings.secondaryPreview') }}
                        </span>
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.accent,
                            }"
                        >
                            {{ t('settings.accentPreview') }}
                        </span>
                    </div>
                </div>

                <div
                    class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:items-center sm:justify-between"
                >
                    <button
                        type="button"
                        class="erin-focus inline-flex h-10 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600"
                        @click="resetColors"
                    >
                        <RotateCcw class="size-4" />
                        {{ t('settings.resetColors') }}
                    </button>
                    <div class="text-right">
                        <p
                            v-if="firstThemeError"
                            class="mb-2 text-xs text-red-600"
                        >
                            {{ firstThemeError }}
                        </p>
                        <button
                            type="submit"
                            :disabled="themeForm.processing"
                            class="erin-focus inline-flex h-10 items-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white disabled:opacity-50"
                        >
                            <Save class="size-4" />
                            {{ t('settings.saveColors') }}
                        </button>
                    </div>
                </div>
            </form>
        </SectionCard>

        <form class="grid gap-6 xl:grid-cols-2" @submit.prevent="savePlatform">
            <SectionCard
                :title="t('settings.noticeTitle')"
                :description="t('settings.noticeDescription')"
            >
                <label
                    class="flex items-center gap-3 text-sm font-semibold text-slate-700"
                >
                    <input
                        v-model="platformForm.dashboard_notice.enabled"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-blue-600"
                    />
                    {{ t('settings.showNotice') }}
                </label>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            {{ t('settings.germanTitle') }}
                        </span>
                        <input
                            v-model="platformForm.dashboard_notice.title_de"
                            class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            {{ t('settings.englishTitle') }}
                        </span>
                        <input
                            v-model="platformForm.dashboard_notice.title_en"
                            class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            {{ t('settings.germanBody') }}
                        </span>
                        <Textarea
                            v-model="platformForm.dashboard_notice.body_de"
                            rows="5"
                            class="mt-1.5"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            {{ t('settings.englishBody') }}
                        </span>
                        <Textarea
                            v-model="platformForm.dashboard_notice.body_en"
                            rows="5"
                            class="mt-1.5"
                        />
                    </label>
                </div>
                <label class="mt-4 block">
                    <span class="text-xs font-bold text-slate-600">
                        {{ t('settings.targetUrl') }}
                    </span>
                    <input
                        v-model="platformForm.dashboard_notice.url"
                        type="url"
                        placeholder="https://…"
                        class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
            </SectionCard>

            <SectionCard
                :title="t('settings.retentionTitle')"
                :description="t('settings.retentionDescription')"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="retention-documents"
                        :label="t('settings.rejectedDocumentDays')"
                    >
                        <input
                            id="retention-documents"
                            v-model="
                                platformForm.retention.rejected_document_days
                            "
                            type="number"
                            min="0"
                            max="3650"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="retention-messages"
                        :label="t('settings.messageAttachmentDays')"
                    >
                        <input
                            id="retention-messages"
                            v-model="
                                platformForm.retention.message_attachment_days
                            "
                            type="number"
                            min="0"
                            max="3650"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="retention-support"
                        :label="t('settings.supportAttachmentDays')"
                    >
                        <input
                            id="retention-support"
                            v-model="
                                platformForm.retention.support_attachment_days
                            "
                            type="number"
                            min="0"
                            max="3650"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="retention-audit"
                        :label="t('settings.auditLogDays')"
                    >
                        <input
                            id="retention-audit"
                            v-model="platformForm.retention.audit_log_days"
                            type="number"
                            min="0"
                            max="3650"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="retention-orphans"
                        :label="t('settings.orphanGraceHours')"
                    >
                        <input
                            id="retention-orphans"
                            v-model="platformForm.retention.orphan_grace_hours"
                            type="number"
                            min="1"
                            max="720"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                </div>
            </SectionCard>

            <SectionCard
                :title="t('settings.billingTitle')"
                :description="t('settings.billingDescription')"
            >
                <div class="space-y-5">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <label
                            class="flex items-center justify-between gap-4 text-sm font-semibold text-slate-700"
                        >
                            <span>{{ t('settings.visaEnabled') }}</span>
                            <input
                                v-model="
                                    platformForm.billing.visa_credit_enabled
                                "
                                type="checkbox"
                                class="size-4 rounded border-slate-300 text-blue-600"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('settings.visaPrice') }}
                            </span>
                            <input
                                v-model="
                                    platformForm.billing.visa_credit_price_cents
                                "
                                type="number"
                                min="1"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                    </div>

                    <div class="rounded-xl border border-slate-200 p-4">
                        <label
                            class="flex items-center justify-between gap-4 text-sm font-semibold text-slate-700"
                        >
                            <span>{{ t('settings.seatEnabled') }}</span>
                            <input
                                v-model="
                                    platformForm.billing.seat_addon_enabled
                                "
                                type="checkbox"
                                class="size-4 rounded border-slate-300 text-blue-600"
                            />
                        </label>
                        <label class="mt-3 block">
                            <span class="text-xs font-bold text-slate-600">
                                {{ t('settings.seatPrice') }}
                            </span>
                            <input
                                v-model="
                                    platformForm.billing.seat_addon_price_cents
                                "
                                type="number"
                                min="1"
                                class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                            />
                        </label>
                    </div>

                    <label class="block">
                        <span
                            class="flex items-center gap-2 text-xs font-bold text-slate-600"
                        >
                            <CircleDollarSign class="size-4 text-teal-600" />
                            {{ t('settings.referralCommission') }}
                        </span>
                        <input
                            v-model="
                                platformForm.billing.referral_commission_cents
                            "
                            type="number"
                            min="0"
                            class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                </div>
            </SectionCard>

            <SectionCard
                :title="t('settings.uploadsTitle')"
                :description="t('settings.uploadsDescription')"
            >
                <div class="mb-5 flex items-center gap-3">
                    <span
                        class="grid size-10 place-items-center rounded-xl bg-teal-50 text-teal-600"
                    >
                        <HardDrive class="size-5" />
                    </span>
                    <p class="text-xs leading-5 text-slate-500">
                        {{ t('settings.uploadsHint') }}
                    </p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="max-file-size"
                        :label="t('settings.maxFileSize')"
                        :error="platformForm.errors['uploads.max_file_size_mb']"
                        required
                    >
                        <input
                            id="max-file-size"
                            v-model="platformForm.uploads.max_file_size_mb"
                            type="number"
                            min="1"
                            max="100"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="user-storage-quota"
                        :label="t('settings.userStorageQuota')"
                        :error="platformForm.errors['uploads.user_quota_mb']"
                        required
                    >
                        <input
                            id="user-storage-quota"
                            v-model="platformForm.uploads.user_quota_mb"
                            type="number"
                            min="10"
                            max="102400"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                </div>
            </SectionCard>

            <SectionCard
                :title="t('settings.adTitle')"
                :description="t('settings.adDescription')"
            >
                <div class="flex items-center justify-between gap-4">
                    <span class="flex items-center gap-2 text-sm font-semibold">
                        <Megaphone class="size-4 text-orange-500" />
                        {{ t('settings.adEnabled') }}
                    </span>
                    <input
                        v-model="platformForm.dashboard_ad.enabled"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-blue-600"
                    />
                </div>
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="ad-campaign-name"
                        :label="t('settings.adCampaignName')"
                    >
                        <input
                            id="ad-campaign-name"
                            v-model="platformForm.dashboard_ad.campaign_name"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-audience"
                        :label="t('settings.adAudience')"
                        :error="platformForm.errors['dashboard_ad.audience']"
                    >
                        <select
                            id="ad-audience"
                            v-model="platformForm.dashboard_ad.audience"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"
                        >
                            <option value="all">
                                {{ t('settings.adAudienceAll') }}
                            </option>
                            <option value="candidate">
                                {{ t('settings.adAudienceCandidates') }}
                            </option>
                            <option value="company">
                                {{ t('settings.adAudienceCompanies') }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="ad-url"
                        :label="t('settings.targetUrl')"
                        :error="platformForm.errors['dashboard_ad.url']"
                    >
                        <input
                            id="ad-url"
                            v-model="platformForm.dashboard_ad.url"
                            type="url"
                            placeholder="https://…"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-title-de"
                        :label="t('settings.germanTitle')"
                        :error="platformForm.errors['dashboard_ad.title_de']"
                    >
                        <input
                            id="ad-title-de"
                            v-model="platformForm.dashboard_ad.title_de"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-title-en"
                        :label="t('settings.englishTitle')"
                        :error="platformForm.errors['dashboard_ad.title_en']"
                    >
                        <input
                            id="ad-title-en"
                            v-model="platformForm.dashboard_ad.title_en"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-body-de"
                        :label="t('settings.germanBody')"
                        :error="platformForm.errors['dashboard_ad.body_de']"
                    >
                        <Textarea
                            id="ad-body-de"
                            v-model="platformForm.dashboard_ad.body_de"
                            rows="4"
                        />
                    </FormField>
                    <FormField
                        id="ad-body-en"
                        :label="t('settings.englishBody')"
                        :error="platformForm.errors['dashboard_ad.body_en']"
                    >
                        <Textarea
                            id="ad-body-en"
                            v-model="platformForm.dashboard_ad.body_en"
                            rows="4"
                        />
                    </FormField>
                    <FormField
                        id="ad-cta-de"
                        :label="t('settings.adCtaGerman')"
                    >
                        <input
                            id="ad-cta-de"
                            v-model="platformForm.dashboard_ad.cta_label_de"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-cta-en"
                        :label="t('settings.adCtaEnglish')"
                    >
                        <input
                            id="ad-cta-en"
                            v-model="platformForm.dashboard_ad.cta_label_en"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField id="ad-starts" :label="t('settings.adStartsAt')">
                        <input
                            id="ad-starts"
                            v-model="platformForm.dashboard_ad.starts_at"
                            type="datetime-local"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                    <FormField
                        id="ad-ends"
                        :label="t('settings.adEndsAt')"
                        :error="platformForm.errors['dashboard_ad.ends_at']"
                    >
                        <input
                            id="ad-ends"
                            v-model="platformForm.dashboard_ad.ends_at"
                            type="datetime-local"
                            class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </FormField>
                </div>
                <div class="mt-5 grid grid-cols-3 gap-3">
                    <div class="rounded-xl bg-slate-50 p-3 text-center">
                        <p class="text-lg font-extrabold text-slate-900">
                            {{ dashboard_ad_stats.impressions }}
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ t('settings.adImpressions') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3 text-center">
                        <p class="text-lg font-extrabold text-slate-900">
                            {{ dashboard_ad_stats.clicks }}
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ t('settings.adClicks') }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-slate-50 p-3 text-center">
                        <p class="text-lg font-extrabold text-slate-900">
                            {{ dashboard_ad_stats.ctr }} %
                        </p>
                        <p class="text-xs text-slate-500">
                            {{ t('settings.adCtr') }}
                        </p>
                    </div>
                </div>
                <div
                    v-if="dashboard_ad.campaign_id"
                    class="mt-5 rounded-xl border border-slate-200 p-4"
                >
                    <p class="text-xs font-bold text-slate-700">
                        {{ t('settings.adArtwork') }}
                    </p>
                    <img
                        v-if="dashboard_ad_media_url"
                        :src="dashboard_ad_media_url"
                        :alt="t('settings.adArtworkAlt')"
                        class="mt-3 h-32 w-full rounded-xl object-cover"
                    />
                    <div class="mt-3 flex flex-wrap gap-2">
                        <label
                            class="erin-focus cursor-pointer rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold text-white"
                        >
                            {{ t('settings.uploadArtwork') }}
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/gif,image/webp"
                                class="sr-only"
                                @change="uploadAdMedia"
                            />
                        </label>
                        <button
                            v-if="dashboard_ad_media_url"
                            type="button"
                            class="erin-focus rounded-xl border border-red-200 px-4 py-2 text-xs font-bold text-red-600"
                            @click="deleteAdMedia"
                        >
                            {{ t('settings.removeArtwork') }}
                        </button>
                    </div>
                </div>
            </SectionCard>

            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end xl:col-span-2"
            >
                <p v-if="firstPlatformError" class="text-xs text-red-600">
                    {{ firstPlatformError }}
                </p>
                <button
                    type="submit"
                    :disabled="platformForm.processing"
                    class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 text-sm font-bold text-white disabled:opacity-50"
                >
                    <Save class="size-4" />
                    {{ t('settings.savePlatform') }}
                </button>
            </div>
        </form>
    </div>
</template>
