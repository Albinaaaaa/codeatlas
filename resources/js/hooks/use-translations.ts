import { usePage } from '@inertiajs/react';
import type { TranslationDictionary, TranslationParameters } from '@/types';

export function translate(
    translations: TranslationDictionary,
    key: string,
    parameters: TranslationParameters = {},
): string {
    let value: string | TranslationDictionary = translations;

    for (const segment of key.split('.')) {
        if (typeof value === 'string' || !(segment in value)) {
            return key;
        }

        value = value[segment];
    }

    if (typeof value !== 'string') {
        return key;
    }

    return Object.entries(parameters).reduce(
        (message, [parameter, replacement]) =>
            message.split(`:${parameter}`).join(String(replacement)),
        value,
    );
}

export function useTranslations() {
    const { localization } = usePage().props;

    return {
        locale: localization.locale,
        t: (key: string, parameters?: TranslationParameters) =>
            translate(localization.translations, key, parameters),
    };
}
