<?php

echo "YouTube Authentication Setup\n";
echo "===============================\n\n";

// Get credentials from config
$config = include __DIR__ . '/config/config.php';
$clientId = $config['youtube']['channels']['channel_1']['client_id'];
$clientSecret = $config['youtube']['channels']['channel_1']['client_secret'];

if ($clientId === 'YOUR_GOOGLE_CLIENT_ID' || $clientSecret === 'YOUR_GOOGLE_CLIENT_SECRET') {
    echo "Please update your Google Client ID and Client Secret in config/config.php first!\n";
    exit(1);
}

echo "Client ID and Secret found in config\n\n";

// Step 1: Generate authorization URL
$redirectUri = 'urn:ietf:wg:oauth:2.0:oob';
$scope = 'https://www.googleapis.com/auth/youtube.upload';

$authUrl = sprintf(
    'https://accounts.google.com/o/oauth2/auth?%s',
    http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scope' => $scope,
        'response_type' => 'code',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ])
);

echo "\nSTEP 1: Get Authorization Code\n";
echo "==================================\n";
echo "1. Open this URL in your browser:\n";
echo "\n" . $authUrl . "\n\n";
echo "2. Sign in with your YouTube channel account\n";
echo "3. Grant permissions to upload videos\n";
echo "4. Copy the authorization code from the page\n\n";

echo "Enter the authorization code: ";
$authCode = trim(fgets(STDIN));

if (empty($authCode)) {
    echo "No authorization code provided!\n";
    exit(1);
}

echo "\nSTEP 2: Exchange Code for Refresh Token\n";
echo "===========================================\n";

// Step 2: Exchange authorization code for refresh token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$postData = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $authCode,
    'grant_type' => 'authorization_code',
    'redirect_uri' => $redirectUri
];

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query($postData)
    ]
]);

$response = file_get_contents($tokenUrl, false, $context);

if ($response === false) {
    echo "Failed to exchange authorization code for tokens!\n";
    exit(1);
}

$tokens = json_decode($response, true);

if (isset($tokens['error'])) {
    echo "Error getting tokens: " . $tokens['error_description'] . "\n";
    exit(1);
}

if (!isset($tokens['refresh_token'])) {
    echo "No refresh token received. Make sure you used 'access_type=offline' and 'prompt=consent'\n";
    echo "Response: " . $response . "\n";
    exit(1);
}

$refreshToken = $tokens['refresh_token'];
$accessToken = $tokens['access_token'];

echo "Tokens received successfully!\n\n";

echo "STEP 3: Update Configuration\n";
echo "===============================\n";
echo "Add this refresh token to your config/config.php:\n\n";
echo "'refresh_token' => '" . $refreshToken . "',\n\n";

echo "STEP 4: Test Upload Permissions\n";
echo "==================================\n";

// Test the tokens by making a simple API call
$testUrl = 'https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true';
$testContext = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => 'Authorization: Bearer ' . $accessToken
    ]
]);

$testResponse = file_get_contents($testUrl, false, $testContext);

if ($testResponse !== false) {
    $channelData = json_decode($testResponse, true);
    if (isset($channelData['items'][0]['snippet']['title'])) {
        echo "Authentication successful!\n";
        echo "Channel: " . $channelData['items'][0]['snippet']['title'] . "\n";
    } else {
        echo "Authentication works but couldn't get channel info\n";
        echo "Response: " . $testResponse . "\n";
    }
} else {
    echo "Couldn't test authentication (might still work)\n";
}

echo "\nSetup Complete!\n";
echo "==================\n";
echo "Now update your config/config.php with the refresh token above and try generating videos again!\n";
