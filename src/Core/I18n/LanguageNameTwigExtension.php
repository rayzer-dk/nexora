<?php

declare(strict_types=1);

namespace Commerce\Core\I18n;

use Doctrine\DBAL\Connection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** locale_name('uk-UA') -> "Українська" in every template, admin and storefront: the name stored for the language, else intl. */
final class LanguageNameTwigExtension extends AbstractExtension
{
    /** @var array<string,string>|null */
    private ?array $names = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('locale_name', $this->name(...))];
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
}
