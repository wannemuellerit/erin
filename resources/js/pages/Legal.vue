<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileWarning, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import PublicHeader from '@/components/product/PublicHeader.vue';
import { contact, home } from '@/routes';
import legal from '@/routes/legal';

type LegalDocument = 'privacy' | 'imprint' | 'terms';

const props = defineProps<{
    document: LegalDocument;
    published: boolean;
    content: string | null;
}>();

const { t } = useI18n();
const titleKeys: Record<LegalDocument, string> = {
    privacy: 'public.legal.privacyTitle',
    imprint: 'public.legal.imprintTitle',
    terms: 'public.legal.termsTitle',
};
const title = computed(() => t(titleKeys[props.document]));
</script>

<template>
    <Head :title="title">
        <meta v-if="!published" name="robots" content="noindex,nofollow" />
    </Head>

    <div class="min-h-screen bg-slate-50 text-slate-950">
        <PublicHeader />

        <main class="mx-auto max-w-4xl px-5 py-14 sm:px-6 lg:px-8 lg:py-20">
            <div>
                <p
                    class="text-xs font-bold tracking-[0.15em] text-blue-600 uppercase"
                >
                    {{ t('public.legal.eyebrow') }}
                </p>
                <h1
                    class="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl"
                >
                    {{ title }}
                </h1>
            </div>

            <article
                v-if="published && content"
                class="mt-10 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10"
            >
                <div
                    class="mb-6 flex items-center gap-2 text-xs font-bold text-teal-700"
                >
                    <ShieldCheck class="size-4" />
                    {{ t('public.legal.publishedLabel') }}
                </div>
                <div
                    class="text-sm leading-7 whitespace-pre-wrap text-slate-700 sm:text-base sm:leading-8"
                >
                    {{ content }}
                </div>
            </article>

            <article
                v-else
                class="mt-10 rounded-2xl border border-amber-200 bg-amber-50 p-6 sm:p-10"
            >
                <div
                    class="grid size-12 place-items-center rounded-2xl bg-white text-amber-600 shadow-sm"
                >
                    <FileWarning class="size-6" />
                </div>
                <h2 class="mt-5 text-xl font-extrabold text-amber-950">
                    {{ t('public.legal.unpublishedTitle') }}
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-amber-900/80">
                    {{ t('public.legal.unpublishedText') }}
                </p>
                <p class="mt-3 max-w-2xl text-xs leading-6 text-amber-800/70">
                    {{ t('public.legal.unpublishedHint') }}
                </p>
            </article>

            <Link
                :href="home()"
                class="erin-focus mt-8 inline-flex items-center gap-2 rounded-lg text-sm font-bold text-blue-700 hover:text-blue-800"
            >
                <ArrowLeft class="size-4" />
                {{ t('public.legal.backHome') }}
            </Link>
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
            >
                <p>
                    {{
                        t('public.common.copyright', {
                            year: new Date().getFullYear(),
                        })
                    }}
                </p>
                <nav
                    class="flex flex-wrap gap-x-5 gap-y-2"
                    :aria-label="t('public.legal.eyebrow')"
                >
                    <Link
                        :href="legal.privacy()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.privacy') }}
                    </Link>
                    <Link
                        :href="legal.imprint()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.imprint') }}
                    </Link>
                    <Link
                        :href="legal.terms()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.terms') }}
                    </Link>
                    <Link
                        :href="contact()"
                        class="erin-focus rounded hover:text-blue-600"
                    >
                        {{ t('public.common.contact') }}
                    </Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
