import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type CapabilityAuth = {
    capabilities?: string[];
};

export function useCapabilities() {
    const page = usePage();
    const capabilities = computed(
        () =>
            (page.props.auth as CapabilityAuth | undefined)?.capabilities ?? [],
    );
    const can = (capability: string) => capabilities.value.includes(capability);

    return { capabilities, can };
}
