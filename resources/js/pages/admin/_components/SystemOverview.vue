<script setup lang="ts">
import {
    Database,
    KeyRound,
    Mail,
    Server,
    ShieldCheck,
    Webhook,
} from '@lucide/vue';
import MetricCard from '@/components/product/MetricCard.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';
import { useAdminI18n } from '../_i18n';

defineProps<{
    runtime: {
        php: string;
        laravel: string;
        environment: string;
        debug: boolean;
        queue_connection: string;
        failed_jobs: number;
    };
    integrations: {
        stripe: boolean;
        openai: boolean;
        livekit: boolean;
        recent_failed_webhooks: number;
    };
    gdpr: {
        open: number;
        overdue: number;
    };
    governance: {
        access_list_entries: number;
        email_templates: number;
    };
}>();

const { t } = useAdminI18n();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
            :label="t('system.metrics.failedJobs')"
            :value="runtime.failed_jobs"
            :hint="
                t('system.metrics.queue', {
                    connection: runtime.queue_connection,
                })
            "
            :icon="Database"
            tone="orange"
        />
        <MetricCard
            :label="t('system.metrics.gdprOpen')"
            :value="gdpr.open"
            :hint="t('system.metrics.overdue', { count: gdpr.overdue })"
            :icon="ShieldCheck"
            tone="teal"
        />
        <MetricCard
            :label="t('system.metrics.accessList')"
            :value="governance.access_list_entries"
            :icon="KeyRound"
        />
        <MetricCard
            :label="t('system.metrics.emailTemplates')"
            :value="governance.email_templates"
            :icon="Mail"
            tone="violet"
        />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <SectionCard
            :title="t('system.runtime.title')"
            :description="t('system.runtime.description')"
        >
            <dl class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-[10px] font-bold text-slate-600 uppercase">
                        Laravel
                    </dt>
                    <dd class="mt-1 font-mono text-sm font-bold text-slate-800">
                        {{ runtime.laravel }}
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-[10px] font-bold text-slate-600 uppercase">
                        PHP
                    </dt>
                    <dd class="mt-1 font-mono text-sm font-bold text-slate-800">
                        {{ runtime.php }}
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-[10px] font-bold text-slate-600 uppercase">
                        {{ t('system.runtime.environment') }}
                    </dt>
                    <dd class="mt-1 text-sm font-bold text-slate-800">
                        {{ runtime.environment }}
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-50 p-4">
                    <dt class="text-[10px] font-bold text-slate-600 uppercase">
                        {{ t('system.runtime.debugMode') }}
                    </dt>
                    <dd class="mt-2">
                        <StatusBadge
                            :label="
                                runtime.debug
                                    ? t('common.active')
                                    : t('common.inactive')
                            "
                            :tone="runtime.debug ? 'yellow' : 'green'"
                        />
                    </dd>
                </div>
            </dl>
        </SectionCard>

        <SectionCard
            :title="t('system.integrations.title')"
            :description="t('system.integrations.description')"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div
                    v-for="integration in [
                        {
                            name: t('system.integrations.stripe'),
                            configured: integrations.stripe,
                        },
                        {
                            name: t('system.integrations.openai'),
                            configured: integrations.openai,
                        },
                        {
                            name: t('system.integrations.livekit'),
                            configured: integrations.livekit,
                        },
                    ]"
                    :key="integration.name"
                    class="flex items-center justify-between rounded-xl border border-slate-200 p-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="grid size-9 place-items-center rounded-xl bg-blue-50 text-blue-600"
                        >
                            <Server class="size-4" />
                        </span>
                        <span class="text-sm font-bold text-slate-800">
                            {{ integration.name }}
                        </span>
                    </div>
                    <StatusBadge
                        :label="
                            integration.configured
                                ? t('system.integrations.configured')
                                : t('system.integrations.missing')
                        "
                        :tone="integration.configured ? 'green' : 'slate'"
                    />
                </div>
                <div
                    class="flex items-center justify-between rounded-xl border border-slate-200 p-4"
                >
                    <div class="flex items-center gap-3">
                        <span
                            class="grid size-9 place-items-center rounded-xl bg-orange-50 text-orange-600"
                        >
                            <Webhook class="size-4" />
                        </span>
                        <span class="text-sm font-bold text-slate-800">
                            {{ t('system.integrations.webhookErrors') }}
                        </span>
                    </div>
                    <StatusBadge
                        :label="integrations.recent_failed_webhooks.toString()"
                        :tone="
                            integrations.recent_failed_webhooks > 0
                                ? 'red'
                                : 'green'
                        "
                    />
                </div>
            </div>
        </SectionCard>
    </div>
</template>
