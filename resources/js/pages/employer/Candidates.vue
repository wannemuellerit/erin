<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ListFilter,
    MessageCircle,
    Send,
    SlidersHorizontal,
    Sparkles,
    UserRoundSearch,
    UsersRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BulkActionBar from '@/components/product/BulkActionBar.vue';
import CandidateCard from '@/components/product/CandidateCard.vue';
import EmptyState from '@/components/product/EmptyState.vue';
import FilterChip from '@/components/product/FilterChip.vue';
import FilterToolbar from '@/components/product/FilterToolbar.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { index as candidatesIndex } from '@/routes/employer/candidates';

type Candidate = {
    id: number | string;
    label?: string;
    current_country_code?: string;
    current_position?: string | null;
    desired_position?: string | null;
    experience_years?: number | null;
    summary?: string | null;
    skills?: Array<{ name_de?: string; name_en?: string; slug?: string }>;
    languages?: Array<{
        name_de?: string;
        name_en?: string;
        code?: string;
        level?: string;
        verified?: boolean;
    }>;
    available_from?: string | null;
    match?: { score?: number; factors?: Record<string, unknown> } | null;
    favorite?: boolean;
};

type JobOption = { id: number; title: string; status: string };
type OccupationOption = { id: number; name_de?: string; name_en?: string };
type TalentList = { id: number; name: string; members_count?: number };
type Filters = {
    search?: string;
    country?: string;
    occupation?: string | number;
    experience?: string | number;
    employment_type?: string;
    relocation_ready?: boolean;
    work_permit?: boolean;
    view?: string;
    job?: string | number;
};

const props = withDefaults(
    defineProps<{
        candidates?: Candidate[];
        jobs?: JobOption[];
        occupations?: OccupationOption[];
        talent_lists?: TalentList[];
        filters?: Filters;
    }>(),
    {
        candidates: () => [],
        jobs: () => [],
        occupations: () => [],
        talent_lists: () => [],
        filters: () => ({}),
    },
);
const { t } = useI18n();
const query = ref(props.filters.search ?? '');
const activeTab = ref(
    props.filters.view === 'favorites'
        ? 'favorites'
        : props.filters.view === 'ai'
          ? 'matching'
          : 'all',
);
const selectedIds = ref<number[]>([]);
const bulkJobId = ref<number | null>(null);
const bulkMessage = ref('');
const bulkProcessing = ref(false);

const visibleCandidateIds = computed(() =>
    props.candidates.map((candidate) => Number(candidate.id)),
);
const availableInviteJobs = computed(() =>
    props.jobs.filter((job) => job.status === 'published'),
);
const allVisibleSelected = computed(
    () =>
        visibleCandidateIds.value.length > 0 &&
        visibleCandidateIds.value.every((id) => selectedIds.value.includes(id)),
);

