<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Menu, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import LocaleSwitcher from '@/components/product/LocaleSwitcher.vue';
import { dashboard, home, login, pricing, register } from '@/routes';

const page = usePage();
const open = ref(false);
const { t } = useI18n();

const links = computed(() => [
    { label: t('public.nav.companies'), href: '/#unternehmen' },
    { label: t('public.nav.candidates'), href: '/#fachkraefte' },
    { label: t('public.nav.process'), href: '/#ablauf' },
    { label: t('public.nav.pricing'), href: pricing().url },
]);
</script>

<template>
    <header
        class="sticky top-0 z-50 border-b border-border/80 bg-card/90 backdrop-blur-xl"
    >
        <div
            class="mx-auto flex h-[72px] max-w-7xl items-center justify-between px-5 sm:px-6 lg:px-8"
        >
            <Link :href="home()" class="erin-focus flex items-center gap-2.5">
                <AppLogoIcon class="size-10" />
                <div>
                    <p
                        class="text-lg leading-none font-extrabold tracking-tight text-foreground"
                    >
                        faden<span class="text-[var(--erin-primary-text)]"
                            >.</span
                        >
                    </p>
                    <p
                        class="mt-1 text-[9px] font-bold tracking-[0.16em] text-muted-foreground uppercase"
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
                    class="erin-focus rounded-md text-sm font-semibold text-muted-foreground hover:text-[var(--erin-primary-text)]"
                >
                    {{ item.label }}
                </Link>
            </nav>

            <div class="hidden items-center gap-2 lg:flex">
                <LocaleSwitcher class="mr-1" compact />

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
                        class="erin-focus inline-flex h-10 items-center rounded-xl px-4 text-sm font-bold text-muted-foreground hover:bg-muted"
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

            <div
                class="flex min-w-0 flex-1 items-center justify-end gap-2 lg:hidden"
            >
                <LocaleSwitcher class="min-w-0" :show-label="false" compact />
                <button
                    type="button"
                    class="erin-focus grid size-10 place-items-center rounded-xl border border-border text-muted-foreground"
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
        </div>
        <div
            v-if="open"
            class="border-t border-border bg-card px-5 py-4 lg:hidden"
        >
            <nav class="grid gap-1" :aria-label="t('public.common.navigation')">
                <Link
                    v-for="item in links"
                    :key="item.href"
                    :href="item.href"
                    class="erin-focus rounded-lg px-3 py-2.5 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    @click="open = false"
                >
                    {{ item.label }}
                </Link>

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
                        class="erin-focus mt-2 rounded-xl border border-border px-3 py-2.5 text-center text-sm font-bold"
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
