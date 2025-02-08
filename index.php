<?php
require_once __DIR__ . '/utils.php';

// Configuration
const ENTITY_TYPE_ID = 1110;
const PF_SOURCE_ID = 'UC_PLY23S';
const COLLECTION_SOURCE = [
    'PF_CALL' => '41308',
    'PF_EMAIL' => '41309',
    'PF_WHATSAPP' => '41310',
];
const MODE_OF_ENQUIRY = [
    'WHATSAPP' => '41290',
    'EMAIL' => '41291',
    'CALL' => '41292',
];
const PROPERTY_TYPE = [
    'Apartment' => '41300',
    'Villa' => '41301',
    'Townhouse' => '41302',
    'Office' => '41303',
    'Plot' => '41304',
    'Building' => '41305',
    'Half Floor' => '41306',
    'Full Floor' => '41307',
];

class LeadProcessor
{
    private $leadFile;
    private $processedLeads;
    private $authToken;

    public function __construct(string $tokenFile, string $leadFile)
    {
        $this->authToken = getAuthToken($tokenFile);
        $this->leadFile = $leadFile;
        $this->processedLeads = getProcessedLeads($leadFile);
    }

    public function fetchAllLeads(string $timestamp): array
    {
        $encodedTimestamp = urlencode($timestamp);
        return [
            'email' => fetchLeads('leads', $encodedTimestamp, $this->authToken)['leads'],
            'whatsapp' => fetchLeads('whatsapp-leads', $encodedTimestamp, $this->authToken)['whatsapp'],
            'call' => fetchLeads('calltrackings', $encodedTimestamp, $this->authToken)['call_trackings']
        ];
    }

    private function extractLeadData(array $lead): array
    {
        $agent = $lead['user']['public'] ?? [];
        return [
            'id' => $lead['id'],
            'property_reference' => $lead['property_reference'] ?? $lead['reference'] ?? '',
            'agent_name' => trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')),
            'agent_phone' => $agent['phone'] ?? '',
            'agent_email' => $agent['email'] ?? '',
            'client_phone' => $lead['phone'] ?? $lead['mobile'] ?? '',
            'client_email' => $lead['email'] ?? '',
            'client_name' => $lead['client_name'] ?? trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?? 'Unknown',
            'enquiry_datetime' => $lead['created_at'] ?? ''
        ];
    }

    private function prepareLeadFields(array $leadData, string $mode, string $collectionSource): array
    {
        $assignedAgentId = determineAgentId($leadData['agent_email']) ?? 1893;

        return [
            'TITLE' => "Property Finder $mode - {$leadData['property_reference']}",
            'ufCrm43_1738827952373' => MODE_OF_ENQUIRY[$mode],
            'ufCrm43_1738828095478' => COLLECTION_SOURCE[$collectionSource],
            'ufCrm43_1738828416520' => $leadData['property_reference'],
            'ufCrm43_1738828919345' => $leadData['client_name'],
            'ufCrm43_1738828974042' => $leadData['client_email'],
            'ufCrm43_1738828948789' => $leadData['client_phone'],
            'ufCrm43_1738828518085' => $leadData['enquiry_datetime'],
            'sourceId' => PF_SOURCE_ID,
            'assignedById' => $assignedAgentId
        ];
    }

    private function handleCallDetails(array $lead, array &$fields): void
    {
        if (empty($lead['download_url'])) {
            return;
        }

        $callDetails = [
            'status' => $lead['status'],
            'call_start' => $lead['call_start'],
            'call_end' => $lead['call_end'],
            'call_time' => $lead['call_time'],
            'talk_time' => $lead['talk_time'],
            'agent_phone' => $lead['user']['public']['phone'] ?? '',
            'wait_time' => $lead['wait_time'],
            'recording_url' => $lead['download_url']
        ];

        $fields['ufCrm43_1738829734288'] = $this->formatCallComments($callDetails);
        $fields['ufCrm43_1738829734288'] = $lead['status'];

        if ($lead['download_url']) {
            $this->processCallRecording($lead, $fields);
        }
    }

