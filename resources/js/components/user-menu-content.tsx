import { Link, router } from '@inertiajs/react';
import { Languages, LogOut, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslations } from '@/hooks/use-translations';
import { logout } from '@/routes';
import { update as updateLocale } from '@/routes/locale';
import { edit } from '@/routes/profile';
import type { Locale, User } from '@/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { locale, t } = useTranslations();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    const handleLocaleChange = (nextLocale: Locale) => {
        if (nextLocale === locale) {
            return;
        }

        router.patch(
            updateLocale(),
            { locale: nextLocale },
            {
                preserveScroll: true,
                preserveState: false,
            },
        );
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full cursor-pointer"
                        href={edit()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        {t('user_menu.settings')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <Languages className="mr-2" />
                        {t('user_menu.language')}
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        <DropdownMenuItem
                            disabled={locale === 'en'}
                            onSelect={() => handleLocaleChange('en')}
                        >
                            {t('user_menu.english')}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            disabled={locale === 'uk'}
                            onSelect={() => handleLocaleChange('uk')}
                        >
                            {t('user_menu.ukrainian')}
                        </DropdownMenuItem>
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    {t('user_menu.logout')}
                </Link>
            </DropdownMenuItem>
        </>
    );
}
