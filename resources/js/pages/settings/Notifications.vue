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
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NotificationPreferencesController from '@/actions/App/Http/Controllers/Settings/NotificationPreferencesController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { edit } from '@/routes/notification-preferences';

type EventKey = 'application' | 'interview' | 'message' | 'support' | 'system';

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
};

const props = defineProps<Props>();
const { locale } = useI18n();

const translations = {
    de: {
        title: 'Benachrichtigungen',
        description:
            'Lege fest, wie Erin dich bei wichtigen Vorgängen erreichen darf.',
        inApp: 'In-App',
        email: 'E-Mail',
        browserPush: 'Browser-Push',
        sms: 'SMS',
        whatsapp: 'WhatsApp',
        comingLater: 'Später',
        pushMissing:
            'Browser-Push ist noch nicht serverseitig eingerichtet. Hinterlege zuerst die VAPID-Schlüssel.',
        pushTitle: 'Browser-Push auf diesem Gerät',
        pushDescription:
            'Registriere diesen Browser, damit freigegebene Ereignisse auch bei geschlossenem Erin-Tab ankommen.',
        pushActive: 'Auf diesem Gerät aktiv',
        pushInactive: 'Auf diesem Gerät nicht aktiv',
        pushUnsupported:
            'Dieser Browser unterstützt keine Web-Push-Benachrichtigungen.',
        pushDenied:
            'Die Browser-Berechtigung wurde blockiert. Erlaube Benachrichtigungen in den Website-Einstellungen.',
        pushEnable: 'Browser-Push aktivieren',
        pushDisable: 'Auf diesem Gerät deaktivieren',
        pushWorking: 'Wird eingerichtet …',
        pushError:
            'Browser-Push konnte nicht eingerichtet werden. Bitte versuche es erneut.',
        registeredDevices: 'registrierte Geräte',
        databaseHint:
            'Wenn In-App deaktiviert ist, erscheint das Ereignis weder in der Glocke noch als Live-Hinweis.',
        save: 'Einstellungen speichern',
        saved: 'Gespeichert',
        events: {
            application: {
                title: 'Bewerbungen',
                description:
                    'Neue Bewerbungen und Änderungen am Bewerbungsstatus.',
            },
            interview: {
                title: 'Interviews',
                description:
                    'Terminvorschläge, Gegenangebote und bestätigte Gespräche.',
            },
            message: {
                title: 'Nachrichten',
                description: 'Neue Nachrichten im Erin-Nachrichtencenter.',
            },
            support: {
                title: 'Support',
                description:
                    'Antworten und Statusänderungen bei Supporttickets.',
            },
            system: {
                title: 'System & Sonstiges',
                description:
                    'Sicherheits-, Abrechnungs- und andere Plattformhinweise.',
            },
        },
    },
    en: {
        title: 'Notifications',
        description:
            'Choose how Erin may contact you about important activity.',
        inApp: 'In app',
        email: 'Email',
        browserPush: 'Browser push',
        sms: 'SMS',
        whatsapp: 'WhatsApp',
        comingLater: 'Coming later',
        pushMissing:
            'Browser push is not configured on the server yet. Add the VAPID keys first.',
        pushTitle: 'Browser push on this device',
        pushDescription:
            'Register this browser so enabled events can arrive while Erin is not open in a tab.',
        pushActive: 'Active on this device',
        pushInactive: 'Not active on this device',
        pushUnsupported:
            'This browser does not support web push notifications.',
        pushDenied:
            'Browser permission is blocked. Allow notifications in the site settings.',
        pushEnable: 'Enable browser push',
        pushDisable: 'Disable on this device',
        pushWorking: 'Setting up …',
        pushError: 'Browser push could not be configured. Please try again.',
        registeredDevices: 'registered devices',
        databaseHint:
            'When in-app notifications are disabled, the event appears neither in the bell nor as a live update.',
        save: 'Save settings',
        saved: 'Saved',
        events: {
            application: {
                title: 'Applications',
                description:
                    'New applications and changes to application statuses.',
            },
            interview: {
                title: 'Interviews',
                description:
                    'Time suggestions, counterproposals and confirmed interviews.',
            },
            message: {
                title: 'Messages',
                description: 'New messages in the Erin message center.',
            },
            support: {
                title: 'Support',
                description: 'Replies and status changes for support tickets.',
            },
            system: {
                title: 'System & other',
                description:
                    'Security, billing and other platform notifications.',
            },
        },
    },
} as const;

const text = computed(() =>
    locale.value === 'en' ? translations.en : translations.de,
);
const eventKeys: EventKey[] = [
    'application',
    'interview',
    'message',
    'support',
    'system',
];
const form = useForm({
    preferences: structuredClone(props.preferences),
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

const isPushActive = computed(() => browserSubscription.value !== null);
const pushStatusText = computed(() => {
    if (!pushSupported.value) {
        return text.value.pushUnsupported;
    }

    if (pushPermission.value === 'denied') {
        return text.value.pushDenied;
    }

    return isPushActive.value ? text.value.pushActive : text.value.pushInactive;
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
                    pushError.value = text.value.pushError;
                },
                onFinish: () => {
                    pushProcessing.value = false;
                },
            },
        );
    } catch {
        pushError.value = text.value.pushError;
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
                pushError.value = text.value.pushError;
            },
            onFinish: () => {
                pushProcessing.value = false;
            },
        });
    } catch {
        pushError.value = text.value.pushError;
        pushProcessing.value = false;
    }
};

