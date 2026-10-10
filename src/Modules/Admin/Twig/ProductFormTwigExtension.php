<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Twig;

use Commerce\Modules\Customer\Application\CustomerGroupService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Customer groups for the price-by-group rows of the product form. */
final class ProductFormTwigExtension extends AbstractExtension
{
    public function __construct(private readonly CustomerGroupService $groups)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('admin_customer_groups', $this->customerGroups(...)), new TwigFunction('admin_customer_group_choices', $this->choices(...))];
    }

    /** @return list<array{code:string,name:string}> every group including the default one, for pickers that assign a customer to a group */
    public function choices(): array
    {
        $out = [];
        foreach ($this->groups->all() as $code => $group) {
            $out[] = ['code' => $code, 'name' => $code === 'default' ? ($group['name'] === 'Default' ? \Commerce\Core\I18n\CanonicalUiText::get('admin.customer_groups.default_name') : $group['name']) : $group['name']];
        }

        return $out;
    }

    /** @return list<array{code:string,name:string}> every group a price can be set for (the shared "default" price is the main price) */
    public function customerGroups(): array
    {
        $out = [];
        foreach ($this->groups->all() as $code => $group) {
            if ($code !== 'default') {
                $out[] = ['code' => $code, 'name' => $group['name']];
            }
        }

        return $out;
    }
}
