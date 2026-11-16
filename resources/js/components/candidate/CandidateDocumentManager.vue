<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Download,
    FilePenLine,
    FileText,
    RefreshCw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import FileAttachmentPicker from '@/components/product/FileAttachmentPicker.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useStatusLabels } from '@/composables/useStatusLabels';
import type { StatusTone } from '@/types';

export type CandidateDocumentItem = {
    id: number;
    type: string;
    title: string;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    status: string;
    scan_result?: string | null;
    rejection_reason?: string | null;
    expires_at?: string | null;
    created_at?: string | null;
    version?: number;
    replaced_at?: string | null;
    active_grants_count?: number;
    active_grants?: Array<{
        application_id: number;
        company_name: string;
        expires_at: string;
    }>;
    download_url?: string | null;
};

const props = defineProps<{
    documents: CandidateDocumentItem[];
    documentTypes: string[];
}>();
const { t, te } = useI18n();
const { statusLabel } = useStatusLabels();
const editing = ref<number | null>(null);
const replacing = ref<number | null>(null);
const uploadFiles = ref<File[]>([]);
const replacementFiles = ref<File[]>([]);
const uploadForm = useForm({
    type: props.documentTypes[0] ?? '',
    title: '',
    expires_at: '',
    file: null as File | null,
});
const editForm = useForm({ type: '', title: '', expires_at: '' });
const replaceForm = useForm({
    type: '',
    title: '',
    expires_at: '',
    file: null as File | null,
});
const fieldClass =
    'erin-focus mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3.5 text-sm';

const typeLabel = (type: string) => {
    const key = `candidate.profile.documents.types.${type}`;

    return te(key) ? t(key) : type.replaceAll('_', ' ');
};
const tone = (status: string): StatusTone =>
    status === 'verified'
        ? 'green'
        : status === 'rejected'
          ? 'red'
          : status === 'in_review'
            ? 'blue'
            : 'yellow';
const dateValue = (value?: string | null) => value?.slice(0, 10) ?? '';
const sizeLabel = (bytes?: number | null) =>
    bytes ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : '—';

