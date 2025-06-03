<?php

require_once 'vendor/autoload.php';

use RedditStoryShorts\App\RedditStoryApp;

// Load configuration
$config = require 'config/config.php';

// Create application instance
$app = new RedditStoryApp($config);

// Parse command line arguments
$options = getopt('t', ['test']);

if (isset($options['t']) || isset($options['test'])) {
    // Test configuration
    echo "Testing system configuration...\n\n";

    $testResults = $app->testConfiguration();

    foreach ($testResults as $component => $result) {
        echo "=== {$component} ===\n";

        if (is_array($result)) {
            foreach ($result as $key => $value) {
                if (is_array($value)) {
                    echo "  {$key}:\n";
                    foreach ($value as $subKey => $subValue) {
                        echo "    {$subKey}: " . (is_bool($subValue) ? ($subValue ? 'Yes' : 'No') : $subValue) . "\n";
                    }
                } else {
                    echo "  {$key}: " . (is_bool($value) ? ($value ? 'Yes' : 'No') : $value) . "\n";
                }
            }
        } else {
            echo "  Result: " . (is_bool($result) ? ($result ? 'OK' : 'FAIL') : $result) . "\n";
        }

        echo "\n";
    }

    exit(0);
}

echo "Reddit Story Shorts Generator\n";
echo "============================\n\n";

$channelCount = count($config['youtube']['channels']);
echo "Generating unique videos for {$channelCount} channels...\n";
echo "Starting video generation process...\n\n";

// Generate and upload videos for all channels
$success = $app->generateAndUploadVideo();

if ($success) {
    echo "Videos generated successfully!\n";
    exit(0);
} else {
    echo "Failed to generate videos. Check logs for details.\n";
    exit(1);
}
