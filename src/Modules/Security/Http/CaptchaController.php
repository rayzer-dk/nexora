<?php

declare(strict_types=1);

namespace Commerce\Modules\Security\Http;

use Commerce\Modules\Security\Captcha\BuiltinCaptcha;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CaptchaController extends AbstractController
{
    public function __construct(private readonly BuiltinCaptcha $builtin, private readonly \Twig\Environment $twig)
    {
    }

    #[Route('/captcha/image/{token}', name: 'storefront_captcha_image', methods: ['GET'], requirements: ['token' => '[a-f0-9]{16}\.[0-9]{9,12}\.[a-f0-9]{32}'], priority: 950)]
    public function image(string $token): Response
    {
        $png = $this->builtin->image($token);
        if ($png === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store, max-age=0']);
    }

    #[Route('/captcha/new', name: 'storefront_captcha_new', methods: ['GET'], priority: 950)]
    public function fresh(): JsonResponse
    {
        $issue = $this->builtin->issue();

        $html = $this->twig->render('@storefront/components/captcha_choice.html.twig', ['target' => $issue['question'], 'options' => $issue['options']]);

        return new JsonResponse(['token' => $issue['token'], 'mode' => $issue['mode'], 'question' => $issue['question'], 'html' => $html], 200, ['Cache-Control' => 'no-store']);
    }
}
