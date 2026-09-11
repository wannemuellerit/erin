<script setup lang="ts">
import {
    ExternalE2EEKeyProvider,
    Room,
    RoomEvent,
    Track,
} from 'livekit-client';
import type { RemoteTrack } from 'livekit-client';
import {
    MessageCircle,
    Mic,
    MicOff,
    MonitorUp,
    PhoneOff,
    Send,
    Video,
    VideoOff,
} from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { productMessages } from '@/i18n/product-locales';
import { newUuid } from '@/lib/uuid';

const props = defineProps<{ interviewId: number; canJoin: boolean }>();
const { t } = useI18n({ useScope: 'local', messages: productMessages });

type RoomController = {
    connect: () => Promise<void>;
    disconnect: () => void;
    setCameraEnabled: (enabled: boolean) => Promise<void>;
    setMicrophoneEnabled: (enabled: boolean) => Promise<void>;
    setScreenShareEnabled: (enabled: boolean) => Promise<void>;
    switchActiveDevice: (
        kind: MediaDeviceKind,
        deviceId: string,
    ) => Promise<void>;
    getDevices: () => Promise<[MediaDeviceInfo[], MediaDeviceInfo[]]>;
    sendChatMessage: (message: string) => Promise<void>;
};

type ChatMessage = {
    id: string;
    body: string;
    senderIdentity: string;
    senderName: string;
    sentAt: string;
    mine: boolean;
};

declare global {
    interface Window {
        __ERIN_LIVEKIT_ROOM_MOCK__?: (options: {
            url: string;
            token: string;
            e2eeKey: string;
            onReconnecting: () => void;
            onReconnected: () => void;
            onDisconnected: () => void;
            onChatMessage: (
                message: string,
                senderIdentity: string,
                senderName: string,
                sentAt?: string,
                id?: string,
            ) => void;
        }) => RoomController;
    }
}

const activeRoom = ref<RoomController | null>(null);
const mediaContainer = ref<HTMLElement | null>(null);
const state = ref<
    'idle' | 'connecting' | 'connected' | 'reconnecting' | 'error'
>('idle');
const error = ref('');
const cameraEnabled = ref(true);
const microphoneEnabled = ref(true);
const sharingScreen = ref(false);
const cameras = ref<MediaDeviceInfo[]>([]);
const microphones = ref<MediaDeviceInfo[]>([]);
const chatMessages = ref<ChatMessage[]>([]);
const chatDraft = ref('');
const chatError = ref('');
const chatSending = ref(false);
const chatContainer = ref<HTMLElement | null>(null);
const participantIdentity = ref('');
const participantName = ref('');
const receivedChatIds = new Set<string>();
const chatTopic = 'erin.interview.chat.v1';
const maxChatLength = 2000;

type Access = {
    url: string;
    token: string;
    e2eeKey: string;
    participantIdentity: string;
    participantName: string;
};

const scrollChatToBottom = async () => {
    await nextTick();

    if (chatContainer.value) {
        chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    }
};

const addChatMessage = (
    body: string,
    senderIdentity: string,
    senderName: string,
    sentAt: string = new Date().toISOString(),
    id: string = newUuid(),
) => {
    const normalizedBody = body.trim();

    if (
        normalizedBody === '' ||
        normalizedBody.length > maxChatLength ||
        receivedChatIds.has(id)
    ) {
        return;
    }

    receivedChatIds.add(id);
    chatMessages.value.push({
        id,
        body: normalizedBody,
        senderIdentity,
        senderName: senderName || t('interviewCenter.chatParticipant'),
        sentAt,
        mine: senderIdentity === participantIdentity.value,
    });
    void scrollChatToBottom();
};

const attachTrack = (track: RemoteTrack) => {
    const element = track.attach();
    element.dataset.livekitTrack = track.sid ?? '';
    element.className =
        track.kind === Track.Kind.Video
            ? 'aspect-video w-full rounded-xl bg-slate-950 object-cover'
            : '';
    mediaContainer.value?.append(element);
};

