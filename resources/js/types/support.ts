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
