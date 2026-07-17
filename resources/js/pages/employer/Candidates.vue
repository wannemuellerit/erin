<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ListFilter,
    Search,
    SlidersHorizontal,
    Sparkles,
    UserRoundSearch,
    UsersRound,
} from '@lucide/vue';
import { ref } from 'vue';
import CandidateCard from '@/components/product/CandidateCard.vue';
import PageHeader from '@/components/product/PageHeader.vue';
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
const query = ref(props.filters.search ?? '');
const activeTab = ref(
    props.filters.view === 'favorites'
        ? 'favorites'
        : props.filters.view === 'ai'
          ? 'matching'
          : 'all',
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
</script>

<template>
    <Head title="Fachkräfte finden" />
    <div class="erin-page">
        <PageHeader
            eyebrow="Talent Discovery"
            title="Fachkräfte finden"
            description="Entdecken Sie qualifizierte, wechselbereite Fachkräfte – anonymisiert und passend zu Ihrem Bedarf."
            :icon="UsersRound"
        >
            <template #actions
                ><StatusBadge label="2.487 veröffentlichte Profile" tone="teal"
            /></template>
        </PageHeader>

        <section class="erin-panel p-4">
            <div class="flex flex-col gap-3 xl:flex-row">
                <div class="flex rounded-xl bg-slate-100 p-1">
                    <button
                        v-for="tab in [
                            { key: 'all', label: 'Alle' },
                            { key: 'favorites', label: 'Favoriten' },
                            { key: 'matching', label: 'AI Matching' },
                        ]"
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
                <form
                    class="relative min-w-0 flex-1"
                    @submit.prevent="submitSearch"
                >
                    <Search
                        class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400"
                    />
                    <input
                        v-model="query"
                        type="search"
                        placeholder="Beruf, Skill oder Land durchsuchen …"
                        class="erin-focus h-11 w-full rounded-xl border border-slate-200 bg-white pr-4 pl-10 text-sm placeholder:text-slate-400"
                    />
                </form>
                <button
                    type="button"
                    class="erin-focus inline-flex h-11 items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50"
                >
                    <SlidersHorizontal class="size-4" /> Filter
                    <span
                        class="rounded-full bg-blue-100 px-1.5 py-0.5 text-[10px] text-blue-700"
                        >3</span
                    >
                </button>
            </div>
            <div
                class="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3"
            >
                <span
                    v-if="filters.country"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                    >Land: {{ filters.country }}</span
                >
                <span
                    v-if="filters.occupation"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                    >Beruf gefiltert</span
                >
                <span
                    v-if="filters.relocation_ready"
                    class="rounded-full bg-blue-50 px-3 py-1.5 text-xs font-semibold text-blue-700"
                    >Umzugsbereit</span
                >
                <button
                    v-if="Object.values(filters).some(Boolean)"
                    type="button"
                    class="px-2 text-xs font-semibold text-slate-400 hover:text-red-600"
                    @click="router.get(candidatesIndex.url())"
                >
                    Alle löschen
                </button>
            </div>
        </section>

        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">
                <strong class="text-slate-900">{{ candidates.length }}</strong>
                passende Fachkräfte
            </p>
            <button
                type="button"
                class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500"
            >
                <ListFilter class="size-3.5" /> Beste Übereinstimmung
            </button>
        </div>
        <div
            v-if="candidates.length"
            class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3"
        >
            <CandidateCard
                v-for="candidate in candidates"
                :key="candidate.id"
                :candidate="candidate"
            />
        </div>
        <div
            v-else
            class="erin-panel grid min-h-72 place-items-center p-8 text-center"
        >
            <div>
                <span
                    class="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-100 text-slate-400"
                    ><UserRoundSearch class="size-6"
                /></span>
                <h2 class="mt-4 font-bold text-slate-900">
                    Keine Fachkräfte gefunden
                </h2>
                <p class="mt-2 max-w-md text-sm text-slate-500">
                    Passen Sie Suche und Filter an oder veröffentlichen Sie
                    zuerst eine Stelle für das KI-Matching.
                </p>
            </div>
        </div>
    </div>
</template>
