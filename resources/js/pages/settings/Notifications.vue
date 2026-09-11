<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    CheckCircle2,
    LoaderCircle,
    Mail,
    MessageSquareText,
    MonitorSmartphone,
    Phone,
    ShieldAlert,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NotificationPreferencesController from '@/actions/App/Http/Controllers/Settings/NotificationPreferencesController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit } from '@/routes/notification-preferences';

type EventKey =
    | 'application'
    | 'interview'
    | 'message'
    | 'document'
    | 'visa'
    | 'referral'
    | 'reminder'
    | 'support'
    | 'billing'
    | 'boost'
    | 'company'
    | 'system';

type Preference = {
    database_enabled: boolean;
    email_enabled: boolean;
    push_enabled: boolean;
    sms_enabled: boolean;
    whatsapp_enabled: boolean;
};

type Props = {
    preferences: Record<EventKey, Preference>;
    push_configured: boolean;
    push_public_key: string;
    push_subscription_count: number;
    push_subscription_store_url: string;
    push_subscription_destroy_url: string;
    push_subscription_test_url: string;
    phone_channels_configured: boolean;
    phone_channels: Array<{
        id: number;
        channel: 'sms' | 'whatsapp';
        masked_phone: string;
        country_code: string;
        verified: boolean;
        consented: boolean;
        quiet_hours_start: string | null;
        quiet_hours_end: string | null;
    }>;
    phone_channel_store_url: string;
    phone_allowed_countries: string[];
};

const props = defineProps<Props>();
const { t } = useI18n();
const eventKeys: EventKey[] = [
    'application',
    'interview',
    'message',
    'document',
    'visa',
    'referral',
    'reminder',
    'support',
    'billing',
    'boost',
    'company',
    'system',
];
const initialPreferences = Object.fromEntries(
    eventKeys.map((event) => [
        event,
        {
            database_enabled: props.preferences[event].database_enabled,
            email_enabled: props.preferences[event].email_enabled,
            push_enabled: props.preferences[event].push_enabled,
            sms_enabled: props.preferences[event].sms_enabled,
            whatsapp_enabled: props.preferences[event].whatsapp_enabled,
        },
    ]),
) as Record<EventKey, Preference>;
const form = useForm({
    preferences: initialPreferences,
});
const preferenceError = computed(
    () =>
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('preferences'),
        )?.[1],
);
const pushSupported = ref(false);
const pushPermission = ref<NotificationPermission>('default');
const browserSubscription = ref<PushSubscription | null>(null);
const pushProcessing = ref(false);
const pushError = ref('');
const pushTestProcessing = ref(false);
const pushTestSent = ref(false);
const phoneForm = useForm({
    channel: 'sms' as 'sms' | 'whatsapp',
    phone: '',
    country_code: props.phone_allowed_countries[0] ?? 'DE',
    consent: false,
    quiet_hours_start: '22:00',
    quiet_hours_end: '07:00',
});
const verificationCodes = reactive<Record<number, string>>({});
const externalEvents = new Set<EventKey>([
    'interview',
    'document',
    'visa',
    'reminder',
    'support',
]);
const phoneChannel = (channel: 'sms' | 'whatsapp') =>
    props.phone_channels.find((entry) => entry.channel === channel);
const channelReady = (channel: 'sms' | 'whatsapp') => {
    const entry = phoneChannel(channel);

    return (
        props.phone_channels_configured && entry?.verified && entry.consented
    );
};

const isPushActive = computed(() => browserSubscription.value !== null);
const pushStatusText = computed(() => {
    if (!pushSupported.value) {
        return t('settings.notifications.pushUnsupported');
    }

    if (pushPermission.value === 'denied') {
        return t('settings.notifications.pushDenied');
    }

    return isPushActive.value
        ? t('settings.notifications.pushActive')
        : t('settings.notifications.pushInactive');
});

const applicationServerKey = (value: string): ArrayBuffer => {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(raw.length);

    for (let index = 0; index < raw.length; index += 1) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes.buffer;
};

