<?php
require_once __DIR__ . '/crest/crest.php';
define('LISTINGS_ENTITY_TYPE_ID', 1084);

function makeApiRequest(string $url, array $headers)
{
    // Validate the URL before making the request
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        logData('error.log', "Invalid URL: $url");
        throw new Exception("Invalid URL: $url");
    }

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in the response
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Execute cURL request
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $error_message = "cURL error: " . curl_error($ch);
        logData('error.log', $error_message);  // Log error message to a file
        throw new Exception($error_message);
    }

    // Check the HTTP status code of the response
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode !== 200) {
        $error_message = "HTTP error: $httpCode - Response: $response";
        logData('error.log', $error_message);  // Log HTTP error
        throw new Exception($error_message);
    }

    // Separate headers and body
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($response, $header_size);

    curl_close($ch);

    // Decode the JSON response
    $data = json_decode($body, true);

    // Check if JSON decoding was successful
    if ($data === null) {
        $json_error_message = "JSON Decoding Error: " . json_last_error_msg();
        logData('error.log', $json_error_message);  // Log JSON decoding error
        throw new Exception($json_error_message);
    }

    // Return the decoded data
    return $data;
}

function logData(string $filename, string $message)
{
    date_default_timezone_set('Asia/Kolkata');

    $logFile = __DIR__ . '/logs/' . $filename;
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

function fetchLeads(string $type, string $date, string $authToken)
{
    if ($type == 'calltrackings') {
        $url = "https://api-v2.mycrm.com/$type?filters[date][from]=$date";
    } elseif ($type == 'leads') {
        $url = "https://api-v2.mycrm.com/$type?filters[created][from]=$date&filters[created][to]=$date";
    }

    try {
        $data = makeApiRequest($url, [
            'Content-Type: application/json',
            "Authorization: Bearer $authToken",
            "X-MyCRM-Expand-Data: true"
        ]);

        if (empty($data)) {
            echo "No new leads available.\n";
            return null;
        }


        return $data;
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

function createBitrixLead($fields)
{
    $response = CRest::call('crm.deal.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function checkExistingContact($filter = [])
{
    $response = CRest::call('crm.contact.list', [
        'filter' => $filter,
        'select' => ['ID', 'EMAIL']
    ]);

    if (isset($response['result']) && $response['total'] > 0) {
        // Check if we have a valid ID and return it
        if (isset($response['result'][0]['ID'])) {
            return $response['result'][0]['ID'];
        }
    }

    return null;
}

function createContact($fields)
{
    $response = CRest::call('crm.contact.add', [
        'fields' => $fields
    ]);

    return $response['result'];
}

function getListingOwner($property_reference)
{
    $response = CRest::call('crm.item.list', [
        'entityTypeId' => 1046,
        'filter' => [
            '%ufCrm5ReferenceNumber' => $property_reference
        ],
        'select' => [
            'ufCrm5AgentName',
            'ufCrm5ReferenceNumber'
        ]
    ]);


    if ($response['total'] > 0 && isset($response['result']['items'][0]['ufCrm5AgentName'])) {

        return trim($response['result']['items'][0]['ufCrm5AgentName']);
    }

    return null;
}

function getUser($filter = [])
{
    $response = CRest::call('user.get', [
        'filter' => $filter,
        'select' => ['ID', 'NAME', 'EMAIL']
    ]);

    if (isset($response['result']) && $response['total'] > 0) {
        if (isset($response['result'][0]['ID'])) {
            return $response['result'][0]['ID'];
        }
    }

    return null;
}

function getProcessedLeads($file)
{
    if (file_exists($file)) {
        return file($file, FILE_IGNORE_NEW_LINES);
    }

    return [];
}

function saveProcessedLead($file, $lead_id)
{
    // Check if the file is writable
    if (!is_writable($file)) {
        echo "Error: The file '{$file}' is not writable. Check file permissions.\n";
        return;
    }

    // Try writing to the file
    $result = file_put_contents($file, $lead_id . PHP_EOL, FILE_APPEND);

    if ($result === false) {
        echo "Error: Failed to write Lead ID {$lead_id} to '{$file}'. Check disk space, file path, and permissions.\n";
    } else {
        echo "Success: Lead ID {$lead_id} saved successfully.\n";
    }

    // Verify by reading the file to confirm the lead_id was saved
    if (!is_readable($file)) {
        echo "Error: The file '{$file}' is not readable. Check file permissions.\n";
        return;
    }

    $contents = file_get_contents($file);
    if (strpos($contents, $lead_id) !== false) {
        echo "Verification successful: Lead ID {$lead_id} found in the file.\n";
    } else {
        echo "Verification failed: Lead ID {$lead_id} not found in the file.\n";
    }
}

function getResponsiblePerson(string $searchValue, string $searchType): ?int
{
    if ($searchType === 'reference') {
        $response = CRest::call('crm.item.list', [
            'entityTypeId' => LISTINGS_ENTITY_TYPE_ID,
            'filter' => ['ufCrm37ReferenceNumber' => $searchValue],
            'select' => ['ufCrm37ReferenceNumber', 'ufCrm37AgentEmail', 'ufCrm37ListingOwner', 'ufCrm37OwnerId'],
        ]);

        if (!empty($response['error'])) {
            error_log(
                'Error getting CRM item: ' . $response['error_description']
            );
            return null;
        }

        if (
            empty($response['result']['items']) ||
            !is_array($response['result']['items'])
        ) {
            error_log(
                'No listing found with reference number: ' . $searchValue
            );
            return null;
        }

        $listing = $response['result']['items'][0];

        $ownerId = $listing['ufCrm37OwnerId'] ?? null;
        if ($ownerId && $ownerId !== 'null') {
            return (int)$ownerId;
        }

        $ownerName = $listing['ufCrm37ListingOwner'] ?? null;

        if ($ownerName) {
            $nameParts = explode(' ', trim($ownerName));

            $firstName = $nameParts[0] ?? null;
            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : null;
            $middleName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : null;

            return getUserId([
                '%NAME' => $firstName,
                '%SECOND_NAME' => $middleName,
                '%LAST_NAME' => $lastName,
                '!ID' => 3,
                '!ID' => 268
            ]);
        }


        $agentEmail = $listing['ufCrm37AgentEmail'] ?? null;
        if ($agentEmail) {
            return getUserId([
                'EMAIL' => $agentEmail,
                '!ID' => 3,
                '!ID' => 268
            ]);
        } else {
            error_log(
                'No agent email found for reference number: ' . $searchValue
            );
            return null;
        }
    } else if ($searchType === 'phone') {
        return getUserId(['%PERSONAL_MOBILE' => preg_replace('/\s+/', '', $searchValue,), '!ID' => 3, '!ID' => 268])
            ?? getUserId(['%WORK_PHONE' => preg_replace('/\s+/', '', $searchValue), '!ID' => 3, '!ID' => 268]);
    } else if ($searchType === 'name') {
        return getUserId(['%NAME' => explode(' ', $searchValue)[0], '%LAST_NAME' => explode(' ', $searchValue)[1], '!ID' => 3, '!ID' => 268]);
    }

    return null;
}

function getUserId(array $filter): ?int
{
    $response = CRest::call('user.get', [
        'filter' => $filter,
    ]);

    if (!empty($response['error'])) {
        error_log('Error getting user: ' . $response['error_description']);
        return null;
    }

    if (empty($response['result'])) {
        return null;
    }

    if (empty($response['result'][0]['ID'])) {
        return null;
    }

    return (int)$response['result'][0]['ID'];
}

function determineAgentId($agent_email)
{
    $agent_id = !empty($agent_email) ? getUser(['%EMAIL' => $agent_email]) : 1893;
    return ($agent_id == 433) ? 1893 : $agent_id;
}

function getAuthToken($token_file)
{
    if (file_exists($token_file)) {
        $token_data = json_decode(file_get_contents($token_file), true);
        if ($token_data && time() < $token_data['expires_at']) {
            return $token_data['access_token'];
        }
    }

    // If token is expired or missing, fetch a new one
    return getNewAuthToken($token_file);
}

function getNewAuthToken($token_file)
{
    $api_url = "https://auth.propertyfinder.com/auth/oauth/v1/token";
    $authorization = "Basic " . base64_encode("YfrXy.SMLd0cKqZf7FGC8Mu4SEfjCGz5jYyDusCA:R5bQQ5ZrdpYqTjjkviVQK9CT9dyV4SnD");

    // Prepare the request payload
    $data = [
        "scope" => "openid",
        "grant_type" => "client_credentials"
    ];

    // Initialize cURL
    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: $authorization",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    // Execute the request
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_status !== 200) {
        error_log("Failed to fetch token. HTTP Status: $http_status, Response: $response");
        return null;
    }

    // Decode and store the token
    $response_data = json_decode($response, true);
    if (isset($response_data['access_token'])) {
        $response_data['expires_at'] = time() + $response_data['expires_in'];
        file_put_contents($token_file, json_encode($response_data));
        return $response_data['access_token'];
    } else {
        error_log("Invalid token response: $response");
        return null;
    }
}

function httpPost($url, $headers, $post_data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        die("Curl error: " . curl_error($ch));
    }
    curl_close($ch);
    return $response;
}

function registerCall($fields)
{
    $res = CRest::call('telephony.externalcall.register', $fields);
    return $res['result'];
}

function finishCall($fields)
{
    $res = CRest::call('telephony.externalcall.finish', $fields);
    return $res['result'];
}

function attachRecord($fields)
{
    $res = CRest::call('telephony.externalcall.attachRecord', $fields);
    return $res['result'];
}

function timeToSec($time)
{
    $time = explode(':', $time);
    return $time[0] * 3600 + $time[1] * 60 + $time[2];
}

function shortenUrl($urlToShorten)
{
    $apiKey = "b5de7cfb65msh1ffd2a1d02a59bap110214jsn39c5a13a3d32";
    // Initialize cURL
    $curl = curl_init();

    // Set cURL options
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://url-shortener-service.p.rapidapi.com/shorten",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => "url=" . urlencode($urlToShorten),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "x-rapidapi-host: url-shortener-service.p.rapidapi.com",
            "x-rapidapi-key: $apiKey"
        ],
    ]);

    // Execute the cURL request
    $response = curl_exec($curl);
    $err = curl_error($curl);

    // Close cURL
    curl_close($curl);

    // Return response or error
    if ($err) {
        return "cURL Error #:" . $err;
    } else {
        $response = json_decode($response, true);
        return $response['result_url'];
    }
}
