import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { translate, useTranslations } from '@/hooks/use-translations';
import { edit as editAppearance } from '@/routes/appearance';
import type { LocalizationData } from '@/types';

export default function Appearance() {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('appearance.title')} />

            <h1 className="sr-only">{t('appearance.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('appearance.title')}
                    description={t('appearance.description')}
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = ({ localization }: { localization: LocalizationData }) => ({
    breadcrumbs: [
        {
            title: translate(localization.translations, 'appearance.title'),
            href: editAppearance(),
        },
    ],
});
