<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\I18n\CanonicalUiText;
use Commerce\Modules\Ai\Application\AiGlossary;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class AiGlossaryTest extends TestCase
{
    private Connection $db;
    private AiGlossary $glossary;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement('CREATE TABLE mc_ai_glossary (id INTEGER PRIMARY KEY AUTOINCREMENT, store_id INT, term TEXT, mode TEXT, translations_json TEXT, created_at TEXT, updated_at TEXT, UNIQUE(store_id, term))');
        $this->glossary = new AiGlossary($this->db);
        $this->glossary->save(1, [
            ['term' => 'Nexora Pro', 'mode' => 'keep'],
            ['term' => 'кавомашина', 'mode' => 'fixed', 'translations' => ['en' => 'espresso machine', 'de-DE' => 'Siebträgermaschine']],
            ['term' => '  ', 'mode' => 'keep'],
            ['term' => 'NEXORA PRO', 'mode' => 'keep'],
        ]);
    }

    public function testRowsAreStoredOncePerTermAndEmptyOnesAreDropped(): void
    {
        $terms = $this->glossary->terms(1);
        self::assertCount(2, $terms);
        self::assertSame(['Nexora Pro', 'кавомашина'], array_column($terms, 'term'));
        self::assertSame(['keep', 'fixed'], array_column($terms, 'mode'));
        self::assertSame(['en' => 'espresso machine', 'de-DE' => 'Siebträgermaschine'], $terms[1]['translations']);
        self::assertSame([], $this->glossary->terms(2), 'another store has its own glossary');
    }

    public function testKeptTermIsReplacedByAMarkerOutsideTagsAndComesBackWordForWord(): void
    {
        $html = '<p title="Nexora Pro">Нова nexora pro — краща. Nexora Professional не чіпаємо.</p>';
        [$protected, $map] = $this->glossary->protect(1, $html, 'en-US');

        self::assertSame('<p title="Nexora Pro">Нова ⟦T1⟧ — краща. Nexora Professional не чіпаємо.</p>', $protected);
        self::assertSame(['⟦T1⟧' => 'nexora pro'], $map);
        $translated = '<p title="Nexora Pro">The new ⟦T1⟧ is better. Nexora Professional untouched.</p>';
        self::assertSame('<p title="Nexora Pro">The new nexora pro is better. Nexora Professional untouched.</p>', $this->glossary->restore($translated, $map));
        self::assertSame('The new nexora pro', $this->glossary->restore('The new ⟦ T1 ⟧', $map), 'a model that pads the marker with spaces is still understood');
    }

    public function testLostMarkerIsAnErrorNotABrokenText(): void
    {
        [, $map] = $this->glossary->protect(1, 'Купіть Nexora Pro', 'en-US');
        self::assertNotSame('admin.ai.glossary_broken', CanonicalUiText::get('admin.ai.glossary_broken'), 'the message is a real text');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(CanonicalUiText::get('admin.ai.glossary_broken'));
        $this->glossary->restore('Buy Nexora', $map);
    }

    public function testFixedTranslationGoesIntoThePromptForTheTargetLanguage(): void
    {
        $hint = $this->glossary->promptHint(1, 'en-US');
        self::assertStringContainsString('"кавомашина"', $hint);
        self::assertStringContainsString('"espresso machine"', $hint);
        self::assertStringContainsString('Siebträgermaschine', $this->glossary->promptHint(1, 'de-DE'));
        self::assertSame('', $this->glossary->promptHint(1, 'pl-PL'), 'no fixed translation for this language');
    }

    public function testExternalTranslatorGetsFixedTermsAsMarkersThatComeBackTranslated(): void
    {
        [$protected, $map] = $this->glossary->protect(1, 'Наша Кавомашина і Nexora Pro', 'en-US', true);
        self::assertSame('Наша ⟦T1⟧ і ⟦T2⟧', $protected);
        self::assertSame('Our espresso machine and Nexora Pro', $this->glossary->restore('Our ⟦T1⟧ and ⟦T2⟧', $map));
        [$plain] = $this->glossary->protect(1, 'Наша кавомашина', 'pl-PL', true);
        self::assertSame('Наша кавомашина', $plain, 'without a translation for the language the term is translated as usual');
    }

    public function testSavingKeepsTheFixedTranslationOfALanguageTheFormDidNotShow(): void
    {
        // German is switched off, so the form sends only English: the German translation stays; an English sent empty is cleared
        $this->glossary->save(1, [['term' => 'кавомашина', 'mode' => 'fixed', 'translations' => ['en' => 'coffee machine']]]);
        self::assertSame(['en' => 'coffee machine', 'de-DE' => 'Siebträgermaschine'], $this->glossary->terms(1)[0]['translations']);
        $this->glossary->save(1, [['term' => 'кавомашина', 'mode' => 'fixed', 'translations' => ['en' => '', 'de-DE' => 'Kaffeemaschine']]]);
        self::assertSame(['de-DE' => 'Kaffeemaschine'], $this->glossary->terms(1)[0]['translations']);
    }
}
