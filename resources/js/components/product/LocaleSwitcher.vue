<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Languages } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { normalizeLocale } from '@/i18n';
import { update as updateLocale } from '@/routes/locale';
import type { SupportedLocale } from '@/i18n';

type PublicPlatform = {
    locale?: string | null;
};

type PublicAuth = {
    user?: {
        locale?: string | null;
    } | null;
};

withDefaults(
    defineProps<{
        showLabel?: boolean;
        compact?: boolean;
    }>(),
    {
        showLabel: true,
        compact: false,
    },
);

const emit = defineEmits<{
    changed: [locale: SupportedLocale];
}>();

const page = usePage();
const { locale, t } = useI18n();
const pendingLocale = ref<SupportedLocale | null>(null);
const serverLocale = normalizeLocale(
    (page.props.auth as PublicAuth | undefined)?.user?.locale ??
        (page.props.platform as PublicPlatform | undefined)?.locale,
);

locale.value = serverLocale;

const languages = computed(() => [
    {
        code: 'de' as const,
        label: t('public.common.german'),
    },
    {
        code: 'en' as const,
        label: t('public.common.english'),
    },
]);

function chooseLocale(nextLocale: SupportedLocale): void {
    if (locale.value === nextLocale || pendingLocale.value !== null) {
        return;
    }

    pendingLocale.value = nextLocale;

    router.post(
        updateLocale.url(),
        { locale: nextLocale },
        {
            preserveScroll: true,
            onSuccess: () => {
                locale.value = nextLocale;
                emit('changed', nextLocale);
            },
            onFinish: () => {
                pendingLocale.value = null;
            },
        },
    );
}
</script>

<template>
    <div
        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-1"
        data-test="locale-switcher"
    >
        <span
            v-if="showLabel"
            class="inline-flex items-center gap-1.5 pl-2 text-xs font-bold text-slate-500"
        >
            <Languages class="size-3.5" aria-hidden="true" />
            {{ t('public.common.language') }}
        </span>

        <div
            class="inline-flex items-center gap-1"
            role="group"
            :aria-label="t('public.common.language')"
        >
            <button
                v-for="language in languages"
                :key="language.code"
                type="button"
                class="erin-focus inline-flex min-h-8 items-center justify-center gap-1.5 rounded-lg px-2.5 text-xs font-black"
                :class="
                    locale === language.code
                        ? 'bg-white text-blue-700 shadow-sm'
                        : 'text-slate-500 hover:bg-white/70 hover:text-slate-900'
                "
                :aria-label="language.label"
                :aria-pressed="locale === language.code"
                :aria-busy="pendingLocale === language.code"
                :disabled="pendingLocale !== null"
                :data-test="`locale-${language.code}`"
                @click="chooseLocale(language.code)"
            >
                <span class="uppercase">{{ language.code }}</span>
                <span v-if="!compact" class="font-semibold">
                    {{ language.label }}
                </span>
            </button>
        </div>
    </div>
</template>
