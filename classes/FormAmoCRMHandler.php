<?php

class FormAmoCRMHandler
{
    const AMOCRM_SUBDOMAIN = '31491690';
    const AMOCRM_ACCESS_TOKEN = '';
    
    const RESPONSIBLE_USER_ID = 3673005;
    const PIPELINE_ID = 10131606;
    const STATUS_ID = 80274978;
    
    public static function sendToAmoCRM($WEB_FORM_ID, $RESULT_ID)
    {
        try {
            if (!\Bitrix\Main\Loader::includeModule('form')) {
                return;
            }
            
            $formData = self::getFormData($RESULT_ID);
            
            if (empty($formData)) {
                return;
            }
            
            $leadData = self::prepareLead($formData, $WEB_FORM_ID);
            self::createLead($leadData);
            
        } catch (\Exception $e) {
            self::log('Ошибка: ' . $e->getMessage());
        }
    }
    
    private static function getFormData($resultId)
    {
        $data = [];
        
        \CFormResult::GetDataByID(
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
        
        foreach ($formData as $fieldCode => $fieldValue) {
            if (empty($fieldValue)) {
                continue;
            }
            
            $fieldCodeUpper = strtoupper($fieldCode);
            
            // Имя
            if (stripos($fieldCode, 'NAME') !== false || 
                stripos($fieldCode, 'IMY') !== false || 
                stripos($fieldCode, 'IMYA') !== false) {
                $contact['name'] = $fieldValue;
                $lead['name'] = 'Заявка от ' . $fieldValue;
                continue;
            }
            
            // Телефон
            if (stripos($fieldCode, 'PHONE') !== false || 
                stripos($fieldCode, 'TEL') !== false ||
                stripos($fieldCode, 'TELEFON') !== false) {
                $contact['custom_fields_values'][] = [
                    'field_code' => 'PHONE',
                    'values' => [
                        ['value' => $fieldValue, 'enum_code' => 'WORK']
                    ]
                ];
                continue;
            }
            
            // Email
            if (stripos($fieldCode, 'EMAIL') !== false || 
                stripos($fieldCode, 'MAIL') !== false ||
                stripos($fieldCode, 'E-MAIL') !== false) {
                $contact['custom_fields_values'][] = [
                    'field_code' => 'EMAIL',
                    'values' => [
                        ['value' => $fieldValue, 'enum_code' => 'WORK']
                    ]
                ];
                continue;
            }
            
            // Остальное в комментарий
            $notes[] = $fieldCode . ': ' . $fieldValue;
        }
        
        return [
            'lead' => $lead,
            'contact' => !empty($contact) ? $contact : null,
            'notes' => $notes
        ];
    }
    
    private static function createLead($data)
    {
        try {
            $contactId = null;
            
            if (!empty($data['contact'])) {
                $contactId = self::createContact($data['contact']);
            }
            
            $leadData = [$data['lead']];
            
            if ($contactId) {
                $leadData[0]['_embedded']['contacts'] = [['id' => $contactId]];
            }
            
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/leads';
            $response = self::apiRequest($url, 'POST', $leadData);
            
            if ($response && !empty($response['_embedded']['leads'][0]['id'])) {
                $leadId = $response['_embedded']['leads'][0]['id'];
                
                if (!empty($data['notes'])) {
                    self::addNote($leadId, implode("\n", $data['notes']));
                }
                
                self::log('Лид создан. ID: ' . $leadId);
                return $leadId;
            }
            
            return false;
            
        } catch (\Exception $e) {
            self::log('Ошибка создания лида: ' . $e->getMessage());
            return false;
        }
    }
    
    private static function createContact($contactData)
    {
        try {
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/contacts';
            $response = self::apiRequest($url, 'POST', [$contactData]);
            
            if ($response && !empty($response['_embedded']['contacts'][0]['id'])) {
                return $response['_embedded']['contacts'][0]['id'];
            }
            
            return null;
            
        } catch (\Exception $e) {
            self::log('Ошибка создания контакта: ' . $e->getMessage());
            return null;
        }
    }
    
    private static function addNote($leadId, $text)
    {
        try {
            $url = 'https://' . self::AMOCRM_SUBDOMAIN . '.amocrm.ru/api/v4/leads/' . $leadId . '/notes';
            
            $noteData = [[
                'note_type' => 'common',
                'params' => ['text' => $text]
            ]];
            
            self::apiRequest($url, 'POST', $noteData);
            
        } catch (\Exception $e) {
            self::log('Ошибка добавления примечания: ' . $e->getMessage());
        }
    }
    
    private static function apiRequest($url, $method = 'POST', $data = null)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $headers = [
                'Authorization: Bearer ' . self::AMOCRM_ACCESS_TOKEN,
                'Content-Type: application/json'
            ];
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            
            if ($method === 'POST' && $data !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300 && $response) {
                return json_decode($response, true);
            }
            
            self::log('API ошибка. HTTP: ' . $httpCode);
            return false;
            
        } catch (\Exception $e) {
            self::log('Ошибка запроса: ' . $e->getMessage());
            return false;
        }
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
                date('Y-m-d H:i:s') . ' - ' . $message . "\n",
                FILE_APPEND
            );
        } catch (\Exception $e) {
        }
    }
}