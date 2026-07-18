<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { watchEffect } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { useFormatters } from '@/composables/useFormatters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

defineProps<{
    email: string;
    token: string;
    submitUrl: string;
    expiresAt: string;
    passwordRules: string;
}>();

const { t } = useI18n();
const { formatDate } = useFormatters();

watchEffect(() => {
    setLayoutProps({
        title: t('auth.bootstrapAdmin.title'),
        description: t('auth.bootstrapAdmin.description'),
    });
});
</script>

<template>
    <Head :title="t('auth.bootstrapAdmin.metaTitle')" />

    <div
        class="mb-6 flex items-start gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 text-sm text-blue-950"
    >
        <ShieldCheck class="mt-0.5 size-5 shrink-0 text-blue-600" />
        <div>
            <p class="font-bold">{{ t('auth.bootstrapAdmin.secureTitle') }}</p>
            <p class="mt-1 leading-6 text-blue-800">
                {{
                    t('auth.bootstrapAdmin.expires', {
                        date: formatDate(expiresAt, {
                            dateStyle: 'long',
                            timeStyle: 'short',
                        }),
                    })
                }}
            </p>
        </div>
    </div>

    <Form
        :action="submitUrl"
        method="post"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
        class="grid gap-6"
    >
        <input type="hidden" name="token" :value="token" />

        <div class="grid gap-2">
            <Label for="email">{{ t('auth.email') }}</Label>
            <Input id="email" :model-value="email" readonly />
        </div>

        <div class="grid gap-2">
            <Label for="password">{{
                t('auth.bootstrapAdmin.password')
            }}</Label>
            <PasswordInput
                id="password"
                name="password"
                autocomplete="new-password"
                autofocus
                :passwordrules="passwordRules"
                :placeholder="t('auth.bootstrapAdmin.password')"
            />
            <InputError :message="errors.password" />
        </div>

        <div class="grid gap-2">
            <Label for="password_confirmation">
                {{ t('auth.confirmPassword') }}
            </Label>
            <PasswordInput
                id="password_confirmation"
                name="password_confirmation"
                autocomplete="new-password"
                :passwordrules="passwordRules"
                :placeholder="t('auth.confirmPassword')"
            />
            <InputError :message="errors.password_confirmation" />
        </div>

        <Button type="submit" class="h-11 w-full" :disabled="processing">
            <Spinner v-if="processing" />
            {{ t('auth.bootstrapAdmin.submit') }}
        </Button>
    </Form>
</template>
