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
