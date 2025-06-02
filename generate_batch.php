<?php

require_once 'vendor/autoload.php';

use RedditStoryShorts\App\RedditStoryApp;

// Parse command line arguments
$options = getopt('n:c:h', ['count:', 'channel:', 'help', 'skip-upload', 'upload']);

if (isset($options['h']) || isset($options['help'])) {
    showHelp();
    exit(0);
}

// Get number of videos to generate
$count = $options['n'] ?? $options['count'] ?? null;

if (!$count || !is_numeric($count) || $count < 1) {
    echo "Error: Please specify a valid number of videos to generate.\n\n";
    showHelp();
    exit(1);
}

$count = (int)$count;

// Get channel parameter (optional)
$channelKey = $options['c'] ?? $options['channel'] ?? null;

// Load configuration
$config = require 'config/config.php';

// Override skip_upload setting if specified
if (isset($options['skip-upload'])) {
    $config['automation']['skip_upload'] = true;
} elseif (isset($options['upload'])) {
    $config['automation']['skip_upload'] = false;
}

$skipUpload = $config['automation']['skip_upload'] ?? false;

// Create application instance
$app = new RedditStoryApp($config);

echo "Reddit Story Batch Generator\n";
echo "===============================\n\n";

echo "Generation Settings:\n";
echo "  • Videos to generate: $count\n";
if ($channelKey) {
    echo "  • Target channel: $channelKey\n";
} else {
    echo "  • Channel selection: Auto (based on schedule)\n";
}
echo "  • Upload mode: " . ($skipUpload ? "Skip (save to output/ only)" : "Upload to YouTube") . "\n";
echo "\n";

$successful = 0;
$failed = 0;
$startTime = time();

echo "Starting batch generation...\n";
echo "================================\n\n";

for ($i = 1; $i <= $count; $i++) {
    echo "[Video $i/$count] Generating... ";

    try {
        $success = $app->generateAndUploadVideo($channelKey);

        if ($success) {
            $successful++;
            echo "Success\n";
        } else {
            $failed++;
            echo "Failed\n";
        }
    } catch (Exception $e) {
        $failed++;
        echo "Error: " . $e->getMessage() . "\n";
    }

    // Show progress
    $progress = round(($i / $count) * 100);
    echo "    Progress: $progress% ($successful successful, $failed failed)\n";

    // Small delay between generations (except for last video)
    if ($i < $count) {
        echo "    Waiting 10 seconds before next video...\n\n";
        sleep(10);
    }
}

$endTime = time();
$duration = $endTime - $startTime;
$minutes = floor($duration / 60);
$seconds = $duration % 60;

echo "\nBatch Generation Complete!\n";
echo "=============================\n";
echo "Results:\n";
echo "  • Total videos: $count\n";
echo "  • Successful: $successful\n";
echo "  • Failed: $failed\n";
echo "  • Success rate: " . round(($successful / $count) * 100) . "%\n";
echo "  • Duration: {$minutes}m {$seconds}s\n";

if ($successful > 0) {
    if ($skipUpload) {
        echo "\nGenerated videos saved to: output/\n";
    } else {
        echo "\nVideos uploaded to YouTube successfully!\n";
    }
}

if ($failed > 0) {
    echo "\nSome videos failed to generate. Check logs/app.log for details.\n";
}

echo "\nTips:\n";
echo "  • Use --skip-upload to generate without uploading\n";
echo "  • Use --channel=channel_1 to target specific channel\n";
echo "  • Check output/ directory for generated videos\n";

function showHelp()
{
    echo "Reddit Story Batch Generator\n";
    echo "===============================\n\n";

    echo "Usage:\n";
    echo "  php generate_batch.php -n <count> [options]\n\n";

    echo "Required:\n";
    echo "  -n, --count <number>     Number of videos to generate\n\n";

    echo "Options:\n";
    echo "  -c, --channel <key>      Target specific channel (e.g., channel_1)\n";
    echo "  --skip-upload            Generate videos without uploading to YouTube\n";
    echo "  --upload                 Force upload to YouTube (override config)\n";
    echo "  -h, --help               Show this help message\n\n";

    echo "Examples:\n";
    echo "  php generate_batch.php -n 5                    # Generate 5 videos\n";
    echo "  php generate_batch.php -n 10 --skip-upload     # Generate 10 videos, no upload\n";
    echo "  php generate_batch.php -n 3 -c channel_1       # Generate 3 videos for channel_1\n";
    echo "  php generate_batch.php --count 8 --upload      # Generate 8 videos, force upload\n\n";

    echo "Notes:\n";
    echo "  • Videos are generated with 10-second intervals\n";
    echo "  • Check output/ directory for generated videos\n";
    echo "  • Check logs/app.log for detailed error messages\n";
    echo "  • Respects skip_upload setting in config unless overridden\n";
}
