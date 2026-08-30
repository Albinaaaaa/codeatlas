import type { Auth } from '@/types/auth';
import type { LocalizationData } from '@/types/localization';

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            localization: LocalizationData;
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