const join = async () => {
    if (!props.canJoin || state.value === 'connecting') {
        return;
    }

    state.value = 'connecting';
    error.value = '';
    chatMessages.value = [];
    chatDraft.value = '';
    chatError.value = '';
    receivedChatIds.clear();

    try {
        const csrf =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';
        const response = await fetch(`/interviews/${props.interviewId}/token`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        });
        const access = (await response.json()) as Access & { message?: string };

        if (!response.ok || !access.e2eeKey) {
            throw new Error(
                access.message ?? t('interviewCenter.roomJoinFailed'),
            );
        }

        participantIdentity.value = access.participantIdentity;
        participantName.value = access.participantName;

        if (import.meta.env.DEV && window.__ERIN_LIVEKIT_ROOM_MOCK__) {
            const mockedRoom = window.__ERIN_LIVEKIT_ROOM_MOCK__({
                url: access.url,
                token: access.token,
                e2eeKey: access.e2eeKey,
                onReconnecting: () => (state.value = 'reconnecting'),
                onReconnected: () => (state.value = 'connected'),
                onDisconnected: () => (state.value = 'idle'),
                onChatMessage: addChatMessage,
            });
            await mockedRoom.connect();
            await mockedRoom.setCameraEnabled(true);
            await mockedRoom.setMicrophoneEnabled(true);
            activeRoom.value = mockedRoom;
            state.value = 'connected';
            [cameras.value, microphones.value] = await mockedRoom.getDevices();

            return;
        }

        const keyProvider = new ExternalE2EEKeyProvider();
        await keyProvider.setKey(access.e2eeKey);
        const nextRoom = new Room({
            adaptiveStream: true,
            dynacast: true,
            encryption: {
                keyProvider,
                worker: new Worker(
                    new URL('livekit-client/e2ee-worker', import.meta.url),
                ),
            },
        });
        nextRoom.registerTextStreamHandler(
            chatTopic,
            async (reader, sender) => {
                try {
                    const body = await reader.readAll();
                    const remoteParticipant = nextRoom.remoteParticipants.get(
                        sender.identity,
                    );
                    addChatMessage(
                        body,
                        sender.identity,
                        remoteParticipant?.name ?? '',
                        new Date(reader.info.timestamp).toISOString(),
                        reader.info.id,
                    );
                } catch {
                    chatError.value = t('interviewCenter.chatReceiveFailed');
                }
            },
        );
        nextRoom
            .on(RoomEvent.TrackSubscribed, attachTrack)
            .on(RoomEvent.Reconnecting, () => (state.value = 'reconnecting'))
            .on(RoomEvent.Reconnected, () => (state.value = 'connected'))
            .on(RoomEvent.Disconnected, () => (state.value = 'idle'));
        await nextRoom.connect(access.url, access.token);
        await nextRoom.setE2EEEnabled(true);
        await nextRoom.localParticipant.setCameraEnabled(true);
        await nextRoom.localParticipant.setMicrophoneEnabled(true);
        activeRoom.value = {
            connect: async () => {},
            disconnect: () => nextRoom.disconnect(),
            setCameraEnabled: async (enabled) => {
                await nextRoom.localParticipant.setCameraEnabled(enabled);
            },
            setMicrophoneEnabled: async (enabled) => {
                await nextRoom.localParticipant.setMicrophoneEnabled(enabled);
            },
            setScreenShareEnabled: async (enabled) => {
                await nextRoom.localParticipant.setScreenShareEnabled(enabled);
            },
            switchActiveDevice: async (kind, deviceId) => {
                await nextRoom.switchActiveDevice(kind, deviceId);
            },
            getDevices: () =>
                Promise.all([
                    Room.getLocalDevices('videoinput'),
                    Room.getLocalDevices('audioinput'),
                ]),
            sendChatMessage: async (message) => {
                await nextRoom.localParticipant.sendText(message, {
                    topic: chatTopic,
                    attributes: {
                        senderName: participantName.value,
                        sentAt: new Date().toISOString(),
                    },
                });
            },
        };
        state.value = 'connected';
        await nextTick();
        nextRoom.localParticipant.trackPublications.forEach((publication) => {
            const track = publication.track;

            if (track) {
                mediaContainer.value?.append(track.attach());
            }
        });
        [cameras.value, microphones.value] = await Promise.all([
            Room.getLocalDevices('videoinput'),
            Room.getLocalDevices('audioinput'),
        ]);
    } catch (exception) {
        state.value = 'error';
        error.value =
            exception instanceof Error
                ? exception.message
                : t('interviewCenter.roomJoinFailed');
        activeRoom.value?.disconnect();
        activeRoom.value = null;
    }
};

