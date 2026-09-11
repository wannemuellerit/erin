<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    FileText,
    Inbox,
    Languages,
    Mic,
    RotateCcw,
    Send,
    Square,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import SearchField from '@/components/product/SearchField.vue';
import { useFormatters } from '@/composables/useFormatters';
import { productMessages } from '@/i18n/product-locales';
import { newUuid } from '@/lib/uuid';
import { index, read, send } from '@/routes/messages';
import type {
    Conversation,
    ConversationMessage,
    MessagingWorkspaceProps,
} from '@/types';

const props = withDefaults(defineProps<MessagingWorkspaceProps>(), {
    perspective: 'employer',
    conversations: () => [],
    selected: null,
});

const { t, locale } = useI18n({
    useScope: 'local',
    messages: productMessages,
});
const { formatDate: formatLocalizedDate } = useFormatters();

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);
const cloneConversations = (items: Conversation[]): Conversation[] =>
    JSON.parse(JSON.stringify(items)) as Conversation[];
const conversations = ref<Conversation[]>(
    cloneConversations(props.conversations),
);
watch(
    () => props.conversations,
    (serverConversations) => {
        conversations.value = cloneConversations(serverConversations);
    },
    { deep: true },
);
const query = ref('');
const activeId = ref<number | null>(
    props.selected ?? props.conversations[0]?.id ?? null,
);
const active = computed(
    () =>
        conversations.value.find(
            (conversation) => conversation.id === activeId.value,
        ) ?? null,
);
const filtered = computed(() =>
    conversations.value.filter((conversation) => {
        const value = [
            conversation.title,
            ...(conversation.participants?.map(
                (participant) => participant.name,
            ) ?? []),
        ]
            .join(' ')
            .toLowerCase();

        return !query.value || value.includes(query.value.toLowerCase());
    }),
);
const otherParticipants = (conversation: Conversation) =>
    conversation.participants?.filter(
        (participant) => participant.id !== currentUserId.value,
    ) ?? [];
const name = (conversation: Conversation) =>
    otherParticipants(conversation)
        .map((participant) => participant.name)
        .join(', ') ||
    conversation.title ||
    t('messagingWorkspace.conversationFallback');
const formatDate = (value: string) =>
    formatLocalizedDate(value, { dateStyle: 'short' });
const formatTime = (value: string) =>
    formatLocalizedDate(value, { timeStyle: 'short' });
const messageForm = useForm({
    body: '',
    type: 'text',
    client_id: newUuid(),
    duration_seconds: null as number | null,
    waveform: [] as number[],
    attachments: [] as File[],
});
const replaceOrAppend = (
    conversationId: number,
    message: ConversationMessage,
) => {
    const conversation = conversations.value.find(
        (item) => item.id === conversationId,
    );
    const messages = conversation?.messages;

    if (!messages) {
        return;
    }

    const index = messages.findIndex(
        (item) =>
            item.id === message.id ||
            (!!message.client_id && item.client_id === message.client_id),
    );

    if (index === -1) {
        messages.push({ ...message, delivery_status: 'sent' });
    } else {
        messages.splice(index, 1, { ...message, delivery_status: 'sent' });
    }

    messages.sort(
        (left, right) =>
            new Date(left.created_at ?? 0).getTime() -
            new Date(right.created_at ?? 0).getTime(),
    );
};

