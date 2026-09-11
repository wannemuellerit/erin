<script setup lang="ts">
import {
    Bot,
    Download,
    ExternalLink,
    Send,
    ThumbsDown,
    ThumbsUp,
    Trash2,
    UserRoundCheck,
} from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { localeNames, normalizeLocale, supportedLocales } from '@/i18n';
import { newUuid } from '@/lib/uuid';
import type { SupportedLocale } from '@/i18n';

type ChatSource = {
    id: number;
    title: string;
    url: string | null;
    version: number;
};
type ChatMessage = {
    id: number;
    author: 'user' | 'assistant';
    body: string;
    sources: ChatSource[];
    escalation_required: boolean;
    feedback: 'helpful' | 'unhelpful' | null;
    created_at: string;
};
type ChatSession = {
    id: string;
    locale: SupportedLocale;
    status: 'active' | 'handed_off';
    ticket_id: number | null;
    retention_expires_at: string;
};

const props = defineProps<{
    initialSession: ChatSession | null;
    initialMessages: ChatMessage[];
}>();

const { t, locale } = useI18n();
const session = ref<ChatSession | null>(props.initialSession);
const messages = ref<ChatMessage[]>([...props.initialMessages]);
const draft = ref('');
const busy = ref(false);
const error = ref('');
const handoffConsent = ref(false);
const scrollArea = ref<HTMLElement | null>(null);
const selectedLocale = ref<SupportedLocale>(
    normalizeLocale(props.initialSession?.locale ?? locale.value),
);
const csrf = () =>
    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content ?? '';
const canSend = computed(
    () =>
        draft.value.trim() !== '' &&
        !busy.value &&
        session.value?.status !== 'handed_off',
);

const request = async <T,>(url: string, options: RequestInit): Promise<T> => {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(options.headers ?? {}),
        },
    });

    if (!response.ok) {
        const payload = (await response.json().catch(() => ({}))) as {
            message?: string;
        };

        throw new Error(
            payload.message || t('operations.support.chatbot.genericError'),
        );
    }

    return (await response.json()) as T;
};

const start = async () => {
    busy.value = true;
    error.value = '';

    try {
        const payload = await request<{ session: ChatSession }>(
            '/support/chat/sessions',
            {
                method: 'POST',
                body: JSON.stringify({
                    locale: selectedLocale.value,
                    current_route: window.location.pathname,
                }),
            },
        );
        session.value = payload.session;
        messages.value = [];
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : t('operations.support.chatbot.genericError');
    } finally {
        busy.value = false;
    }
};

const send = async () => {
    if (!canSend.value) {
        return;
    }

    if (!session.value) {
        await start();
    }

    if (!session.value) {
        return;
    }

    const body = draft.value.trim();
    const clientId = newUuid();
    messages.value.push({
        id: -Date.now(),
        author: 'user',
        body,
        sources: [],
        escalation_required: false,
        feedback: null,
        created_at: new Date().toISOString(),
    });
    draft.value = '';
    busy.value = true;
    error.value = '';
    await nextTick();
    scrollArea.value?.scrollTo({
        top: scrollArea.value.scrollHeight,
        behavior: 'smooth',
    });

    try {
        const payload = await request<{ message: ChatMessage }>(
            `/support/chat/sessions/${session.value.id}/messages`,
            {
                method: 'POST',
                body: JSON.stringify({
                    client_id: clientId,
                    message: body,
                    locale: selectedLocale.value,
                    current_route: window.location.pathname,
                }),
            },
        );
        messages.value.push(payload.message);
        await nextTick();
        scrollArea.value?.scrollTo({
            top: scrollArea.value.scrollHeight,
            behavior: 'smooth',
        });
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : t('operations.support.chatbot.genericError');
    } finally {
        busy.value = false;
    }
};

const feedback = async (
    message: ChatMessage,
    value: 'helpful' | 'unhelpful',
) => {
    if (!session.value) {
        return;
    }

    try {
        const payload = await request<{ message: ChatMessage }>(
            `/support/chat/sessions/${session.value.id}/messages/${message.id}/feedback`,
            {
                method: 'PATCH',
                body: JSON.stringify({ feedback: value }),
            },
        );
        Object.assign(message, payload.message);
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : t('operations.support.chatbot.genericError');
    }
};

const handoff = async () => {
    if (!session.value || !handoffConsent.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const payload = await request<{ url: string }>(
            `/support/chat/sessions/${session.value.id}/handoff`,
            {
                method: 'POST',
                body: JSON.stringify({
                    consent: true,
                    idempotency_key: newUuid(),
                    diagnostics: { browser: navigator.userAgent.slice(0, 180) },
                }),
            },
        );
        window.location.assign(payload.url);
    } catch (caught) {
        error.value =
            caught instanceof Error
                ? caught.message
                : t('operations.support.chatbot.genericError');
        busy.value = false;
    }
};

