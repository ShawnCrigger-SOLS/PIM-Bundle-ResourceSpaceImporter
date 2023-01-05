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


class AddCustomMetaDataCommand extends ContainerAwareCommand {

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
        // These get set for the CLI for some reason to on
        @ini_set("gd.jpeg_ignore_warning", 1);
        @ini_set('display_errors', 0);
        @ini_set('display_startup_errors', 0);
        parent::__construct();
        // These get set for the CLI for some reason to on
        @ini_set("gd.jpeg_ignore_warning", 1);
        @ini_set('display_errors', 0);
        @ini_set('display_startup_errors', 0);

    }

    protected function configure() {
        $this->setName('resourcespace:metadata')
                ->setDescription('Reads CSV of Resource Space IDs and verifies Asset has MetaData if it exists and makes note if it does not exist.')
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
        if ($sourcefile != '') {
            $ext = explode('.', $sourcefile);
            $fExt = $ext[1];
        }

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

        $db = \Pimcore\Db::get();
        $id_query = "SELECT `id`, `hasMetaData` FROM `assets` WHERE `type`='image' AND `filename` = ?";
        $id = $db->fetchRow($id_query, [$newFile]);
        if (!is_array($id) && !isset($id['id'])) {
            $output->writeln([
                "Retuned ID was not numeric",
                $id,
                $newFile
            ]);
            dump($id);
            return false;
        }
        if ($id['hasMetaData'] != 0) {
            $output->writeln([
                "",
                "{$id['id']} for {$newFile} already has metadata not overwriting metadata.",
                "",
            ]);
            return false;
        }
        $id = $id['id'];

        $asset = \Pimcore\Model\Asset::getById($id);
        if (!is_object($asset)) {
            $output->writeln([
                "Asset not found with filename",
                $newFile
            ]);
            dd($asset);
            return false;
        }
        if (is_object($asset)) {
            $sf = $asset->getMetadata("sourcefile", "en");
            $of = $asset->getMetadata("originalfilename", "en");
            if ((!$sf && !$of) OR empty($sf) && empty($of)) {
                if (is_array($newMetaData)) {
                    foreach ($newMetaData as $meta) {
                        $asset->addMetadata($meta->name, "input", $meta->value, "en");
                    }
                    $asset->save();
                    $this->progressBar->setMessage('Added Missing Meta Data to Resource Space ID:' . $id);
                    return true;
                } else {
                    dd($sf, $of);
                }

            }
            return false;
        }

/*            // unset($newData);
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
*/
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