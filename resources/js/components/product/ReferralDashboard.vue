<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    CreditCard,
    Euro,
    Gift,
    Mail,
    MousePointerClick,
    Send,
    Share2,
    UserCheck,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FormField from '@/components/product/FormField.vue';
import MetricCard from '@/components/product/MetricCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import { useFormatters } from '@/composables/useFormatters';
import { useCapabilities } from '@/composables/useCapabilities';
import { useStatusLabels } from '@/composables/useStatusLabels';
import { productMessages } from '@/i18n/product-locales';
import { create, email as sendReferralEmail } from '@/routes/referrals';
import type { Referral, ReferralDashboardProps, StatusTone } from '@/types';

const props = withDefaults(defineProps<ReferralDashboardProps>(), {
    perspective: 'candidate',
    code: null,
    metrics: () => ({
        clicks: 0,
        registrations: 0,
        applications: 0,
        placements: 0,
        approved_cents: 0,
        paid_cents: 0,
    }),
    referrals: () => [],
    payoutAccount: null,
    payoutIntents: () => [],
});

const { t } = useI18n({
    useScope: 'local',
    messages: productMessages,
});
const { formatCurrency, formatDate: formatLocalizedDate } = useFormatters();
const { can } = useCapabilities();
const canManageReferrals = () => can('referrals.manage');

const copied = ref(false);
const showEmailForm = ref(false);
const emailForm = useForm({
    email: '',
    message: '',
});
const payoutForm = useForm({
    provider: 'stripe',
    external_account_token: '',
    country_code: 'DE',
    currency_code: 'EUR',
    terms_version: 'payout-v1',
    terms_accepted: false,
});
const connectPayout = () =>
    payoutForm.post('/referrals/payout-account', {
        preserveScroll: true,
        onSuccess: () =>
            payoutForm.reset('external_account_token', 'terms_accepted'),
    });
const disconnectPayout = () => {
    if (props.payoutAccount) {
        router.delete(`/referrals/payout-account/${props.payoutAccount.id}`, {
            preserveScroll: true,
        });
    }
};

const money = (amount: number, currency = 'EUR') =>
    formatCurrency(amount / 100, currency);
const formatDate = (value: string) =>
    formatLocalizedDate(value, { dateStyle: 'medium' });
const { statusLabel: translatedStatusLabel } = useStatusLabels();
const statusLabel = (status: string) =>
    translatedStatusLabel('referral', status);
const statusTone = (status: string): StatusTone => {
    if (status === 'paid' || status === 'approved') {
        return 'green';
    }

    if (status === 'rejected') {
        return 'red';
    }

    if (status === 'holding' || status === 'hired') {
        return 'orange';
    }

    if (status === 'applied') {
        return 'violet';
    }

    return 'blue';
};
const referralDate = (referral: Referral) =>
    referral.registered_at ??
    referral.clicked_at ??
    referral.hired_at ??
    referral.approved_at ??
    referral.paid_at;
const createLink = () => {
    router.post(create.url(), {}, { preserveScroll: true });
};
const copyLink = async () => {
    if (!props.code?.url) {
        return;
    }

    await navigator.clipboard.writeText(props.code.url);
    copied.value = true;
    window.setTimeout(() => {
        copied.value = false;
    }, 2000);
};
const shareLink = async () => {
    if (!props.code?.url) {
        return;
    }

    if (navigator.share) {
        await navigator.share({
            title: 'Faden',
            text: t('referralDashboard.shareText'),
            url: props.code.url,
        });

        return;
    }

    await copyLink();
};
const sendEmail = () => {
    emailForm.post(sendReferralEmail.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emailForm.reset();
            showEmailForm.value = false;
        },
    });
};
</script>