const registerServiceWorker =
    async (): Promise<ServiceWorkerRegistration | null> => {
        if (
            !('serviceWorker' in navigator) ||
            !('PushManager' in window) ||
            !('Notification' in window)
        ) {
            pushSupported.value = false;

            return null;
        }

        pushSupported.value = true;
        pushPermission.value = Notification.permission;

        const registration = await navigator.serviceWorker.register('/sw.js', {
            scope: '/',
        });
        browserSubscription.value =
            await registration.pushManager.getSubscription();

        return registration;
    };

const enablePush = async () => {
    if (
        pushProcessing.value ||
        !props.push_configured ||
        props.push_public_key === ''
    ) {
        return;
    }

    pushProcessing.value = true;
    pushError.value = '';

    try {
        const registration = await registerServiceWorker();

        if (registration === null) {
            return;
        }

        pushPermission.value = await Notification.requestPermission();

        if (pushPermission.value !== 'granted') {
            return;
        }

        const subscription =
            browserSubscription.value ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: applicationServerKey(
                    props.push_public_key,
                ),
            }));
        const serialized = subscription.toJSON();

        if (
            !serialized.endpoint ||
            !serialized.keys?.p256dh ||
            !serialized.keys.auth
        ) {
            throw new Error('The browser returned an incomplete subscription.');
        }

        router.post(
            props.push_subscription_store_url,
            {
                endpoint: serialized.endpoint,
                keys: {
                    p256dh: serialized.keys.p256dh,
                    auth: serialized.keys.auth,
                },
                content_encoding: 'aes128gcm',
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    browserSubscription.value = subscription;
                },
                onError: () => {
                    pushError.value = t('settings.notifications.pushError');
                },
                onFinish: () => {
                    pushProcessing.value = false;
                },
            },
        );
    } catch {
        pushError.value = t('settings.notifications.pushError');
        pushProcessing.value = false;
    }
};

const disablePush = async () => {
    if (pushProcessing.value) {
        return;
    }

    pushProcessing.value = true;
    pushError.value = '';

    try {
        const registration = await registerServiceWorker();
        const subscription =
            browserSubscription.value ??
            (await registration?.pushManager.getSubscription()) ??
            null;

        if (subscription === null) {
            pushProcessing.value = false;

            return;
        }

        router.delete(props.push_subscription_destroy_url, {
            data: {
                endpoint: subscription.endpoint,
            },
            preserveScroll: true,
            onSuccess: () => {
                void subscription.unsubscribe().then(() => {
                    browserSubscription.value = null;
                });
            },
            onError: () => {
                pushError.value = t('settings.notifications.pushError');
            },
            onFinish: () => {
                pushProcessing.value = false;
            },
        });
    } catch {
        pushError.value = t('settings.notifications.pushError');
        pushProcessing.value = false;
    }
};

const sendTestPush = () => {
    if (pushTestProcessing.value || !isPushActive.value) {
        return;
    }

    pushTestProcessing.value = true;
    pushTestSent.value = false;
    pushError.value = '';

    router.post(
        props.push_subscription_test_url,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                pushTestSent.value = true;
            },
            onError: () => {
                pushError.value = t('settings.notifications.pushTestError');
            },
            onFinish: () => {
                pushTestProcessing.value = false;
            },
        },
    );
};

const submit = () => {
    form.patch(NotificationPreferencesController.update.url(), {
        preserveScroll: true,
    });
};

const registerPhoneChannel = () => {
    phoneForm.post(props.phone_channel_store_url, {
        preserveScroll: true,
        onSuccess: () => phoneForm.reset('phone', 'consent'),
    });
};

const verifyPhoneChannel = (id: number) => {
    router.post(
        `/settings/notification-phone-channels/${id}/verify`,
        { code: verificationCodes[id] ?? '' },
        { preserveScroll: true },
    );
};

