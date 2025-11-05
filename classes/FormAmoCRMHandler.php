<?php

class FormAmoCRMHandler
{
    const AMOCRM_SUBDOMAIN = 'pkintellekt';
    const AMOCRM_ACCESS_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiIsImp0aSI6ImQyNzE2ZTI1YzJkNzE2YWE2MzA1YmRiMjIwMTJhNWM1OWQ5NjJmNDUzNjc3YzMwMDY5MzQ5YzdlN2QxZmVhNTMzODVkNGFiMzNjMmMzYzVlIn0.eyJhdWQiOiI0MjY4ZTU3MC0xZGE0LTQ1NjktOTAyZi0yODBlYzVjMzQwODUiLCJqdGkiOiJkMjcxNmUyNWMyZDcxNmFhNjMwNWJkYjIyMDEyYTVjNTlkOTYyZjQ1MzY3N2MzMDA2OTM0OWM3ZTdkMWZlYTUzMzg1ZDRhYjMzYzJjM2M1ZSIsImlhdCI6MTc2MjM0MTI4OSwibmJmIjoxNzYyMzQxMjg5LCJleHAiOjE4OTYxMzQ0MDAsInN1YiI6IjExOTAzODEzIiwiZ3JhbnRfdHlwZSI6IiIsImFjY291bnRfaWQiOjMxNDkxNjkwLCJiYXNlX2RvbWFpbiI6ImFtb2NybS5ydSIsInZlcnNpb24iOjIsInNjb3BlcyI6WyJjcm0iLCJmaWxlcyIsImZpbGVzX2RlbGV0ZSIsIm5vdGlmaWNhdGlvbnMiLCJwdXNoX25vdGlmaWNhdGlvbnMiXSwiaGFzaF91dWlkIjoiMjE5OTNjZWUtNzE0OC00NWViLWE3Y2MtZDUyZmFhMzcyMDliIiwidXNlcl9mbGFncyI6MCwiYXBpX2RvbWFpbiI6ImFwaS1iLmFtb2NybS5ydSJ9.EAmzZf0cS2Asln6P5TeM9PGaCJgyFe36XTEvJo1PtihVK8HgqKlJmtJLiHMaGyP42G8t4FX0k3Vy83MKcQfa0h7ldncU_k5IOCJrPPlSmIlo_yq6Mqd1r0AdloNONijBr-xUKmvCM3jXi7wKf8X2BS2x_dxUvH9ONF5H1vl-y-cXMhn0zduejFraAOvVvyej90FwOTV64HIjXHV7Moz1nonY5cKIzVsabWD2hdeCEwOJb31FvcKTu68dM8wdSVcaLYgnYXN61fCnB0fJgo1HI_PyRre75JHrvS3noXDtQ-Kn58mF_RcNU0qlfws7R5xsOuzHKnBlp8iI6MnY9YRG_g';
    
    const RESPONSIBLE_USER_ID = 3673005;
    const PIPELINE_ID = 10131606;
    const STATUS_ID = 80274978;
    
    public static function sendToAmoCRM($WEB_FORM_ID, $RESULT_ID)
    {
        try {
            if (!\CModule::IncludeModule('form')) {
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