const visit = (
    changes: Record<string, string | number | boolean | undefined>,
) => {
    const view =
        activeTab.value === 'favorites'
            ? 'favorites'
            : activeTab.value === 'matching'
              ? 'ai'
              : undefined;
    router.get(
        candidatesIndex.url(),
        { ...props.filters, ...changes, view },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const selectTab = (tab: 'all' | 'favorites' | 'matching') => {
    activeTab.value = tab;
    visit({
        view:
            tab === 'favorites'
                ? 'favorites'
                : tab === 'matching'
                  ? 'ai'
                  : undefined,
    });
};

const submitSearch = () => visit({ search: query.value || undefined });
const setCandidateSelected = (
    candidateId: number | string,
    selected: boolean,
) => {
    const id = Number(candidateId);

    selectedIds.value = selected
        ? [...new Set([...selectedIds.value, id])].slice(0, 100)
        : selectedIds.value.filter((selectedId) => selectedId !== id);
};
const toggleAllVisible = () => {
    selectedIds.value = allVisibleSelected.value
        ? []
        : visibleCandidateIds.value.slice(0, 100);
};
const runBulkAction = (action: 'invite' | 'message') => {
    bulkProcessing.value = true;
    router.post(
        `/employer/candidates/bulk/${action}`,
        {
            candidate_ids: selectedIds.value,
            ...(action === 'invite'
                ? {
                      job_posting_id: bulkJobId.value,
                      message: bulkMessage.value || null,
                  }
                : { message: bulkMessage.value }),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = [];
                bulkMessage.value = '';
            },
            onFinish: () => {
                bulkProcessing.value = false;
            },
        },
    );
};
const tabs = computed(() => [
    { key: 'all' as const, label: t('employer.candidates.tabs.all') },
    {
        key: 'favorites' as const,
        label: t('employer.candidates.tabs.favorites'),
    },
    {
        key: 'matching' as const,
        label: t('employer.candidates.tabs.matching'),
    },
]);
</script>

<template>
    <Head :title="t('employer.candidates.metaTitle')" />
    <div class="erin-page">
        <PageHeader
            :eyebrow="t('employer.candidates.eyebrow')"
            :title="t('employer.candidates.title')"
            :description="t('employer.candidates.description')"
            :icon="UsersRound"
        >
            <template #actions
                ><StatusBadge
                    :label="t('employer.candidates.publishedProfiles')"
                    tone="teal"
            /></template>
        </PageHeader>

        <FilterToolbar>
            <template #tabs>
                <div class="flex rounded-xl bg-slate-100 p-1">
                    <button
                        v-for="tab in tabs"
                        :key="tab.key"
                        type="button"
                        class="flex-1 rounded-lg px-4 py-2 text-xs font-bold whitespace-nowrap transition xl:flex-none"
                        :class="
                            activeTab === tab.key
                                ? 'bg-white text-blue-700 shadow-sm'
                                : 'text-slate-500 hover:text-slate-800'
                        "
                        @click="
                            selectTab(
                                tab.key as 'all' | 'favorites' | 'matching',
                            )
                        "
                    >
                        <Sparkles
                            v-if="tab.key === 'matching'"
                            class="mr-1.5 inline size-3.5 text-violet-500"
                        />{{ tab.label }}
                    </button>
                </div>
            </template>
            <form @submit.prevent="submitSearch">
                <SearchField
                    v-model="query"
                    :placeholder="t('employer.candidates.searchPlaceholder')"
                />
            </form>
            <template #actions>
                <button
                    type="button"
                    class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                    <SlidersHorizontal class="size-4" />
                    {{ t('employer.candidates.filters') }}
                    <span
                        class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700"
                        >3</span
                    >
                </button>
            </template>
            <template #filters>
                <FilterChip
                    v-if="filters.country"
                    :label="
                        t('employer.candidates.countryFilter', {
                            country: filters.country,
                        })
                    "
                />
                <FilterChip
                    v-if="filters.occupation"
                    :label="t('employer.candidates.occupationFiltered')"
                />
                <FilterChip
                    v-if="filters.relocation_ready"
                    :label="t('employer.candidates.relocationReady')"
                />
                <button
                    v-if="Object.values(filters).some(Boolean)"
                    type="button"
                    class="px-2 text-xs font-semibold text-slate-400 hover:text-red-600"
                    @click="router.get(candidatesIndex.url())"
                >
                    {{ t('employer.candidates.clearAll') }}
                </button>
            </template>
        </FilterToolbar>

        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">
                <strong class="text-slate-900">{{ candidates.length }}</strong>
                {{ t('employer.candidates.results') }}
            </p>
            <button
                type="button"
                class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500"
            >
                <ListFilter class="size-3.5" />
                {{ t('employer.candidates.bestMatch') }}
            </button>
        </div>
        <div v-if="candidates.length" class="flex items-center gap-2">
            <label
                class="inline-flex cursor-pointer items-center gap-2 text-xs font-bold text-slate-600"
            >
                <input
                    type="checkbox"
                    class="erin-focus size-4 rounded border-slate-300 text-blue-600"
                    :checked="allVisibleSelected"
                    @change="toggleAllVisible"
                />
                {{ t('employer.candidates.selectAll') }}
            </label>
        </div>
        <BulkActionBar :count="selectedIds.length" @clear="selectedIds = []">
            <select
                v-model="bulkJobId"
                class="erin-focus h-9 min-w-44 rounded-xl border border-blue-200 bg-white px-3 text-xs font-bold text-slate-700"
                :aria-label="t('employer.candidates.chooseJob')"
            >
                <option :value="null">
                    {{ t('employer.candidates.chooseJob') }}
                </option>
                <option
                    v-for="job in availableInviteJobs"
                    :key="job.id"
                    :value="job.id"
                >
                    {{ job.title }}
                </option>
            </select>
            <input
                v-model="bulkMessage"
                type="text"
                maxlength="3000"
                class="erin-focus h-9 min-w-52 rounded-xl border border-blue-200 bg-white px-3 text-xs"
                :placeholder="t('employer.candidates.bulkMessagePlaceholder')"
            />
            <button
                type="button"
                class="erin-focus inline-flex h-9 items-center gap-1.5 rounded-xl border border-blue-200 bg-white px-3 text-xs font-bold text-blue-700 disabled:opacity-50"
                :disabled="bulkProcessing || !bulkMessage"
                @click="runBulkAction('message')"
            >
                <MessageCircle class="size-3.5" />
                {{ t('employer.candidates.messageSelected') }}
            </button>
            <button
                type="button"
                class="erin-focus inline-flex h-9 items-center gap-1.5 rounded-xl bg-blue-600 px-3 text-xs font-bold text-white disabled:opacity-50"
                :disabled="bulkProcessing || !bulkJobId"
                @click="runBulkAction('invite')"
            >
                <Send class="size-3.5" />
                {{ t('employer.candidates.inviteSelected') }}
            </button>
        </BulkActionBar>
        <div
            v-if="candidates.length"
            class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3"
        >
            <CandidateCard
                v-for="candidate in candidates"
                :key="candidate.id"
                :candidate="candidate"
                selectable
                :selected="selectedIds.includes(Number(candidate.id))"
                @update:selected="setCandidateSelected(candidate.id, $event)"
            />
        </div>
        <EmptyState
            v-else
            panel
            :icon="UserRoundSearch"
            :title="t('employer.candidates.emptyTitle')"
            :description="t('employer.candidates.emptyDescription')"
        />
    </div>
</template>
