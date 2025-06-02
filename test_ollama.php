<?php

require_once 'vendor/autoload.php';
$config = require 'config/config.php';

echo "TESTING OLLAMA SETUP\n";
echo "=======================\n\n";

// Test Ollama connection
echo "Testing Ollama connection...\n";

try {
    $client = new \GuzzleHttp\Client(['timeout' => 30]);

    // Test if Ollama is running
    $response = $client->get('http://localhost:11434/api/tags');
    $models = json_decode($response->getBody()->getContents(), true);

    echo "Ollama is running!\n";
    echo "Available models:\n";

    if (isset($models['models']) && !empty($models['models'])) {
        foreach ($models['models'] as $model) {
            echo "  - " . $model['name'] . " (" . round($model['size'] / 1e9, 1) . "GB)\n";
        }
    } else {
        echo "  No models found. Let's download llama3.2:3b...\n";

        // Download the model if it's not available
        echo "\nDownloading llama3.2:3b (this may take a few minutes)...\n";
        exec('ollama pull llama3.2:3b', $output, $returnCode);

        if ($returnCode === 0) {
            echo "Model downloaded successfully!\n";
        } else {
            echo "Failed to download model. Error code: $returnCode\n";
            exit(1);
        }
    }
} catch (\Exception $e) {
    echo "Ollama connection failed: " . $e->getMessage() . "\n";
    echo "\nTroubleshooting:\n";
    echo "1. Make sure Ollama is running: brew services start ollama\n";
    echo "2. Wait a moment and try again\n";
    echo "3. Check if port 11434 is available\n";
    exit(1);
}

echo "\nTesting text generation...\n";

try {
    $response = $client->post('http://localhost:11434/api/generate', [
        'headers' => [
            'Content-Type' => 'application/json'
        ],
        'json' => [
            'model' => $config['ai']['ollama']['model'],
            'prompt' => 'Write a short Reddit story about a funny neighbor situation.',
            'stream' => false,
            'options' => [
                'temperature' => 0.8,
                'num_predict' => 150
            ]
        ]
    ]);

    $result = json_decode($response->getBody()->getContents(), true);

    if (isset($result['response'])) {
        echo "Text generation working!\n";
        echo "\nSample output:\n";
        echo "\"" . substr($result['response'], 0, 200) . "...\"\n\n";
        echo "Ollama is ready for story generation!\n";
    } else {
        echo "Unexpected response: " . json_encode($result) . "\n";
    }
} catch (\Exception $e) {
    echo "Text generation failed: " . $e->getMessage() . "\n";
}

echo "\n=== Ollama Test Complete ===\n";
