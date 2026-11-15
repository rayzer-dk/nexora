<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Names for people in every template, admin and storefront: locale_name('uk-UA') -> "Українська" (the name stored for the
 * language, else intl) and currency_name('USD') -> the currency in the language of the page (admin: the interface language).
 */
final class LanguageNameTwigExtension extends AbstractExtension
{
    /** @var array<string,string>|null */
    private ?array $names = null;

    public function __construct(private readonly Connection $db, private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('locale_name', $this->name(...)), new TwigFunction('currency_name', $this->currency(...))];
    }

    public function name(?string $code): string
    {
        $code = (string) $code;
        if ($code === '') {
            return '';
        }
        if ($this->names === null) {
            $this->names = [];
            try {
                foreach ($this->db->fetchAllAssociative('SELECT code,native_name FROM mc_locale') as $row) {
                    $this->names[(string) $row['code']] = LanguageName::plain((string) $row['native_name'], (string) $row['code']);
                }
            } catch (\Doctrine\DBAL\Exception) {
                // before installation there is no table yet: intl names
            }
        }

        return $this->names[$code] ?? LanguageName::native($code);
    }

    public function currency(?string $code, string $fallback = ''): string
    {
        $request = $this->requests->getCurrentRequest();
        $locale = $request?->attributes->get(AdminInterfaceLocale::REQUEST_ATTRIBUTE);
        $locale = is_string($locale) && $locale !== '' ? $locale : ($request?->getLocale() ?? 'en');

        return CurrencyName::localized((string) $code, $locale, $fallback);
    }
}
