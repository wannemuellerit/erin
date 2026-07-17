<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Globe2,
    ShieldCheck,
    Sparkles,
    UsersRound,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const advantages = [
    { icon: UsersRound, key: 'auth.advantages.crossBorder' },
    { icon: Sparkles, key: 'auth.advantages.explainable' },
    { icon: ShieldCheck, key: 'auth.advantages.operations' },
];

const { t } = useI18n();
const copyrightYear = computed(() => new Date().getFullYear());
</script>

<template>
    <div
        class="grid min-h-svh bg-white lg:grid-cols-[minmax(0,1fr)_minmax(32rem,0.82fr)]"
    >
        <aside
            class="relative hidden overflow-hidden bg-[#0F2854] p-10 text-white lg:flex lg:flex-col xl:p-14"
        >
            <div class="erin-grid absolute inset-0 opacity-30" />
            <div
                class="absolute -top-40 -right-44 size-[34rem] rounded-full bg-blue-500/25 blur-3xl"
            />
            <div
                class="absolute -bottom-56 -left-40 size-[32rem] rounded-full bg-teal-400/20 blur-3xl"
            />

            <Link :href="home()" class="relative z-10 flex items-center gap-3">
                <AppLogoIcon class="size-11" />
                <div>
                    <p class="text-xl font-extrabold tracking-tight">
                        erin<span class="text-orange-400">.</span>
                    </p>
                    <p
                        class="text-[10px] font-bold tracking-[0.16em] text-blue-200 uppercase"
                    >
                        Recruiting OS
                    </p>
                </div>
            </Link>

            <div class="relative z-10 my-auto max-w-xl py-16">
                <div
                    class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-xs font-semibold text-blue-100 backdrop-blur"
                >
                    <Globe2 class="size-3.5 text-teal-300" />
                    {{ t('auth.borderlessRecruiting') }}
                </div>
                <h2
                    class="text-4xl leading-[1.12] font-extrabold tracking-tight xl:text-5xl"
                >
                    {{ t('auth.connectPeople') }}<br />
                    <span class="text-teal-300">
                        {{ t('auth.shapeFuture') }}
                    </span>
                </h2>
                <p class="mt-5 max-w-lg text-base leading-7 text-blue-100/80">
                    {{ t('auth.marketingDescription') }}
                </p>

                <ul class="mt-9 space-y-4">
                    <li
                        v-for="advantage in advantages"
                        :key="advantage.key"
                        class="flex items-center gap-3 text-sm font-medium text-white/90"
                    >
                        <span
                            class="grid size-9 place-items-center rounded-xl bg-white/10 text-teal-300 ring-1 ring-white/10"
                        >
                            <component
                                :is="advantage.icon"
                                class="size-[18px]"
                            />
                        </span>
                        {{ t(advantage.key) }}
                    </li>
                </ul>
            </div>

            <div
                class="relative z-10 flex items-center gap-4 text-xs text-blue-200/70"
            >
                <span class="inline-flex items-center gap-1.5">
                    <CheckCircle2 class="size-3.5 text-teal-300" />
                    {{ t('auth.gdpr') }}
                </span>
                <span>•</span>
                <span>{{ t('auth.madeInGermany') }}</span>
            </div>
        </aside>

        <main class="relative flex min-h-svh flex-col bg-white">
            <div class="flex items-center justify-between px-5 py-5 sm:px-8">
                <Link
                    :href="home()"
                    class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500 hover:text-blue-600"
                >
                    <ArrowLeft class="size-4" />
                    {{ t('auth.backHome') }}
                </Link>
                <div class="flex items-center gap-2 lg:hidden">
                    <AppLogoIcon class="size-8" />
                    <span class="font-extrabold text-slate-950"
                        >erin<span class="text-blue-600">.</span></span
                    >
                </div>
            </div>

            <div
                class="flex flex-1 items-center justify-center px-5 py-8 sm:px-8"
            >
                <div class="w-full max-w-[27rem]">
                    <div class="mb-8">
                        <p
                            class="mb-2 text-xs font-bold tracking-[0.12em] text-[var(--erin-primary,#2563EB)] uppercase"
                        >
                            {{ t('auth.welcomeEyebrow') }}
                        </p>
                        <h1
                            v-if="title"
                            class="text-3xl font-extrabold tracking-tight text-slate-950"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="mt-2 text-sm leading-6 text-slate-500"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>

            <div class="px-8 py-5 text-center text-[11px] text-slate-400">
                © {{ copyrightYear }} Erin · {{ t('auth.privacy') }} ·
                {{ t('auth.imprint') }}
            </div>
        </main>
    </div>
</template>
