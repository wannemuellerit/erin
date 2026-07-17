<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Briefcase,
    CheckCircle2,
    Clock3,
    Heart,
    MapPin,
    MessageCircle,
    Star,
} from '@lucide/vue';
import { computed } from 'vue';
import MatchScore from '@/components/product/MatchScore.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';

type Candidate = {
    id: number | string;
    label?: string;
    reference?: string;
    current_country_code?: string;
    country?: string;
    current_position?: string | null;
    desired_position?: string | null;
    position?: string;
    experience_years?: number | null;
    experience?: string | number | null;
    summary?: string | null;
    skills?: Array<
        string | { name_de?: string; name_en?: string; slug?: string }
    >;
    languages?: Array<{
        name_de?: string;
        name_en?: string;
        code?: string;
        level?: string;
        verified?: boolean;
    }>;
    language?: string;
    available_from?: string | null;
    available?: string;
    match?: { score?: number; factors?: Record<string, unknown> } | null;
    score?: number;
    verified?: boolean;
    favorite?: boolean;
};

const props = withDefaults(
    defineProps<{
        candidate: Candidate;
        href?: string;
    }>(),
    {
        href: '',
    },
);

const reference = computed(
    () =>
        props.candidate.label ??
        props.candidate.reference ??
        `#ER-${props.candidate.id}`,
);
const country = computed(
    () =>
        props.candidate.current_country_code ?? props.candidate.country ?? '—',
);
const position = computed(
    () =>
        props.candidate.desired_position ??
        props.candidate.current_position ??
        props.candidate.position ??
        'Fachkraft',
);
const experience = computed(() => {
    const value =
        props.candidate.experience_years ?? props.candidate.experience;

    if (typeof value === 'number') {
        return `${value} Jahre Erfahrung`;
    }

    return value ? String(value) : 'Erfahrung nicht angegeben';
});
const skillLabels = computed(() =>
    (props.candidate.skills ?? [])
        .map((skill) =>
            typeof skill === 'string'
                ? skill
                : (skill.name_de ?? skill.name_en ?? skill.slug ?? ''),
        )
        .filter(Boolean),
);
const language = computed(() => {
    if (props.candidate.language) {
        return props.candidate.language;
    }

    const first = props.candidate.languages?.[0];

    return first
        ? `${first.name_de ?? first.name_en ?? first.code ?? 'Sprache'} ${first.level ?? ''}`.trim()
        : 'Sprache nicht angegeben';
});
const available = computed(
    () =>
        props.candidate.available ??
        (props.candidate.available_from
            ? `Verfügbar ab ${props.candidate.available_from}`
            : 'Eintritt offen'),
);
const score = computed(
    () => props.candidate.match?.score ?? props.candidate.score ?? 0,
);
const verified = computed(
    () =>
        props.candidate.verified ??
        Boolean(props.candidate.languages?.some((item) => item.verified)),
);
</script>

<template>
    <article
        class="erin-panel group flex h-full flex-col p-5 transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-xl hover:shadow-blue-950/5"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <div
                    class="relative grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-teal-50 to-blue-100 text-sm font-extrabold text-blue-700"
                >
                    {{ reference.slice(-2) }}
                    <span
                        v-if="verified"
                        class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full bg-white text-teal-500"
                        title="Profil verifiziert"
                    >
                        <CheckCircle2 class="size-4" />
                    </span>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <h3 class="truncate font-bold text-slate-950">
                            {{ position }}
                        </h3>
                        <StatusBadge
                            v-if="verified"
                            label="Verifiziert"
                            tone="teal"
                            :dot="false"
                        />
                    </div>
                    <p
                        class="mt-1 flex items-center gap-1 text-xs text-slate-500"
                    >
                        <MapPin class="size-3.5 text-teal-500" />
                        {{ country }}
                        <span class="text-slate-300">•</span>
                        {{ reference }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                class="erin-focus rounded-lg p-2"
                :class="
                    candidate.favorite
                        ? 'bg-orange-50 text-orange-500'
                        : 'text-slate-300 hover:bg-slate-50 hover:text-orange-500'
                "
                aria-label="Als Favorit markieren"
            >
                <Heart
                    class="size-4"
                    :fill="candidate.favorite ? 'currentColor' : 'none'"
                />
            </button>
        </div>

        <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">
            {{ candidate.summary || 'Noch keine Kurzbeschreibung hinterlegt.' }}
        </p>

        <div class="mt-4 flex flex-wrap gap-1.5">
            <span
                v-for="skill in skillLabels.slice(0, 4)"
                :key="skill"
                class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600"
            >
                {{ skill }}
            </span>
        </div>

        <div
            class="mt-5 grid grid-cols-[1fr_auto] items-center gap-4 border-t border-slate-100 pt-4"
        >
            <div class="grid gap-2 text-xs text-slate-500">
                <span class="flex items-center gap-2">
                    <Briefcase class="size-3.5 text-blue-500" />
                    {{ experience }}
                </span>
                <span class="flex items-center gap-2">
                    <Star class="size-3.5 text-amber-500" /> {{ language }}
                </span>
                <span class="flex items-center gap-2">
                    <Clock3 class="size-3.5 text-orange-500" /> {{ available }}
                </span>
            </div>
            <MatchScore :score="score" size="md" />
        </div>

        <div class="mt-5 grid grid-cols-[1fr_auto] gap-2">
            <Link
                :href="href || `/employer/candidates/${candidate.id}`"
                class="erin-focus inline-flex h-10 items-center justify-center rounded-xl bg-blue-600 px-4 text-sm font-bold text-white hover:bg-blue-700"
            >
                Profil ansehen
            </Link>
            <button
                type="button"
                class="erin-focus grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:border-teal-300 hover:bg-teal-50 hover:text-teal-600"
                aria-label="Kandidat zum Gespräch einladen"
            >
                <MessageCircle class="size-4" />
            </button>
        </div>
    </article>
</template>
