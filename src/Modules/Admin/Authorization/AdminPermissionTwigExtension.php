<?php

declare(strict_types=1);
namespace Commerce\Modules\Admin\Authorization;
use Commerce\Modules\Admin\Domain\AdminUser;
use Commerce\Modules\Admin\Http\AdminContextResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AdminPermissionTwigExtension extends AbstractExtension
{
    public function __construct(private readonly Security $security, private readonly AdminAuthorizationService $authorization, private readonly AdminContextResolver $contexts, private readonly RequestStack $requests) {}
    public function getFunctions(): array { return [new TwigFunction('admin_can',[$this,'can'])]; }
    public function can(string $permission): bool
    {
        $user=$this->security->getUser(); if(!$user instanceof AdminUser) return false;
        if(in_array('ROLE_SUPER_ADMIN',$user->getRoles(),true)) return true;
        $global=in_array($permission,['extensions.manage','system.recovery','system.update','admin_users.manage'],true);
        $storeId=null; $request=$this->requests->getCurrentRequest();
        if(!$global && $request!==null){try{$storeId=$this->contexts->resolve($request)->storeId;}catch(\Throwable){return false;}}
        return $this->authorization->isGranted($user,$permission,$storeId);
    }
}
