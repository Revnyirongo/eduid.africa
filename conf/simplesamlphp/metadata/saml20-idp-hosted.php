<?php

$container = require __DIR__ . '/../bootstrap_symfony.php';
$sspgetter = $container->get(\App\Utils\SSPGetter::class);

$metadata = $sspgetter->getIdps($_SERVER['HTTP_HOST'] ?? '');

return $metadata;
