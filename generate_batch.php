<?php

require_once 'vendor/autoload.php';

use RedditStoryShorts\App\RedditStoryApp;

// Parse command line arguments
$options = getopt('n:h', ['count:', 'help', 'skip-upload', 'upload']);

if (isset($options['h']) || isset($options['help'])) {
    showHelp();
    exit(0);
}

// Get number of videos to generate per channel
$count = $options['n'] ?? $options['count'] ?? null;

if (!$count || !is_numeric($count) || $count < 1) {
    echo "Error: Please specify a valid number of videos to generate per channel.\n\n";
    showHelp();
    exit(1);
}

$count = (int)$count;

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

$channelCount = count($config['youtube']['channels']);
$totalVideos = $count * $channelCount;

echo "Generation Settings:\n";
echo "  • Videos per channel: $count\n";
echo "  • Number of channels: $channelCount\n";
echo "  • Total videos: $totalVideos\n";
echo "  • Upload mode: " . ($skipUpload ? "Skip (save to output/ only)" : "Upload to YouTube") . "\n";
echo "\n";

$successful = 0;
$failed = 0;
$startTime = time();

echo "Starting batch generation...\n";
echo "================================\n\n";

for ($i = 1; $i <= $count; $i++) {
    echo "[Run $i/$count] Generating $channelCount videos... ";

    try {
        $success = $app->generateAndUploadVideo();

        if ($success) {
            $successful += $channelCount;
            echo "Success\n";
        } else {
            $failed += $channelCount;
            echo "Failed\n";
        }
    } catch (Exception $e) {
        $failed += $channelCount;
        echo "Error: " . $e->getMessage() . "\n";
    }

    // Show progress
    $progress = round(($i / $count) * 100);
    echo "    Progress: $progress% ($successful successful, $failed failed)\n";

    // Small delay between generations (except for last video)
    if ($i < $count) {
        echo "    Waiting 30 seconds before next run...\n\n";
        sleep(30);
    }
}

$endTime = time();
$duration = $endTime - $startTime;
$minutes = floor($duration / 60);
$seconds = $duration % 60;

echo "\nBatch Generation Complete!\n";
echo "=============================\n";
echo "Results:\n";
echo "  • Total runs: $count\n";
echo "  • Total videos: $totalVideos\n";
echo "  • Successful: $successful\n";
echo "  • Failed: $failed\n";
echo "  • Success rate: " . round(($successful / $totalVideos) * 100) . "%\n";
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
echo "  • Each run generates unique videos for all {$channelCount} channels\n";
echo "  • Check output/ directory for generated videos\n";

function showHelp()
{
    echo "Reddit Story Batch Generator\n";
    echo "===============================\n\n";

    echo "Usage:\n";
    echo "  php generate_batch.php -n <count> [options]\n\n";

    echo "Required:\n";
    echo "  -n, --count <number>     Number of video sets to generate (each set = 1 video per channel)\n\n";

    echo "Options:\n";
    echo "  --skip-upload            Generate videos without uploading to YouTube\n";
    echo "  --upload                 Force upload to YouTube (override config)\n";
    echo "  -h, --help               Show this help message\n\n";

    echo "Examples:\n";
    echo "  php generate_batch.php -n 5                    # Generate 5 video sets\n";
    echo "  php generate_batch.php -n 10 --skip-upload     # Generate 10 sets, no upload\n";
    echo "  php generate_batch.php --count 8 --upload      # Generate 8 sets, force upload\n\n";

    echo "Notes:\n";
    echo "  • Each 'set' generates unique videos for ALL channels\n";
    echo "  • If you have 3 channels and run with -n 5, you get 15 total videos\n";
    echo "  • Video sets are generated with 30-second intervals\n";
    echo "  • Check output/ directory for generated videos\n";
    echo "  • Check logs/app.log for detailed error messages\n";
}
