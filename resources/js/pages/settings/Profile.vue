<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
/* @chisel-email-verification */
import { Link } from '@inertiajs/vue3';
/* @end-chisel-email-verification */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
/* @chisel-email-verification */
import { send } from '@/routes/verification';
/* @end-chisel-email-verification */

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'settings.profile.title',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const { t } = useI18n();
</script>

<template>
    <Head :title="t('settings.profile.title')" />

    <h1 class="sr-only">{{ t('settings.profile.title') }}</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            :title="t('settings.profile.heading')"
            :description="t('settings.profile.description')"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('settings.profile.name') }}</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    :placeholder="t('settings.profile.fullName')"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('settings.profile.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    :placeholder="t('settings.profile.email')"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <!-- @chisel-email-verification -->
            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    {{ t('settings.profile.unverified') }}
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {{ t('settings.profile.resend') }}
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    {{ t('settings.profile.verificationSent') }}
                </div>
            </div>
            <!-- @end-chisel-email-verification -->

            <div class="flex items-center gap-4">
                <Button
                    :disabled="processing"
                    data-test="update-profile-button"
                >
                    {{ t('settings.profile.save') }}
                </Button>
            </div>
        </Form>
    </div>

    <DeleteUser />
</template>
