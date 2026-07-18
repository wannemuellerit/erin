export type MessageAttachment = {
    id: number;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    scan_result?: string | null;
    download_url?: string | null;
};

export type ConversationMessage = {
    id: number;
    sender?: { id: number; name: string } | null;
    sender_id?: number | null;
    type?: string;
    body?: string | null;
    created_at?: string | null;
    attachments?: MessageAttachment[];
};

export type Conversation = {
    id: number;
    title?: string | null;
    participants?: Array<{ id: number; name: string }>;
    last_message_at?: string | null;
    unread?: number;
    messages?: ConversationMessage[];
};

export type InterviewProposal = {
    id: number;
    starts_at?: string | null;
    ends_at?: string | null;
    timezone?: string;
    status?: string;
    proposer?: { id: number; name: string } | null;
};

export type Interview = {
    id: number;
    status: string;
    starts_at?: string | null;
    ends_at?: string | null;
    timezone?: string;
    ics_url?: string | null;
    proposals?: InterviewProposal[];
    application?: {
        id: number;
        job_posting?: {
            id: number;
            title: string;
            company?: { id: number; name: string } | null;
        } | null;
        candidate_profile?: {
            user?: { id: number; name: string } | null;
        } | null;
    } | null;
};

export type Availability = {
    id?: number;
    weekday: number;
    starts_at: string;
    ends_at: string;
    timezone: string;
};

export type ProductPerspective = 'employer' | 'candidate';

export type MessagingWorkspaceProps = {
    perspective?: ProductPerspective;
    conversations?: Conversation[];
    selected?: number | null;
};

export type InterviewCenterProps = {
    perspective?: ProductPerspective;
    interviews?: Interview[];
    availability?: Availability[];
    timezone?: string;
};

export type ReferralCode = {
    id: number;
    code: string;
    url: string;
    commission_cents?: number;
    currency?: string;
};

export type ReferralMetrics = {
    clicks: number;
    registrations: number;
    applications: number;
    placements: number;
    approved_cents: number;
    paid_cents: number;
};

export type Referral = {
    id: number;
    status: string;
    clicked_at?: string | null;
    registered_at?: string | null;
    hired_at?: string | null;
    hold_until?: string | null;
    approved_at?: string | null;
    paid_at?: string | null;
    commission_cents?: number;
    currency?: string;
};

export type ReferralDashboardProps = {
    perspective?: ProductPerspective;
    code?: ReferralCode | null;
    metrics?: ReferralMetrics;
    referrals?: Referral[];
};
