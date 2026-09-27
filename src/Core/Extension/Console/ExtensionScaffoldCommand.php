<?php

declare(strict_types=1);

namespace Commerce\Core\Extension\Console;

use Commerce\Core\Platform\PlatformVersion;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commerce:extension:scaffold', description: 'Create a safe declarative/remote extension skeleton without Core patching.')]
final class ExtensionScaffoldCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('code', InputArgument::REQUIRED, 'Extension code, e.g. vendor.module')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable name')
            ->addOption('execution', null, InputOption::VALUE_REQUIRED, 'declarative, remote_app or trusted_release', 'declarative')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'basic, product-block, remote-integration, trusted-route or theme', 'basic')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Output directory relative to the project', 'var/extension-scaffold');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = trim((string) $input->getArgument('code'));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $code) !== 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.3a21f74eea89'));
        }
        $execution = trim((string) $input->getOption('execution'));
        $preset = trim((string) $input->getOption('preset'));
        if (!in_array($preset, ['basic', 'product-block', 'remote-integration', 'trusted-route', 'theme'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.unsupported_preset'));
        }
        if ($preset === 'remote-integration') {
            $execution = 'remote_app';
        } elseif (in_array($preset, ['trusted-route','theme'], true)) {
            $execution = 'trusted_release';
        }
        if (!in_array($execution, ['declarative', 'remote_app', 'trusted_release'], true)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.49b5a3e25c25'));
        }
        $name = trim((string) ($input->getOption('name') ?: $code));
        $targetRoot = trim((string) $input->getOption('target'));
        if ($targetRoot === '' || str_starts_with($targetRoot, '/') || preg_match('#(^|/)\.\.(?:/|$)#', str_replace('\\', '/', $targetRoot)) === 1) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.555871d19a5e'));
        }
        $dir = rtrim($this->projectDir, '/\\') . '/' . trim($targetRoot, '/\\') . '/' . $code;
        if (is_dir($dir) || file_exists($dir)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.6fe2863c6577'));
        }
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.80c75fb72d67'));
        }

        $manifest = [
            'code' => $code,
            'name' => $name,
            'version' => '1.0.0',
            'type' => $preset === 'theme' ? 'theme' : ($execution === 'trusted_release' ? 'trusted-module' : 'app'),
            'core' => '^' . PlatformVersion::VERSION,
            'extension_api' => PlatformVersion::EXTENSION_API,
            'execution' => $execution,
            'permissions' => [],
            'capabilities' => [],
            'events' => [],
            'ui_slots' => [],
            'blocks' => [],
            'routes' => [],
            'pages' => [],
            'slot_contributions' => [],
            'assets' => [],
            'settings_schema' => 'settings.schema.json',
            'isolation' => 'contract_only',
            'hot_disable' => true,
            'data_policy' => 'retain_on_uninstall',
            'localization' => [
                'default_locale' => 'uk-UA',
                'locales' => ['uk-UA', 'en-US'],
                'translations_path' => 'translations',
            ],
        ];
        $namespace = \Commerce\Core\I18n\TranslationCatalogLoader::namespaceForCode($code);
        $namespaceParts = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $code) ?: []));
        $phpNamespace = implode('\\', array_map(static fn(string $part): string => ucfirst(strtolower($part)), $namespaceParts));
        if ($phpNamespace === '') { $phpNamespace = 'NexoraExtension'; }

        if ($preset === 'product-block') {
            $blockId = $namespace . 'product_panel';
            $manifest['blocks'] = [[
                'id' => $blockId,
                'surface' => 'product',
                'regions' => ['main', 'sidebar'],
                'label_key' => $namespace . 'product_panel.label',
                'settings_schema' => 'blocks/product_panel.schema.json',
                'allow_multiple' => false,
            ]];
        }

        if ($execution === 'trusted_release') {
            $manifest['publisher'] = ['name' => 'Your Company', 'key_id' => 'replace-with-publisher-key-id'];
            $manifest['signature'] = ['algorithm' => 'ed25519', 'key_id' => 'replace-with-publisher-key-id', 'file' => 'SIGNATURE.ed25519'];
            $manifest['autoload'] = ['psr4' => [$phpNamespace . '\\' => 'src']];
            $manifest['entrypoint'] = $phpNamespace . '\\Entrypoint';
            $manifest['migrations'] = [];
        }
        if ($execution === 'remote_app') {
            $manifest['remote'] = [
                'base_url' => 'https://example.invalid',
                'health_path' => '/health',
                'webhook_path' => '/webhooks/commerce',
            ];
        }
        $settings = [
            'schema_version' => 2,
            'fields' => [
                [
                    'key' => 'enabled_feature',
                    'type' => 'boolean',
                    'label' => 'Enable feature',
                    'default' => false,
                    'help' => 'Example declarative setting. Remove or replace it in your extension.',
                ],
            ],
        ];
        $uk = [$namespace . 'name' => $name];
        $en = [$namespace . 'name' => $name];
        if ($preset === 'product-block') {
            $uk[$namespace . 'product_panel.label'] = \Commerce\Core\I18n\CanonicalUiText::get('extension.sdk.product_block_label');
            $en[$namespace . 'product_panel.label'] = 'Product block';
        }
        $readme = "# {$name}\n\nThis extension uses Nexora Commerce Extension API " . PlatformVersion::EXTENSION_API . ".\nIt must not patch Core files. Declare permissions, events and UI slots in manifest.json.\n\nDevelopment guide: docs/DEVELOPER_GUIDE.md in the Nexora distribution.\nValidate: php bin/console commerce:extension:validate module.zip\nTest: php bin/console commerce:extension:test module.zip\nPack: php bin/console commerce:extension:pack .\n";

        file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/settings.schema.json', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        foreach (['uk-UA', 'en-US'] as $locale) {
            $localeDir = $dir . '/translations/' . $locale;
            if (!@mkdir($localeDir, 0750, true) && !is_dir($localeDir)) {
                throw new RuntimeException(\Commerce\Core\I18n\CanonicalUiText::get('runtime.exception.80c75fb72d67'));
            }
        }
        file_put_contents($dir . '/translations/uk-UA/messages.json', json_encode($uk, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($dir . '/translations/en-US/messages.json', json_encode($en, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        if ($preset === 'product-block') {
            @mkdir($dir . '/blocks', 0750, true);
            $blockSettings = ['schema_version' => 2, 'fields' => [
                ['key'=>'title','type'=>'multilingual_text','label'=>'Title','group'=>'content'],
                ['key'=>'text','type'=>'multilingual_textarea','label'=>'Text','group'=>'content'],
            ]];
            file_put_contents($dir . '/blocks/product_panel.schema.json', json_encode($blockSettings, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        }
        if ($execution === 'trusted_release' && $preset !== 'theme') {
            @mkdir($dir . '/src', 0750, true);
            @mkdir($dir . '/migrations', 0750, true);
            @mkdir($dir . '/assets', 0750, true);
            file_put_contents($dir . '/src/Entrypoint.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$phpNamespace};\n\nuse Commerce\\Core\\Extension\\TrustedExtensionContext;\nuse Commerce\\Core\\Extension\\TrustedExtensionEntrypointInterface;\n\nfinal class Entrypoint implements TrustedExtensionEntrypointInterface\n{\n    public function boot(TrustedExtensionContext \\$context): void\n    {\n        // Register only routes/events declared in manifest.json.\n    }\n}\n");
            file_put_contents($dir . '/SIGNATURE.ed25519', "SIGN THIS PACKAGE WITH YOUR TRUSTED ED25519 PUBLISHER KEY\n");
        }
        if ($preset === 'theme') {
            @mkdir($dir . '/templates/example', 0750, true);
            file_put_contents($dir . '/theme.css', ":root{--theme-accent:#0b63f6;}
/* Add storefront-only theme styles here. */
");
            file_put_contents($dir . '/templates/components/product_card.html.twig', "{# Copy the current default component here only when you need to override it. Remove this placeholder before packaging. #}
{% include '@storefront/components/product_card.html.twig' %}
");
            file_put_contents($dir . '/SIGNATURE.ed25519', "SIGN THIS PACKAGE WITH YOUR TRUSTED ED25519 PUBLISHER KEY
");
            $readme .= "
Theme SDK: storefront Twig overrides belong under templates/. Admin/email/order_document overrides are rejected. Start by copying only the default templates you intentionally customize.
";
        }

        file_put_contents($dir . '/README.md', $readme);

        $output->writeln('Created extension scaffold: ' . $dir);
        return Command::SUCCESS;
    }
}
