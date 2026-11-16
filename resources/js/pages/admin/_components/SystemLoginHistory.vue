<script setup lang="ts">
import { useAdminI18n } from '../_i18n';
import EmptyState from '@/components/product/EmptyState.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';

defineProps<{
    loginHistory: Array<{
        id: number;
        user_id: number | null;
        email: string;
        event: string;
        successful: boolean;
        ip_address: string | null;
        user_agent: string | null;
        failure_reason: string | null;
        created_at: string;
        user: {
            id: number;
            name: string;
            email: string;
        } | null;
    }>;
}>();

const { t, formatDate, humanize } = useAdminI18n();
</script>

<template>
    <SectionCard
        :title="t('system.login.title')"
        :description="t('system.login.description')"
        flush
    >
        <div v-if="loginHistory.length > 0" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-border text-left">
                <thead class="bg-muted/80">
                    <tr
                        class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        <th class="px-5 py-3">
                            {{ t('system.login.columns.account') }}
                        </th>
                        <th class="px-5 py-3">
                            {{ t('system.login.columns.event') }}
                        </th>
                        <th class="px-5 py-3">
                            {{ t('system.login.columns.result') }}
                        </th>
                        <th class="px-5 py-3">
                            {{ t('system.login.columns.network') }}
                        </th>
                        <th class="px-5 py-3 text-right">
                            {{ t('system.login.columns.time') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="entry in loginHistory"
                        :key="entry.id"
                        class="align-top"
                    >
                        <td class="px-5 py-4">
                            <p class="text-sm font-semibold text-foreground">
                                {{ entry.user?.name ?? entry.email }}
                            </p>
                            <p
                                v-if="entry.user"
                                class="mt-0.5 text-xs text-muted-foreground"
                            >
                                {{ entry.email }}
                            </p>
                        </td>
                        <td class="px-5 py-4 text-xs text-muted-foreground">
                            {{ humanize(entry.event) }}
                        </td>
                        <td class="px-5 py-4">
                            <StatusBadge
                                :label="
                                    entry.successful
                                        ? t('system.login.successful')
                                        : (entry.failure_reason ??
                                          t('system.login.failed'))
                                "
                                :tone="entry.successful ? 'green' : 'red'"
                            />
                        </td>
                        <td class="px-5 py-4 text-xs text-muted-foreground">
                            <p>{{ entry.ip_address ?? '—' }}</p>
                            <p
                                v-if="entry.user_agent"
                                class="mt-1 max-w-64 truncate text-muted-foreground"
                                :title="entry.user_agent"
                            >
                                {{ entry.user_agent }}
                            </p>
                        </td>
                        <td
                            class="px-5 py-4 text-right text-xs whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatDate(entry.created_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            :title="t('system.login.emptyTitle')"
            :description="t('system.login.emptyDescription')"
        />
    </SectionCard>
</template>
