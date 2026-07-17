<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Check,
    FileSearch,
    FileText,
    Search,
    ShieldCheck,
    ShieldX,
    X,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import adminDocuments from '@/routes/admin/documents';
import AdminEmptyState from './_components/AdminEmptyState.vue';
import AdminPagination from './_components/AdminPagination.vue';
import {
    cleanFilters,
    formatBytes,
    formatDate,
    humanize,
    statusTone,
} from './_shared';
import type { AdminPaginator } from './_shared';

type DocumentRow = {
    id: number;
    candidate_profile_id: number;
    type: string;
    title: string | null;
    original_name: string;
    mime_type: string;
    size_bytes: number;
    status: string;
    rejection_reason: string | null;
    expires_at: string | null;
    verified_by: number | null;
    verified_at: string | null;
    scan_completed_at: string | null;
    scan_result: string | null;
    created_at: string;
    candidate_profile: {
        id: number;
        user_id: number;
        first_name: string | null;
        last_name: string | null;
        current_position: string | null;
        user: {
            id: number;
            name: string;
            email: string;
        };
    };
    verifier: {
        id: number;
        name: string;
    } | null;
};

type DocumentFilters = {
    search?: string;
    status?: string;
    type?: string;
    scan_result?: string;
};

const props = defineProps<{
    documents: AdminPaginator<DocumentRow>;
    filters: DocumentFilters;
    statuses: string[];
    types: string[];
}>();

const filters = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    type: props.filters.type ?? '',
    scan_result: props.filters.scan_result ?? '',
});

const selectedId = ref<number | null>(props.documents.data[0]?.id ?? null);
const selectedDocument = computed(
    () =>
        props.documents.data.find(
            (document) => document.id === selectedId.value,
        ) ??
        props.documents.data[0] ??
        null,
);

const reviewForm = useForm({
    status: 'in_review',
    rejection_reason: '',
});

watch(
    () => props.documents.data,
    (documents) => {
        if (!documents.some((document) => document.id === selectedId.value)) {
            selectedId.value = documents[0]?.id ?? null;
        }
    },
);

watch(selectedId, () => {
    reviewForm.clearErrors();
    reviewForm.rejection_reason = '';
});

function applyFilters(): void {
    router.get(adminDocuments.index.url(), cleanFilters(filters), {
        preserveState: true,
        replace: true,
    });
}

function resetFilters(): void {
    router.get(adminDocuments.index.url(), {}, { replace: true });
}

function submitReview(status: 'in_review' | 'verified' | 'rejected'): void {
    if (!selectedDocument.value) {
        return;
    }

    reviewForm.status = status;
    reviewForm.patch(adminDocuments.review.url(selectedDocument.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            reviewForm.reset();
            reviewForm.status = 'in_review';
        },
    });
}
</script>