const revokePhoneChannel = (id: number) => {
    router.delete(`/settings/notification-phone-channels/${id}`, {
        preserveScroll: true,
    });
};

onMounted(() => {
    void registerServiceWorker().catch(() => {
        pushSupported.value = false;
    });
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'settings.notifications.title',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="t('settings.notifications.title')" />

    <h1 class="sr-only">{{ t('settings.notifications.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.notifications.title')"
            :description="t('settings.notifications.description')"
        />

        <div
            class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-sm leading-6 text-blue-900"
        >
            <div class="flex gap-3">
                <BellRing
                    class="mt-0.5 size-5 shrink-0 text-[var(--erin-primary-text)]"
                />
                <p>{{ t('settings.notifications.databaseHint') }}</p>
            </div>
        </div>

        <section
            class="rounded-2xl border border-border bg-card p-5 shadow-sm"
            aria-labelledby="browser-push-title"
        >
            <div
                class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex min-w-0 gap-3">
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-600"
                    >
                        <MonitorSmartphone class="size-5" />
                    </div>
                    <div>
                        <h2
                            id="browser-push-title"
                            class="font-semibold text-foreground"
                        >
                            {{ t('settings.notifications.pushTitle') }}
                        </h2>
                        <p class="mt-1 text-sm leading-5 text-muted-foreground">
                            {{ t('settings.notifications.pushDescription') }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium"
                            :class="
                                isPushActive
                                    ? 'text-emerald-700'
                                    : 'text-muted-foreground'
                            "
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <CheckCircle2
                                    v-if="isPushActive"
                                    class="size-4"
                                />
                                <ShieldAlert v-else class="size-4" />
                                {{ pushStatusText }}
                            </span>
                            <span
                                v-if="push_subscription_count > 0"
                                class="text-muted-foreground"
                            >
                                {{ push_subscription_count }}
                                {{
                                    t(
                                        'settings.notifications.registeredDevices',
                                    )
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <div v-if="isPushActive" class="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="pushProcessing || pushTestProcessing"
                        @click="sendTestPush"
                    >
                        <LoaderCircle
                            v-if="pushTestProcessing"
                            class="size-4 animate-spin"
                        />
                        {{
                            pushTestProcessing
                                ? t('settings.notifications.pushTestProcessing')
                                : t('settings.notifications.pushTest')
                        }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="pushProcessing || pushTestProcessing"
                        @click="disablePush"
                    >
                        <LoaderCircle
                            v-if="pushProcessing"
                            class="size-4 animate-spin"
                        />
                        {{ t('settings.notifications.pushDisable') }}
                    </Button>
                </div>
                <Button
                    v-else
                    type="button"
                    :disabled="
                        pushProcessing ||
                        !push_configured ||
                        !pushSupported ||
                        pushPermission === 'denied'
                    "
                    @click="enablePush"
                >
                    <LoaderCircle
                        v-if="pushProcessing"
                        class="size-4 animate-spin"
                    />
                    {{
                        pushProcessing
                            ? t('settings.notifications.pushWorking')
                            : t('settings.notifications.pushEnable')
                    }}
                </Button>
            </div>

            <p
                v-if="pushError"
                class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"
                role="alert"
            >
                {{ pushError }}
            </p>
            <p
                v-if="pushTestSent"
                class="mt-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700"
                role="status"
            >
                {{ t('settings.notifications.pushTestSent') }}
            </p>
        </section>

        <section
            class="rounded-2xl border border-border bg-card p-5 shadow-sm"
            aria-labelledby="phone-channels-title"
        >
            <div class="flex gap-3">
                <div
                    class="grid size-11 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700"
                >
                    <Phone class="size-5" />
                </div>
                <div>
                    <h2
                        id="phone-channels-title"
                        class="font-semibold text-foreground"
                    >
                        {{ t('settings.notifications.phoneTitle') }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('settings.notifications.phoneDescription') }}
                    </p>
                </div>
            </div>

            <p
                v-if="!phone_channels_configured"
                class="mt-4 rounded-xl bg-orange-50 p-3 text-sm text-orange-900"
            >
                {{ t('settings.notifications.phoneUnavailable') }}
            </p>
            <form
                v-else
                class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                @submit.prevent="registerPhoneChannel"
            >
                <select
                    v-model="phoneForm.channel"
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
                >
                    <option value="sms">
                        {{ t('settings.notifications.sms') }}
                    </option>
                    <option value="whatsapp">
                        {{ t('settings.notifications.whatsapp') }}
                    </option>
                </select>
                <input
                    v-model="phoneForm.phone"
                    required
                    pattern="\+[1-9][0-9]{7,14}"
                    class="erin-focus h-11 rounded-xl border border-border px-3 text-sm"
                    :placeholder="t('settings.notifications.phonePlaceholder')"
                />
                <select
                    v-model="phoneForm.country_code"
                    class="erin-focus h-11 rounded-xl border border-border bg-card px-3 text-sm"
                >
                    <option
                        v-for="country in phone_allowed_countries"
                        :key="country"
                        :value="country"
                    >
                        {{ country }}
                    </option>
                </select>
                <div class="grid grid-cols-2 gap-2">
                    <input
                        v-model="phoneForm.quiet_hours_start"
                        type="time"
                        class="erin-focus h-11 rounded-xl border border-border px-2 text-sm"
                        :aria-label="t('settings.notifications.quietStart')"
                    />
                    <input
                        v-model="phoneForm.quiet_hours_end"
                        type="time"
                        class="erin-focus h-11 rounded-xl border border-border px-2 text-sm"
                        :aria-label="t('settings.notifications.quietEnd')"
                    />
                </div>
                <label
                    class="flex items-start gap-2 text-xs text-muted-foreground md:col-span-2 xl:col-span-3"
                >
                    <Checkbox v-model="phoneForm.consent" />
                    <span>{{
                        t('settings.notifications.phoneConsent', {
                            channel:
                                phoneForm.channel === 'sms'
                                    ? t('settings.notifications.sms')
                                    : t('settings.notifications.whatsapp'),
                        })
                    }}</span>
                </label>
                <Button
                    type="submit"
                    :disabled="phoneForm.processing || !phoneForm.consent"
                    >{{ t('settings.notifications.sendCode') }}</Button
                >
                <InputError
                    class="md:col-span-2 xl:col-span-4"
                    :message="
                        phoneForm.errors.phone || phoneForm.errors.consent
                    "
                />
            </form>

            <div
                v-if="phone_channels.length"
                class="mt-5 grid gap-3 md:grid-cols-2"
            >
                <article
                    v-for="channel in phone_channels"
                    :key="channel.id"
                    class="rounded-xl border border-border p-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <strong class="text-sm uppercase">{{
                                channel.channel
                            }}</strong>
                            <p class="text-xs text-muted-foreground">
                                {{ channel.masked_phone }} ·
                                {{ channel.country_code }}
                            </p>
                        </div>
                        <Badge
                            :variant="
                                channel.verified && channel.consented
                                    ? 'default'
                                    : 'outline'
                            "
                            >{{
                                channel.verified
                                    ? t('settings.notifications.verified')
                                    : t(
                                          'settings.notifications.verificationPending',
                                      )
                            }}</Badge
                        >
                    </div>
                    <div v-if="!channel.verified" class="mt-3 flex gap-2">
                        <input
                            v-model="verificationCodes[channel.id]"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            class="erin-focus h-10 min-w-0 flex-1 rounded-xl border border-border px-3 text-sm"
                            :placeholder="t('settings.notifications.code')"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            @click="verifyPhoneChannel(channel.id)"
                            >{{ t('settings.notifications.verify') }}</Button
                        >
                    </div>
                    <button
                        type="button"
                        class="erin-focus mt-3 text-xs font-bold text-red-600 underline"
                        @click="revokePhoneChannel(channel.id)"
                    >
                        {{ t('settings.notifications.revoke') }}
                    </button>
                </article>
            </div>
        </section>

        <form class="space-y-4" @submit.prevent="submit">
            <article
                v-for="event in eventKeys"
                :key="event"
                class="overflow-hidden rounded-2xl border border-border bg-card shadow-sm"
            >
                <header class="border-b border-border bg-muted/70 px-5 py-4">
                    <h2 class="font-semibold text-foreground">
                        {{ t(`settings.notifications.events.${event}.title`) }}
                    </h2>
                    <p class="mt-1 text-sm leading-5 text-muted-foreground">
                        {{
                            t(
                                `settings.notifications.events.${event}.description`,
                            )
                        }}
                    </p>
                </header>

                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        :for="`${event}-database`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-border p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-database`"
                            v-model="form.preferences[event].database_enabled"
                        />
                        <BellRing
                            class="size-4 text-[var(--erin-primary-text)]"
                        />
                        <span class="text-sm font-medium text-muted-foreground">
                            {{ t('settings.notifications.inApp') }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-email`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-border p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-email`"
                            v-model="form.preferences[event].email_enabled"
                        />
                        <Mail class="size-4 text-teal-600" />
                        <span class="text-sm font-medium text-muted-foreground">
                            {{ t('settings.notifications.email') }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-push`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-border p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-push`"
                            v-model="form.preferences[event].push_enabled"
                        />
                        <MonitorSmartphone class="size-4 text-orange-600" />
                        <span class="text-sm font-medium text-muted-foreground">
                            {{ t('settings.notifications.browserPush') }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-sms`"
                        class="flex items-center gap-3 rounded-xl border p-3"
                        :class="
                            channelReady('sms') && externalEvents.has(event)
                                ? 'cursor-pointer border-border text-muted-foreground'
                                : 'border-dashed border-border bg-muted text-muted-foreground'
                        "
                    >
                        <Checkbox
                            :id="`${event}-sms`"
                            v-model="form.preferences[event].sms_enabled"
                            :disabled="
                                !channelReady('sms') ||
                                !externalEvents.has(event)
                            "
                        />
                        <Phone class="size-4" />
                        <span class="text-sm font-medium">
                            {{ t('settings.notifications.sms') }}
                        </span>
                        <Badge
                            v-if="!channelReady('sms')"
                            variant="outline"
                            class="ml-auto text-[10px]"
                            >{{
                                t('settings.notifications.verificationRequired')
                            }}</Badge
                        >
                    </label>

                    <label
                        :for="`${event}-whatsapp`"
                        class="flex items-center gap-3 rounded-xl border p-3"
                        :class="
                            channelReady('whatsapp') &&
                            externalEvents.has(event)
                                ? 'cursor-pointer border-border text-muted-foreground'
                                : 'border-dashed border-border bg-muted text-muted-foreground'
                        "
                    >
                        <Checkbox
                            :id="`${event}-whatsapp`"
                            v-model="form.preferences[event].whatsapp_enabled"
                            :disabled="
                                !channelReady('whatsapp') ||
                                !externalEvents.has(event)
                            "
                        />
                        <MessageSquareText class="size-4" />
                        <span class="text-sm font-medium">
                            {{ t('settings.notifications.whatsapp') }}
                        </span>
                        <Badge
                            v-if="!channelReady('whatsapp')"
                            variant="outline"
                            class="ml-auto text-[10px]"
                            >{{
                                t('settings.notifications.verificationRequired')
                            }}</Badge
                        >
                    </label>
                </div>
            </article>

            <p
                v-if="!push_configured"
                class="rounded-xl bg-orange-50 px-4 py-3 text-sm leading-5 text-orange-900"
            >
                {{ t('settings.notifications.pushMissing') }}
            </p>

            <InputError :message="preferenceError" />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ t('settings.notifications.save') }}
                </Button>
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm font-medium text-emerald-600"
                >
                    {{ t('settings.notifications.saved') }}
                </span>
            </div>
        </form>
    </div>
</template>