for (const conversation of props.conversations) {
    useEcho<{ message: ConversationMessage }>(
        `conversation.${conversation.id}`,
        '.message.sent',
        ({ message }) => {
            replaceOrAppend(conversation.id, message);

            if (message.sender_id !== currentUserId.value) {
                if (activeId.value === conversation.id) {
                    router.post(
                        read.url(conversation.id),
                        {},
                        { preserveScroll: true },
                    );
                } else {
                    const target = conversations.value.find(
                        (item) => item.id === conversation.id,
                    );

                    if (target) {
                        target.unread = (target.unread ?? 0) + 1;
                    }
                }
            }
        },
    );

    useEcho<{ user_id: number; read_at: string }>(
        `conversation.${conversation.id}`,
        '.conversation.read',
        ({ user_id }) => {
            if (user_id !== currentUserId.value) {
                const target = conversations.value.find(
                    (item) => item.id === conversation.id,
                );

                if (target) {
                    target.unread = 0;
                }
            }
        },
    );

    useEcho<{
        message_id: number;
        target_locale: string;
        translation: {
            status: string;
            body?: string | null;
            model?: string | null;
            prompt_version?: string | null;
        };
    }>(
        `conversation.${conversation.id}`,
        '.message.translated',
        ({ message_id, target_locale, translation }) => {
            const message = conversations.value
                .find((item) => item.id === conversation.id)
                ?.messages?.find((item) => item.id === message_id);

            if (message) {
                message.translations ??= {};
                message.translations[target_locale] = translation;
            }
        },
    );
}

const translating = ref<number | null>(null);
const translateMessage = async (message: ConversationMessage) => {
    translating.value = message.id;

    try {
        const targetLocale = locale.value;
        const csrfToken =
            document
                .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        const response = await fetch(
            `/messages/items/${message.id}/translations`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    target_locale: targetLocale,
                    explicit_consent: true,
                }),
            },
        );
        const data = (await response.json()) as {
            status: string;
            body?: string | null;
            model?: string | null;
            prompt_version?: string | null;
        };

        if (!response.ok) {
            throw new Error('translation_failed');
        }

        message.translations ??= {};
        message.translations[targetLocale] = {
            status: data.status,
            body: data.body,
            model: data.model,
            prompt_version: data.prompt_version,
        };
    } finally {
        translating.value = null;
    }
};
const selectConversation = (conversation: Conversation) => {
    activeId.value = conversation.id;
    router.get(
        index.url(),
        { conversation: conversation.id },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['conversations', 'selected'],
        },
    );

    if (conversation.unread) {
        router.post(read.url(conversation.id), {}, { preserveScroll: true });
    }
};
const submit = () => {
    if (
        !active.value ||
        (!messageForm.body.trim() && !messageForm.attachments.length)
    ) {
        return;
    }

    const optimistic: ConversationMessage = {
        id: -Date.now(),
        client_id: messageForm.client_id,
        sender_id: Number(currentUserId.value),
        type: messageForm.type,
        body: messageForm.body.trim() || null,
        created_at: new Date().toISOString(),
        delivery_status: 'sending',
        attachments: messageForm.attachments.map((file, index) => ({
            id: -(Date.now() + index + 1),
            original_name: file.name,
            mime_type: file.type,
            scan_result: 'pending',
            duration_seconds: messageForm.duration_seconds,
            waveform: messageForm.waveform,
        })),
    };
    active.value.messages ??= [];
    active.value.messages.push(optimistic);

    messageForm.post(send.url(active.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            messageForm.reset();
            messageForm.client_id = newUuid();
            discardVoice();
        },
        onError: () => {
            optimistic.delivery_status = 'failed';
        },
    });
};

const retry = (message: ConversationMessage) => {
    if (!active.value || !message.client_id) {
        return;
    }

    messageForm.body = message.body ?? '';
    messageForm.type = message.type ?? 'text';
    messageForm.client_id = message.client_id;
    message.delivery_status = 'sending';
    messageForm.post(send.url(active.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onError: () => (message.delivery_status = 'failed'),
        onSuccess: () => {
            message.delivery_status = 'sent';
            messageForm.reset();
            messageForm.client_id = newUuid();
        },
    });
};

const recording = ref(false);
const voicePreview = ref<string | null>(null);
let recorder: MediaRecorder | null = null;
let stream: MediaStream | null = null;
let chunks: Blob[] = [];
let recordingStartedAt = 0;

