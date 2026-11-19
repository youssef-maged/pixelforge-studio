export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    in_flight: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimate: string;
    charge: string | null;
    image: string | null;
    error_code: string | null;
    failure_reason: string | null;
    created_at: string;
    completed_at: string | null;
};

export type PricingList = {
    rates: Record<string, string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
};

export type Credits = { balance: string; held: string; available: string };
