<?php

namespace RedditStoryShorts\App;

use RedditStoryShorts\AI\StoryGenerator;
use RedditStoryShorts\TTS\TextToSpeech;
use RedditStoryShorts\Video\VideoGenerator;
use RedditStoryShorts\YouTube\YouTubeUploader;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Level;

class RedditStoryApp
{
    private array $config;
    private Logger $logger;
    private StoryGenerator $storyGenerator;
    private TextToSpeech $tts;
    private VideoGenerator $videoGenerator;
    private YouTubeUploader $youtubeUploader;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->setupLogger();
        $this->initializeServices();
    }

    private function setupLogger(): void
    {
        $this->logger = new Logger('RedditStoryApp');

        // Create logs directory if it doesn't exist
        $logDir = dirname($this->config['logging']['file']);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Add file handler using StreamHandler
        $fileHandler = new StreamHandler($this->config['logging']['file'], Level::Debug);
        $this->logger->pushHandler($fileHandler);

        // Add console handler for debugging
        $streamHandler = new StreamHandler(STDOUT, Level::Debug);
        $this->logger->pushHandler($streamHandler);
    }

    private function initializeServices(): void
    {
        $this->storyGenerator = new StoryGenerator($this->config, $this->logger);
        $this->tts = new TextToSpeech($this->config, $this->logger);
        $this->videoGenerator = new VideoGenerator($this->config, $this->logger);
        $this->youtubeUploader = new YouTubeUploader($this->config, $this->logger);
    }

    public function generateAndUploadVideo(): bool
    {
        $this->logger->info('Starting video generation and upload process');

        $skipUpload = $this->config['automation']['skip_upload'] ?? false;
        $channels = $this->youtubeUploader->getAllChannels();
        $successCount = 0;

        // Generate unique video for each channel
        foreach ($channels as $index => $refreshToken) {
            try {
                $this->logger->info("Generating video " . ($index + 1) . "/" . count($channels) . " for channel");

                // Generate story
                $story = $this->storyGenerator->generateStory();
                $this->logger->info('Generated story: ' . $story['title']);

                // Generate audio
                $audioPath = $this->generateAudioFile($story);
                if (!$audioPath) {
                    throw new \Exception('Failed to generate audio');
                }

                // Generate video
                $videoPath = $this->generateVideoFile($story, $audioPath);
                if (!$videoPath) {
                    // Clean up audio file if video generation failed
                    if (file_exists($audioPath)) {
                        unlink($audioPath);
                        $this->logger->debug('Cleaned up audio file after video generation failure: ' . $audioPath);
                    }
                    throw new \Exception('Failed to generate video');
                }

                // Generate thumbnail
                $thumbnailPath = $this->generateThumbnailFile($videoPath);

                $uploadSuccess = true; // Default to true when skipping upload

                if ($skipUpload) {
                    $this->logger->info('Upload skipped due to skip_upload configuration. Video saved to: ' . $videoPath);
                } else {
                    // Check if we should upload now
                    if ($this->youtubeUploader->shouldUploadNow()) {
                        $uploadSuccess = $this->youtubeUploader->uploadToChannel($refreshToken, $videoPath, $story, $thumbnailPath);

                        if ($uploadSuccess) {
                            $this->youtubeUploader->incrementUploadCount();
                            $this->logger->info('Successfully uploaded video for channel ' . ($index + 1));
                        }
                    } else {
                        $this->logger->info('Upload skipped - not within upload schedule');
                        $uploadSuccess = false;
                    }
                }

                // Cleanup temporary files
                $this->cleanupFiles([$audioPath, $thumbnailPath]);

                if ($uploadSuccess) {
                    $successCount++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Video generation failed for channel ' . ($index + 1) . ': ' . $e->getMessage());
            }

            // Small delay between channel videos (except for last one)
            if ($index < count($channels) - 1) {
                sleep(5);
            }
        }

        $this->logger->info("Video generation completed. Successfully processed {$successCount}/" . count($channels) . " channels");
        return $successCount > 0;
    }

    private function generateAudioFile(array $story): ?string
    {
        $audioPath = $this->config['paths']['temp'] . 'audio_' . uniqid() . '.wav';

        // Create temp directory if it doesn't exist
        if (!is_dir($this->config['paths']['temp'])) {
            mkdir($this->config['paths']['temp'], 0755, true);
        }

        $success = $this->tts->generateAudio($story['content'], $audioPath);
        return $success ? $audioPath : null;
    }

    private function generateVideoFile(array $story, string $audioPath): ?string
    {
        $videoPath = $this->config['paths']['output'] . 'video_' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.mp4';

        // Create output directory if it doesn't exist
        if (!is_dir($this->config['paths']['output'])) {
            mkdir($this->config['paths']['output'], 0755, true);
        }

        $success = $this->videoGenerator->generateVideo($story, $audioPath, $videoPath);
        return $success ? $videoPath : null;
    }

    private function generateThumbnailFile(string $videoPath): ?string
    {
        $thumbnailPath = $this->config['paths']['temp'] . 'thumbnail_' . uniqid() . '.jpg';

        $success = $this->videoGenerator->addThumbnail($videoPath, $thumbnailPath);
        return $success ? $thumbnailPath : null;
    }

    private function cleanupFiles(array $files): void
    {
        foreach ($files as $file) {
            if ($file && file_exists($file)) {
                unlink($file);
                $this->logger->debug('Cleaned up file: ' . $file);
            }
        }
    }

    public function runAutomation(): void
    {
        $this->logger->info('Starting automation run');

        if (!$this->config['automation']['enabled']) {
            $this->logger->info('Automation is disabled');
            return;
        }

        $maxVideosPerChannel = $this->config['automation']['max_videos_per_run'];
        $channelCount = $this->youtubeUploader->getChannelCount();
        $totalVideos = $maxVideosPerChannel * $channelCount;
        $generated = 0;
        $skipUpload = $this->config['automation']['skip_upload'] ?? false;

        $this->logger->info("Planning to generate {$maxVideosPerChannel} videos per channel ({$channelCount} channels) = {$totalVideos} total videos");

        for ($run = 0; $run < $maxVideosPerChannel; $run++) {
            // Check if we should continue uploading (skip this check if uploads are disabled)
            if (!$skipUpload && !$this->youtubeUploader->shouldUploadNow()) {
                $this->logger->info('Upload schedule limit reached or outside upload hours');
                break;
            }

            $this->logger->info('Starting generation run ' . ($run + 1) . '/' . $maxVideosPerChannel);

            // Generate videos for all channels
            $success = $this->generateAndUploadVideo();

            if ($success) {
                $generated += $channelCount; // Each successful run generates videos for all channels
                $this->logger->info("Successfully completed run " . ($run + 1) . ". Total videos generated: {$generated}");
            } else {
                $this->logger->error("Failed generation run " . ($run + 1));

                // Wait before retrying
                sleep($this->config['automation']['retry_delay']);
            }

            // Delay between runs (except for last run)
            if ($run < $maxVideosPerChannel - 1) {
                sleep(60); // 1 minute between runs
            }
        }

        $this->logger->info("Automation completed. Generated {$generated} total videos across all channels.");

        // Cleanup old videos if enabled
        if ($this->config['automation']['cleanup_old_videos']) {
            $this->cleanupOldVideos();
        }
    }

    private function cleanupOldVideos(): void
    {
        $outputDir = $this->config['paths']['output'];
        $keepDays = $this->config['automation']['keep_videos_days'];
        $cutoffTime = time() - ($keepDays * 24 * 60 * 60);

        $files = glob($outputDir . '*.mp4');
        $deletedCount = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                $deletedCount++;
            }
        }

        if ($deletedCount > 0) {
            $this->logger->info("Cleaned up {$deletedCount} old video files");
        }
    }

    public function getStatus(): array
    {
        $channels = $this->youtubeUploader->getAllChannels();

        $status = [
            'app_version' => '1.0.0',
            'automation_enabled' => $this->config['automation']['enabled'],
            'channel_count' => count($channels),
            'should_upload_now' => $this->youtubeUploader->shouldUploadNow(),
            'upload_schedule' => $this->youtubeUploader->getUploadSchedule(),
            'upload_history' => $this->youtubeUploader->getUploadHistory(7),
            'daily_limit' => [
                'videos_per_channel' => $this->config['youtube']['upload_schedule']['posts_per_day'],
                'total_videos' => $this->config['youtube']['upload_schedule']['posts_per_day'] * count($channels)
            ]
        ];

        return $status;
    }

    public function testConfiguration(): array
    {
        $results = [
            'ffmpeg' => $this->testFFmpeg(),
            'python' => $this->testPython(),
            'edge_tts' => $this->testEdgeTTS(),
            'directories' => $this->testDirectories(),
            'ai_api' => $this->testAIAPI(),
            'youtube_api' => $this->testYouTubeAPI()
        ];

        return $results;
    }

    private function testFFmpeg(): array
    {
        exec('ffmpeg -version 2>&1', $output, $returnCode);
        return [
            'available' => $returnCode === 0,
            'version' => $returnCode === 0 ? $output[0] : 'Not found'
        ];
    }

    private function testPython(): array
    {
        exec('python3 --version 2>&1', $output, $returnCode);
        return [
            'available' => $returnCode === 0,
            'version' => $returnCode === 0 ? $output[0] : 'Not found'
        ];
    }

    private function testEdgeTTS(): array
    {
        exec('python3 -c "import edge_tts" 2>&1', $output, $returnCode);
        return [
            'available' => $returnCode === 0,
            'error' => $returnCode !== 0 ? implode("\n", $output) : null
        ];
    }

    private function testDirectories(): array
    {
        $dirs = ['output', 'temp', 'logs', 'background_videos'];
        $results = [];

        foreach ($dirs as $dir) {
            $path = $this->config['paths'][$dir];
            $results[$dir] = [
                'path' => $path,
                'exists' => is_dir($path),
                'writable' => is_writable($path) || is_writable(dirname($path))
            ];
        }

        return $results;
    }

    private function testAIAPI(): array
    {
        try {
            // Test AI API connection
            $story = $this->storyGenerator->generateStory();
            return [
                'available' => true,
                'test_story_length' => str_word_count($story['content'])
            ];
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function testYouTubeAPI(): array
    {
        $results = [];

        foreach ($this->youtubeUploader->getAllChannels() as $channelKey) {
            try {
                $channelConfig = $this->config['youtube']['channels'][$channelKey];
                // Basic credential check
                $hasCredentials = !empty($channelConfig['client_id']) &&
                    !empty($channelConfig['client_secret']) &&
                    !empty($channelConfig['refresh_token']);

                $results[$channelKey] = [
                    'has_credentials' => $hasCredentials,
                    'configured' => $hasCredentials
                ];
            } catch (\Exception $e) {
                $results[$channelKey] = [
                    'has_credentials' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }
}