const startVoice = async () => {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    chunks = [];
    recorder = new MediaRecorder(stream);
    recorder.addEventListener('dataavailable', (event) => {
        if (event.data.size) {
            chunks.push(event.data);
        }
    });
    recorder.addEventListener('stop', () => {
        const blob = new Blob(chunks, {
            type: recorder?.mimeType || 'audio/webm',
        });
        const file = new File([blob], `voice-${Date.now()}.webm`, {
            type: blob.type,
        });
        messageForm.attachments = [file];
        messageForm.type = 'voice';
        messageForm.duration_seconds = Math.max(
            1,
            Math.ceil((Date.now() - recordingStartedAt) / 1000),
        );
        messageForm.waveform = Array.from({ length: 24 }, (_, index) =>
            Number((0.25 + ((index * 17) % 55) / 100).toFixed(2)),
        );
        voicePreview.value = URL.createObjectURL(blob);
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    });
    recordingStartedAt = Date.now();
    recorder.start(250);
    recording.value = true;
};

const stopVoice = () => {
    recorder?.stop();
    recording.value = false;
};

const discardVoice = () => {
    if (voicePreview.value) {
        URL.revokeObjectURL(voicePreview.value);
    }

    voicePreview.value = null;
    messageForm.attachments = [];
    messageForm.type = 'text';
    messageForm.duration_seconds = null;
    messageForm.waveform = [];
};

onBeforeUnmount(() => {
    if (recording.value) {
        recorder?.stop();
    }

    stream?.getTracks().forEach((track) => track.stop());

    if (voicePreview.value) {
        URL.revokeObjectURL(voicePreview.value);
    }
});
</script>

