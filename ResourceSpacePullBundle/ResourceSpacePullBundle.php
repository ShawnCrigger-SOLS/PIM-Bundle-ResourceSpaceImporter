<?php

namespace ResourceSpacePullBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;

class ResourceSpacePullBundle extends AbstractPimcoreBundle
{
    public function getJsPaths()
    {
        return [
            '/bundles/resourcespacepull/js/pimcore/startup.js'
        ];
    }
}