<template>
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('referralDashboard.eyebrow')"
            :title="t('referralDashboard.title')"
            :description="t('referralDashboard.description')"
            :icon="Gift"
        />

        <section
            class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-blue-800 p-6 text-white shadow-xl shadow-blue-900/15 sm:p-8"
        >
            <div class="erin-grid absolute inset-0 opacity-15" />
            <div
                class="relative grid items-center gap-8 lg:grid-cols-[1fr_auto]"
            >
                <div>
                    <p
                        class="text-xs font-bold tracking-wider text-teal-300 uppercase"
                    >
                        {{ t('referralDashboard.personalLink') }}
                    </p>
                    <h2 class="mt-2 text-2xl font-extrabold">
                        {{ t('referralDashboard.heroTitle') }}
                    </h2>
                    <p
                        class="mt-2 max-w-2xl text-sm leading-6 text-blue-100/80"
                    >
                        {{ t('referralDashboard.heroDescription') }}
                    </p>
                    <div v-if="code" class="mt-5 max-w-xl">
                        <div class="flex rounded-xl bg-card p-1.5">
                            <input
                                readonly
                                :value="code.url"
                                class="min-w-0 flex-1 bg-transparent px-3 text-xs font-medium text-muted-foreground outline-none"
                            />
                            <button
                                v-if="canManageReferrals()"
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-[var(--erin-primary)] px-3 text-xs font-bold text-[var(--erin-primary-foreground)]"
                                @click="copyLink"
                            >
                                <Check v-if="copied" class="size-3.5" />
                                <Copy v-else class="size-3.5" />
                                {{
                                    t(
                                        copied
                                            ? 'referralDashboard.copied'
                                            : 'referralDashboard.copy',
                                    )
                                }}
                            </button>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button
                                v-if="canManageReferrals()"
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-white/10 px-3 text-xs font-bold ring-1 ring-white/10"
                                @click="showEmailForm = !showEmailForm"
                            >
                                <Mail class="size-3.5" />
                                {{ t('referralDashboard.email') }}
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center gap-2 rounded-lg bg-white/10 px-3 text-xs font-bold ring-1 ring-white/10"
                                @click="shareLink"
                            >
                                <Share2 class="size-3.5" />
                                {{ t('referralDashboard.share') }}
                            </button>
                        </div>
                    </div>
                    <button
                        v-else-if="canManageReferrals()"
                        type="button"
                        class="mt-5 h-10 rounded-xl bg-card px-4 text-xs font-bold text-[var(--erin-primary-text)]"
                        @click="createLink"
                    >
                        {{ t('referralDashboard.createLink') }}
                    </button>
                </div>
                <div
                    class="hidden size-40 place-items-center rounded-full bg-white/10 ring-1 ring-white/15 lg:grid"
                >
                    <div
                        class="grid size-28 place-items-center rounded-full bg-teal-400/20"
                    >
                        <Gift class="size-12 text-teal-300" />
                    </div>
                </div>
            </div>
        </section>

        <SectionCard
            :title="t('referralDashboard.payout.title')"
            :description="t('referralDashboard.payout.description')"
        >
            <div
                v-if="payoutAccount"
                class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border p-4"
            >
                <div class="flex items-center gap-3">
                    <CreditCard class="size-5 text-teal-600" />
                    <div>
                        <p class="font-semibold">
                            {{ payoutAccount.provider }} ·
                            {{ payoutAccount.country_code }} ·
                            {{ payoutAccount.currency_code }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t('referralDashboard.payout.accountStatus', {
                                    status: payoutAccount.status,
                                    kyc: payoutAccount.kyc_status,
                                })
                            }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    class="erin-button erin-button-secondary"
                    @click="disconnectPayout"
                >
                    {{ t('referralDashboard.payout.disconnect') }}
                </button>
            </div>
            <form
                v-else
                class="grid gap-3 md:grid-cols-3"
                @submit.prevent="connectPayout"
            >
                <label class="grid gap-1 text-sm md:col-span-2"
                    ><span>{{
                        t('referralDashboard.payout.accountToken')
                    }}</span
                    ><input
                        v-model="payoutForm.external_account_token"
                        class="erin-input"
                        :placeholder="
                            t(
                                'referralDashboard.payout.accountTokenPlaceholder',
                            )
                        "
                        required /></label
                ><label class="grid gap-1 text-sm"
                    ><span>{{ t('referralDashboard.payout.country') }}</span
                    ><input
                        v-model="payoutForm.country_code"
                        class="erin-input uppercase"
                        maxlength="2"
                        required /></label
                ><label class="flex items-start gap-2 text-sm md:col-span-3"
                    ><input
                        v-model="payoutForm.terms_accepted"
                        class="mt-1"
                        type="checkbox"
                        required
                    /><span>{{
                        t('referralDashboard.payout.terms')
                    }}</span></label
                ><button
                    class="erin-button erin-button-primary md:w-fit"
                    :disabled="payoutForm.processing"
                >
                    {{ t('referralDashboard.payout.connect') }}
                </button>
            </form>
            <div v-if="payoutIntents.length" class="mt-4 space-y-2">
                <div
                    v-for="intent in payoutIntents"
                    :key="intent.public_id"
                    class="flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm"
                >
                    <span>{{
                        money(intent.amount_cents, intent.currency_code)
                    }}</span
                    ><StatusBadge
                        :label="intent.status"
                        :tone="statusTone(intent.status)"
                    />
                </div>
            </div>
        </SectionCard>

        <form
            v-if="showEmailForm && code && canManageReferrals()"
            class="erin-panel grid gap-4 p-5 sm:grid-cols-2"
            @submit.prevent="sendEmail"
        >
            <FormField
                id="referral-email"
                :label="t('referralDashboard.recipientEmail')"
                :error="emailForm.errors.email"
                required
            >
                <input
                    id="referral-email"
                    v-model="emailForm.email"
                    required
                    type="email"
                    class="erin-focus h-10 w-full rounded-xl border border-border px-3 text-sm"
                />
            </FormField>
            <FormField
                id="referral-message"
                class="sm:row-span-2"
                :label="t('referralDashboard.personalMessage')"
            >
                <Textarea
                    id="referral-message"
                    v-model="emailForm.message"
                    rows="4"
                />
            </FormField>
            <button
                type="submit"
                :disabled="emailForm.processing"
                class="h-10 rounded-xl bg-[var(--erin-primary)] text-xs font-bold text-[var(--erin-primary-foreground)] disabled:opacity-50"
            >
                {{ t('referralDashboard.sendRecommendation') }}
            </button>
        </form>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <MetricCard
                :label="t('referralDashboard.metrics.clicks')"
                :value="metrics.clicks"
                :icon="MousePointerClick"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.registrations')"
                :value="metrics.registrations"
                :icon="Users"
                tone="teal"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.applications')"
                :value="metrics.applications"
                :icon="Send"
                tone="violet"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.placements')"
                :value="metrics.placements"
                :icon="UserCheck"
                tone="orange"
            />
            <MetricCard
                :label="t('referralDashboard.metrics.approvedCommission')"
                :value="money(metrics.approved_cents)"
                :hint="
                    t('referralDashboard.metrics.paidHint', {
                        amount: money(metrics.paid_cents),
                    })
                "
                :icon="Euro"
                tone="teal"
            />
        </section>

        <SectionCard
            :title="t('referralDashboard.listTitle')"
            :description="t('referralDashboard.listDescription')"
        >
            <div v-if="referrals.length" class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead>
                        <tr
                            class="border-b border-border text-left text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                        >
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.reference') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.captured') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.status') }}
                            </th>
                            <th class="pb-3">
                                {{ t('referralDashboard.columns.holdPeriod') }}
                            </th>
                            <th class="pb-3 text-right">
                                {{ t('referralDashboard.columns.commission') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="referral in referrals"
                            :key="referral.id"
                            class="border-b border-border last:border-0"
                        >
                            <td class="py-4 font-bold text-foreground">
                                #REF-{{ referral.id }}
                            </td>
                            <td class="py-4 text-muted-foreground">
                                {{
                                    referralDate(referral)
                                        ? formatDate(
                                              referralDate(referral) as string,
                                          )
                                        : '—'
                                }}
                            </td>
                            <td class="py-4">
                                <StatusBadge
                                    :label="statusLabel(referral.status)"
                                    :tone="statusTone(referral.status)"
                                />
                            </td>
                            <td class="py-4 text-muted-foreground">
                                {{
                                    referral.hold_until
                                        ? formatDate(referral.hold_until)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="py-4 text-right font-bold text-foreground"
                            >
                                {{
                                    referral.commission_cents
                                        ? money(
                                              referral.commission_cents,
                                              referral.currency ?? 'EUR',
                                          )
                                        : '—'
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-else
                compact
                :icon="Gift"
                :title="t('referralDashboard.emptyTitle')"
                :description="t('referralDashboard.emptyDescription')"
            />
        </SectionCard>
    </div>
</template>
