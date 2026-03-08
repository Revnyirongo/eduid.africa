<?php

$container = require __DIR__ . '/bootstrap_symfony.php';
$sspgetter = $container->get(\App\Utils\SSPGetter::class);
$config = $sspgetter->getAuthsources();

return $config;
