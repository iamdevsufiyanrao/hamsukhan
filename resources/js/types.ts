export type CallStatus = 'completed' | 'dropped' | 'failed';

export type CallRowData = {
    id: number;
    title: string;
    tag: string;
    status: CallStatus;
    isTest: boolean;
    startedAt: string;
    duration: number;
    cost: number;
    quote: string | null;
};

export type CallDetail = CallRowData & {
    summary: string | null;
    recordingUrl: string | null;
    endedReason: string | null;
    transcript: ['ai' | 'user', string][];
};

export type SharedProps = {
    auth: {
        user: { name: string; firstName: string; email: string; initials: string; avatarUrl: string | null; isAdmin: boolean };
        wallet: { balance: number; minutesLeft: number; low: boolean };
        badges: { newBookings: number; pendingTopUps: number; newMessages: number };
        bot: { publicKey: string } | null;
        impersonator: { name: string } | null;
    } | null;
    flash: { success: string | null; error: string | null };
    site: { name: string; announcement: string | null; allowSignups: boolean; termsUrl: string | null; privacyUrl: string | null; supportEmail: string | null; supportPhone: string | null; whatsapp: string | null; demoBotKey: string | null };
    config: {
        ratePerMinute: number;
        minTopUp: number;
        ratePerMinutePkr: number;
        minTopUpPkr: number;
        pkrPerUsd: number;
        accounts: { id: 'jazzcash' | 'easypaisa' | 'bank'; label: string; title: string; number: string }[];
    };
    errors: Record<string, string>;
};
