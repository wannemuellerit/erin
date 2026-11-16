<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Handshake } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '@/components/product/PageHeader.vue';
import SectionCard from '@/components/product/SectionCard.vue';
import StatusBadge from '@/components/product/StatusBadge.vue';

type Organization = {
    id: number;
    name: string;
    category: string;
    legal_approved_at?: string;
    blocked_at?: string;
    members_count: number;
    offerings_count: number;
};
type Rule = {
    id: number;
    country_code: string;
    service_type: string;
    status: string;
    version: number;
    approved_at?: string;
};
defineProps<{
    organizations: Organization[];
    rules: Rule[];
    cases: Array<{ service_type: string; status: string; total: number }>;
    serviceTypes: string[];
    countryOperations: string[];
}>();
const { t } = useI18n();
const organizationForm = useForm({
    name: '',
    category: 'language_course',
    country_codes: ['DE'],
    service_types: ['language_course'],
    languages: ['de', 'en'],
    integration_mode: 'manual',
});
const ruleForm = useForm({
    country_code: 'DE',
    service_type: 'language_course',
    status: 'disabled',
    currency_code: 'EUR',
    tax_model: '',
    legal_basis: '',
    requirements: {},
    approved: false,
});
</script>

<template>
    <Head :title="t('partnersAdmin.title')" />
    <div class="erin-page space-y-6">
        <PageHeader
            :eyebrow="t('partnersAdmin.eyebrow')"
            :title="t('partnersAdmin.title')"
            :description="t('partnersAdmin.description')"
            :icon="Handshake"
        />
        <SectionCard :title="t('partnersAdmin.organizations')">
            <div class="space-y-2">
                <div
                    v-for="organization in organizations"
                    :key="organization.id"
                    class="flex items-center justify-between rounded-xl border p-3"
                >
                    <div>
                        <strong>{{ organization.name }}</strong>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t('partnersAdmin.organizationCounts', {
                                    category: organization.category,
                                    members: organization.members_count,
                                    offerings: organization.offerings_count,
                                })
                            }}
                        </p>
                    </div>
                    <StatusBadge
                        :label="
                            organization.blocked_at
                                ? 'blocked'
                                : organization.legal_approved_at
                                  ? 'approved'
                                  : 'pending'
                        "
                        :tone="
                            organization.blocked_at
                                ? 'red'
                                : organization.legal_approved_at
                                  ? 'green'
                                  : 'orange'
                        "
                    />
                </div>
            </div>
            <form
                class="mt-4 grid gap-3 md:grid-cols-3"
                @submit.prevent="
                    organizationForm.post('/admin/partners/organizations')
                "
            >
                <input
                    v-model="organizationForm.name"
                    class="erin-input"
                    :placeholder="t('partnersAdmin.name')"
                    required
                />
                <select v-model="organizationForm.category" class="erin-input">
                    <option v-for="type in serviceTypes" :key="type">
                        {{ type }}
                    </option>
                </select>
                <button class="erin-button erin-button-primary">
                    {{ t('partnersAdmin.createDraft') }}
                </button>
            </form>
        </SectionCard>
        <SectionCard :title="t('partnersAdmin.countryMatrix')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr>
                            <th>{{ t('partnersAdmin.country') }}</th>
                            <th>{{ t('partnersAdmin.service') }}</th>
                            <th>{{ t('partnersAdmin.status') }}</th>
                            <th>{{ t('partnersAdmin.version') }}</th>
                            <th>{{ t('partnersAdmin.approval') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="rule in rules" :key="rule.id">
                            <td>{{ rule.country_code }}</td>
                            <td>{{ rule.service_type }}</td>
                            <td>{{ rule.status }}</td>
                            <td>
                                {{
                                    t('partnersAdmin.versionValue', {
                                        version: rule.version,
                                    })
                                }}
                            </td>
                            <td>
                                {{
                                    rule.approved_at
                                        ? t('partnersAdmin.yes')
                                        : t('partnersAdmin.no')
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <form
                class="mt-4 grid gap-3 md:grid-cols-4"
                @submit.prevent="ruleForm.post('/admin/partners/country-rules')"
            >
                <input
                    v-model="ruleForm.country_code"
                    class="erin-input uppercase"
                    maxlength="2"
                    required
                />
                <select v-model="ruleForm.service_type" class="erin-input">
                    <option v-for="type in countryOperations" :key="type">
                        {{ type }}
                    </option>
                </select>
                <select v-model="ruleForm.status" class="erin-input">
                    <option value="disabled">
                        {{ t('partnersAdmin.statuses.disabled') }}
                    </option>
                    <option value="pilot">
                        {{ t('partnersAdmin.statuses.pilot') }}
                    </option>
                    <option value="enabled">
                        {{ t('partnersAdmin.statuses.enabled') }}
                    </option>
                </select>
                <input
                    v-model="ruleForm.legal_basis"
                    class="erin-input"
                    :placeholder="t('partnersAdmin.legalBasis')"
                />
                <label class="flex items-center gap-2"
                    ><input v-model="ruleForm.approved" type="checkbox" />
                    {{ t('partnersAdmin.legallyApproved') }}</label
                >
                <button class="erin-button erin-button-primary">
                    {{ t('partnersAdmin.newRuleVersion') }}
                </button>
            </form>
        </SectionCard>
    </div>
</template>