function submitUpload(): void {
    uploadForm.file = uploadFiles.value[0] ?? null;
    uploadForm.post('/candidate/profile/documents', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset();
            uploadFiles.value = [];
        },
    });
}
function startEdit(document: CandidateDocumentItem): void {
    editing.value = document.id;
    replacing.value = null;
    editForm.type = document.type;
    editForm.title = document.title;
    editForm.expires_at = dateValue(document.expires_at);
}
function saveEdit(document: CandidateDocumentItem): void {
    editForm.patch(`/candidate/profile/documents/${document.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}
function startReplace(document: CandidateDocumentItem): void {
    replacing.value = document.id;
    editing.value = null;
    replacementFiles.value = [];
    replaceForm.type = document.type;
    replaceForm.title = document.title;
    replaceForm.expires_at = dateValue(document.expires_at);
}
function replace(document: CandidateDocumentItem): void {
    replaceForm.file = replacementFiles.value[0] ?? null;
    replaceForm.post(`/candidate/profile/documents/${document.id}/replace`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            replacing.value = null;
            replacementFiles.value = [];
        },
    });
}
function destroy(document: CandidateDocumentItem): void {
    if (
        window.confirm(
            t('candidate.profile.documents.deleteConfirm', {
                title: document.title,
            }),
        )
    ) {
        router.delete(`/candidate/profile/documents/${document.id}`, {
            preserveScroll: true,
        });
    }
}
function revoke(document: CandidateDocumentItem, applicationId: number): void {
    if (window.confirm(t('candidate.profile.documents.revokeConfirm'))) {
        router.delete(
            `/documents/${document.id}/applications/${applicationId}/grant`,
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <div class="space-y-6">
        <SectionCard
            :title="t('candidate.profile.documents.title')"
            :description="t('candidate.profile.documents.description')"
        >
            <div v-if="documents.length" class="space-y-3">
                <article
                    v-for="document in documents"
                    :key="document.id"
                    class="rounded-xl border border-slate-200 p-4"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            class="grid size-10 place-items-center rounded-xl bg-blue-50 text-[var(--erin-primary)]"
                            ><FileText class="size-4"
                        /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold">
                                {{ document.title }}
                            </p>
                            <p class="truncate text-xs text-slate-400">
                                {{ document.original_name }} ·
                                {{ sizeLabel(document.size_bytes) }} ·
                                {{
                                    t('candidate.profile.documents.version', {
                                        version: document.version ?? 1,
                                    })
                                }}
                            </p>
                            <p
                                v-if="document.expires_at"
                                class="mt-1 text-xs text-slate-500"
                            >
                                {{
                                    t('candidate.profile.documents.expiresAt', {
                                        date: dateValue(document.expires_at),
                                    })
                                }}
                            </p>
                            <p
                                v-if="document.active_grants_count"
                                class="mt-1 text-xs font-semibold text-teal-700"
                            >
                                {{
                                    t(
                                        'candidate.profile.documents.activeGrants',
                                        { count: document.active_grants_count },
                                    )
                                }}
                            </p>
                            <p
                                v-if="document.rejection_reason"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ document.rejection_reason }}
                            </p>
                        </div>
                        <StatusBadge
                            :label="statusLabel('document', document.status)"
                            :tone="tone(document.status)"
                        />
                        <a
                            v-if="document.download_url"
                            :href="document.download_url"
                            class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100"
                            :aria-label="
                                t('candidate.profile.documents.download', {
                                    title: document.title,
                                })
                            "
                            ><Download class="size-4"
                        /></a>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="startEdit(document)"
                            ><FilePenLine class="size-4" />
                            {{ t('candidate.profile.documents.edit') }}</Button
                        >
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="startReplace(document)"
                            ><RefreshCw class="size-4" />
                            {{
                                t('candidate.profile.documents.replace')
                            }}</Button
                        >
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            class="text-red-600"
                            @click="destroy(document)"
                            ><Trash2 class="size-4"
                        /></Button>
                    </div>
                    <div
                        v-if="document.active_grants?.length"
                        class="mt-4 space-y-2 border-t border-slate-100 pt-4"
                    >
                        <div
                            v-for="grant in document.active_grants"
                            :key="grant.application_id"
                            class="flex items-center gap-3 rounded-lg bg-teal-50 px-3 py-2 text-xs text-teal-900"
                        >
                            <span class="min-w-0 flex-1">
                                <strong>{{ grant.company_name }}</strong> ·
                                {{
                                    t(
                                        'candidate.profile.documents.grantExpires',
                                        { date: dateValue(grant.expires_at) },
                                    )
                                }}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="revoke(document, grant.application_id)"
                            >
                                {{ t('candidate.profile.documents.revoke') }}
                            </Button>
                        </div>
                    </div>
                    <form
                        v-if="editing === document.id"
                        class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3"
                        @submit.prevent="saveEdit(document)"
                    >
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.type')
                            }}<select
                                v-model="editForm.type"
                                :class="fieldClass"
                            >
                                <option
                                    v-for="type in documentTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ typeLabel(type) }}
                                </option>
                            </select></label
                        >
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.documentTitle')
                            }}<input
                                v-model="editForm.title"
                                required
                                :class="fieldClass"
                        /></label>
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.expiryDate')
                            }}<input
                                v-model="editForm.expires_at"
                                type="date"
                                :class="fieldClass"
                        /></label>
                        <div class="flex gap-2 sm:col-span-3">
                            <Button
                                type="submit"
                                :disabled="editForm.processing"
                                >{{
                                    t('candidate.profile.documents.save')
                                }}</Button
                            ><Button
                                type="button"
                                variant="ghost"
                                @click="editing = null"
                                >{{
                                    t('candidate.profile.documents.cancel')
                                }}</Button
                            >
                        </div>
                    </form>
                    <form
                        v-if="replacing === document.id"
                        class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3"
                        @submit.prevent="replace(document)"
                    >
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.type')
                            }}<select
                                v-model="replaceForm.type"
                                :class="fieldClass"
                            >
                                <option
                                    v-for="type in documentTypes"
                                    :key="type"
                                    :value="type"
                                >
                                    {{ typeLabel(type) }}
                                </option>
                            </select></label
                        >
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.documentTitle')
                            }}<input
                                v-model="replaceForm.title"
                                required
                                :class="fieldClass"
                        /></label>
                        <label class="text-xs font-bold text-slate-600"
                            >{{ t('candidate.profile.documents.expiryDate')
                            }}<input
                                v-model="replaceForm.expires_at"
                                type="date"
                                :class="fieldClass"
                        /></label>
                        <div class="sm:col-span-3">
                            <FileAttachmentPicker
                                id="replacement-document"
                                v-model="replacementFiles"
                                :multiple="false"
                                :max-files="1"
                                accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                                :label="
                                    t('candidate.profile.documents.chooseFile')
                                "
                                :remove-label="
                                    t('candidate.profile.documents.removeFile')
                                "
                            />
                        </div>
                        <div class="flex gap-2 sm:col-span-3">
                            <Button
                                type="submit"
                                :disabled="
                                    replaceForm.processing ||
                                    !replacementFiles.length
                                "
                                >{{
                                    t(
                                        'candidate.profile.documents.replaceSubmit',
                                    )
                                }}</Button
                            ><Button
                                type="button"
                                variant="ghost"
                                @click="replacing = null"
                                >{{
                                    t('candidate.profile.documents.cancel')
                                }}</Button
                            >
                        </div>
                    </form>
                </article>
            </div>
            <p v-else class="py-6 text-center text-sm text-slate-400">
                {{ t('candidate.profile.documents.empty') }}
            </p>
        </SectionCard>
        <SectionCard :title="t('candidate.profile.documents.uploadTitle')">
            <form
                class="grid gap-4 sm:grid-cols-2"
                @submit.prevent="submitUpload"
            >
                <label class="text-xs font-bold text-slate-600"
                    >{{ t('candidate.profile.documents.type')
                    }}<select
                        v-model="uploadForm.type"
                        required
                        :class="fieldClass"
                    >
                        <option
                            v-for="type in documentTypes"
                            :key="type"
                            :value="type"
                        >
                            {{ typeLabel(type) }}
                        </option>
                    </select></label
                >
                <label class="text-xs font-bold text-slate-600"
                    >{{ t('candidate.profile.documents.documentTitle')
                    }}<input
                        v-model="uploadForm.title"
                        required
                        :class="fieldClass"
                /></label>
                <label class="text-xs font-bold text-slate-600"
                    >{{ t('candidate.profile.documents.expiryDate')
                    }}<input
                        v-model="uploadForm.expires_at"
                        type="date"
                        :class="fieldClass"
                /></label>
                <div class="self-end">
                    <FileAttachmentPicker
                        id="candidate-document"
                        v-model="uploadFiles"
                        :multiple="false"
                        :max-files="1"
                        accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                        :label="t('candidate.profile.documents.chooseFile')"
                        :remove-label="
                            t('candidate.profile.documents.removeFile')
                        "
                    />
                </div>
                <Button
                    type="submit"
                    :disabled="uploadForm.processing || !uploadFiles.length"
                    class="sm:col-span-2"
                    ><Upload class="size-4" />{{
                        t('candidate.profile.documents.upload')
                    }}</Button
                >
            </form>
        </SectionCard>
    </div>
</template>
