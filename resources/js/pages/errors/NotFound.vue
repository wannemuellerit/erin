<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import NotFoundState from '@/components/errors/NotFoundState.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { home } from '@/routes';

defineProps<{
    status: number;
}>();

const { t } = useI18n();
const page = usePage();
const isAuthenticated = computed(() => Boolean(page.props.auth?.user));
const breadcrumbs = computed(() => [
    {
        title: t('errors.notFound.title'),
        href: home(),
    },
]);
</script>

<template>
    <Head :title="t('errors.notFound.title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <AppLayout v-if="isAuthenticated" :breadcrumbs="breadcrumbs">
        <NotFoundState />
    </AppLayout>
    <main v-else>
        <NotFoundState />
    </main>
</template>