    private function formatCallComments(array $callDetails): string
    {
        return "
            Receiver Number: {$callDetails['agent_phone']}
            Call Status: {$callDetails['status']}
            Call Start Time: {$callDetails['call_start']}
            Call End Time: {$callDetails['call_end']}
            Call Duration: {$callDetails['call_time']}
            Call Connected Duration: {$callDetails['talk_time']}
            Call Waiting Duration: {$callDetails['wait_time']}
            Call Recording URL: {$callDetails['recording_url']}
        ";
    }

    private function processCallRecording(array $lead, array $fields): void
    {
        $callRecordContent = file_get_contents($lead['download_url']);

        $registerCallData = [
            'USER_PHONE_INNER' => $lead['user']['public']['phone'] ?? '',
            'USER_ID' => $fields['assignedById'],
            'PHONE_NUMBER' => $lead['phone'] ?? '',
            'CALL_START_DATE' => $lead['call_start'],
            'CRM_CREATE' => false,
            'CRM_SOURCE' => 41,
            'CRM_ENTITY_TYPE' => 'CONTACT',
            'CRM_ENTITY_ID' => $fields['contactId'],
            'SHOW' => false,
            'TYPE' => 2,
            'LINE_NUMBER' => 'PF ' . ($lead['user']['public']['phone'] ?? '')
        ];

        $registerCall = registerCall($registerCallData);
        $callId = $registerCall['CALL_ID'] ?? null;

        if ($callId) {
            $this->finalizeCallRecording($callId, $fields['assignedById'], $lead, $callRecordContent);
        }
    }

    private function finalizeCallRecording(string $callId, int $assignedById, array $lead, string $callRecordContent): void
    {
        finishCall([
            'CALL_ID' => $callId,
            'USER_ID' => $assignedById,
            'DURATION' => $lead['talk_time'],
            'STATUS_CODE' => 200
        ]);

        attachRecord([
            'CALL_ID' => $callId,
            'FILENAME' => $lead['id'] . '|' . uniqid('call') . '.mp3',
            'FILE_CONTENT' => base64_encode($callRecordContent)
        ]);
    }

    public function processLeads(array $leads, string $mode, string $collectionSource): void
    {
        foreach ($leads as $lead) {
            if (in_array($lead['id'], $this->processedLeads)) {
                echo "Duplicate Lead Skipped: {$lead['id']}\n";
                continue;
            }

            $leadData = $this->extractLeadData($lead);
            logData(strtolower($mode) . '-lead.log', print_r($leadData, true));

            $fields = $this->prepareLeadFields($leadData, $mode, $collectionSource);

            // Handle contact creation or lookup
            $existingContact = checkExistingContact(['PHONE' => $leadData['client_phone']]);
            if (!$existingContact) {
                $contactId = $this->createNewContact($leadData, $fields['assignedById']);
                if ($contactId) {
                    $fields['contactId'] = $contactId;
                }
            } else {
                $fields['contactId'] = $existingContact;
            }

            // Handle call-specific data
            if ($mode === 'CALL') {
                $this->handleCallDetails($lead, $fields);
            }

            $newLeadId = createBitrixLead(ENTITY_TYPE_ID, $fields);
            echo "New Lead Created: $newLeadId\n";

            saveProcessedLead($this->leadFile, $lead['id']);
        }
    }

    private function createNewContact(array $leadData, int $assignedById): ?int
    {
        return createContact([
            'NAME' => $leadData['client_name'],
            'PHONE' => [['VALUE' => $leadData['client_phone'], 'VALUE_TYPE' => 'WORK']],
            'ASSIGNED_BY_ID' => $assignedById,
            'CREATED_BY_ID' => $assignedById
        ]);
    }
}

// Usage
$processor = new LeadProcessor(__DIR__ . '/auth_token.json', __DIR__ . '/processed_leads.txt');
$leads = $processor->fetchAllLeads(date('Y-m-d'));

$processor->processLeads($leads['whatsapp'], 'WHATSAPP', 'PF_WHATSAPP');
$processor->processLeads($leads['email'], 'EMAIL', 'PF_EMAIL');
$processor->processLeads($leads['call'], 'CALL', 'PF_CALL');
