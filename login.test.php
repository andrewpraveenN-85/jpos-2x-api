<?php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to make API request
function makeLoginRequest($email, $password) {
    $url = 'https://jpos2xapi.jaan.lk/login.php';
    
    $data = [
        "email" => $email,
        "password" => $password
    ];
    
    $headers = [
        'X-DB-Host: localhost',
        'X-DB-User: dbuser',
        'X-DB-Pass: L{582Phb1Lh5',
        'X-DB-Name: pos_v2',
        'X-DB-Port: 3306',
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    
    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true, // To get response even on HTTP errors
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]
    ];
    
    $context = stream_context_create($options);
    
    try {
        $response = file_get_contents($url, false, $context);
        
        // Get HTTP response code
        preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
        $http_code = $matches[1] ?? 0;
        
        if ($http_code >= 400) {
            return [
                "error" => true,
                "status_code" => $http_code,
                "message" => "HTTP Error",
                "response" => $response
            ];
        }
        
        return $response;
        
    } catch (Exception $e) {
        return [
            "error" => true,
            "message" => "Request failed",
            "details" => $e->getMessage()
        ];
    }
}

// Usage
$response = makeLoginRequest("admin@gmail.com", "123456789");
echo is_array($response) ? json_encode($response) : $response;