<?php

declare(strict_types=1);

namespace Commerce\Modules\Forms\Twig;

use Commerce\Modules\Forms\Application\FormService;
use Commerce\Modules\Storefront\Infrastructure\StorefrontContextResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Lets the merchant place a form anywhere rich text is edited (information pages, blog articles, category
 * texts) by typing `[form slug="wholesale-request"]`. Applied to already sanitised HTML on output.
 */
final class FormEmbedTwigExtension extends AbstractExtension
{
    private const PATTERN = '~(?:<p>\s*)?\[form(?:\s+slug=(?:"|&quot;|“|”)?|:)([a-z0-9][a-z0-9-]{0,118})(?:"|&quot;|“|”)?\s*\](?:\s*</p>)?~u';

    public function __construct(
        private readonly FormService $forms,
        private readonly StorefrontContextResolver $contexts,
        private readonly RequestStack $requests,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('with_forms', $this->embed(...), ['needs_environment' => true, 'is_safe' => ['html']])];
    }

    public function embed(Environment $twig, mixed $html): string
    {
        $html = (string) $html;
        $request = $this->requests->getCurrentRequest();
        if ($request === null || !str_contains($html, '[form')) {
            return $html;
        }
        $context = $this->contexts->resolve($request);
        $back = $request->getPathInfo();
        $used = [];

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($twig, $context, $back, &$used): string {
            $slug = $m[1];
            if (isset($used[$slug]) || count($used) >= 3) {
                return ''; // the same form twice on a page would duplicate ids and tokens
            }
            $form = $this->forms->findPublic($context->storeId, $slug);
            if ($form === null) {
                return '';
            }
            $used[$slug] = true;

            return $twig->render('@storefront/forms/embed.html.twig', ['form' => $this->forms->localize($form, $context->locale), 'form_back' => $back]);
        }, $html);
    }
}
