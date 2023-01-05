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


class ExportCommand extends ContainerAwareCommand {

    /**
     * @var $db holds database object
     */
    protected $db;

    /**
     * @var $missing holds assets missing from resource space
     */
    protected $missing = 0;

    /**
     * @var $differentFileName holds assets with a different file name
     */
    protected $differentFileName = 0;



    /**
     * ExportCommand constructor.
     * @param BundleService $bundleService
     */
    public function __construct(BundleService $bundleService)
    {
        $this->db = \Pimcore\Db::get();
        $this->bundleService = $bundleService;
        parent::__construct();
        // These get set for the CLI for some reason to on
        @ini_set("gd.jpeg_ignore_warning", 1);
        @ini_set('display_errors', 0);
        @ini_set('display_startup_errors', 0);
    }

    protected function configure() {
        $this->setName('resourcespace:pull-items')
                ->setDescription('Pulls Items, then Fetches Data from our Data Base, creates Directory Strucutre Based on Taxonomy Structure and finally renamed File and Moves to Final Taxonomy.')
                ->addArgument('csv', InputArgument::REQUIRED, 'CSV file to read Resource Space IDs from');
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
        /*

        $db = \Pimcore\Db::get();
        $path_query = "SELECT * FROM `assets` WHERE `filename` LIKE '%images%'";
        $paths = $db->fetchAll($path_query);
        $count = 0;
        $total = count($paths);
        foreach ($paths as $key => $value) {
            $id = $value['id'];
            $parent = 24515;
            $path = str_replace('images', 'images-bad', $value['path']);
            $path = 'images-bad';
            $up_query = "UPDATE `assets` SET `filename`='{$path}' WHERE id={$id}";
            $db->query($up_query);
            $count++;
            echo "{$count}/{$total} rows updated\n";
        }

        die();
        */

        $csv = $input->getArgument('csv');
        $ids = $this->read($csv);
        $ids = $this->array_flatten($ids);
        $ids = array_values($ids);
        $total = count($ids);

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% -- %message%');
        $this->progressBar = new ProgressBar($output, $total);
        $this->progressBar->setFormat('custom');
        $this->progressBar->start();

        foreach ($ids as $k => $id) {
            if (!is_numeric($id)) continue;
            $this->progressBar->advance();
            $this->progressBar->setMessage('Fetching Resource Space ID:' . $id);
            // $output->writeln(['Fetching Resource Space ID:' . $id]);
            $this->fetch_resource($id, $output);
        }
        $this->progressBar->finish();
        $output->writeln([
            "Files completed transfer currently {$this->missing} files are missing from Resource Space",
            "So far {$this->differentFileName} files exist on RS with different names."
        ]);

    }

