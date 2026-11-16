<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { home } from '@/routes';

const { t } = useI18n();
</script>

<template>
    <section
        class="relative isolate grid min-h-[calc(100svh-4rem)] place-items-center overflow-hidden px-5 py-10 sm:px-8"
        aria-labelledby="not-found-title"
    >
        <div
            class="absolute inset-0 -z-20 bg-[var(--background)]"
            aria-hidden="true"
        />
        <div
            class="absolute inset-0 -z-10 [background-image:radial-gradient(circle_at_1px_1px,rgb(148_163_184_/_24%)_1px,transparent_0)] [mask-image:linear-gradient(to_bottom,black,transparent_80%)] [background-size:24px_24px] opacity-50"
            aria-hidden="true"
        />
        <div
            class="absolute top-[8%] left-[8%] -z-10 size-80 rounded-full bg-blue-600/10 blur-3xl"
            aria-hidden="true"
        />
        <div
            class="absolute right-[8%] bottom-[8%] -z-10 size-72 rounded-full bg-teal-500/10 blur-3xl"
            aria-hidden="true"
        />

        <div
            class="w-full max-w-2xl rounded-[1.75rem] border border-border/90 bg-card/95 px-6 py-10 text-center shadow-[0_1px_2px_rgb(15_23_42_/_0.04),0_28px_80px_rgb(15_23_42_/_0.09)] backdrop-blur sm:px-12 sm:py-14"
        >
            <img
                src="/favicon.svg"
                :alt="t('errors.logoAlt')"
                class="mx-auto size-24 drop-shadow-[0_14px_24px_rgb(37_99_235_/_0.2)]"
            />
            <p
                class="mt-3 text-2xl font-extrabold tracking-[-0.04em] text-foreground"
                aria-hidden="true"
            >
                faden<span class="text-[var(--erin-primary-text)]">.</span>
            </p>
            <h1
                id="not-found-title"
                class="mt-8 text-3xl leading-tight font-extrabold tracking-[-0.04em] text-foreground sm:text-4xl"
            >
                {{ t('errors.notFound.title') }}
            </h1>
            <p
                class="mx-auto mt-4 max-w-xl text-base leading-7 text-muted-foreground sm:text-lg sm:leading-8"
            >
                {{ t('errors.notFound.message') }}
                {{ t('errors.notFound.returnPrefix') }}
                <Link
                    :href="home()"
                    class="erin-focus rounded-sm font-extrabold text-[var(--erin-primary-text)] underline decoration-2 underline-offset-4 hover:text-[var(--erin-primary-text-hover)]"
                >
                    {{ t('errors.notFound.returnLink') }} </Link
                >{{ t('errors.notFound.returnSuffix') }}
            </p>
        </div>
    </section>
</template>
