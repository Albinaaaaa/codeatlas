export type Locale = 'en' | 'uk';

export type TranslationDictionary = {
    [key: string]: string | TranslationDictionary;
};

export type TranslationParameters = Record<string, string | number>;

export type LocalizationData = {
    locale: Locale;
    translations: TranslationDictionary;
};
