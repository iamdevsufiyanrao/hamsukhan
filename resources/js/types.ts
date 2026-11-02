export type CallStatus = 'completed' | 'dropped' | 'failed';

export type CallRowData = {
    id: number;
    title: string;
    tag: string;
    status: CallStatus;
    isTest: boolean;
    direction?: 'web' | 'inbound' | 'outbound';
    customerNumber?: string | null;
    startedAt: string;
    duration: number;
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
        user: { name: string; firstName: string; email: string; initials: string; avatarUrl: string | null; isAdmin: boolean; verified: boolean; teamRole: 'admin' | 'operator' | 'viewer' | null };
        plan: { active: boolean; name: string | null; expiresAt: string | null; daysLeft: number };
        badges: { newBookings: number; pendingTopUps: number; newMessages: number };
        bot: { id: number; publicKey: string; name: string } | null;
        stores: { id: number; name: string }[];
        impersonator: { name: string } | null;
    } | null;
    flash: { success: string | null; error: string | null };
    site: { name: string; announcement: string | null; allowSignups: boolean; termsUrl: string | null; privacyUrl: string | null; supportEmail: string | null; supportPhone: string | null; whatsapp: string | null; demoBotKey: string | null; logoUrl: string | null; introVideo: IntroVideo | null };
    config: {
        plans: Plan[];
        trialDays: number;
    };
    errors: Record<string, string>;
};

export type Plan = { id: 'basic' | 'business'; name: string; pricePkr: number; stores: number; users: number };

export type PaymentAccount = { id: 'jazzcash' | 'easypaisa' | 'bank'; label: string; title: string; number: string };

/** The intro video on the home page: an uploaded file (src + optional poster) or a YouTube link (youtubeId). */
export type IntroVideo = { src: string | null; poster: string | null; youtubeId: string | null; vertical: boolean };
