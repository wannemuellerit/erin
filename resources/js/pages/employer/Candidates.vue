<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    BookmarkPlus,
    ChevronLeft,
    ChevronRight,
    MessageCircle,
    Send,
    SlidersHorizontal,
    Sparkles,
    Trash2,
    UserRoundSearch,
    UsersRound,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BulkActionBar from '@/components/product/BulkActionBar.vue';
import CandidateCard from '@/components/product/CandidateCard.vue';
import EmptyState from '@/components/product/EmptyState.vue';
import FilterChip from '@/components/product/FilterChip.vue';
import FilterToolbar from '@/components/product/FilterToolbar.vue';
import PageHeader from '@/components/product/PageHeader.vue';
import SearchField from '@/components/product/SearchField.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { useCapabilities } from '@/composables/useCapabilities';
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
type TalentList = {
    id: number;
    name: string;
    members_count?: number;
    is_default?: boolean;
};
type NamedOption = {
    id: number;
    code?: string;
    name_de?: string;
    name_en?: string;
};
type SavedSearch = {
    id: number;
    name: string;
    filters: Filters;
};
type PaginationLink = {
    url?: string | null;
    label: string;
    active: boolean;
};
type CandidatePage = {
    data: Candidate[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
};
type Filters = {
    search?: string;
    country?: string;
    occupation?: string | number;
    experience?: string | number;
    employment_type?: string;
    relocation_ready?: boolean;
    work_permit?: boolean;
    visa?: string;
    weekly_hours?: string | number;
    skill?: string | number;
    language?: string;
    language_level?: string;
    driving_license?: string;
    salary_max?: string | number;
    available_before?: string;
    documents_complete?: boolean;
    view?: string;
    job?: string | number;
    sort?: string;
    per_page?: string | number;
};

const props = withDefaults(
    defineProps<{
        candidates?: CandidatePage;
        jobs?: JobOption[];
        occupations?: OccupationOption[];
        skills?: NamedOption[];
        languages?: NamedOption[];
        countries?: string[];
        talent_lists?: TalentList[];
        saved_searches?: SavedSearch[];
        filters?: Filters;
    }>(),
    {
        candidates: () => ({
            data: [],
            current_page: 1,
            last_page: 1,
            per_page: 24,
            total: 0,
            links: [],
        }),
        jobs: () => [],
        occupations: () => [],
        skills: () => [],
        languages: () => [],
        countries: () => [],
        talent_lists: () => [],
        saved_searches: () => [],
        filters: () => ({}),
    },
);
const { t } = useI18n();
const { can } = useCapabilities();
const canManageCandidates = computed(() => can('candidates.manage'));
const query = ref(props.filters.search ?? '');
const filterPanelOpen = ref(false);
const filterForm = reactive<Filters>({ ...props.filters });
const savedSearchName = ref('');
const newListName = ref('');
const talentListNames = reactive(
    Object.fromEntries(
        props.talent_lists.map((list) => [list.id, list.name]),
    ) as Record<number, string>,
);
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
    props.candidates.data.map((candidate) => Number(candidate.id)),
);
const activeFilterCount = computed(
    () =>
        Object.entries(props.filters).filter(
            ([key, value]) =>
                !['view', 'sort', 'per_page', 'job'].includes(key) &&
                value !== undefined &&
                value !== null &&
                value !== '' &&
                value !== false,
        ).length,
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
const applyFilters = () => {
    filterPanelOpen.value = false;
    visit({ ...filterForm, page: undefined });
};
const clearFilters = () => router.get(candidatesIndex.url());
const applySavedSearch = (saved: SavedSearch) => {
    Object.assign(filterForm, saved.filters);
    router.get(candidatesIndex.url(), saved.filters);
};
const saveSearch = () => {
    if (!savedSearchName.value.trim()) {
        return;
    }

    router.post(
        '/employer/candidate-saved-searches',
        { name: savedSearchName.value, filters: props.filters },
        { preserveScroll: true, onSuccess: () => (savedSearchName.value = '') },
    );
};
const createTalentList = () => {
    if (!newListName.value.trim()) {
        return;
    }

    router.post(
        '/employer/talent-lists',
        { name: newListName.value },
        { preserveScroll: true, onSuccess: () => (newListName.value = '') },
    );
};
const updateTalentList = (list: TalentList) => {
    const name = talentListNames[list.id]?.trim();

    if (!name) {
        return;
    }

    router.patch(
        `/employer/talent-lists/${list.id}`,
        { name },
        { preserveScroll: true },
    );
};
const deleteTalentList = (list: TalentList) => {
    router.delete(`/employer/talent-lists/${list.id}`, {
        preserveScroll: true,
    });
};
const deleteSavedSearch = (saved: SavedSearch) => {
    router.delete(`/employer/candidate-saved-searches/${saved.id}`, {
        preserveScroll: true,
    });
};
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
                    @click="filterPanelOpen = !filterPanelOpen"
                >
                    <SlidersHorizontal class="size-4" />
                    {{ t('employer.candidates.filters') }}
                    <span
                        class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700"
                        >{{ activeFilterCount }}</span
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
                    @click="clearFilters"
                >
                    {{ t('employer.candidates.clearAll') }}
                </button>
            </template>
        </FilterToolbar>

        <SectionCard
            v-if="filterPanelOpen"
            :title="t('employer.candidates.filterPanel.title')"
            :description="t('employer.candidates.filterPanel.description')"
        >
            <form
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                @submit.prevent="applyFilters"
            >
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{ t('employer.candidates.filterPanel.job') }}</span>
                    <select
                        v-model="filterForm.job"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="job in jobs"
                            :key="job.id"
                            :value="job.id"
                        >
                            {{ job.title }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.country')
                    }}</span>
                    <select
                        v-model="filterForm.country"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="country in countries"
                            :key="country"
                            :value="country"
                        >
                            {{ country }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.occupation')
                    }}</span>
                    <select
                        v-model="filterForm.occupation"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="occupation in occupations"
                            :key="occupation.id"
                            :value="occupation.id"
                        >
                            {{ occupation.name_de || occupation.name_en }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.skill')
                    }}</span>
                    <select
                        v-model="filterForm.skill"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="skill in skills"
                            :key="skill.id"
                            :value="skill.id"
                        >
                            {{ skill.name_de || skill.name_en }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.language')
                    }}</span>
                    <select
                        v-model="filterForm.language"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="language in languages"
                            :key="language.id"
                            :value="language.code"
                        >
                            {{ language.name_de || language.name_en }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.languageLevel')
                    }}</span>
                    <select
                        v-model="filterForm.language_level"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option
                            v-for="level in [
                                'A1',
                                'A2',
                                'B1',
                                'B2',
                                'C1',
                                'C2',
                            ]"
                            :key="level"
                            :value="level"
                        >
                            {{ level }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.experience')
                    }}</span>
                    <input
                        v-model.number="filterForm.experience"
                        type="number"
                        min="0"
                        max="60"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.employmentType')
                    }}</span>
                    <select
                        v-model="filterForm.employment_type"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option value="full_time">
                            {{ t('employer.candidates.filterPanel.fullTime') }}
                        </option>
                        <option value="part_time">
                            {{ t('employer.candidates.filterPanel.partTime') }}
                        </option>
                        <option value="temporary">
                            {{ t('employer.candidates.filterPanel.temporary') }}
                        </option>
                        <option value="permanent">
                            {{ t('employer.candidates.filterPanel.permanent') }}
                        </option>
                    </select>
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.weeklyHours')
                    }}</span>
                    <input
                        v-model.number="filterForm.weekly_hours"
                        type="number"
                        min="1"
                        max="80"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.drivingLicense')
                    }}</span>
                    <input
                        v-model="filterForm.driving_license"
                        maxlength="10"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.salaryMax')
                    }}</span>
                    <input
                        v-model.number="filterForm.salary_max"
                        type="number"
                        min="0"
                        step="10000"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{
                        t('employer.candidates.filterPanel.availableBefore')
                    }}</span>
                    <input
                        v-model="filterForm.available_before"
                        type="date"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    />
                </label>
                <label class="space-y-1 text-xs font-bold text-slate-600">
                    <span>{{ t('employer.candidates.filterPanel.visa') }}</span>
                    <select
                        v-model="filterForm.visa"
                        class="erin-focus h-10 w-full rounded-xl border border-slate-200 px-3 text-sm"
                    >
                        <option value="">
                            {{ t('employer.candidates.filterPanel.all') }}
                        </option>
                        <option value="required">
                            {{
                                t(
                                    'employer.candidates.filterPanel.visaRequired',
                                )
                            }}
                        </option>
                        <option value="not_required">
                            {{
                                t(
                                    'employer.candidates.filterPanel.visaNotRequired',
                                )
                            }}
                        </option>
                    </select>
                </label>
                <div
                    class="grid gap-2 pt-5 text-sm sm:col-span-2 lg:col-span-3"
                >
                    <label class="inline-flex items-center gap-2">
                        <input
                            v-model="filterForm.relocation_ready"
                            type="checkbox"
                        />
                        {{
                            t('employer.candidates.filterPanel.relocationReady')
                        }}
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input
                            v-model="filterForm.work_permit"
                            type="checkbox"
                        />
                        {{ t('employer.candidates.filterPanel.workPermit') }}
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input
                            v-model="filterForm.documents_complete"
                            type="checkbox"
                        />
                        {{
                            t(
                                'employer.candidates.filterPanel.documentsComplete',
                            )
                        }}
                    </label>
                </div>
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <Button type="submit">
                        {{ t('employer.candidates.filterPanel.apply') }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="clearFilters"
                    >
                        {{ t('employer.candidates.clearAll') }}
                    </Button>
                </div>
            </form>

            <div
                v-if="canManageCandidates"
                class="mt-6 grid gap-4 border-t border-slate-100 pt-5 lg:grid-cols-2"
            >
                <div>
                    <p class="text-sm font-extrabold text-slate-800">
                        {{ t('employer.candidates.savedSearches') }}
                    </p>
                    <div class="mt-2 flex gap-2">
                        <input
                            v-model="savedSearchName"
                            class="erin-focus h-10 min-w-0 flex-1 rounded-xl border border-slate-200 px-3 text-sm"
                            :placeholder="
                                t('employer.candidates.savedSearchName')
                            "
                        />
                        <Button type="button" @click="saveSearch">
                            <BookmarkPlus class="size-4" />
                            {{ t('employer.candidates.saveSearch') }}
                        </Button>
                    </div>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <div
                            v-for="saved in saved_searches"
                            :key="saved.id"
                            class="inline-flex overflow-hidden rounded-lg bg-blue-50 text-xs font-bold text-blue-700"
                        >
                            <button
                                type="button"
                                class="px-3 py-2"
                                @click="applySavedSearch(saved)"
                            >
                                {{ saved.name }}
                            </button>
                            <button
                                type="button"
                                class="border-l border-blue-100 px-2 hover:bg-red-50 hover:text-red-600"
                                :aria-label="
                                    t('employer.candidates.deleteSavedSearch', {
                                        name: saved.name,
                                    })
                                "
                                @click="deleteSavedSearch(saved)"
                            >
                                <Trash2 class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </div>
                <div>
                    <p class="text-sm font-extrabold text-slate-800">
                        {{ t('employer.candidates.talentLists') }}
                    </p>
                    <div class="mt-2 flex gap-2">
                        <input
                            v-model="newListName"
                            class="erin-focus h-10 min-w-0 flex-1 rounded-xl border border-slate-200 px-3 text-sm"
                            :placeholder="
                                t('employer.candidates.talentListName')
                            "
                        />
                        <Button type="button" @click="createTalentList">
                            {{ t('employer.candidates.createList') }}
                        </Button>
                    </div>
                    <div class="mt-2 space-y-2">
                        <div
                            v-for="list in talent_lists"
                            :key="list.id"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="talentListNames[list.id]"
                                class="erin-focus h-9 min-w-0 flex-1 rounded-lg border border-slate-200 px-3 text-xs font-semibold"
                            />
                            <span class="text-xs text-slate-500">
                                {{ list.members_count ?? 0 }}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="updateTalentList(list)"
                            >
                                {{ t('employer.candidates.renameList') }}
                            </Button>
                            <Button
                                v-if="!list.is_default"
                                type="button"
                                size="icon"
                                variant="ghost"
                                :aria-label="
                                    t('employer.candidates.deleteList', {
                                        name: list.name,
                                    })
                                "
                                @click="deleteTalentList(list)"
                            >
                                <Trash2 class="size-4 text-red-600" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </SectionCard>

        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">
                <strong class="text-slate-900">{{ candidates.total }}</strong>
                {{ t('employer.candidates.results') }}
            </p>
            <select
                :value="filters.sort || 'published_desc'"
                class="erin-focus h-9 rounded-xl border border-slate-200 px-3 text-xs font-semibold text-slate-600"
                @change="
                    visit({
                        sort: ($event.target as HTMLSelectElement).value,
                    })
                "
            >
                <option value="published_desc">
                    {{ t('employer.candidates.sortNewest') }}
                </option>
                <option value="experience_desc">
                    {{ t('employer.candidates.sortExperience') }}
                </option>
                <option value="availability_asc">
                    {{ t('employer.candidates.sortAvailability') }}
                </option>
                <option value="salary_asc">
                    {{ t('employer.candidates.sortSalary') }}
                </option>
            </select>
        </div>
        <div
            v-if="canManageCandidates && candidates.data.length"
            class="flex items-center gap-2"
        >
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
        <BulkActionBar
            v-if="canManageCandidates"
            :count="selectedIds.length"
            @clear="selectedIds = []"
        >
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
            v-if="candidates.data.length"
            class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3"
        >
            <CandidateCard
                v-for="candidate in candidates.data"
                :key="candidate.id"
                :candidate="candidate"
                :selectable="canManageCandidates"
                :can-manage="canManageCandidates"
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
        <nav
            v-if="candidates.last_page > 1"
            class="flex items-center justify-center gap-2"
            :aria-label="t('employer.candidates.pagination')"
        >
            <Button
                type="button"
                variant="outline"
                size="icon"
                :disabled="candidates.current_page <= 1"
                @click="visit({ page: candidates.current_page - 1 })"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <span class="px-3 text-xs font-bold text-slate-600">
                {{
                    t('employer.candidates.pageOf', {
                        current: candidates.current_page,
                        last: candidates.last_page,
                    })
                }}
            </span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                :disabled="candidates.current_page >= candidates.last_page"
                @click="visit({ page: candidates.current_page + 1 })"
            >
                <ChevronRight class="size-4" />
            </Button>
        </nav>
    </div>
</template>
