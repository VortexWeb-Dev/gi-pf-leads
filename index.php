<?php
require_once __DIR__ . '/utils.php';

// Configuration
const ENTITY_TYPE_ID = 1110;
const PF_CALL_SOURCE_ID = 'UC_L31Q25';
const PF_EMAIL_SOURCE_ID = 'RC_GENERATOR';

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
            // 'whatsapp' => fetchLeads('whatsapp-leads', $encodedTimestamp, $this->authToken)['whatsapp'],
            'call' => fetchLeads('calltrackings', $encodedTimestamp, $this->authToken)['call_trackings'],
            'email' => fetchLeads('leads', $encodedTimestamp, $this->authToken)['leads'],
        ];
    }

    private function extractLeadData(array $lead): array
    {
        $agent = $lead['user']['public'] ?? [];
        $message = $lead['notes'][0]['body'] ?? '';

        return [
            'id' => $lead['id'],
            'property_reference' => $lead['property_reference'] ?? $this->getLeadReference($message) ?? $lead['reference'] ?? '',
            'agent_name' => trim(($agent['first_name'] ?? '') . ' ' . ($agent['last_name'] ?? '')),
            'agent_phone' => $agent['phone'] ?? '',
            'agent_email' => $agent['email'] ?? '',
            'client_phone' => $lead['phone'] ?? $lead['mobile'] ?? '',
            'client_email' => $lead['email'] ?? '',
            'client_name' => $lead['client_name'] ?? trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) ?? 'Unknown',
            'message' => $lead['notes'][0]['body'] ?? '',
            'enquiry_datetime' => $lead['created_at'] ?? ''
        ];
    }

    private function prepareLeadFields(array $leadData, string $mode, string $collectionSource): array
    {
        if ($leadData['property_reference']) {
            $assignedAgentId = getResponsiblePerson($leadData['property_reference'], 'reference') ?? 1593;
        } else {
            $assignedAgentId = getResponsiblePerson($leadData['agent_name'], 'name') ?? 1593;
        }

        $contactId = createContact([
            'NAME' => $leadData['client_name'] ?? "Unknown from Property Finder " . ucfirst(strtolower($mode)) . " (" . $leadData['client_phone'] . ")",
            'PHONE' => [
                [
                    'VALUE' => $leadData['client_phone'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ],
            'EMAIL' => [
                [
                    'VALUE' => $leadData['client_email'],
                    'VALUE_TYPE' => 'WORK'
                ]
            ]
        ]);

        return [
            'TITLE' => "Property Finder - " . ucfirst(strtolower($mode)) . " - " . ($leadData['property_reference'] !== '' ? $leadData['property_reference'] : 'No reference'),
            'UF_CRM_1739890146108' => $leadData['property_reference'],
            'UF_CRM_1701770331658' => $leadData['client_name'],
            'UF_CRM_65732038DAD70' => $leadData['client_email'],
            'UF_CRM_1721198325274' => $leadData['client_email'],
            'UF_CRM_PHONE_WORK' => $leadData['client_phone'],
            'UF_CRM_1736406984' => $leadData['client_phone'],
            'COMMENTS' => $leadData['message'],
            'SOURCE_ID' => $mode === 'CALL' ?  PF_CALL_SOURCE_ID : PF_EMAIL_SOURCE_ID,
            'CATEGORY_ID' => 24,
            'ASSIGNED_BY_ID' => $assignedAgentId,
            'CONTACT_ID' => $contactId,
            'OPPORTUNITY' => getPropertyPrice($leadData['property_reference']) ?? '',
        ];
    }

    private function handleCallDetails(array $lead, array &$fields, string $newLeadId): void
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

        $fields['COMMENTS'] = $this->formatCallComments($callDetails);

        if ($lead['download_url']) {
            $this->processCallRecording($lead, $fields, $newLeadId);
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

    private function processCallRecording(array $lead, array $fields, string $newLeadId): void
    {
        $callRecordContent = file_get_contents($lead['download_url']);

        $registerCallData = [
            'USER_PHONE_INNER' => $lead['user']['public']['phone'] ?? '',
            'USER_ID' => $fields['ASSIGNED_BY_ID'],
            'PHONE_NUMBER' => $lead['phone'] ?? '',
            'CALL_START_DATE' => $lead['call_start'],
            'CRM_CREATE' => false,
            'CRM_SOURCE' => 41,
            'CRM_ENTITY_TYPE' => 'DEAL',
            'CRM_ENTITY_ID' => $newLeadId,
            'SHOW' => false,
            'TYPE' => 2,
            'LINE_NUMBER' => 'PF ' . ($lead['user']['public']['phone'] ?? '')
        ];

        $registerCall = registerCall($registerCallData);
        $callId = $registerCall['CALL_ID'] ?? null;

        if ($callId) {
            $this->finalizeCallRecording($callId, $fields['ASSIGNED_BY_ID'], $lead, $callRecordContent);
        }
    }

    private function finalizeCallRecording(string $callId, int $ASSIGNED_BY_ID, array $lead, string $callRecordContent): void
    {
        finishCall([
            'CALL_ID' => $callId,
            'USER_ID' => $ASSIGNED_BY_ID,
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
            logData('fields.log', print_r($fields, true));

            $newLeadId = createBitrixLead($fields);
            echo "New Lead Created: $newLeadId\n";

            // Handle call-specific data
            if ($mode === 'CALL') {
                $this->handleCallDetails($lead, $fields, $newLeadId);
            }

            saveProcessedLead($this->leadFile, $lead['id']);
        }
    }

    private function getLeadReference(string $message): ?string
    {
        $pattern = '/ref:\s*([a-zA-Z0-9-]+)/';
        if (preg_match($pattern, $message, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

// Usage
$processor = new LeadProcessor(__DIR__ . '/auth_token.json', __DIR__ . '/processed_leads.txt');
$date = date('Y-m-d');
var_dump($date);
$leads = $processor->fetchAllLeads($date);

//$processor->processLeads($leads['whatsapp'], 'WHATSAPP', 'PF_WHATSAPP');
$processor->processLeads($leads['call'], 'CALL', 'PF_CALL');
$processor->processLeads($leads['email'], 'EMAIL', 'PF_EMAIL');
