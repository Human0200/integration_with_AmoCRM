<?php

class FormAmoCRMHandler
{
    const AMOCRM_SUBDOMAIN = 'pkintellekt';
    const AMOCRM_ACCESS_TOKEN = '';
    
    const RESPONSIBLE_USER_ID = 3673005;
    const PIPELINE_ID = 10131606;
    const STATUS_ID = 80274978;
    
    // ========== ОБРАБОТЧИК ФОРМ ==========
    public static function sendToAmoCRM($WEB_FORM_ID, $RESULT_ID)
    {
        try {
            if (!CModule::IncludeModule('form')) {
                return;
            }
            
            $formData = self::getFormData($RESULT_ID);
            
            if (empty($formData)) {
                return;
            }
            
            $leadData = self::prepareLead($formData, $WEB_FORM_ID);
            self::createAmoCRMLead($leadData);
            
        } catch (Exception $e) {
            self::log('Form Error: ' . $e->getMessage());
        }
    }
    
    private static function getFormData($resultId)
    {
        $data = [];
        
        CFormResult::GetDataByID(
            $resultId,
            [],
            $arForm,
            $arQuestions,
            $arAnswers
        );
        
        if (!empty($arQuestions)) {
            foreach ($arQuestions as $sid => $arQuestion) {
                if (is_array($arQuestion)) {
                    foreach ($arQuestion as $answerId => $arAnswer) {
                        if (!empty($arAnswer['VALUE'])) {
                            $data[$sid] = $arAnswer['VALUE'];
                            break;
                        } elseif (!empty($arAnswer['USER_TEXT'])) {
                            $data[$sid] = $arAnswer['USER_TEXT'];
                            break;
                        }
                    }
                }
            }
        }
        
        return $data;
    }
    
    private static function prepareLead($formData, $formId)
    {
        $lead = [
            'name' => 'Заявка с сайта',
            'responsible_user_id' => self::RESPONSIBLE_USER_ID,
            'pipeline_id' => self::PIPELINE_ID,
            'status_id' => self::STATUS_ID,
            'created_at' => time(),
        ];
        
        $contact = [];
        $notes = [];
        
        $notes[] = 'ID формы: ' . $formId;
        $notes[] = 'Дата: ' . date('d.m.Y H:i:s');
        $notes[] = '---';
        
        // Имя
        if (!empty($formData['NAME'])) {
            $contact['name'] = $formData['NAME'];
            $lead['name'] = 'Заявка от ' . $formData['NAME'];
        }
        
        // Телефон
        if (!empty($formData['PHONE'])) {
            $contact['custom_fields_values'][] = [
                'field_code' => 'PHONE',
                'values' => [
                    ['value' => $formData['PHONE'], 'enum_code' => 'WORK']
                ]
            ];
        }
        
        // Email
        if (!empty($formData['EMAIL'])) {
            $contact['custom_fields_values'][] = [
                'field_code' => 'EMAIL',
                'values' => [
                    ['value' => $formData['EMAIL'], 'enum_code' => 'WORK']
                ]
            ];
        }
        
        // Все остальные поля в комментарий
        foreach ($formData as $fieldCode => $fieldValue) {
            if (in_array($fieldCode, ['NAME', 'PHONE', 'EMAIL']) || empty($fieldValue)) {
                continue;
            }
            
            $notes[] = $fieldCode . ': ' . $fieldValue;
        }
        
        return [
            'lead' => $lead,
            'contact' => !empty($contact) ? $contact : null,
            'notes' => $notes
        ];
    }
    
    // ========== ОБРАБОТЧИК ЗАКАЗОВ ==========
    public static function onOrderSaved($order, $isNew, $isCanceled, $fields)
    {
        try {
            self::log('=== START ORDER PROCESSING ===');
            
            // Проверяем, что это новый заказ
            if (!$isNew) {
                self::log('Order is not new, skipping');
                return;
            }
            
            // Проверяем, что передан объект заказа
            if (!($order instanceof \Bitrix\Sale\Order)) {
                self::log('Invalid order object');
                return;
            }
            
            $orderId = $order->getId();
            if (!$orderId) {
                self::log('Order ID is empty');
                return;
            }
            
            self::log('Processing new order ID: ' . $orderId);
            
            // Обрабатываем заказ
            self::processOrder($order);
            
            self::log('=== ORDER PROCESSED SUCCESSFULLY ===');
            
        } catch (Exception $e) {
            self::log('ERROR in onOrderSaved: ' . $e->getMessage());
        }
    }
    
    private static function processOrder(\Bitrix\Sale\Order $order)
    {
        $orderId = $order->getId();
        $accountNumber = $order->getField('ACCOUNT_NUMBER');
        $price = $order->getPrice();
        
        self::log("Order #{$accountNumber}, Amount: {$price} RUB");
        
        // Получаем свойства заказа
        $properties = [];
        $propertyCollection = $order->getPropertyCollection();
        foreach ($propertyCollection as $property) {
            $code = $property->getField('CODE');
            $value = $property->getValue();
            if (!empty($code) && !empty($value)) {
                $properties[$code] = $value;
                self::log("Property: {$code} = {$value}");
            }
        }
        
        // Получаем товары
        $products = [];
        $basket = $order->getBasket();
        foreach ($basket as $item) {
            $productName = $item->getField('NAME');
            $quantity = $item->getQuantity();
            $price = $item->getPrice();
            
            if (!empty($productName)) {
                $productInfo = "{$productName} (x{$quantity}) - {$price} RUB";
                $products[] = $productInfo;
                self::log("Product: {$productInfo}");
            }
        }
        
        // Подготавливаем данные для amoCRM
        $leadData = self::prepareOrderData($order, $properties, $products);
        
        // Создаем лид в amoCRM
        $leadId = self::createAmoCRMLead($leadData);
        
        if ($leadId) {
            self::log("SUCCESS: Lead created in amoCRM with ID: {$leadId}");
        } else {
            self::log("ERROR: Failed to create lead in amoCRM");
        }
        
        return $leadId;
    }
    
