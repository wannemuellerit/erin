<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleDollarSign, RotateCcw, Save, Settings2 } from '@lucide/vue';
import { computed } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import adminSettings from '@/routes/admin/settings';
import { humanize } from './_shared';

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

const props = defineProps<{
    colors: Record<string, string>;
    defaults: Record<string, string>;
    dashboard_notice: DashboardNotice;
    billing: BillingSettings;
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
});

const firstThemeError = computed(
    () => Object.values(themeForm.errors)[0] as string | undefined,
);
const firstPlatformError = computed(
    () => Object.values(platformForm.errors)[0] as string | undefined,
);

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
</script>

<template>
    <Head title="Plattformeinstellungen" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Konfiguration"
            title="Plattformeinstellungen"
            description="Semantische Plattformfarben, Dashboard-Hinweis und konfigurierbare Preise."
            :icon="Settings2"
        />

        <SectionCard
            title="Design & Farben"
            description="Alle vom Backend vorgegebenen Theme-Tokens. Kontrast und Hexwerte werden beim Speichern validiert."
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
                    <p class="text-sm font-bold">Live-Vorschau</p>
                    <p
                        class="mt-1 text-xs"
                        :style="{ color: themeForm.colors.text_muted }"
                    >
                        Diese Vorschau verwendet direkt die aktuell eingegebenen
                        Farbwerte.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.primary,
                            }"
                        >
                            Primary
                        </span>
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.secondary,
                            }"
                        >
                            Secondary
                        </span>
                        <span
                            class="rounded-lg px-4 py-2 text-xs font-bold text-white"
                            :style="{
                                backgroundColor: themeForm.colors.accent,
                            }"
                        >
                            Accent
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
                        Standardwerte einsetzen
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
                            Farben speichern
                        </button>
                    </div>
                </div>
            </form>
        </SectionCard>

        <form class="grid gap-6 xl:grid-cols-2" @submit.prevent="savePlatform">
            <SectionCard
                title="Dashboard-Hinweis"
                description="Zweisprachiger, administrierbarer Hinweis für das Firmen-Dashboard."
            >
                <label
                    class="flex items-center gap-3 text-sm font-semibold text-slate-700"
                >
                    <input
                        v-model="platformForm.dashboard_notice.enabled"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-blue-600"
                    />
                    Hinweis anzeigen
                </label>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            Titel Deutsch
                        </span>
                        <input
                            v-model="platformForm.dashboard_notice.title_de"
                            class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            Titel Englisch
                        </span>
                        <input
                            v-model="platformForm.dashboard_notice.title_en"
                            class="erin-focus mt-1.5 h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            Text Deutsch
                        </span>
                        <textarea
                            v-model="platformForm.dashboard_notice.body_de"
                            rows="5"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                        />
                    </label>
                    <label>
                        <span class="text-xs font-bold text-slate-600">
                            Text Englisch
                        </span>
                        <textarea
                            v-model="platformForm.dashboard_notice.body_en"
                            rows="5"
                            class="erin-focus mt-1.5 w-full rounded-xl border border-slate-200 p-3 text-sm"
                        />
                    </label>
                </div>
                <label class="mt-4 block">
                    <span class="text-xs font-bold text-slate-600">
                        Optionale Ziel-URL
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
                title="Billing-Konfiguration"
                description="Zusatzkäufe und Referral-Provisionen werden erst durch diese Werte aktivierbar."
            >
                <div class="space-y-5">
                    <div class="rounded-xl border border-slate-200 p-4">
                        <label
                            class="flex items-center justify-between gap-4 text-sm font-semibold text-slate-700"
                        >
                            <span>Visa-Zusatzkauf aktiviert</span>
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
                                Preis pro Visa-Credit in Cent
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
                            <span>Zusatzsitze aktiviert</span>
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
                                Preis pro Zusatzsitz in Cent
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
                            Standard-Referralprovision in Cent
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
                    Plattformkonfiguration speichern
                </button>
            </div>
        </form>
    </div>
</template>
