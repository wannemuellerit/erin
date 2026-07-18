<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useEcho } from '@laravel/echo-vue';
import {
    Bot,
    CheckCircle2,
    Clock3,
    FileText,
    Send,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FormField from '@/components/product/FormField.vue';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import Textarea from '@/components/product/Textarea.vue';
import type { StatusTone, SupportTicket, SupportTicketMessage } from '@/types';

const props = withDefaults(
    defineProps<{
        ticket: SupportTicket;
        replyUrl: string;
        currentUserId: number;
        allowInternal?: boolean;
        readOnly?: boolean;
        messageField?: 'message' | 'body';
    }>(),
    {
        allowInternal: false,
        readOnly: false,
        messageField: 'message',
    },
);

const { locale, t } = useI18n();
const messages = ref<SupportTicketMessage[]>([...props.ticket.messages]);
const form = useForm({
    message: '',
    body: '',
    is_internal: false,
    attachments: [] as File[],
});
const draft = computed({
    get: () => (props.messageField === 'body' ? form.body : form.message),
    set: (value: string) => {
        if (props.messageField === 'body') {
            form.body = value;
        } else {
            form.message = value;
        }
    },
});
const draftError = computed(() =>
    props.messageField === 'body' ? form.errors.body : form.errors.message,
);

useEcho<{ message: SupportTicketMessage }>(
    `support-ticket.${props.ticket.id}`,
    '.support.message.created',
    ({ message }) => {
        const existingIndex = messages.value.findIndex(
            (item) => item.id === message.id,
        );

        if (existingIndex === -1) {
            messages.value.push(message);
        } else {
            messages.value.splice(existingIndex, 1, message);
        }
    },
);

const statusTone = computed<StatusTone>(() => {
    if (['resolved', 'closed'].includes(props.ticket.status)) {
        return 'green';
    }

    return props.ticket.status === 'waiting_for_customer' ? 'orange' : 'blue';
});

const formatDate = (value: string) =>
    new Intl.DateTimeFormat(locale.value, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));

const send = () => {
    form.post(props.replyUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('message', 'body', 'is_internal', 'attachments');
        },
    });
};
</script>

<template>
    <div class="flex min-h-[32rem] flex-col">
        <header
            class="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <p class="text-xs font-bold text-slate-600">
                    {{ ticket.number }}
                </p>
                <h2 class="mt-1 font-bold text-slate-950">
                    {{ ticket.subject }}
                </h2>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge
                    :label="t(`operations.support.statuses.${ticket.status}`)"
                    :tone="statusTone"
                />
                <StatusBadge
                    :label="
                        ticket.external_id
                            ? t('operations.support.synced')
                            : t('operations.support.local')
                    "
                    :tone="ticket.external_id ? 'teal' : 'slate'"
                    :dot="false"
                />
            </div>
        </header>

        <div
            class="flex-1 space-y-4 overflow-y-auto bg-slate-50/70 p-4 sm:p-6"
            aria-live="polite"
        >
            <article
                v-for="message in messages"
                :key="message.id"
                class="flex gap-3"
                :class="
                    message.author_id === currentUserId
                        ? 'flex-row-reverse'
                        : ''
                "
            >
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-xl"
                    :class="
                        message.author_id === currentUserId
                            ? 'bg-blue-600 text-white'
                            : 'bg-teal-100 text-teal-700'
                    "
                >
                    <UserRound
                        v-if="message.author_id === currentUserId"
                        class="size-4"
                    />
                    <Bot v-else class="size-4" />
                </span>
                <div
                    class="max-w-[min(42rem,82%)] rounded-2xl px-4 py-3 shadow-sm"
                    :class="
                        message.author_id === currentUserId
                            ? 'rounded-tr-sm bg-blue-600 text-white'
                            : 'rounded-tl-sm border border-slate-200 bg-white text-slate-700'
                    "
                >
                    <p class="text-sm leading-6 whitespace-pre-wrap">
                        {{ message.body }}
                    </p>
                    <div
                        v-if="message.attachments?.length"
                        class="mt-3 space-y-2"
                    >
                        <a
                            v-for="attachment in message.attachments"
                            :key="attachment.id"
                            :href="attachment.download_url ?? undefined"
                            class="flex items-center gap-2 rounded-lg bg-black/10 px-2.5 py-2 text-xs font-bold"
                            :class="{
                                'pointer-events-none opacity-60':
                                    !attachment.download_url,
                            }"
                            :aria-disabled="!attachment.download_url"
                        >
                            <FileText class="size-4 shrink-0" />
                            <span class="truncate">
                                {{ attachment.original_name }}
                            </span>
                            <span
                                v-if="!attachment.download_url"
                                class="ml-auto text-[10px] font-semibold"
                            >
                                {{
                                    t(
                                        `operations.support.attachmentStatus.${attachment.scan_result}`,
                                    )
                                }}
                            </span>
                        </a>
                    </div>
                    <div
                        class="mt-2 flex flex-wrap items-center gap-2 text-[10px]"
                        :class="
                            message.author_id === currentUserId
                                ? 'text-blue-100'
                                : 'text-slate-600'
                        "
                    >
                        <span>
                            {{
                                message.author_id === currentUserId
                                    ? t('operations.support.you')
                                    : (message.author?.name ??
                                      t('operations.support.supportTeam'))
                            }}
                        </span>
                        <span>·</span>
                        <span>{{ formatDate(message.created_at) }}</span>
                        <span
                            v-if="message.author_id === currentUserId"
                            class="inline-flex items-center gap-1"
                        >
                            <CheckCircle2
                                v-if="message.delivery_status === 'delivered'"
                                class="size-3"
                            />
                            <Clock3 v-else class="size-3" />
                            {{
                                t(
                                    `operations.support.delivery.${message.delivery_status ?? 'pending'}`,
                                )
                            }}
                        </span>
                    </div>
                </div>
            </article>
        </div>

        <form
            v-if="!readOnly"
            class="border-t border-slate-100 bg-white p-4"
            @submit.prevent="send"
        >
            <FormField
                id="support-reply"
                :label="t('operations.support.message')"
                :error="draftError"
            >
                <Textarea
                    id="support-reply"
                    v-model="draft"
                    rows="3"
                    :placeholder="t('operations.support.replyPlaceholder')"
                />
            </FormField>
            <div class="mt-3">
                <FileAttachmentPicker
                    :id="`support-attachments-${ticket.id}`"
                    v-model="form.attachments"
                    :label="t('operations.support.attachments')"
                    :remove-label="t('operations.support.removeAttachment')"
                    :disabled="form.processing"
                />
                <p
                    v-if="form.errors.attachments"
                    class="mt-1 text-xs text-red-600"
                >
                    {{ form.errors.attachments }}
                </p>
            </div>
            <div
                class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <label
                    v-if="allowInternal"
                    class="inline-flex items-center gap-2 text-xs font-semibold text-slate-600"
                >
                    <input
                        v-model="form.is_internal"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-blue-600"
                    />
                    {{ t('operations.support.internal') }}
                </label>
                <span v-else />
                <button
                    type="submit"
                    :disabled="
                        form.processing ||
                        (!draft.trim() && !form.attachments.length)
                    "
                    class="erin-focus inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Send class="size-4" />
                    {{
                        form.processing
                            ? t('operations.support.sending')
                            : t('operations.support.send')
                    }}
                </button>
            </div>
        </form>
    </div>
</template>
