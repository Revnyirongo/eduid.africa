<?php

use Doctrine\Common\Annotations\AnnotationRegistry;
use Symfony\Component\Dotenv\Dotenv;

$appDir = null;
$searchDir = dirname(__DIR__, 2); // conf/simplesamlphp
for ($i = 0; $i < 6 && $searchDir !== '.'; $i++) {
    foreach (array('app-modern', 'app') as $candidate) {
        $candidateDir = $searchDir . '/' . $candidate;
        if (is_file($candidateDir . '/vendor/autoload.php')) {
            $appDir = $candidateDir;
            break 2;
        }
    }

    $searchDir = dirname($searchDir);
}

if ($appDir === null) {
    throw new RuntimeException('Unable to locate a Symfony app directory with vendor/autoload.php');
}

require_once $appDir . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($appDir . '/.env')) {
    $dotenv = new Dotenv();
    $dotenv->usePutenv()->bootEnv($appDir . '/.env');
}

if (class_exists(AnnotationRegistry::class)) {
    AnnotationRegistry::registerLoader('class_exists');
}

$kernelClass = null;
if (class_exists('App\\Kernel')) {
    $kernelClass = 'App\\Kernel';
} elseif (class_exists('AppKernel')) {
    $kernelClass = 'AppKernel';
}

if ($kernelClass === null) {
    throw new RuntimeException('Unable to locate Symfony Kernel class.');
}

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'prod';
$debug = (bool)($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? false);

$kernel = new $kernelClass($env, $debug);
$kernel->boot();

return $kernel->getContainer();
