<?php

require_once 'vendor/autoload.php';

use RedditStoryShorts\App\RedditStoryApp;

// Load configuration
$config = require 'config/config.php';

// Create application instance
$app = new RedditStoryApp($config);

// Parse command line arguments
$options = getopt('s', ['status']);

if (isset($options['s']) || isset($options['status'])) {
    // Show status
    echo "Reddit Story Shorts - System Status\n";
    echo "===================================\n\n";

    $status = $app->getStatus();

    echo "App Version: {$status['app_version']}\n";
    echo "Automation Enabled: " . ($status['automation_enabled'] ? 'Yes' : 'No') . "\n\n";

    echo "Channel Status:\n";
    echo "---------------\n";

    foreach ($status['channels'] as $channelKey => $channelStatus) {
        echo "Channel: {$channelStatus['name']} ({$channelKey})\n";
        echo "  Should Upload Now: " . ($channelStatus['should_upload_now'] ? 'Yes' : 'No') . "\n";
        echo "  Upload History (last 7 days):\n";

        foreach ($channelStatus['upload_history'] as $date => $count) {
            echo "    {$date}: {$count} uploads\n";
        }

        echo "\n";
    }

    exit(0);
}

echo "Reddit Story Shorts - Automation Mode\n";
echo "=====================================\n\n";

echo "Starting automation process...\n";
echo "Press Ctrl+C to stop the automation.\n\n";

// Set up signal handling for graceful shutdown
if (function_exists('pcntl_signal')) {

    declare(ticks=1);

    $shutdown = false;

    pcntl_signal(SIGTERM, function () use (&$shutdown) {
        echo "\nReceived SIGTERM, shutting down gracefully...\n";
        $shutdown = true;
    });

    pcntl_signal(SIGINT, function () use (&$shutdown) {
        echo "\nReceived SIGINT, shutting down gracefully...\n";
        $shutdown = true;
    });
}

// Main automation loop
while (true) {
    if (isset($shutdown) && $shutdown) {
        echo "Automation stopped.\n";
        break;
    }

    try {
        echo "[" . date('Y-m-d H:i:s') . "] Running automation cycle...\n";

        // Run automation
        $app->runAutomation();

        echo "[" . date('Y-m-d H:i:s') . "] Automation cycle completed.\n";

        // Wait 30 minutes before next cycle
        $waitTime = 30 * 60; // 30 minutes
        echo "Waiting {$waitTime} seconds until next cycle...\n\n";

        // Sleep with periodic checks for shutdown signal
        for ($i = 0; $i < $waitTime; $i += 60) {
            if (isset($shutdown) && $shutdown) {
                break 2;
            }
            sleep(60); // Sleep 1 minute at a time
        }
    } catch (Exception $e) {
        echo "Error in automation cycle: " . $e->getMessage() . "\n";
        echo "Waiting 5 minutes before retrying...\n\n";
        sleep(300); // Wait 5 minutes on error
    }
}

echo "Automation finished.\n";