const toggleCamera = async () => {
    cameraEnabled.value = !cameraEnabled.value;
    await activeRoom.value?.setCameraEnabled(cameraEnabled.value);
};
const toggleMicrophone = async () => {
    microphoneEnabled.value = !microphoneEnabled.value;
    await activeRoom.value?.setMicrophoneEnabled(microphoneEnabled.value);
};
const toggleScreen = async () => {
    sharingScreen.value = !sharingScreen.value;
    await activeRoom.value?.setScreenShareEnabled(sharingScreen.value);
};
const sendChatMessage = async () => {
    const message = chatDraft.value.trim();

    if (state.value !== 'connected' || message === '' || chatSending.value) {
        return;
    }

    if (message.length > maxChatLength) {
        chatError.value = t('interviewCenter.chatTooLong', {
            count: maxChatLength,
        });

        return;
    }

    chatSending.value = true;
    chatError.value = '';

    try {
        await activeRoom.value?.sendChatMessage(message);
        addChatMessage(
            message,
            participantIdentity.value,
            participantName.value,
        );
        chatDraft.value = '';
    } catch {
        chatError.value = t('interviewCenter.chatSendFailed');
    } finally {
        chatSending.value = false;
    }
};
const leave = () => {
    activeRoom.value?.disconnect();
    activeRoom.value = null;
    state.value = 'idle';
    mediaContainer.value?.replaceChildren();
    chatMessages.value = [];
    chatDraft.value = '';
    chatError.value = '';
    receivedChatIds.clear();
};
const switchDevice = async (kind: MediaDeviceKind, event: Event) => {
    await activeRoom.value?.switchActiveDevice(
        kind,
        (event.target as HTMLSelectElement).value,
    );
};

onBeforeUnmount(leave);
</script>

