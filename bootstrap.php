<?php
// bootstrap.php
require_once 'vendor\autoload.php';
require_once 'src\Product.php';
require_once 'src\ProductRepository.php';

use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\EntityManager;

if (!file_exists('testing.ini')) {
	echo "testing.ini does not exist.\n";
	exit();
}
$ini = parse_ini_file('testing.ini');

$isDevMode = true;
$config = Setup::createAnnotationMetadataConfiguration(array(__DIR__."\src"), $isDevMode);

$connectionOptions = array(
    'driver'   => 'pdo_mysql',
    'host'     => $ini['db_serv'],
    'dbname'   => $ini['db_name'],
    'user'     => $ini['db_user'],
    'password' => $ini['db_pass']
);
$entityManager = EntityManager::create($connectionOptions, $config);
