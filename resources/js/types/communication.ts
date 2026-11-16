export type MessageAttachment = {
    id: number;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    scan_result?: string | null;
    download_url?: string | null;
    duration_seconds?: number | null;
    waveform?: number[] | null;
};

export type ConversationMessage = {
    id: number;
    client_id?: string | null;
    sender?: { id: number; name: string } | null;
    sender_id?: number | null;
    type?: string;
    body?: string | null;
    translations?: Record<
        string,
        {
            status: string;
            body?: string | null;
            model?: string | null;
            prompt_version?: string | null;
        }
    >;
    delivery_status?: 'sending' | 'failed' | 'sent';
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
    can_join?: boolean;
    attendances?: Array<{
        user_id: number;
        first_joined_at?: string | null;
        last_left_at?: string | null;
        total_seconds: number;
        join_count: number;
    }>;
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
    applications?: Array<{
        id: number;
        job_title: string;
        candidate_name: string;
    }>;
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
    payoutAccount?: {
        id: number;
        provider: string;
        country_code: string;
        currency_code: string;
        status: string;
        kyc_status: string;
    } | null;
    payoutIntents?: Array<{
        public_id: string;
        amount_cents: number;
        currency_code: string;
        status: string;
        submitted_at?: string | null;
        paid_at?: string | null;
        failure_code?: string | null;
    }>;
};
