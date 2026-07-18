export type SupportTicketMessage = {
    id: number;
    author_id?: number | null;
    author?: {
        id: number;
        name: string;
        role?: string;
    } | null;
    body: string;
    is_internal: boolean;
    source?: string;
    delivery_status?: string;
    created_at: string;
    attachments?: SupportTicketAttachment[];
};

export type SupportTicketAttachment = {
    id: number;
    original_name: string;
    mime_type?: string | null;
    size_bytes?: number | null;
    scan_result: string;
    download_url?: string | null;
};

export type SupportTicket = {
    id: number;
    requester_id: number;
    number: string;
    subject: string;
    category?: string | null;
    priority: string;
    status: string;
    external_system?: string | null;
    external_id?: string | null;
    sync_status?: string;
    last_reply_at?: string | null;
    created_at: string;
    requester?: {
        id: number;
        name: string;
        email: string;
    };
    assignee?: {
        id: number;
        name: string;
    } | null;
    messages: SupportTicketMessage[];
};