    protected function curl_get_contents($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo curl_error($ch);
        } else {

        }
        curl_close($ch);
        return json_decode($result);
    }
    protected function fetch_resource($id, OutputInterface $output)
    {

        $params = [
            'function' => 'get_resource_field_data',
            'params' => '&resource=' . $id
        ];

        $q = $this->bundleService->buildQuery($params);
        // $field_data = json_decode(file_get_contents($q));
        $field_data = $this->curl_get_contents($q);

        $skuid     = '';
        $priority  = 0;
        $classpath = '';
        $sourcefile = false;
        $newMetaData = $field_data;
        if (!is_array($field_data)) {
            $output->writeln([
                "Metadata does not exist for RS ID {$id}",
                $field_data
            ]);
            return false;
        }

        foreach ($field_data as $data) {
            if (!is_object($data)) continue;
            if ($data->name == 'skuid') {
                $skuid = $data->value;
            } elseif ($data->name == 'priority') {
                $priority = (int) $data->value;
            } elseif ($data->name == 'classpath') {
                $classpath = $data->value;
            } elseif ($data->name == 'sourcefile') {
                $sourcefile = $data->value;
            } elseif ($data->name == 'originalfilename') {
                $sourcefile = $data->value;
            } else {
                continue;
            }
        }

        $params = [
            'function' => 'get_resource_path',
            'params' => "&ref={$id}&getfilepath=false"
        ];

        $getFileQuery = $this->bundleService->buildQuery($params);
        // $filename = json_decode(file_get_contents($q));
        $filename = $this->curl_get_contents($getFileQuery);
        $fExt  = pathinfo($filename, PATHINFO_EXTENSION);

        $data = $this->db->fetchAssoc('SELECT
            siteOneItem,
            country,
            (SELECT category_name FROM object_store_2 WHERE object_store_2.oo_id = object_store_1.taxonomyLevel1) AS taxonomy1,
            (SELECT category_name FROM object_store_2 WHERE object_store_2.oo_id = object_store_1.taxonomyLevel2) AS taxonomy2,
            (SELECT category_name FROM object_store_2 WHERE object_store_2.oo_id = object_store_1.taxonomyLevel3) AS taxonomy3,
            (SELECT category_name FROM object_store_2 WHERE object_store_2.oo_id = object_store_1.taxonomyLevel4) AS taxonomy4
            FROM object_store_1
            WHERE ueSkuId = ?', [$skuid]);

        $soIDExists = true;
        if ( ! is_array($data) && ! is_object($data)) {
            $newFile = "UE-{$skuid}-{$priority}.{$fExt}";
            $output->writeln([
                "SKU ID {$skuid} not found in PimCore database, putting in missing-so-id directory with name {$newFile}"
            ]);
            $soIDExists = false;
            $data['country'] = 'missing-image';
        } else {
            $newFile = "{$data['siteOneItem']}-{$priority}.{$fExt}";
        }

        $newData = @fopen($filename, 'r');
        // $newData = @file_get_contents($filename); // Prevent errors from being triggered in favor of exceptions
        if (!$newData || $newData === false) {
            $this->missing++;
            $output->writeln([
                "File not found on ResourceSpace file system, skipping RS ID {$id}",
                "So far {$this->missing} files do not exist on RS."
            ]);
            return false;
        }

        $newAsset = new \Pimcore\Model\Asset\Image();
        $newAsset->setFilename($newFile);
        $newAsset->setStream($newData);

        $assetPath = '/media';
        $previousTax = 'media';

        if ($data['country'] == US_CODE_ID) {
            $country = COUNTRY_US;
            $country_tag = 'united-states';
        } else if ($data['country'] == CA_CODE_ID) {
            $country = COUNTRY_CA;
            $country_tag = 'canada';
        } else if ($data['country'] == 'missing-so-id') {
            $country = 'missing-so-id';
            $country_tag = false;
        } else if ($data['country'] == 'missing-image') {
            $country = 'missing-image';
            $country_tag = false;
        }
        $assetPath .= "/{$country}";
        $previousTax .= "/{$country}";
        $taxTags    = array();

        for ($i=1; $i < 5; $i++) {
            $key = "taxonomy{$i}";
            if (!isset($data[$key]) || empty($data[$key])) {
                break;
            }
            $folder      = $data[$key];
            $folder      = strtolower($folder);
            $folder      = str_replace([' ', '&'], ['-', 'and'], $folder);
            // $folder      = str_replace(['--'], ['-'], $folder);
            $folder      = $this->getValidKeyName($folder);
            $taxTags[]   = $folder;
            $assetPath  .= '/' . $folder;
        }

        $exists = \Pimcore\Model\Asset::getByPath($assetPath . '/' . $newFile);
        if (is_object($exists)) {
            $sf = $exists->getMetadata("sourcefile", "en");
            $of = $exists->getMetadata("originalfilename", "en");
            if ((!$sf && !$of) OR empty($sf) && empty($of)) {
                $date = $exists->getCreationDate();
                // dd($date, 1642168977, $date <= 1642168977);
                if ($date <= 1642168977) {
                    if (is_array($newMetaData)) {
                        foreach ($newMetaData as $meta) {
                            $exists->addMetadata($meta->name, "input", $meta->value, "en");
                        }
                        $exists->save();
                        @fclose($newData);
                        $this->progressBar->setMessage('Added Missing Meta Data to Resource Space ID:' . $id);
                        return true;
                    }
                    return false;
                }
                return false;
            }

            $ogFileName = "{$skuid}-{$priority}.{$fExt}";
            if ($sf == $ogFileName OR $of == $ogFileName) {
                $this->progressBar->setMessage('Resource Space ID:' . $id . ' already exists.');
                return false;
            }

            $newFile = "DUPE-UE-{$skuid}-{$priority}.{$fExt}";
            $assetPath = "/media/duplicates";
            $previousTax = "media/duplicates";
            $this->differentFileName++;
            $this->progressBar->setMessage('Resource Space ID:' . $id . ' has different name then one pulled.');
            $output->writeln([
                "File on ResourceSpace file system, has different file name with same ID {$id}",
                "So far {$this->differentFileName} files have different file names then on RS."
            ]);

        }

        $this->checkFolderExist($assetPath);
        //$newAsset->setPath($assetPath);

        try {
            $newAsset->setParent(\Pimcore\Model\Asset::getByPath($assetPath));
            if (is_array($newMetaData)) {
                foreach ($newMetaData as $meta) {
                    $newAsset->addMetadata($meta->name, "input", $meta->value, "en");
                }
            } else {
                $output->writeln([
                    "Assets ID {$id} has no meta data?",
                    $newMetaData
                ]);

            }
            $newAsset->save();
            // $cId = $newAsset->getId();
            @fclose($newData);
            // unset($newData);
        } catch (\Exception $e) {

            $errorMsg = $e->getMessage();

            if (strstr($errorMsg, 'corrupt') > 0) {

                $output->writeln([
                    "RS ID {$id} has extraneous data in image file need to inspect better",
                    "Error Code:" . $e->getCode(),
                    'Hopefully try exececutting the following in a browser to obtain the image and inspect it with virus/malware and a hex editor.',
                    $getFileQuery,
                ]);
                return false;
            }

            $trace = $e->getTraceAsString();
            $output->writeln([
                'Exception:'.$e->getMessage,
                'ExceptionCode:'.$e->getCode,
                'Source File:'.$e->getFile . '@' . $e->getLine,
                'Stack Trace:'.$trace,
            ]);
            return false;
        }

    }










    private function addTags($cId, $countryTag, $taxTags, $output)
    {
        if (!isset($this->parentCountryId)) {
            $SQL = "SELECT id FROM tags WHERE name = 'country' LIMIT 1";
            $parentID = $this->db->fetchAll($SQL);
            if (isset($parentID[0])) {
                $parentID = $parentID[0];
                if (isset($parentID['id'])) {
                    $parentID = $parentID['id'];
                }
            }
            if (!is_numeric($parentID)) {
                $tag = new \Pimcore\Model\Element\Tag();
                $tag->setName("country")->save();
                $parentID = $tag->getId();
            }

            $this->parentCountryId = $parentID;
        }
        if (!isset($this->taxonomyTagId)) {
            $SQL = "SELECT id FROM tags WHERE name = 'taxonomy' LIMIT 1";
            $taxonomyTagId = $this->db->fetchAll($SQL);
            if (isset($taxonomyTagId[0])) {
                $taxonomyTagId = $taxonomyTagId[0];
                if (isset($taxonomyTagId['id'])) {
                    $taxonomyTagId = $taxonomyTagId['id'];
                }
            }
            if (!is_numeric($taxonomyTagId)) {
                $tag = new \Pimcore\Model\Element\Tag();
                $tag->setName("taxonomy")->save();
                $taxonomyID = $tag->getId();
            }

            $this->taxonomyTagId = $taxonomyID;
        }

        $SQL = "SELECT * FROM tags WHERE parentId = '{$this->parentCountryId}'";
        $tags = $this->db->fetchAll($SQL);
        foreach ($tags as $key => $value) {
            if (isset($value['name']) && $value['name'] == 'united-states') {
                $this->usTagId = $value['id'];
                $this->usTagId = new \Pimcore\Model\Element\Tag();
                $this->usTagId->setName("united-states")->setParentId($this->parentCountryId);
                $this->usTagId->setId($this->usTagId)->save();
                $output->writeln(['Using existing tag "united-states" with ID' . $this->usTagID]);

            }
            if (isset($value['name']) && $value['name'] == 'canada') {
                $this->caTagId = $value['id'];
                $this->caTag = new \Pimcore\Model\Element\Tag();
                $this->caTag->setName("canada")->setParentId($this->parentCountryId);
                $this->caTag->setId($this->caTagId)->save();

                $output->writeln(['Using existing tag "canada" with ID' . $this->caTagID]);
            }
        }

        if (!isset($this->caTag)) {
            $this->caTag = new \Pimcore\Model\Element\Tag();
            $this->caTag->setName("canada")->setParentId($this->parentCountryId)->save();
            $output->writeln(['Created new tag "canada" with ID' . $this->caTag->getId()]);
        }
        if (!isset($this->usTag)) {
            $this->usTag = new \Pimcore\Model\Element\Tag();
            $this->usTag->setName("united-states")->setParentId($this->parentCountryId)->save();
            $output->writeln(['Created new tag "united-states" with ID' . $this->usTag->getId()]);
        }
        if ($countryTag == 'united-states') {
            \Pimcore\Model\Element\Tag::addTagToElement("asset", $cId, $this->usTag);
        } else {
            \Pimcore\Model\Element\Tag::addTagToElement("asset", $cId, $this->caTag);
        }

        foreach ($taxTags as $taxTag) {
            $tag = false;
            $SQL = "SELECT * FROM tags WHERE `name` = '{$this->taxTag}'";
            $tags = $this->db->fetchAll($SQL);
            if ($tags && isset($tags[0]) && isset($tags[0]['id'])) {
                $tags = $tags[0];
                $tag = new \Pimcore\Model\Element\Tag();
                $tag->setName($tags['name'])->setId($tags['id'])->setParentId($this->taxonomyTagId)->save();
                $output->writeln(["Reusing tag \"{$taxTag}\" with ID" . $tag->getId()]);
            } else {
                $tag = new \Pimcore\Model\Element\Tag();
                $tag->setName($taxTag)->setParentId($this->taxonomyTagId)->save();
                $output->writeln(["Created new tag \"{$taxTag}\" with ID" . $tag->getId()]);
            }
            if (is_object($tags)) {
                \Pimcore\Model\Element\Tag::addTagToElement("asset", $cId, $tag);
            }
        }

    }
    private function read($csv) {
        if (!file_exists($csv)) {
            throw new \Exception("CSV file missing", 500);
        }

        $file = fopen($csv, 'r');
        while (!feof($file) ) {
            $line[] = fgetcsv($file, 1024);
        }
        fclose($file);
        return $line;
    }

    private function checkFolderExist($path) {
        if (empty(Service::pathExists($path))) {
            $folder = Service::createFolderByPath($path);
        } else {
            $folder = DataObject\AbstractObject::getByPath($path);
        }
        return $folder;
    }

    private function getValidKeyName($key) {
        return \Pimcore\File::getValidFilename($key);
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