<template>
    <Head title="Dokumentenprüfung" />

    <div class="erin-page">
        <PageHeader
            eyebrow="Trust & Verification"
            title="Dokumentenprüfung"
            :description="`${documents.total} Dokumente in der realen Prüfwarteschlange.`"
            :icon="FileSearch"
        />

        <SectionCard flush>
            <form
                class="grid gap-3 border-b border-slate-100 p-4 lg:grid-cols-[minmax(16rem,1fr)_12rem_13rem_12rem_auto]"
                @submit.prevent="applyFilters"
            >
                <label class="relative">
                    <span class="sr-only">Dokumente suchen</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="filters.search"
                        type="search"
                        placeholder="Datei, Titel oder Fachkraft …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 pr-3 pl-10 text-sm"
                    />
                </label>
                <select
                    v-model="filters.status"
                    aria-label="Dokumentstatus"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Status</option>
                    <option
                        v-for="status in statuses"
                        :key="status"
                        :value="status"
                    >
                        {{ humanize(status) }}
                    </option>
                </select>
                <select
                    v-model="filters.type"
                    aria-label="Dokumenttyp"
                    class="erin-focus h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm"
                >
                    <option value="">Alle Dokumenttypen</option>
                    <option v-for="type in types" :key="type" :value="type">
                        {{ humanize(type) }}
                    </option>
                </select>
                <input
                    v-model="filters.scan_result"
                    type="text"
                    placeholder="Scan-Ergebnis"
                    aria-label="Scan-Ergebnis"
                    class="erin-focus h-11 rounded-xl border border-slate-200 px-3 text-sm"
                />
                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="erin-focus h-11 rounded-xl bg-blue-600 px-4 text-sm font-bold text-white"
                    >
                        Filtern
                    </button>
                    <button
                        type="button"
                        aria-label="Filter zurücksetzen"
                        class="erin-focus grid size-11 place-items-center rounded-xl border border-slate-200 text-slate-500"
                        @click="resetFilters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </form>

            <div
                v-if="documents.data.length > 0"
                class="grid min-h-[34rem] xl:grid-cols-[22rem_minmax(0,1fr)]"
            >
                <aside
                    class="border-b border-slate-200 xl:border-r xl:border-b-0"
                >
                    <div class="divide-y divide-slate-100">
                        <button
                            v-for="document in documents.data"
                            :key="document.id"
                            type="button"
                            class="w-full p-4 text-left transition"
                            :class="
                                selectedDocument?.id === document.id
                                    ? 'bg-blue-50'
                                    : 'hover:bg-slate-50'
                            "
                            @click="selectedId = document.id"
                        >
                            <div class="flex items-start gap-3">
                                <span
                                    class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-blue-600 ring-1 ring-slate-200"
                                >
                                    <FileText class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="truncate text-sm font-bold text-slate-800"
                                    >
                                        {{
                                            document.title ??
                                            humanize(document.type)
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 truncate text-xs text-slate-500"
                                    >
                                        {{
                                            document.candidate_profile.user.name
                                        }}
                                    </p>
                                    <div
                                        class="mt-2 flex items-center justify-between gap-2"
                                    >
                                        <StatusBadge
                                            :label="humanize(document.status)"
                                            :tone="statusTone(document.status)"
                                        />
                                        <span
                                            class="text-[10px] text-slate-400"
                                        >
                                            #{{ document.id }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </button>
                    </div>
                </aside>

                <main v-if="selectedDocument" class="p-5 sm:p-6">
                    <div class="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <section>
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:justify-between"
                            >
                                <div>
                                    <p class="text-xs font-bold text-blue-600">
                                        {{ humanize(selectedDocument.type) }}
                                    </p>
                                    <h2
                                        class="mt-1 text-xl font-bold text-slate-950"
                                    >
                                        {{
                                            selectedDocument.title ??
                                            selectedDocument.original_name
                                        }}
                                    </h2>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ selectedDocument.original_name }}
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="humanize(selectedDocument.status)"
                                    :tone="statusTone(selectedDocument.status)"
                                />
                            </div>

                            <dl
                                class="mt-6 grid gap-3 rounded-2xl bg-slate-50 p-4 sm:grid-cols-2"
                            >
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Fachkraft
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-semibold text-slate-800"
                                    >
                                        {{
                                            selectedDocument.candidate_profile
                                                .user.name
                                        }}
                                    </dd>
                                    <dd class="text-xs text-slate-500">
                                        {{
                                            selectedDocument.candidate_profile
                                                .user.email
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Beruf
                                    </dt>
                                    <dd
                                        class="mt-1 text-sm font-semibold text-slate-800"
                                    >
                                        {{
                                            selectedDocument.candidate_profile
                                                .current_position ??
                                            'Nicht hinterlegt'
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Datei
                                    </dt>
                                    <dd class="mt-1 text-sm text-slate-700">
                                        {{ selectedDocument.mime_type }} ·
                                        {{
                                            formatBytes(
                                                selectedDocument.size_bytes,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Hochgeladen
                                    </dt>
                                    <dd class="mt-1 text-sm text-slate-700">
                                        {{
                                            formatDate(
                                                selectedDocument.created_at,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Gültig bis
                                    </dt>
                                    <dd class="mt-1 text-sm text-slate-700">
                                        {{
                                            formatDate(
                                                selectedDocument.expires_at,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-[10px] font-bold text-slate-400 uppercase"
                                    >
                                        Letzte Prüfung
                                    </dt>
                                    <dd class="mt-1 text-sm text-slate-700">
                                        {{
                                            selectedDocument.verifier?.name ??
                                            '—'
                                        }}
                                    </dd>
                                    <dd class="text-xs text-slate-500">
                                        {{
                                            formatDate(
                                                selectedDocument.verified_at,
                                            )
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <div
                                class="mt-5 flex items-start gap-3 rounded-xl p-4"
                                :class="
                                    selectedDocument.scan_result === 'clean'
                                        ? 'bg-emerald-50 text-emerald-800'
                                        : selectedDocument.scan_result
                                          ? 'bg-red-50 text-red-800'
                                          : 'bg-amber-50 text-amber-800'
                                "
                            >
                                <ShieldCheck
                                    v-if="
                                        selectedDocument.scan_result === 'clean'
                                    "
                                    class="mt-0.5 size-5 shrink-0"
                                />
                                <ShieldX
                                    v-else
                                    class="mt-0.5 size-5 shrink-0"
                                />
                                <div>
                                    <p class="text-sm font-bold">
                                        Virenscan:
                                        {{
                                            humanize(
                                                selectedDocument.scan_result ??
                                                    'pending',
                                            )
                                        }}
                                    </p>
                                    <p class="mt-1 text-xs">
                                        Abgeschlossen
                                        {{
                                            formatDate(
                                                selectedDocument.scan_completed_at,
                                            )
                                        }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="selectedDocument.rejection_reason"
                                class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4"
                            >
                                <p class="text-xs font-bold text-red-800">
                                    Ablehnungsgrund
                                </p>
                                <p class="mt-1 text-sm leading-6 text-red-700">
                                    {{ selectedDocument.rejection_reason }}
                                </p>
                            </div>
                        </section>

                        <aside
                            class="h-fit rounded-2xl border border-slate-200 p-5"
                        >
                            <h3 class="font-bold text-slate-900">
                                Prüfentscheidung
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                Verifizierung ist erst nach einem sauberen,
                                abgeschlossenen Virenscan möglich.
                            </p>

                            <label class="mt-5 block">
                                <span class="text-xs font-bold text-slate-700">
                                    Ablehnungsgrund
                                </span>
                                <textarea
                                    v-model="reviewForm.rejection_reason"
                                    rows="5"
                                    placeholder="Bei Ablehnung mindestens 5 Zeichen …"
                                    class="erin-focus mt-2 w-full rounded-xl border border-slate-200 p-3 text-sm"
                                />
                            </label>
                            <p
                                v-if="reviewForm.errors.rejection_reason"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ reviewForm.errors.rejection_reason }}
                            </p>
                            <p
                                v-if="reviewForm.errors.status"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ reviewForm.errors.status }}
                            </p>

                            <div class="mt-5 grid gap-2">
                                <button
                                    type="button"
                                    :disabled="
                                        reviewForm.processing ||
                                        selectedDocument.scan_result !==
                                            'clean' ||
                                        !selectedDocument.scan_completed_at
                                    "
                                    class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-emerald-600 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-40"
                                    @click="submitReview('verified')"
                                >
                                    <Check class="size-4" />
                                    Verifizieren
                                </button>
                                <button
                                    type="button"
                                    :disabled="reviewForm.processing"
                                    class="erin-focus h-10 rounded-xl border border-blue-200 text-xs font-bold text-blue-700 disabled:opacity-50"
                                    @click="submitReview('in_review')"
                                >
                                    In Prüfung setzen
                                </button>
                                <button
                                    type="button"
                                    :disabled="reviewForm.processing"
                                    class="erin-focus h-10 rounded-xl border border-red-200 text-xs font-bold text-red-700 disabled:opacity-50"
                                    @click="submitReview('rejected')"
                                >
                                    Ablehnen
                                </button>
                            </div>
                        </aside>
                    </div>
                </main>
            </div>
            <AdminEmptyState
                v-else
                title="Keine Dokumente in der Warteschlange"
                description="Für die gewählten Filter liegen derzeit keine Dokumente vor."
            />
            <AdminPagination :paginator="documents" />
        </SectionCard>
    </div>
</template>
