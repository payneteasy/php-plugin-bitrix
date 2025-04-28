<?php
require __DIR__ . '/vendor/autoload.php';

// Manual autoloader queue list
$autoloadDir = 'classes/general';
$autoloadFiles = array_diff(scandir(__DIR__ . DIRECTORY_SEPARATOR . $autoloadDir), ['.','..']);
$autoloadClasses = [];
if (!empty($autoloadFiles)) {
    foreach ($autoloadFiles as $file) {
        $namespace = str_replace('.php','',$file);
        $autoloadClasses["Paynet\Pay\\$namespace"] = "{$autoloadDir}/{$file}";
    }
}

CModule::AddAutoloadClasses('payneteasypayments.ecom', $autoloadClasses);
