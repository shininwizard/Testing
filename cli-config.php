<?php
// cli-config.php

require_once 'vendor\autoload.php';

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

$isDevMode = true;
$config = Setup::createAnnotationMetadataConfiguration(array(__DIR__."\src"), $isDevMode);

$ini = parse_ini_file('testing.ini');
$connectionOptions = array(
    'driver'   => 'pdo_mysql',
    'host'     => $ini['db_serv'],
    'dbname'   => $ini['db_name'],
    'user'     => $ini['db_user'],
    'password' => $ini['db_pass']
);

$entityManager = EntityManager::create($connectionOptions, $config);

return \Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($entityManager);