<template>
    <div
        class="erin-panel grid min-h-[680px] overflow-hidden lg:grid-cols-[21rem_minmax(0,1fr)]"
    >
        <aside class="border-r border-border bg-card">
            <div class="border-b border-border p-4">
                <SearchField
                    v-model="query"
                    size="sm"
                    :label="t('messagingWorkspace.searchLabel')"
                    :placeholder="t('messagingWorkspace.searchPlaceholder')"
                    class="bg-muted text-xs"
                />
            </div>
            <div v-if="filtered.length" class="divide-y divide-border">
                <button
                    v-for="conversation in filtered"
                    :key="conversation.id"
                    type="button"
                    :data-test="`conversation-${conversation.id}`"
                    class="flex w-full gap-3 p-4 text-left"
                    :class="
                        activeId === conversation.id
                            ? 'bg-blue-50/70'
                            : 'hover:bg-muted'
                    "
                    @click="selectConversation(conversation)"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-xs font-extrabold text-blue-800"
                        >{{
                            name(conversation).slice(0, 2).toUpperCase()
                        }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2"
                            ><span
                                class="truncate text-sm font-bold text-foreground"
                                >{{ name(conversation) }}</span
                            ><span
                                v-if="conversation.last_message_at"
                                class="text-[9px] text-muted-foreground"
                                >{{
                                    formatDate(conversation.last_message_at)
                                }}</span
                            ></span
                        >
                        <span
                            class="mt-0.5 block truncate text-[10px] text-muted-foreground"
                            >{{ conversation.title }}</span
                        >
                        <span
                            class="mt-1 block truncate text-xs text-muted-foreground"
                            >{{
                                conversation.messages?.at(-1)?.body ||
                                t('messagingWorkspace.noMessagePreview')
                            }}</span
                        >
                    </span>
                    <span
                        v-if="conversation.unread"
                        class="mt-7 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--erin-accent)] text-[9px] font-bold text-[var(--erin-accent-foreground)]"
                        >{{ conversation.unread }}</span
                    >
                </button>
            </div>
            <EmptyState
                v-else
                compact
                :icon="Inbox"
                :title="t('messagingWorkspace.noConversationsTitle')"
                :description="
                    t('messagingWorkspace.noConversationsDescription')
                "
            />
        </aside>

        <section v-if="active" class="flex min-w-0 flex-col bg-muted/60">
            <header
                class="flex h-[73px] items-center gap-3 border-b border-border bg-card px-5"
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-xs font-extrabold text-blue-800"
                    >{{ name(active).slice(0, 2).toUpperCase() }}</span
                >
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-bold text-foreground">
                        {{ name(active) }}
                    </h2>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ active.title }}
                    </p>
                </div>
            </header>
            <div class="flex-1 space-y-5 overflow-y-auto p-4 sm:p-6">
                <div
                    v-if="!active.messages?.length"
                    class="grid h-full place-items-center text-center"
                >
                    <EmptyState
                        compact
                        :icon="Inbox"
                        :title="t('messagingWorkspace.firstMessageTitle')"
                        :description="
                            t('messagingWorkspace.firstMessageDescription')
                        "
                    />
                </div>
                <div
                    v-for="message in active.messages ?? []"
                    :key="message.id"
                    :data-test="`message-${message.id}`"
                    class="max-w-[82%]"
                    :class="
                        message.sender_id === currentUserId ? 'ml-auto' : ''
                    "
                >
                    <div
                        class="rounded-2xl px-4 py-3 text-sm leading-6 shadow-sm"
                        :class="
                            message.sender_id === currentUserId
                                ? 'rounded-br-md bg-[var(--erin-primary)] text-[var(--erin-primary-foreground)]'
                                : 'rounded-bl-md bg-card text-muted-foreground ring-1 ring-border'
                        "
                    >
                        <p v-if="message.body">{{ message.body }}</p>
                        <div
                            v-if="message.translations?.[locale]?.body"
                            class="mt-2 border-t border-current/15 pt-2 text-xs"
                        >
                            <span class="mb-1 block font-bold opacity-70">{{
                                t('messagingWorkspace.translationLabel')
                            }}</span>
                            {{ message.translations[locale]?.body }}
                        </div>
                        <div
                            v-if="message.attachments?.length"
                            class="mt-2 space-y-2"
                        >
                            <a
                                v-for="attachment in message.attachments"
                                :key="attachment.id"
                                :href="attachment.download_url ?? undefined"
                                class="flex items-center gap-2 rounded-lg bg-black/10 p-2"
                                :class="{
                                    'pointer-events-none opacity-60':
                                        !attachment.download_url,
                                }"
                            >
                                <FileText class="size-4" /><span
                                    class="truncate text-xs font-bold"
                                    >{{ attachment.original_name }}</span
                                ><span
                                    v-if="!attachment.download_url"
                                    class="ml-auto text-[9px]"
                                    >{{
                                        t(
                                            'messagingWorkspace.attachmentPending',
                                        )
                                    }}</span
                                >
                            </a>
                            <audio
                                v-if="
                                    message.type === 'voice' &&
                                    message.attachments[0]?.download_url
                                "
                                class="mt-2 w-full"
                                controls
                                preload="metadata"
                                :src="
                                    message.attachments[0].download_url ??
                                    undefined
                                "
                                :aria-label="t('messagingWorkspace.playVoice')"
                            />
                            <div
                                v-if="
                                    message.type === 'voice' &&
                                    message.attachments[0]?.waveform?.length
                                "
                                class="flex h-5 items-center gap-0.5"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="(sample, index) in message
                                        .attachments[0].waveform"
                                    :key="index"
                                    class="w-0.5 rounded bg-current opacity-50"
                                    :style="{ height: `${sample * 100}%` }"
                                />
                            </div>
                        </div>
                    </div>
                    <button
                        v-if="
                            message.body &&
                            message.id > 0 &&
                            !message.translations?.[locale]?.body
                        "
                        type="button"
                        class="erin-focus mt-1 inline-flex items-center gap-1 text-[10px] font-bold text-violet-600 disabled:opacity-50"
                        :disabled="translating === message.id"
                        @click="translateMessage(message)"
                    >
                        <Languages class="size-3" />
                        {{
                            translating === message.id
                                ? t('messagingWorkspace.translating')
                                : t('messagingWorkspace.translateConsent')
                        }}
                    </button>
                    <p
                        v-if="message.created_at"
                        class="mt-1 text-[9px] text-muted-foreground"
                        :class="
                            message.sender_id === currentUserId
                                ? 'text-right'
                                : ''
                        "
                    >
                        {{ formatTime(message.created_at) }}
                        <span v-if="message.delivery_status === 'sending'">
                            · {{ t('messagingWorkspace.sending') }}
                        </span>
                        <button
                            v-if="message.delivery_status === 'failed'"
                            type="button"
                            class="ml-2 inline-flex items-center gap-1 font-bold text-red-600"
                            @click="retry(message)"
                        >
                            <RotateCcw class="size-3" />
                            {{ t('messagingWorkspace.retry') }}
                        </button>
                    </p>
                </div>
            </div>
            <footer class="border-t border-border bg-card p-4">
                <div class="mb-2 flex items-center gap-2 text-[10px]">
                    <Languages class="size-3.5 text-violet-500" /><span
                        class="text-muted-foreground"
                        >{{ t('messagingWorkspace.translations') }}</span
                    >
                </div>
                <div
                    v-if="voicePreview"
                    class="mb-2 flex items-center gap-2 rounded-xl bg-muted p-2"
                >
                    <audio
                        class="h-9 min-w-0 flex-1"
                        controls
                        :src="voicePreview"
                        :aria-label="t('messagingWorkspace.voicePreview')"
                    />
                    <button
                        type="button"
                        class="erin-focus grid size-9 place-items-center rounded-lg text-red-600 hover:bg-red-50"
                        :aria-label="t('messagingWorkspace.discardVoice')"
                        @click="discardVoice"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
                <form
                    class="flex items-end gap-2 rounded-2xl border border-border bg-muted p-2"
                    @submit.prevent="submit"
                >
                    <FileAttachmentPicker
                        v-if="messageForm.type !== 'voice'"
                        id="message-attachments"
                        v-model="messageForm.attachments"
                        compact
                        :label="t('messagingWorkspace.attachFiles')"
                        :remove-label="t('messagingWorkspace.removeAttachment')"
                        :disabled="messageForm.processing"
                    />
                    <button
                        v-if="messageForm.type !== 'voice'"
                        type="button"
                        data-test="voice-record-toggle"
                        class="erin-focus grid size-9 shrink-0 place-items-center rounded-xl text-muted-foreground hover:bg-card"
                        :class="recording ? 'bg-red-50 text-red-600' : ''"
                        :aria-label="
                            recording
                                ? t('messagingWorkspace.stopRecording')
                                : t('messagingWorkspace.startRecording')
                        "
                        @click="recording ? stopVoice() : startVoice()"
                    >
                        <Square v-if="recording" class="size-4" />
                        <Mic v-else class="size-4" />
                    </button>
                    <textarea
                        v-if="messageForm.type !== 'voice'"
                        v-model="messageForm.body"
                        data-test="message-compose"
                        rows="1"
                        :placeholder="
                            t('messagingWorkspace.messagePlaceholder')
                        "
                        class="max-h-28 min-h-9 flex-1 resize-none bg-transparent py-2 text-sm outline-none placeholder:text-muted-foreground"
                    />
                    <button
                        type="submit"
                        data-test="message-send"
                        :disabled="messageForm.processing"
                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-[var(--erin-primary)] text-[var(--erin-primary-foreground)] disabled:opacity-50"
                        :aria-label="t('messagingWorkspace.send')"
                    >
                        <Send class="size-4" />
                    </button>
                </form>
            </footer>
        </section>
        <section
            v-else
            class="grid place-items-center bg-muted p-8 text-center"
        >
            <EmptyState
                :icon="Inbox"
                :title="t('messagingWorkspace.noSelectionTitle')"
                :description="t('messagingWorkspace.noSelectionDescription')"
            />
        </section>
    </div>
</template>
