<?php
require 'vendor/autoload.php';
$app = \Laminas\Mvc\Application::init(require 'config/application.config.php');
$c = $app->getServiceManager();
$db = $c->get(\Application\Service\DbService::class)->getAdapter();

echo "Total pricing items: ";
$res = $db->query('SELECT COUNT(*) as c FROM pricing_items', [])->current();
echo ($res['c'] ?? 'ERROR') . PHP_EOL;

echo "Total active pricing items: ";
$res = $db->query('SELECT COUNT(*) as c FROM pricing_items WHERE isActive = 1', [])->current();
echo ($res['c'] ?? 'ERROR') . PHP_EOL;
