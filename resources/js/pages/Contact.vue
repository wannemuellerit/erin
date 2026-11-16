<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Building2, Mail, MapPin, Phone } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import PublicHeader from '@/components/product/PublicHeader.vue';
import { contact as contactRoute, pricing } from '@/routes';
import legal from '@/routes/legal';

type ContactDetails = {
    available: boolean;
    email: string | null;
    phone: string | null;
    address: string | null;
};

defineProps<{
    contact: ContactDetails;
}>();

const { t } = useI18n();
</script>

<template>
    <Head :title="t('public.contact.metaTitle')">
        <meta
            v-if="!contact.available"
            name="robots"
            content="noindex,nofollow"
        />
    </Head>

    <div class="min-h-screen bg-muted text-foreground">
        <PublicHeader />

        <main class="mx-auto max-w-5xl px-5 py-14 sm:px-6 lg:px-8 lg:py-20">
            <div class="max-w-3xl">
                <p
                    class="text-xs font-bold tracking-[0.15em] text-[var(--erin-primary-text)] uppercase"
                >
                    {{ t('public.contact.eyebrow') }}
                </p>
                <h1
                    class="mt-3 text-4xl font-extrabold tracking-tight sm:text-5xl"
                >
                    {{ t('public.contact.title') }}
                </h1>
                <p class="mt-5 text-base leading-7 text-muted-foreground">
                    {{ t('public.contact.description') }}
                </p>
            </div>

            <div
                v-if="contact.available"
                class="mt-10 grid gap-5 md:grid-cols-3"
            >
                <a
                    v-if="contact.email"
                    :href="`mailto:${contact.email}`"
                    class="erin-focus rounded-2xl border border-border bg-card p-6 shadow-sm hover:border-blue-200 hover:shadow-md"
                >
                    <span
                        class="grid size-11 place-items-center rounded-xl bg-blue-50 text-[var(--erin-primary-text)]"
                    >
                        <Mail class="size-5" />
                    </span>
                    <p
                        class="mt-5 text-xs font-bold text-muted-foreground uppercase"
                    >
                        {{ t('public.contact.email') }}
                    </p>
                    <p class="mt-1 text-sm font-bold break-all text-foreground">
                        {{ contact.email }}
                    </p>
                </a>
                <a
                    v-if="contact.phone"
                    :href="`tel:${contact.phone.replace(/[^+\d]/g, '')}`"
                    class="erin-focus rounded-2xl border border-border bg-card p-6 shadow-sm hover:border-blue-200 hover:shadow-md"
                >
                    <span
                        class="grid size-11 place-items-center rounded-xl bg-teal-50 text-teal-600"
                    >
                        <Phone class="size-5" />
                    </span>
                    <p
                        class="mt-5 text-xs font-bold text-muted-foreground uppercase"
                    >
                        {{ t('public.contact.phone') }}
                    </p>
                    <p class="mt-1 text-sm font-bold text-foreground">
                        {{ contact.phone }}
                    </p>
                </a>
                <div
                    v-if="contact.address"
                    class="rounded-2xl border border-border bg-card p-6 shadow-sm"
                >
                    <span
                        class="grid size-11 place-items-center rounded-xl bg-orange-50 text-orange-600"
                    >
                        <MapPin class="size-5" />
                    </span>
                    <p
                        class="mt-5 text-xs font-bold text-muted-foreground uppercase"
                    >
                        {{ t('public.contact.address') }}
                    </p>
                    <p
                        class="mt-1 text-sm leading-6 font-bold whitespace-pre-wrap text-foreground"
                    >
                        {{ contact.address }}
                    </p>
                </div>
            </div>

            <article
                v-else
                class="mt-10 rounded-2xl border border-blue-200 bg-blue-50 p-6 sm:p-10"
            >
                <div
                    class="grid size-12 place-items-center rounded-2xl bg-card text-[var(--erin-primary-text)] shadow-sm"
                >
                    <Building2 class="size-6" />
                </div>
                <h2 class="mt-5 text-xl font-extrabold text-blue-950">
                    {{ t('public.contact.unavailableTitle') }}
                </h2>
                <p class="mt-3 max-w-2xl text-sm leading-7 text-blue-900/75">
                    {{ t('public.contact.unavailableText') }}
                </p>
            </article>

            <Link
                :href="pricing()"
                class="erin-focus mt-8 inline-flex items-center gap-2 rounded-lg text-sm font-bold text-[var(--erin-primary-text-hover)] hover:text-blue-800"
            >
                <ArrowLeft class="size-4" />
                {{ t('public.contact.backPricing') }}
            </Link>
        </main>

        <footer class="border-t border-border bg-card">
            <div
                class="mx-auto flex max-w-7xl flex-col gap-4 px-5 py-8 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
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
                        class="erin-focus rounded hover:text-[var(--erin-primary-text)]"
                    >
                        {{ t('public.common.privacy') }}
                    </Link>
                    <Link
                        :href="legal.imprint()"
                        class="erin-focus rounded hover:text-[var(--erin-primary-text)]"
                    >
                        {{ t('public.common.imprint') }}
                    </Link>
                    <Link
                        :href="legal.terms()"
                        class="erin-focus rounded hover:text-[var(--erin-primary-text)]"
                    >
                        {{ t('public.common.terms') }}
                    </Link>
                    <Link
                        :href="contactRoute()"
                        class="erin-focus rounded hover:text-[var(--erin-primary-text)]"
                    >
                        {{ t('public.common.contact') }}
                    </Link>
                </nav>
            </div>
        </footer>
    </div>
</template>