<template>
    <div class="mt-4 rounded-2xl border border-violet-200 bg-violet-50/50 p-4">
        <div v-if="state === 'idle' || state === 'error'">
            <button
                type="button"
                :disabled="!canJoin"
                class="erin-focus inline-flex h-10 items-center gap-2 rounded-xl bg-violet-700 px-4 text-xs font-bold text-white disabled:cursor-not-allowed disabled:opacity-50"
                data-test="livekit-join"
                @click="join"
            >
                <Video class="size-4" />
                {{
                    canJoin
                        ? t('interviewCenter.joinRoom')
                        : t('interviewCenter.roomUnavailable')
                }}
            </button>
            <p
                v-if="error"
                class="mt-2 text-xs font-bold text-red-600"
                role="alert"
            >
                {{ error }}
            </p>
        </div>
        <p
            v-else-if="state === 'connecting'"
            class="text-xs font-bold text-violet-800"
        >
            {{ t('interviewCenter.roomConnecting') }}
        </p>
        <div v-else>
            <p
                class="mb-3 text-xs font-bold text-violet-900"
                aria-live="polite"
            >
                {{
                    state === 'reconnecting'
                        ? t('interviewCenter.roomReconnecting')
                        : t('interviewCenter.roomE2eeActive')
                }}
            </p>
            <div
                class="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]"
            >
                <div>
                    <div
                        ref="mediaContainer"
                        class="grid gap-3 sm:grid-cols-2"
                        data-test="livekit-media"
                    />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-xl bg-card"
                            :aria-label="t('interviewCenter.toggleCamera')"
                            @click="toggleCamera"
                        >
                            <Video
                                v-if="cameraEnabled"
                                class="size-4"
                            /><VideoOff v-else class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-xl bg-card"
                            :aria-label="t('interviewCenter.toggleMicrophone')"
                            @click="toggleMicrophone"
                        >
                            <Mic
                                v-if="microphoneEnabled"
                                class="size-4"
                            /><MicOff v-else class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-xl bg-card"
                            :aria-label="t('interviewCenter.toggleScreen')"
                            @click="toggleScreen"
                        >
                            <MonitorUp class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="grid size-10 place-items-center rounded-xl bg-red-600 text-white"
                            :aria-label="t('interviewCenter.leaveRoom')"
                            @click="leave"
                        >
                            <PhoneOff class="size-4" />
                        </button>
                        <select
                            class="h-10 rounded-xl border bg-card px-2 text-xs"
                            :aria-label="t('interviewCenter.cameraDevice')"
                            @change="switchDevice('videoinput', $event)"
                        >
                            <option
                                v-for="device in cameras"
                                :key="device.deviceId"
                                :value="device.deviceId"
                            >
                                {{ device.label }}
                            </option>
                        </select>
                        <select
                            class="h-10 rounded-xl border bg-card px-2 text-xs"
                            :aria-label="t('interviewCenter.microphoneDevice')"
                            @change="switchDevice('audioinput', $event)"
                        >
                            <option
                                v-for="device in microphones"
                                :key="device.deviceId"
                                :value="device.deviceId"
                            >
                                {{ device.label }}
                            </option>
                        </select>
                    </div>
                </div>
                <aside
                    class="flex min-h-80 flex-col overflow-hidden rounded-xl border border-violet-200 bg-card"
                    :aria-label="t('interviewCenter.roomChat')"
                    data-test="livekit-chat"
                >
                    <header class="border-b border-violet-100 px-4 py-3">
                        <h3
                            class="flex items-center gap-2 text-sm font-bold text-foreground"
                        >
                            <MessageCircle class="size-4 text-violet-700" />
                            {{ t('interviewCenter.roomChat') }}
                        </h3>
                        <p
                            class="mt-1 text-[11px] leading-4 text-muted-foreground"
                        >
                            {{ t('interviewCenter.chatEphemeral') }}
                        </p>
                    </header>
                    <div
                        ref="chatContainer"
                        class="min-h-0 flex-1 space-y-3 overflow-y-auto p-4"
                        role="log"
                        aria-live="polite"
                        aria-relevant="additions"
                    >
                        <p
                            v-if="chatMessages.length === 0"
                            class="grid min-h-32 place-items-center text-center text-xs text-muted-foreground"
                        >
                            {{ t('interviewCenter.roomChatEmpty') }}
                        </p>
                        <article
                            v-for="message in chatMessages"
                            :key="message.id"
                            class="flex"
                            :class="
                                message.mine ? 'justify-end' : 'justify-start'
                            "
                            data-test="livekit-chat-message"
                        >
                            <div
                                class="max-w-[88%] rounded-2xl px-3 py-2 text-xs"
                                :class="
                                    message.mine
                                        ? 'rounded-br-md bg-violet-700 text-white'
                                        : 'rounded-bl-md bg-muted text-foreground'
                                "
                            >
                                <p
                                    class="mb-1 text-[10px] font-bold opacity-75"
                                >
                                    {{
                                        message.mine
                                            ? t('interviewCenter.chatYou')
                                            : message.senderName
                                    }}
                                </p>
                                <p class="break-words whitespace-pre-wrap">
                                    {{ message.body }}
                                </p>
                                <time
                                    class="mt-1 block text-right text-[9px] opacity-65"
                                    :datetime="message.sentAt"
                                >
                                    {{
                                        new Date(
                                            message.sentAt,
                                        ).toLocaleTimeString([], {
                                            hour: '2-digit',
                                            minute: '2-digit',
                                        })
                                    }}
                                </time>
                            </div>
                        </article>
                    </div>
                    <form
                        class="border-t border-violet-100 p-3"
                        @submit.prevent="sendChatMessage"
                    >
                        <label
                            class="sr-only"
                            :for="`interview-chat-${interviewId}`"
                        >
                            {{ t('interviewCenter.chatPlaceholder') }}
                        </label>
                        <div class="flex items-end gap-2">
                            <textarea
                                :id="`interview-chat-${interviewId}`"
                                v-model="chatDraft"
                                :maxlength="maxChatLength"
                                rows="2"
                                class="erin-focus min-h-10 flex-1 resize-none rounded-xl border border-border px-3 py-2 text-xs"
                                :placeholder="
                                    t('interviewCenter.chatPlaceholder')
                                "
                                data-test="livekit-chat-input"
                                @keydown.enter.exact.prevent="sendChatMessage"
                            />
                            <button
                                type="submit"
                                class="erin-focus grid size-10 shrink-0 place-items-center rounded-xl bg-violet-700 text-white disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="
                                    state !== 'connected' ||
                                    chatDraft.trim() === '' ||
                                    chatSending
                                "
                                :aria-label="
                                    t('interviewCenter.sendChatMessage')
                                "
                            >
                                <Send class="size-4" />
                            </button>
                        </div>
                        <p
                            v-if="chatError"
                            class="mt-2 text-xs font-bold text-red-600"
                            role="alert"
                        >
                            {{ chatError }}
                        </p>
                    </form>
                </aside>
            </div>
        </div>
    </div>
</template>
