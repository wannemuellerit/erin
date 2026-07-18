<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import { FileText, Inbox, Languages, Send } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import SearchField from '@/components/product/SearchField.vue';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';
import { index, read, send } from '@/routes/messages';
import type { Conversation, MessagingWorkspaceProps } from '@/types';

const props = withDefaults(defineProps<MessagingWorkspaceProps>(), {
    perspective: 'employer',
    conversations: () => [],
    selected: null,
});

const { locale, t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});

const page = usePage();
const currentUserId = computed(() => page.props.auth?.user?.id);
const query = ref('');
const activeId = ref<number | null>(
    props.selected ?? props.conversations[0]?.id ?? null,
);
const active = computed(
    () =>
        props.conversations.find(
            (conversation) => conversation.id === activeId.value,
        ) ?? null,
);
const filtered = computed(() =>
    props.conversations.filter((conversation) => {
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
    new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'short',
    }).format(new Date(value));
const formatTime = (value: string) =>
    new Intl.DateTimeFormat(locale.value, {
        timeStyle: 'short',
    }).format(new Date(value));
const messageForm = useForm({
    body: '',
    type: 'text',
    attachments: [] as File[],
});
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

    messageForm.post(send.url(active.value.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => messageForm.reset(),
    });
};
</script>

<template>
    <div
        class="erin-panel grid min-h-[680px] overflow-hidden lg:grid-cols-[21rem_minmax(0,1fr)]"
    >
        <aside class="border-r border-slate-200 bg-white">
            <div class="border-b border-slate-100 p-4">
                <SearchField
                    v-model="query"
                    size="sm"
                    :label="t('messagingWorkspace.searchLabel')"
                    :placeholder="t('messagingWorkspace.searchPlaceholder')"
                    class="bg-slate-50 text-xs"
                />
            </div>
            <div v-if="filtered.length" class="divide-y divide-slate-100">
                <button
                    v-for="conversation in filtered"
                    :key="conversation.id"
                    type="button"
                    class="flex w-full gap-3 p-4 text-left"
                    :class="
                        activeId === conversation.id
                            ? 'bg-blue-50/70'
                            : 'hover:bg-slate-50'
                    "
                    @click="selectConversation(conversation)"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-xs font-extrabold text-[var(--erin-primary)]"
                        >{{
                            name(conversation).slice(0, 2).toUpperCase()
                        }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center justify-between gap-2"
                            ><span
                                class="truncate text-sm font-bold text-slate-800"
                                >{{ name(conversation) }}</span
                            ><span
                                v-if="conversation.last_message_at"
                                class="text-[9px] text-slate-400"
                                >{{
                                    formatDate(conversation.last_message_at)
                                }}</span
                            ></span
                        >
                        <span
                            class="mt-0.5 block truncate text-[10px] text-slate-400"
                            >{{ conversation.title }}</span
                        >
                        <span
                            class="mt-1 block truncate text-xs text-slate-500"
                            >{{
                                conversation.messages?.at(-1)?.body ||
                                t('messagingWorkspace.noMessagePreview')
                            }}</span
                        >
                    </span>
                    <span
                        v-if="conversation.unread"
                        class="mt-7 grid size-5 shrink-0 place-items-center rounded-full bg-[var(--erin-accent)] text-[9px] font-bold text-white"
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

        <section v-if="active" class="flex min-w-0 flex-col bg-slate-50/60">
            <header
                class="flex h-[73px] items-center gap-3 border-b border-slate-200 bg-white px-5"
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-blue-100 text-xs font-extrabold text-[var(--erin-primary)]"
                    >{{ name(active).slice(0, 2).toUpperCase() }}</span
                >
                <div class="min-w-0 flex-1">
                    <h2 class="truncate text-sm font-bold text-slate-900">
                        {{ name(active) }}
                    </h2>
                    <p class="truncate text-xs text-slate-400">
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
                    class="max-w-[82%]"
                    :class="
                        message.sender_id === currentUserId ? 'ml-auto' : ''
                    "
                >
                    <div
                        class="rounded-2xl px-4 py-3 text-sm leading-6 shadow-sm"
                        :class="
                            message.sender_id === currentUserId
                                ? 'rounded-br-md bg-[var(--erin-primary)] text-white'
                                : 'rounded-bl-md bg-white text-slate-700 ring-1 ring-slate-100'
                        "
                    >
                        <p v-if="message.body">{{ message.body }}</p>
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
                        </div>
                    </div>
                    <p
                        v-if="message.created_at"
                        class="mt-1 text-[9px] text-slate-400"
                        :class="
                            message.sender_id === currentUserId
                                ? 'text-right'
                                : ''
                        "
                    >
                        {{ formatTime(message.created_at) }}
                    </p>
                </div>
            </div>
            <footer class="border-t border-slate-200 bg-white p-4">
                <div class="mb-2 flex items-center gap-2 text-[10px]">
                    <Languages class="size-3.5 text-violet-500" /><span
                        class="text-slate-400"
                        >{{ t('messagingWorkspace.translations') }}</span
                    >
                </div>
                <form
                    class="flex items-end gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-2"
                    @submit.prevent="submit"
                >
                    <FileAttachmentPicker
                        id="message-attachments"
                        v-model="messageForm.attachments"
                        compact
                        :label="t('messagingWorkspace.attachFiles')"
                        :remove-label="t('messagingWorkspace.removeAttachment')"
                        :disabled="messageForm.processing"
                    />
                    <textarea
                        v-model="messageForm.body"
                        rows="1"
                        :placeholder="
                            t('messagingWorkspace.messagePlaceholder')
                        "
                        class="max-h-28 min-h-9 flex-1 resize-none bg-transparent py-2 text-sm outline-none placeholder:text-slate-400"
                    />
                    <button
                        type="submit"
                        :disabled="messageForm.processing"
                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-[var(--erin-primary)] text-white disabled:opacity-50"
                        :aria-label="t('messagingWorkspace.send')"
                    >
                        <Send class="size-4" />
                    </button>
                </form>
            </footer>
        </section>
        <section
            v-else
            class="grid place-items-center bg-slate-50 p-8 text-center"
        >
            <EmptyState
                :icon="Inbox"
                :title="t('messagingWorkspace.noSelectionTitle')"
                :description="t('messagingWorkspace.noSelectionDescription')"
            />
        </section>
    </div>
</template>