const remove = async () => {
    if (
        !session.value ||
        !window.confirm(t('operations.support.chatbot.deleteConfirm'))
    ) {
        return;
    }

    const response = await fetch(`/support/chat/sessions/${session.value.id}`, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
    });

    if (response.ok) {
        session.value = null;
        messages.value = [];
    }
};
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border border-blue-200 bg-card shadow-sm"
        aria-labelledby="support-chatbot-title"
    >
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b border-blue-100 bg-blue-50 px-5 py-4"
        >
            <div class="flex items-center gap-3">
                <span
                    class="grid size-10 place-items-center rounded-xl bg-blue-600 text-white"
                    ><Bot class="size-5"
                /></span>
                <div>
                    <h2
                        id="support-chatbot-title"
                        class="font-bold text-foreground"
                    >
                        {{ t('operations.support.chatbot.title') }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ t('operations.support.chatbot.aiLabel') }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <label class="sr-only" for="support-chat-locale">{{
                    t('operations.support.chatbot.language')
                }}</label>
                <select
                    id="support-chat-locale"
                    v-model="selectedLocale"
                    class="erin-focus h-9 rounded-lg border border-border bg-card px-2 text-xs"
                >
                    <option
                        v-for="supportedLocale in supportedLocales"
                        :key="supportedLocale"
                        :value="supportedLocale"
                    >
                        {{ localeNames[supportedLocale] }}
                    </option>
                </select>
                <a
                    v-if="session"
                    :href="`/support/chat/sessions/${session.id}/export`"
                    class="erin-focus rounded-lg p-2 text-muted-foreground hover:bg-card"
                    :aria-label="t('operations.support.chatbot.export')"
                    ><Download class="size-4"
                /></a>
                <button
                    v-if="session"
                    type="button"
                    class="erin-focus rounded-lg p-2 text-red-600 hover:bg-card"
                    :aria-label="t('operations.support.chatbot.delete')"
                    @click="remove"
                >
                    <Trash2 class="size-4" />
                </button>
            </div>
        </header>

        <div
            ref="scrollArea"
            class="max-h-[30rem] space-y-4 overflow-y-auto p-5"
            role="log"
            aria-live="polite"
        >
            <p
                v-if="messages.length === 0"
                class="rounded-xl bg-muted p-4 text-sm text-muted-foreground"
            >
                {{ t('operations.support.chatbot.welcome') }}
            </p>
            <article
                v-for="message in messages"
                :key="message.id"
                class="max-w-[90%] rounded-2xl p-4 text-sm"
                :class="
                    message.author === 'user'
                        ? 'ml-auto bg-blue-600 text-white'
                        : 'bg-muted text-foreground'
                "
            >
                <p class="whitespace-pre-wrap">{{ message.body }}</p>
                <div
                    v-if="message.sources.length"
                    class="mt-3 border-t border-border/50 pt-2 text-xs"
                >
                    <p class="mb-1 font-bold">
                        {{ t('operations.support.chatbot.sources') }}
                    </p>
                    <a
                        v-for="source in message.sources"
                        :key="source.id"
                        :href="source.url || '#support-chatbot-title'"
                        :target="source.url ? '_blank' : undefined"
                        rel="noreferrer"
                        class="mr-3 inline-flex items-center gap-1 underline"
                    >
                        {{
                            t('operations.support.chatbot.sourceVersion', {
                                title: source.title,
                                version: source.version,
                            })
                        }}
                        <ExternalLink v-if="source.url" class="size-3" />
                    </a>
                </div>
                <div
                    v-if="message.author === 'assistant'"
                    class="mt-3 flex gap-1"
                >
                    <button
                        type="button"
                        class="erin-focus rounded p-1"
                        :class="
                            message.feedback === 'helpful'
                                ? 'bg-green-100 text-green-700'
                                : ''
                        "
                        :aria-label="t('operations.support.chatbot.helpful')"
                        @click="feedback(message, 'helpful')"
                    >
                        <ThumbsUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="erin-focus rounded p-1"
                        :class="
                            message.feedback === 'unhelpful'
                                ? 'bg-red-100 text-red-700'
                                : ''
                        "
                        :aria-label="t('operations.support.chatbot.unhelpful')"
                        @click="feedback(message, 'unhelpful')"
                    >
                        <ThumbsDown class="size-4" />
                    </button>
                </div>
            </article>
            <p v-if="busy" class="text-sm text-muted-foreground" role="status">
                {{ t('operations.support.chatbot.thinking') }}
            </p>
        </div>

        <div
            v-if="session?.status !== 'handed_off'"
            class="border-t border-border p-4"
        >
            <form class="flex gap-2" @submit.prevent="send">
                <label class="sr-only" for="support-chatbot-message">{{
                    t('operations.support.chatbot.input')
                }}</label>
                <textarea
                    id="support-chatbot-message"
                    v-model="draft"
                    rows="2"
                    maxlength="4000"
                    class="erin-focus min-h-12 flex-1 resize-none rounded-xl border border-border px-3 py-2 text-sm"
                    :placeholder="t('operations.support.chatbot.placeholder')"
                    @keydown.ctrl.enter.prevent="send"
                />
                <button
                    type="submit"
                    :disabled="!canSend"
                    class="erin-focus grid size-12 place-items-center self-end rounded-xl bg-blue-600 text-white disabled:opacity-40"
                    :aria-label="t('operations.support.chatbot.send')"
                >
                    <Send class="size-5" />
                </button>
            </form>
            <p v-if="error" class="mt-2 text-sm text-red-600" role="alert">
                {{ error }}
            </p>
            <div
                v-if="session"
                class="mt-4 rounded-xl border border-orange-200 bg-orange-50 p-3"
            >
                <label
                    class="flex items-start gap-2 text-xs text-muted-foreground"
                >
                    <input
                        v-model="handoffConsent"
                        type="checkbox"
                        class="mt-0.5"
                    />
                    <span>{{
                        t('operations.support.chatbot.handoffConsent')
                    }}</span>
                </label>
                <button
                    type="button"
                    :disabled="!handoffConsent || busy"
                    class="erin-focus mt-3 inline-flex items-center gap-2 rounded-lg bg-orange-700 px-3 py-2 text-xs font-bold text-white disabled:opacity-40"
                    @click="handoff"
                >
                    <UserRoundCheck class="size-4" />{{
                        t('operations.support.chatbot.handoff')
                    }}
                </button>
            </div>
        </div>
        <p
            v-else
            class="border-t border-green-100 bg-green-50 p-4 text-sm text-green-800"
        >
            {{ t('operations.support.chatbot.handedOff') }}
        </p>
    </section>
</template>
