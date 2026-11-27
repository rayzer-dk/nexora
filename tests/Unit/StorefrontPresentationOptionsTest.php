<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Configuration\ConfigurationRevisionStore;
use Commerce\Core\Id\PublicIdFactory;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Commerce\Modules\Appearance\Infrastructure\ThemePresetCatalog;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

final class StorefrontPresentationOptionsTest extends TestCase
{
    private function settings(): StorefrontPresentationSettings
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        return new StorefrontPresentationSettings(
            new ConfigurationRevisionStore($db, new PublicIdFactory()),
            new ThemePresetCatalog(dirname(__DIR__, 2) . '/config/appearance/presets.json'),
            new RequestStack(),
        );
    }

    public function testOldSettingsKeepCompatibleDefaults(): void
    {
        $normalized = $this->settings()->normalize([]);
        self::assertSame('0', $normalized['theme']['concept']);
        self::assertSame('builder', $normalized['home']['composition']);
        self::assertSame('auto', $normalized['header']['layout']);
        self::assertSame('auto', $normalized['header']['nav_style']);
        self::assertSame('auto', $normalized['display']['cart_effect']);
        self::assertSame('standard', $normalized['display']['product_sections']);
    }

    public function testIndependentChoicesArePreservedAndInvalidValuesFallBack(): void
    {
        $settings = $this->settings();
        $selected = $settings->normalize([
            'theme' => ['preset' => 'dynamic'],
            'header' => ['layout' => 'wide_search', 'nav_style' => 'pill'],
            'display' => ['cart_effect' => 'off', 'product_sections' => 'editorial', 'card_style' => 'outlined'],
        ]);
        self::assertSame('dynamic', $selected['theme']['preset']);
        self::assertSame('wide_search', $selected['header']['layout']);
        self::assertSame('pill', $selected['header']['nav_style']);
        self::assertSame('off', $selected['display']['cart_effect']);
        self::assertSame('editorial', $selected['display']['product_sections']);
        self::assertSame('outlined', $selected['display']['card_style']);

        $invalid = $settings->normalize([
            'header' => ['layout' => 'unsupported', 'nav_style' => 'unsupported'],
            'display' => ['cart_effect' => 'unsupported', 'product_sections' => 'unsupported'],
        ]);
        self::assertSame('auto', $invalid['header']['layout']);
        self::assertSame('auto', $invalid['header']['nav_style']);
        self::assertSame('auto', $invalid['display']['cart_effect']);
        self::assertSame('standard', $invalid['display']['product_sections']);
    }
    public function testSevenConceptsHaveDistinctLayoutProfilesAndStandardSectionOrders(): void
    {
        $catalog = new ThemePresetCatalog(dirname(__DIR__, 2) . '/config/appearance/presets.json');
        $profiles = $catalog->all();
        self::assertCount(7, $profiles);
        $layouts = [];
        self::assertCount(7, array_unique(array_column(array_column($profiles, 'theme'), 'primary')));
        $orders = [];
        foreach ($profiles as $profile) {
            self::assertSame('1', $profile['form']['theme_concept']);
            self::assertSame('theme', $profile['form']['home_composition']);
            self::assertEqualsCanonicalizing(['hero', 'category_grid', 'product_grid', 'article_grid', 'recently_viewed'], $profile['home_order']);
            $layouts[] = json_encode($profile['form'], JSON_THROW_ON_ERROR);
            $orders[] = implode(',', $profile['home_order']);
        }
        self::assertCount(7, array_unique($layouts));
        self::assertCount(7, array_unique($orders));
    }

}
