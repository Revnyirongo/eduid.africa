<?php

use Doctrine\Common\Annotations\AnnotationRegistry;

$projectRoot = dirname(__DIR__, 3); // /app
$vendorDir = $projectRoot . '/app/vendor';

require_once $vendorDir . '/simplesamlphp/simplesamlphp/www/_include.php';

$loader = require $vendorDir . '/autoload.php';

AnnotationRegistry::registerLoader(array($loader, 'loadClass'));

$kernel = new AppKernel('prod', true);
$kernel->boot();
$container = $kernel->getContainer();
$sspgetter = $container->get('appbundle.sspgetter');

$metadata = $sspgetter->getIdps($_SERVER['HTTP_HOST']);

return $metadata;
