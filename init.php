<?php

require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/classes/FormAmoCRMHandler.php');


AddEventHandler('form', 'onAfterResultAdd', ['FormAmoCRMHandler', 'sendToAmoCRM']);


AddEventHandler('sale', 'OnSaleOrderSaved', ['FormAmoCRMHandler', 'sendOrderToAmoCRM']);