    private static function prepareOrderData($order, $properties, $products)
    {
        $orderId = $order->getId();
        $accountNumber = $order->getField('ACCOUNT_NUMBER');
        $price = $order->getPrice();
        $dateInsert = $order->getField('DATE_INSERT') ? $order->getField('DATE_INSERT')->format('d.m.Y H:i:s') : date('d.m.Y H:i:s');
        
        // Формируем название лида
        $leadName = "Order #{$accountNumber}";
        if (!empty($properties['NAME'])) {
            $leadName = "Order from {$properties['NAME']}";
        } elseif (!empty($properties['FIO'])) {
            $leadName = "Order from {$properties['FIO']}";
        }
        
        // Основные данные лида
        $lead = [
            'name' => $leadName,
            'price' => (int)$price,
            'responsible_user_id' => self::RESPONSIBLE_USER_ID,
            'pipeline_id' => self::PIPELINE_ID,
            'status_id' => self::STATUS_ID,
            'created_at' => time(),
        ];
        
        // Данные контакта
        $contact = [];
        if (!empty($properties['NAME'])) {
            $contact['name'] = $properties['NAME'];
        } elseif (!empty($properties['FIO'])) {
            $contact['name'] = $properties['FIO'];
        }
        
        if (!empty($properties['PHONE'])) {
            $contact['custom_fields_values'][] = [
                'field_code' => 'PHONE',
                'values' => [
                    ['value' => $properties['PHONE'], 'enum_code' => 'WORK']
                ]
            ];
        }
        
        if (!empty($properties['EMAIL'])) {
            $contact['custom_fields_values'][] = [
                'field_code' => 'EMAIL',
                'values' => [
                    ['value' => $properties['EMAIL'], 'enum_code' => 'WORK']
                ]
            ];
        }
        
        // Примечание
        $notes = [];
        $notes[] = "Online Store Order";
        $notes[] = "Order Number: {$accountNumber}";
        $notes[] = "Amount: {$price} RUB";
        $notes[] = "Date: {$dateInsert}";
        $notes[] = "---";
        
        if (!empty($products)) {
            $notes[] = "PRODUCTS:";
            foreach ($products as $product) {
                $notes[] = "- {$product}";
            }
            $notes[] = "---";
        }
        
        // Дополнительные свойства
        foreach ($properties as $code => $value) {
            if (in_array($code, ['NAME', 'FIO', 'PHONE', 'EMAIL']) || empty($value)) {
                continue;
            }
            $notes[] = "{$code}: {$value}";
        }
        
        return [
            'lead' => $lead,
            'contact' => !empty($contact) ? $contact : null,
            'notes' => $notes
        ];
    }
    
    // ========== ОБЩИЕ МЕТОДЫ ДЛЯ AMOCRM ==========
    private static function createAmoCRMLead($data)
    {
        try {
            $contactId = null;
            
            // Создаем контакт если есть данные
            if (!empty($data['contact'])) {
                $contactId = self::createAmoCRMContact($data['contact']);
            }
            
            // Подготавливаем данные лида
            $leadData = [$data['lead']];
            
            // Добавляем контакт к лиду
            if ($contactId) {
                $leadData[0]['_embedded']['contacts'] = [['id' => $contactId]];
            }
            
            // Создаем лид
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/leads';
            $response = self::apiRequest($url, 'POST', $leadData);
            
            if ($response && !empty($response['_embedded']['leads'][0]['id'])) {
                $leadId = $response['_embedded']['leads'][0]['id'];
                
                // Добавляем примечание
                if (!empty($data['notes'])) {
                    self::addAmoCRMNote($leadId, implode("\n", $data['notes']));
                }
                
                return $leadId;
            }
            
            self::log('Failed to create lead. Response: ' . json_encode($response));
            return false;
            
        } catch (Exception $e) {
            self::log('Error creating lead: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function createAmoCRMContact($contactData)
    {
        try {
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/contacts';
            $response = self::apiRequest($url, 'POST', [$contactData]);
            
            if ($response && !empty($response['_embedded']['contacts'][0]['id'])) {
                return $response['_embedded']['contacts'][0]['id'];
            }
            
            return null;
            
        } catch (Exception $e) {
            self::log('Error creating contact: ' . $e->getMessage());
            return null;
        }
    }
    
    private static function addAmoCRMNote($leadId, $text)
    {
        try {
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/leads/' . $leadId . '/notes';
            
            $noteData = [[
                'note_type' => 'common',
                'params' => ['text' => $text]
            ]];
            
            self::apiRequest($url, 'POST', $noteData);
            
        } catch (Exception $e) {
            self::log('Error adding note: ' . $e->getMessage());
        }
    }
    
    private static function apiRequest($url, $method = 'POST', $data = null)
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $headers = [
            'Authorization: Bearer ' . self::AMOCRM_ACCESS_TOKEN,
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_error($ch)) {
            self::log('CURL Error: ' . curl_error($ch));
        }
        
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            return json_decode($response, true);
        }
        
        self::log("API Error. HTTP: {$httpCode}, Response: {$response}");
        return false;
    }
    
    private static function log($message)
    {
        try {
            $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/amocrm.log';
            $logDir = dirname($logFile);
            
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            file_put_contents(
                $logFile,
                date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (Exception $e) {
            // ignore
        }
    }
}