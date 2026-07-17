<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { AdminPaginator } from '../_shared';
import { formatNumber } from '../_shared';

defineProps<{
    paginator: AdminPaginator<unknown>;
}>();
</script>

<template>
    <nav
        v-if="paginator.last_page > 1 || paginator.total > 0"
        class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
        aria-label="Seitennavigation"
    >
        <p class="text-xs text-slate-500">
            <template v-if="paginator.total > 0">
                {{ formatNumber(paginator.from) }}–{{
                    formatNumber(paginator.to)
                }}
                von {{ formatNumber(paginator.total) }}
            </template>
            <template v-else>Keine Einträge</template>
        </p>

        <div
            v-if="paginator.last_page > 1"
            class="flex flex-wrap items-center gap-1"
        >
            <template
                v-for="(link, index) in paginator.links"
                :key="`${link.label}-${index}`"
            >
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="erin-focus inline-flex min-h-9 min-w-9 items-center justify-center rounded-lg border px-2.5 text-xs font-bold transition"
                    :class="
                        link.active
                            ? 'border-blue-600 bg-blue-600 text-white'
                            : 'border-slate-200 bg-white text-slate-600 hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700'
                    "
                >
                    <span v-html="link.label" />
                </Link>
                <span
                    v-else
                    class="inline-flex min-h-9 min-w-9 cursor-not-allowed items-center justify-center rounded-lg border border-slate-100 px-2.5 text-xs font-bold text-slate-300"
                >
                    <span v-html="link.label" />
                </span>
            </template>
        </div>
    </nav>
</template>
