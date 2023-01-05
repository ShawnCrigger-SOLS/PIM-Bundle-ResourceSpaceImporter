<?php

namespace ResourceSpacePullBundle\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use ResourceSpacePullBundle\Lib\Utils;

class DefaultController extends FrontendController
{
    /**
     * @Route("/resource_space_pull")
     */
    public function indexAction(Request $request)
    {
        $c = Utils::buildUser();
        dd($c);
        return new Response('Hello world from resource_space_pull');
    }
}
