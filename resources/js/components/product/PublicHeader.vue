<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Languages, Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { dashboard, home, login, pricing, register } from '@/routes';
import { update as updateLocale } from '@/routes/locale';
import type { SupportedLocale } from '@/i18n';
import { normalizeLocale } from '@/i18n';

type PublicPlatform = {
    locale?: string | null;
};

type PublicAuth = {
    user?: {
        locale?: string | null;
    } | null;
};

const page = usePage();
const open = ref(false);
const { locale, t } = useI18n();
const serverLocale = normalizeLocale(
    (page.props.auth as PublicAuth | undefined)?.user?.locale ??
        (page.props.platform as PublicPlatform | undefined)?.locale,
);

locale.value = serverLocale;

const links = computed(() => [
    { label: t('public.nav.companies'), href: '/#unternehmen' },
    { label: t('public.nav.candidates'), href: '/#fachkraefte' },
    { label: t('public.nav.process'), href: '/#ablauf' },
    { label: t('public.nav.pricing'), href: pricing().url },
]);

function chooseLocale(nextLocale: SupportedLocale): void {
    if (locale.value === nextLocale) {
        return;
    }

    router.post(
        updateLocale.url(),
        { locale: nextLocale },
        {
            preserveScroll: true,
            onSuccess: () => {
                locale.value = nextLocale;
                open.value = false;
            },
        },
    );
}
</script>

<template>
    <header
        class="sticky top-0 z-50 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl"
    >
        <div
            class="mx-auto flex h-[72px] max-w-7xl items-center justify-between px-5 sm:px-6 lg:px-8"
        >
            <Link :href="home()" class="erin-focus flex items-center gap-2.5">
                <AppLogoIcon class="size-10" />
                <div>
                    <p
                        class="text-lg leading-none font-extrabold tracking-tight text-slate-950"
                    >
                        erin<span class="text-blue-600">.</span>
                    </p>
                    <p
                        class="mt-1 text-[9px] font-bold tracking-[0.16em] text-slate-400 uppercase"
                    >
                        {{ t('public.common.recruitingOs') }}
                    </p>
                </div>
            </Link>

            <nav
                class="hidden items-center gap-7 lg:flex"
                :aria-label="t('public.common.navigation')"
            >
                <Link
                    v-for="item in links"
                    :key="item.href"
                    :href="item.href"
                    class="erin-focus rounded-md text-sm font-semibold text-slate-600 hover:text-blue-600"
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div class="hidden items-center gap-2 sm:flex">
                <div
                    class="mr-1 inline-flex items-center rounded-xl border border-slate-200 bg-slate-50 p-1"
                    role="group"
                    :aria-label="t('public.common.language')"
                >
                    <Languages
                        class="mx-1 size-3.5 text-slate-400"
                        aria-hidden="true"
                    />
                    <button
                        v-for="language in [
                            {
                                code: 'de' as const,
                                label: t('public.common.german'),
                            },
                            {
                                code: 'en' as const,
                                label: t('public.common.english'),
                            },
                        ]"
                        :key="language.code"
                        type="button"
                        class="erin-focus rounded-lg px-2 py-1.5 text-[11px] font-black uppercase"
                        :class="
                            locale === language.code
                                ? 'bg-white text-blue-700 shadow-sm'
                                : 'text-slate-400 hover:text-slate-700'
                        "
                        :aria-pressed="locale === language.code"
                        :title="language.label"
                        @click="chooseLocale(language.code)"
                    >
                        {{ language.code }}
                    </button>
                </div>

                <Link
                    v-if="page.props.auth?.user"
                    :href="dashboard()"
                    class="erin-focus inline-flex h-10 items-center rounded-xl bg-blue-600 px-5 text-sm font-bold text-white hover:bg-blue-700"
                >
                    {{ t('public.common.dashboard') }}
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="erin-focus inline-flex h-10 items-center rounded-xl px-4 text-sm font-bold text-slate-600 hover:bg-slate-100"
                    >
                        {{ t('public.common.signIn') }}
                    </Link>
                    <!-- @chisel-registration -->
                    <Link
                        :href="register()"
                        class="erin-focus inline-flex h-10 items-center rounded-xl bg-blue-600 px-5 text-sm font-bold text-white shadow-lg shadow-blue-600/15 hover:bg-blue-700"
                    >
                        {{ t('public.common.startFree') }}
                    </Link>
                    <!-- @end-chisel-registration -->
                </template>
            </div>

            <button
                type="button"
                class="erin-focus grid size-10 place-items-center rounded-xl border border-slate-200 text-slate-600 sm:hidden"
                :aria-label="
                    open
                        ? t('public.common.menuClose')
                        : t('public.common.menuOpen')
                "
                :aria-expanded="open"
                @click="open = !open"
            >
                <X v-if="open" class="size-5" />
                <Menu v-else class="size-5" />
            </button>
        </div>
        <div
            v-if="open"
            class="border-t border-slate-100 bg-white px-5 py-4 sm:hidden"
        >
            <nav class="grid gap-1" :aria-label="t('public.common.navigation')">
                <Link
                    v-for="item in links"
                    :key="item.href"
                    :href="item.href"
                    class="erin-focus rounded-lg px-3 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                    @click="open = false"
                >
                    {{ item.label }}
                </Link>

                <div
                    class="my-2 flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2"
                >
                    <span
                        class="inline-flex items-center gap-2 text-xs font-bold text-slate-500"
                    >
                        <Languages class="size-4" />
                        {{ t('public.common.language') }}
                    </span>
                    <div class="flex gap-1">
                        <button
                            v-for="language in [
                                {
                                    code: 'de' as const,
                                    label: t('public.common.german'),
                                },
                                {
                                    code: 'en' as const,
                                    label: t('public.common.english'),
                                },
                            ]"
                            :key="language.code"
                            type="button"
                            class="erin-focus rounded-lg px-2.5 py-1.5 text-[11px] font-black uppercase"
                            :class="
                                locale === language.code
                                    ? 'bg-white text-blue-700 shadow-sm'
                                    : 'text-slate-400'
                            "
                            :aria-label="language.label"
                            :aria-pressed="locale === language.code"
                            @click="chooseLocale(language.code)"
                        >
                            {{ language.code }}
                        </button>
                    </div>
                </div>

                <Link
                    v-if="page.props.auth?.user"
                    :href="dashboard()"
                    class="erin-focus mt-2 rounded-xl bg-blue-600 px-3 py-2.5 text-center text-sm font-bold text-white"
                    @click="open = false"
                >
                    {{ t('public.common.dashboard') }}
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="erin-focus mt-2 rounded-xl border border-slate-200 px-3 py-2.5 text-center text-sm font-bold"
                        @click="open = false"
                    >
                        {{ t('public.common.signIn') }}
                    </Link>
                    <Link
                        :href="register()"
                        class="erin-focus rounded-xl bg-blue-600 px-3 py-2.5 text-center text-sm font-bold text-white"
                        @click="open = false"
                    >
                        {{ t('public.common.startFree') }}
                    </Link>
                </template>
            </nav>
        </div>
    </header>
</template>
