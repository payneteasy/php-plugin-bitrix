<?php

namespace Paynet\Pay;

class PaynetSearcher {
    private $rootPath;
    private $foundFolders = [];
    private $short;

    public function __construct($short = false)
    {
        $this->rootPath = $_SERVER['DOCUMENT_ROOT'];
        $this->short = $short;
    }

    /**
     * @param $targetFolder
     * @return array
     */
    public function search($targetFolder): array
    {
        $this->recursiveSearch($this->rootPath, $targetFolder);
        return $this->foundFolders;
    }

    /**
     * @param $targetFolder
     * @return bool
     */
    public function stepSearch($targetFolder): bool
    {
        $checked = 0;
        $stepTarget = explode('/', trim($targetFolder, '/'));

        if (is_array($stepTarget) && count($stepTarget) > 1) {
            $clueTarget = '';

            foreach($stepTarget as $folder) {
                $clueTarget .= '/' . $folder;

                if (file_exists($this->rootPath . $clueTarget)) {
                    $checked++;
                }
            }
        }

        if ($checked) {
            return true;
        }

        return false;
    }

    /**
     * @param $currentPath
     * @param $targetFolder
     * @return void
     */
    private function recursiveSearch($currentPath, $targetFolder): void
    {
        $excludes = [
            '.',
            '..',
            'about',
            'auth',
            'catalog',
            'desktop_app',
            'demo',
            'login',
            'news',
            'personal',
            'search',
            'store',
            'upload',
            'var',
            'activities',
            'blocks',
            'cache',
            'catalog_export',
            'components',
            'css',
            'fonts',
            'gadgets',
            'js',
            'legal',
            'managed_cache',
            'otp',
            'panel',
            'services',
            'sound',
            'stack_cache',
            'template',
            'templates',
            'themes',
            'tmp',
            'tools',
            'updates',
            'wizard',
        ];
        $files = array_diff(scandir($currentPath), $excludes);

        if (!empty($files)) {
            sort($files);
            foreach ($files as $file) {
                $filePath = $currentPath . '/' . $file;

                if (is_dir($filePath) && strpos($filePath, $currentPath . '/include') !== true) {
                    if ($file === $targetFolder) {
                        $folderName = basename($filePath);
                        $shortPath = substr($filePath, strlen($this->rootPath));
                        if ($this->short) {
                            $this->foundFolders[$folderName] = $shortPath;
                        } else {
                            $this->foundFolders[$folderName] = $filePath;
                        }
                    } else {
                        $this->recursiveSearch($filePath, $targetFolder);
                    }
                }
            }
        }
    }
}