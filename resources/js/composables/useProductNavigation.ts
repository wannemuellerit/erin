import { usePage } from '@inertiajs/vue3';
import {
    Activity,
    BriefcaseBusiness,
    Building2,
    CalendarDays,
    CreditCard,
    FileCheck2,
    Gift,
    LayoutDashboard,
    LifeBuoy,
    ListChecks,
    MessageSquare,
    Plane,
    ReceiptText,
    ScrollText,
    Search,
    Settings2,
    ShieldCheck,
    Sparkles,
    UserRound,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { ProductNavGroup, ProductRole, User } from '@/types';

type ProductUser = User & {
    role?: string;
    persona?: string;
};

const roleAliases: Record<string, ProductRole> = {
    candidate: 'candidate',
    fachkraft: 'candidate',
    company_owner: 'employer',
    company_admin: 'employer',
    recruiter: 'employer',
    viewer: 'employer',
    employer: 'employer',
    support: 'support',
    support_agent: 'support',
    admin: 'super_admin',
    superadmin: 'super_admin',
    super_admin: 'super_admin',
};

export function useProductNavigation() {
    const page = usePage();
    const { t } = useI18n();

    const role = computed<ProductRole>(() => {
        const user = page.props.auth?.user as ProductUser | undefined;
        const rawRole = user?.role ?? user?.persona;

        if (rawRole && roleAliases[rawRole]) {
            return roleAliases[rawRole];
        }

        return 'employer';
    });

    const employerNavigation = computed<ProductNavGroup[]>(() => [
        {
            items: [
                {
                    label: t('nav.overview'),
                    href: '/dashboard',
                    icon: LayoutDashboard,
                },
                {
                    label: t('nav.candidates'),
                    href: '/employer/candidates',
                    icon: Search,
                },
                {
                    label: t('nav.jobs'),
                    href: '/employer/jobs',
                    icon: BriefcaseBusiness,
                },
                {
                    label: t('nav.applications'),
                    href: '/employer/pipeline',
                    icon: ListChecks,
                },
                {
                    label: t('operations.nav.analytics'),
                    href: '/employer/analytics',
                    icon: Activity,
                },
            ],
        },
        {
            label: t('nav.groups.communication'),
            items: [
                {
                    label: t('nav.messages'),
                    href: '/employer/messages',
                    icon: MessageSquare,
                },
                {
                    label: t('nav.interviews'),
                    href: '/employer/interviews',
                    icon: CalendarDays,
                },
                {
                    label: t('operations.nav.productivity'),
                    href: '/employer/productivity',
                    icon: ListChecks,
                },
                {
                    label: t('operations.nav.support'),
                    href: '/support',
                    icon: LifeBuoy,
                },
            ],
        },
        {
            label: t('nav.groups.organization'),
            items: [
                {
                    label: t('nav.visa'),
                    href: '/employer/visa',
                    icon: Plane,
                },
                {
                    label: t('nav.referrals'),
                    href: '/employer/referrals',
                    icon: Gift,
                },
                {
                    label: t('nav.company'),
                    href: '/employer/company',
                    icon: Building2,
                },
                {
                    label: t('nav.team'),
                    href: '/employer/team',
                    icon: Users,
                },
                {
                    label: t('nav.billing'),
                    href: '/employer/billing',
                    icon: CreditCard,
                },
            ],
        },
    ]);

    const candidateNavigation = computed<ProductNavGroup[]>(() => [
        {
            items: [
                {
                    label: t('nav.overview'),
                    href: '/dashboard',
                    icon: LayoutDashboard,
                },
                {
                    label: t('nav.matchingJobs'),
                    href: '/candidate/jobs',
                    icon: BriefcaseBusiness,
                },
                {
                    label: t('nav.companies'),
                    href: '/candidate/companies',
                    icon: Building2,
                },
                {
                    label: t('nav.applications'),
                    href: '/candidate/applications',
                    icon: ListChecks,
                },
                {
                    label: t('nav.myProfile'),
                    href: '/candidate/profile',
                    icon: UserRound,
                },
            ],
        },
        {
            label: t('nav.groups.communication'),
            items: [
                {
                    label: t('nav.messages'),
                    href: '/candidate/messages',
                    icon: MessageSquare,
                },
                {
                    label: t('nav.interviews'),
                    href: '/candidate/interviews',
                    icon: CalendarDays,
                },
            ],
        },
        {
            label: t('nav.groups.more'),
            items: [
                {
                    label: t('nav.aiStudio'),
                    href: '/candidate/ai-studio',
                    icon: Sparkles,
                },
                {
                    label: t('nav.referrals'),
                    href: '/candidate/referrals',
                    icon: Gift,
                },
                {
                    label: t('operations.nav.support'),
                    href: '/support',
                    icon: LifeBuoy,
                },
            ],
        },
    ]);

    const adminNavigation = computed<ProductNavGroup[]>(() => [
        {
            items: [
                {
                    label: t('nav.overview'),
                    href: '/admin',
                    icon: LayoutDashboard,
                },
                {
                    label: t('nav.users'),
                    href: '/admin/users',
                    icon: Users,
                },
                {
                    label: t('nav.companies'),
                    href: '/admin/companies',
                    icon: Building2,
                },
                {
                    label: t('nav.documentReview'),
                    href: '/admin/documents',
                    icon: FileCheck2,
                },
                {
                    label: t('nav.visaCases'),
                    href: '/admin/visa',
                    icon: Plane,
                },
                {
                    label: t('nav.support'),
                    href: '/admin/support',
                    icon: LifeBuoy,
                },
            ],
        },
        {
            label: t('nav.groups.platform'),
            items: [
                {
                    label: t('nav.billing'),
                    href: '/admin/billing',
                    icon: ReceiptText,
                },
                {
                    label: t('nav.referrals'),
                    href: '/admin/referrals',
                    icon: Gift,
                },
                {
                    label: t('nav.auditLog'),
                    href: '/admin/audit',
                    icon: ScrollText,
                },
                {
                    label: t('nav.system'),
                    href: '/admin/system',
                    icon: Activity,
                },
                {
                    label: t('nav.settings'),
                    href: '/admin/settings',
                    icon: Settings2,
                },
            ],
        },
    ]);

    const supportNavigation = computed<ProductNavGroup[]>(() => [
        {
            items: [
                {
                    label: t('nav.overview'),
                    href: '/admin',
                    icon: LayoutDashboard,
                },
                {
                    label: t('nav.support'),
                    href: '/admin/support',
                    icon: LifeBuoy,
                },
                {
                    label: t('nav.users'),
                    href: '/admin/users',
                    icon: Users,
                },
                {
                    label: t('nav.companies'),
                    href: '/admin/companies',
                    icon: Building2,
                },
                {
                    label: t('nav.documents'),
                    href: '/admin/documents',
                    icon: FileCheck2,
                },
            ],
        },
        {
            label: t('nav.groups.control'),
            items: [
                {
                    label: t('nav.auditLog'),
                    href: '/admin/audit',
                    icon: ShieldCheck,
                },
            ],
        },
    ]);

    const navigation = computed(() => {
        if (role.value === 'candidate') {
            return candidateNavigation.value;
        }

        if (role.value === 'support') {
            return supportNavigation.value;
        }

        if (role.value === 'super_admin') {
            return adminNavigation.value;
        }

        return employerNavigation.value;
    });

    const roleLabel = computed(() => t(`roles.${role.value}`));

    return { role, navigation, roleLabel };
}
