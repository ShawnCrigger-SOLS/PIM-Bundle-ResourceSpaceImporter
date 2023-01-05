<?php

namespace ResourceSpacePullBundle\Services;

use Pimcore\Cache;
use Pimcore\Model\Asset;
use ResourceSpacePullBundle\Lib\Constants;
use ResourceSpacePullBundle\Lib\Utils;
use ResourceSpacePullBundle\Lib\RSLogger;

class BundleService
{
    /**
     * get bundle configs
     *
     * @param null|string $param
     * @return mixed
     */
    public function getConfig(?string $param = null)
    {
        $configFile = Constants::CONFIG_FILE;
        $config = self::getConfigFromYmlFile($configFile);

        if($param){
            return $config[$param];
        }

        return $config;
    }

    /**
     * get yml config for the given file
     * caching the file data if environment is not dev
     *
     * @param $filePath
     * @return mixed
     */
    public function getConfigFromYmlFile($filePath)
    {
        $env = \Pimcore\Config::getEnvironment();
        $cacheKey = Utils::getCacheKeyForFilePath($filePath);

        if ($env != "dev") {
            if ($config = Cache::load($cacheKey)) {
            } else {
                $config = Utils::getYmlConfig($filePath);
                Cache::save($config, $cacheKey);
            }
        } else {
            $config = Utils::getYmlConfig($filePath);
            Cache::save($config, $cacheKey);
        }
        $returnConfig = $config;
        return $returnConfig;
    }

    /**
     * get the asset folder to keep uploaded templates
     *
     * @return Asset
     * @throws \Exception
     */
    public function getAssetFolderForUploadedTemplates(): Asset
    {
        $folderPath = '/'.$this->getConfig('asset_folder').'/'.$this->getConfig('folder_for_uploaded_template_in_asset_folder');
        $folder = $this->getAssetFolderForPath($folderPath);

        return $folder;
    }

    /**
     * get asset folder for exported files
     *
     * @return Asset
     * @throws \Exception
     */
    public function getDefaultAssetFolderForExportedFiles(): Asset
    {
        $folderPath = $this->getDefaultAssetFolderPathForExportedFiles();
        $folder = $this->getAssetFolderForPath($folderPath);

        return $folder;
    }

    /**
     * @return string
     */
    public function getDefaultAssetFolderPathForExportedFiles(): string
    {
        return '/'.$this->getConfig('asset_folder').'/'.$this->getConfig('folder_for_exported_files_in_asset_folder');
    }

    /**
     * recursive function to create assets for path
     *
     * @param $path
     * @return null|Asset
     * @throws \Exception
     */
    public function getAssetFolderForPath($path): Asset
    {
        $asset = Asset::getByPath($path);

        if(!$asset){
            $assetParentPath = dirname($path);
            $assetName = basename($path);

            $assetParent = $this->getAssetFolderForPath($assetParentPath);
            $asset = new Asset();
            $asset->setParent($assetParent);
            $asset->setFilename($assetName);
            $asset->setType('folder');
            $asset->save();
        }
        return $asset;
    }

    /**
     * Function to build Resource Space Query and return the Query and the Signed Encrypted Signature
     *
     * @return string with keys query and signature
     */
    public function buildQuery($params = []) {
        try {
            $config = $this->getConfig('resource_space_pull');
            if ( ! isset($config['resource_space'])) {
                throw new \Exception("Resource Space Bundle is not configured", 500);
            }
        } catch (\Exception $e) {
            RSLogger::logException('rSpace', $e->getMessage(), $e);
            return false;
        }

        if (!isset($params['function'])) {
            return false;
        }
        $user   = $config['resource_space']['resource_user'];
        $apiKey = $config['resource_space']['resource_apikey'];
        $host   = $config['resource_space']['resource_url'];
        $function = $params['function'];
        $params = $params['params'];
// $function = 'get_system_status';
// $params = '';
// dd($user,$apiKey,$host);
        $query = "user={$user}&function={$function}{$params}";
        $sign  = hash("sha256", $apiKey . $query);
        return $host . $query . '&sign=' . $sign;
    }

}
