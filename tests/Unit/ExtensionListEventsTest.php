<?php

declare(strict_types=1);

namespace Commerce\Tests\Unit;

use Commerce\Core\Event\EventNames;
use Commerce\Core\Extension\Console\ExtensionListEventsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `commerce:extension:list-events` is what a module author subscribes from. It must name exactly the events the core
 * publishes (found here by reading the publishers in src/), mark the rest, and agree with the developer guide.
 */
final class ExtensionListEventsTest extends TestCase
{
    public function testListsExactlyTheEventsTheCorePublishes(): void
    {
        $tester = new CommandTester(new ExtensionListEventsCommand());
        self::assertSame(0, $tester->execute([]));
        $listed = array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())), static fn (string $line): bool => $line !== ''));

        self::assertSame($this->publishedInSource(), $listed);
    }

    public function testAllMarksEveryReservedNameAndKeepsThePublishedOnesPlain(): void
    {
        $tester = new CommandTester(new ExtensionListEventsCommand());
        self::assertSame(0, $tester->execute(['--all' => true]));
        $lines = array_values(array_filter(array_map('trim', explode("\n", $tester->getDisplay())), static fn (string $line): bool => $line !== ''));

        $published = $this->publishedInSource();
        $all = array_values((new \ReflectionClass(EventNames::class))->getConstants());
        self::assertCount(count($all), $lines, 'Every name of EventNames is printed once with --all.');
        foreach ($lines as $line) {
            $name = strtok($line, ' ');
            self::assertContains($name, $all);
            if (in_array($name, $published, true)) {
                self::assertSame($name, $line, 'A published event is printed without a mark.');
            } else {
                self::assertStringContainsString('reserved', $line, $name . ' is never published and must be marked.');
            }
        }
    }

    public function testDeveloperGuideTableNamesTheSameEvents(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/DEVELOPER_MODULES_UK.md');
        $start = strpos($doc, "\n### Події");
        self::assertNotFalse($start, 'The guide has a «Події» section.');
        $end = strpos($doc, "\n#", $start + 5);
        $section = substr($doc, $start, $end === false ? null : $end - $start);
        preg_match_all('/^\| `([a-z_.]+)` \|/m', $section, $matches);
        $documented = $matches[1];
        sort($documented);

        self::assertSame($this->publishedInSource(), $documented);
    }

    /** @return list<string> event names passed to DomainEventFactory::create() anywhere in src/ */
    private function publishedInSource(): array
    {
        $constants = (new \ReflectionClass(EventNames::class))->getConstants();
        $names = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all('/->create\(\s*EventNames::([A-Z_]+)/', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $constant) {
                $names[] = (string) $constants[$constant];
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }
}
