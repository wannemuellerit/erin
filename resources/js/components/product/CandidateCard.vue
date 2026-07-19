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
import { useI18n } from 'vue-i18n';
import MatchScore from '@/components/product/MatchScore.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useFormatters } from '@/composables/useFormatters';
import de from '@/i18n/messages/product-components-de';
import en from '@/i18n/messages/product-components-en';

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
        selectable?: boolean;
        selected?: boolean;
    }>(),
    {
        href: '',
        selectable: false,
        selected: false,
    },
);

const emit = defineEmits<{
    'update:selected': [selected: boolean];
}>();

const { locale, t } = useI18n({
    useScope: 'local',
    messages: { de, en },
});
const { formatDate } = useFormatters();

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
        t('candidateCard.professionFallback'),
);
const experience = computed(() => {
    const value =
        props.candidate.experience_years ?? props.candidate.experience;

    if (typeof value === 'number') {
        return t('candidateCard.experienceYears', value);
    }

    return value ? String(value) : t('candidateCard.experienceMissing');
});
const skillLabels = computed(() =>
    (props.candidate.skills ?? [])
        .map((skill) =>
            typeof skill === 'string'
                ? skill
                : locale.value === 'en'
                  ? (skill.name_en ?? skill.name_de ?? skill.slug ?? '')
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
        ? `${
              locale.value === 'en'
                  ? (first.name_en ??
                    first.name_de ??
                    first.code ??
                    t('candidateCard.languageFallback'))
                  : (first.name_de ??
                    first.name_en ??
                    first.code ??
                    t('candidateCard.languageFallback'))
          } ${first.level ?? ''}`.trim()
        : t('candidateCard.languageMissing');
});
const formatAvailabilityDate = (value: string) =>
    formatDate(value, { dateStyle: 'medium' });
const available = computed(
    () =>
        props.candidate.available ??
        (props.candidate.available_from
            ? t('candidateCard.availableFrom', {
                  date: formatAvailabilityDate(props.candidate.available_from),
              })
            : t('candidateCard.availabilityOpen')),
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
        :class="selected ? 'border-blue-400 ring-2 ring-blue-100' : ''"
    >
        <div class="flex items-start justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                <label v-if="selectable" class="relative shrink-0">
                    <span class="sr-only">
                        {{
                            t('candidateCard.select', {
                                candidate: reference,
                            })
                        }}
                    </span>
                    <input
                        type="checkbox"
                        class="erin-focus size-5 rounded border-slate-300 text-blue-600"
                        :checked="selected"
                        @change="
                            emit(
                                'update:selected',
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                </label>
                <div
                    class="relative grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-teal-50 to-blue-100 text-sm font-extrabold text-blue-700"
                >
                    {{ reference.slice(-2) }}
                    <span
                        v-if="verified"
                        class="absolute -right-1 -bottom-1 grid size-5 place-items-center rounded-full bg-white text-teal-500"
                        :title="t('candidateCard.verifiedTitle')"
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
                            :label="t('candidateCard.verified')"
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
                :aria-label="
                    t(
                        candidate.favorite
                            ? 'candidateCard.removeFavorite'
                            : 'candidateCard.favorite',
                    )
                "
            >
                <Heart
                    class="size-4"
                    :fill="candidate.favorite ? 'currentColor' : 'none'"
                />
            </button>
        </div>

        <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">
            {{ candidate.summary || t('candidateCard.summaryMissing') }}
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
                {{ t('candidateCard.viewProfile') }}
            </Link>
            <button
                type="button"
                class="erin-focus grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-500 hover:border-teal-300 hover:bg-teal-50 hover:text-teal-600"
                :aria-label="t('candidateCard.invite')"
            >
                <MessageCircle class="size-4" />
            </button>
        </div>
    </article>
</template>
