<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/FormAmoCRMHandler.php');

use Bitrix\Main\EventManager;

$eventManager = EventManager::getInstance();
$eventManager->addEventHandler(
    'form',
    'onAfterResultAdd',
    ['FormAmoCRMHandler', 'sendToAmoCRM']
);