<?php

declare(strict_types=1);

namespace Commerce\Modules\System\Http;

use Commerce\Core\Runtime\ComponentInventory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ComponentInventoryController
{
    public function __construct(private readonly ComponentInventory $inventory)
    {
    }

    #[Route('/admin/system/components.json', name: 'admin_system_components', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->inventory->collect());
    }
}
