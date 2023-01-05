<?php

namespace ResourceSpacePullBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Pimcore\Console\AbstractCommand;
use Pimcore\Model\DataObject;
// use Pimcore\Model\DataObject\Service;
use Pimcore\Model\Asset\Service;
use ResourceSpacePullBundle\Services\BundleService;
use Pimcore\Db;
use Pimcore\Model\Asset;
use Symfony\Component\Console\Helper\ProgressBar;
use Pimcore\Model\Element\Tag;

class TagCommand extends ContainerAwareCommand {

    /**
     * @var $db holds database object
     */
    protected $db;

    /**
     * @var $rootParent holds the Taxonomy Parent Tag
     */
    protected $rootParent;

    /**
     * @var $usTagID holds the Tag ID for the US Country Tag
     */
    protected $usTagID = 521;

    /**
     * @var $caTagID holds the Tag ID for the CA Country Tag
     */
    protected $caTagID = 522;

    /**
     * @var $rootTaxTagID holds the Taxonomy ID for the Root Taxonomy Tag
     */
    protected $rootTaxTagID = 523;

    /**
     * @var $vendorTagRootID holds the Vendor Parent Tag
     */
    protected $vendorTagRootID = 1022;

    /**
     * @var $productTypeTagRootID holds the Taxonomy Parent Tag
     */
    protected $productTypeTagRootID = 1023;


    /**
     * ExportCommand constructor.
     * @param BundleService $bundleService
     */
    public function __construct(BundleService $bundleService)
    {
        ini_set("gd.jpeg_ignore_warning", 1);
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);

        $this->db = \Pimcore\Db::get();
        $this->bundleService = $bundleService;
        parent::__construct();
    }

    protected function configure() {
        $this->setName('resourcespace:tag-assets')->setDescription('Tags all existing assets.');
    }

    /**
     * Execute command - from cli
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws \Exception
     *
     * @return int
     *
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $db = \Pimcore\Db::get();
        $path_query = "SELECT `id`, `path`  FROM `assets` WHERE `type`='image'";
        $paths = $db->fetchAll($path_query);
        $count = 0;
        $ids = [];
        foreach ($paths as $value) {
            $ids[] = $value;
        }

        $total = count($paths);

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% -- %message%');
        $progressBar = new ProgressBar($output, $total);
        $progressBar->setFormat('custom');
        $progressBar->start();

        $usTag = \Pimcore\Model\Element\Tag::getById($this->usTagID);
        $caTag = \Pimcore\Model\Element\Tag::getById($this->caTagID);
        $this->rootParent = \Pimcore\Model\Element\Tag::getById($this->rootTaxTagID);
        foreach ($ids as $asset) {
            $id = $asset['id'];
            $image = \Pimcore\Model\Asset::getById($id);
            $vendorName  = $image->getMetadata("vendor", "en");
            $productType = $image->getMetadata("producttype", "en");
            $vendorTag      = $this->setVendorTag($vendorName);
            $productTypeTag = $this->setProductTypeTag($productType);

            // Handle Country Tag
            $countryTag = $usTag;
            if (strstr($asset['path'], 'media/CA/') > 0) {
                $countryTag = $caTag;
            }
            $tags = [$countryTag];
            if (is_object($vendorTag)) {
                $tags[] = $vendorTag;
            }
            if (is_object($productTypeTag)) {
                $tags[] = $productTypeTag;
            }

            \Pimcore\Model\Element\Tag::setTagsForElement('asset', $id, $tags);

            // Figure out Taxonomy Tags from Path
            $path = str_replace(['/media/CA/', '/media/US/'], '', $asset['path']);
            $taxes = explode('/', $path);
            $taxes = array_filter($taxes);
            $i = 0;
            $parent = $this->rootParent->getId();
            $parentText = $this->rootParent->getName();
            foreach ($taxes as $tax) {
                if (empty($tax)) continue;
                $tag = $this->getTaxTag($tax, $parent);
                $progressBar->setMessage('Tagging Asset ID:' . $id . " with Tax Tag {$tax} and {$parentText}");
                \Pimcore\Model\Element\Tag::addTagToElement('asset', $id, $tag);
                $i++;
                if ($i > 0) {
                    $parent = $tag->getId();
                    $parentText = $tag->getName();
                } elseif ($i >= 4) {
                    $progressBar->setMessage("Asset ID:{$id} has more then {$i} levels of taxonomy!");
                    $parent = $this->rootParent->getId();
                    $parentText = $this->rootParent->getName();
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();
    }


    public function setProductTypeTag($productType) {
        if ($productType == '' || empty($productType)) return false;
        $SQL = "SELECT id FROM tags WHERE name = ? LIMIT 1";
        $tagId = $this->db->fetchCol($SQL, [$productType]);
        if ($tagId[0] > 0) {
            return \Pimcore\Model\Element\Tag::getById($tagId[0]);
        }
        // else create new tax tag
        $tag = new \Pimcore\Model\Element\Tag();
        $tag->setName($productType)->setParentId($this->productTypeTagRootID)->save();
        return $tag;
    }

    public function setVendorTag($vendorName) {
        if ($vendorName == '' || empty($vendorName)) return false;
        $SQL = "SELECT id FROM tags WHERE name = ? LIMIT 1";
        $vendorTagId = $this->db->fetchCol($SQL, [$vendorName]);
        if ($vendorTagId[0] > 0) {
            return \Pimcore\Model\Element\Tag::getById($vendorTagId[0]);
        }
        // else create new tax tag
        $tag = new \Pimcore\Model\Element\Tag();
        $tag->setName($vendorName)->setParentId($this->vendorTagRootID)->save();
        return $tag;
    }

    public function getTaxTag($taxName, $parent = '2') {
        $SQL = "SELECT id FROM tags WHERE name = ? LIMIT 1";
        $taxonomyTagId = $this->db->fetchCol($SQL, [$taxName]);
        if ($taxonomyTagId[0] > 0) {
            return \Pimcore\Model\Element\Tag::getById($taxonomyTagId[0]);
        }
        // else create new tax tag
        $tag = new \Pimcore\Model\Element\Tag();
        $tag->setName($taxName)->setParentId($parent)->save();
        return $tag;
    }

    private function array_flatten($array, $prefix = '') {
        $result = array();
        foreach($array as $key=>$value) {
            if(is_array($value)) {
                $result = $result + $this->array_flatten($value, $prefix . $key . '.');
            }
            else {
                $result[$prefix.$key] = $value;
            }
        }
        return $result;
    }

}