const submit = () => {
    form.patch(NotificationPreferencesController.update.url(), {
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
                title: 'Notifications',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="text.title" />

    <h1 class="sr-only">{{ text.title }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="text.title"
            :description="text.description"
        />

        <div
            class="rounded-2xl border border-blue-100 bg-blue-50/70 p-4 text-sm leading-6 text-blue-900"
        >
            <div class="flex gap-3">
                <BellRing class="mt-0.5 size-5 shrink-0 text-blue-600" />
                <p>{{ text.databaseHint }}</p>
            </div>
        </div>

        <section
            class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
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
                            class="font-semibold text-slate-950"
                        >
                            {{ text.pushTitle }}
                        </h2>
                        <p class="mt-1 text-sm leading-5 text-slate-500">
                            {{ text.pushDescription }}
                        </p>
                        <div
                            class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-medium"
                            :class="
                                isPushActive
                                    ? 'text-emerald-700'
                                    : 'text-slate-500'
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
                                class="text-slate-400"
                            >
                                {{ push_subscription_count }}
                                {{ text.registeredDevices }}
                            </span>
                        </div>
                    </div>
                </div>

                <Button
                    v-if="isPushActive"
                    type="button"
                    variant="outline"
                    :disabled="pushProcessing"
                    @click="disablePush"
                >
                    <LoaderCircle
                        v-if="pushProcessing"
                        class="size-4 animate-spin"
                    />
                    {{ text.pushDisable }}
                </Button>
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
                    {{ pushProcessing ? text.pushWorking : text.pushEnable }}
                </Button>
            </div>

            <p
                v-if="pushError"
                class="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"
                role="alert"
            >
                {{ pushError }}
            </p>
        </section>

        <form class="space-y-4" @submit.prevent="submit">
            <article
                v-for="event in eventKeys"
                :key="event"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
            >
                <header
                    class="border-b border-slate-100 bg-slate-50/70 px-5 py-4"
                >
                    <h2 class="font-semibold text-slate-950">
                        {{ text.events[event].title }}
                    </h2>
                    <p class="mt-1 text-sm leading-5 text-slate-500">
                        {{ text.events[event].description }}
                    </p>
                </header>

                <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                    <label
                        :for="`${event}-database`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-database`"
                            v-model="form.preferences[event].database_enabled"
                        />
                        <BellRing class="size-4 text-blue-600" />
                        <span class="text-sm font-medium text-slate-700">
                            {{ text.inApp }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-email`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-email`"
                            v-model="form.preferences[event].email_enabled"
                        />
                        <Mail class="size-4 text-teal-600" />
                        <span class="text-sm font-medium text-slate-700">
                            {{ text.email }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-push`"
                        class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 p-3 transition hover:border-blue-200 hover:bg-blue-50/40"
                    >
                        <Checkbox
                            :id="`${event}-push`"
                            v-model="form.preferences[event].push_enabled"
                        />
                        <MonitorSmartphone class="size-4 text-orange-600" />
                        <span class="text-sm font-medium text-slate-700">
                            {{ text.browserPush }}
                        </span>
                    </label>

                    <label
                        :for="`${event}-sms`"
                        class="flex items-center gap-3 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-slate-400"
                    >
                        <Checkbox
                            :id="`${event}-sms`"
                            :model-value="false"
                            disabled
                        />
                        <Phone class="size-4" />
                        <span class="text-sm font-medium">{{ text.sms }}</span>
                        <Badge variant="outline" class="ml-auto text-[10px]">
                            {{ text.comingLater }}
                        </Badge>
                    </label>

                    <label
                        :for="`${event}-whatsapp`"
                        class="flex items-center gap-3 rounded-xl border border-dashed border-slate-200 bg-slate-50 p-3 text-slate-400"
                    >
                        <Checkbox
                            :id="`${event}-whatsapp`"
                            :model-value="false"
                            disabled
                        />
                        <MessageSquareText class="size-4" />
                        <span class="text-sm font-medium">{{
                            text.whatsapp
                        }}</span>
                        <Badge variant="outline" class="ml-auto text-[10px]">
                            {{ text.comingLater }}
                        </Badge>
                    </label>
                </div>
            </article>

            <p
                v-if="!push_configured"
                class="rounded-xl bg-orange-50 px-4 py-3 text-sm leading-5 text-orange-900"
            >
                {{ text.pushMissing }}
            </p>

            <InputError :message="preferenceError" />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    {{ text.save }}
                </Button>
                <span
                    v-if="form.recentlySuccessful"
                    class="text-sm font-medium text-emerald-600"
                >
                    {{ text.saved }}
                </span>
            </div>
        </form>
    </div>
</template>
