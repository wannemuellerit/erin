export type DemoAccountRole = 'super_admin' | 'employer' | 'candidate';

export type DemoAccount = {
    id: string;
    name: string;
    email: string;
    role: DemoAccountRole;
};
