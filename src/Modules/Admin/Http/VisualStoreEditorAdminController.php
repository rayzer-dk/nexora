<?php

declare(strict_types=1);

namespace Commerce\Modules\Admin\Http;

use Commerce\Modules\Appearance\Builder\LayoutRevisionStore;
use Commerce\Modules\Appearance\Infrastructure\StorefrontPresentationSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VisualStoreEditorAdminController extends AbstractController
{
    public function __construct(private readonly AdminContextResolver $contexts, private readonly LayoutRevisionStore $layouts, private readonly StorefrontPresentationSettings $presentation) {}

    #[Route('/admin/appearance/editor', name:'admin_visual_store_editor', methods:['GET'])]
    public function index(Request $request): Response
    {
        $ctx=$this->contexts->resolve($request);
        $type=(string)$request->query->get('type','home');
        if(!in_array($type,['home','product','checkout','category','cart'],true))$type='home';
        $preview=match($type){'checkout'=>'/checkout','product'=>'/catalog','category'=>'/catalog','cart'=>'/cart',default=>'/'};
        return $this->render('@storefront/admin/appearance/editor.html.twig',[
            'type'=>$type,
            'preview_url'=>$preview,
            'presentation'=>$this->presentation->get($ctx->storeId),
            'has_draft'=>$this->layouts->draft($ctx->storeId,$type)!==null,
            'history'=>$this->layouts->history($ctx->storeId,$type),
        ]);
    }
}
