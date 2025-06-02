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

    public function generateAndUploadVideo(string $channelKey = null): bool
    {
        $this->logger->info('Starting video generation and upload process');

        try {
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

            // Check if upload should be skipped
            $skipUpload = $this->config['automation']['skip_upload'] ?? false;

            $uploadSuccess = true; // Default to true when skipping upload

            if ($skipUpload) {
                $this->logger->info('Upload skipped due to skip_upload configuration. Video saved to: ' . $videoPath);
            } else {
                // Upload to channels
                if ($channelKey) {
                    // Upload to specific channel
                    $uploadSuccess = $this->uploadToChannel($channelKey, $videoPath, $story, $thumbnailPath);
                } else {
                    // Upload to all available channels based on their schedules
                    $uploadSuccess = $this->uploadToAvailableChannels($videoPath, $story, $thumbnailPath);
                }
            }

            // Cleanup temporary files - now including the audio file
            $this->cleanupFiles([$audioPath, $thumbnailPath]);
            $this->logger->info('Cleaned up generated audio and thumbnail files');

            return $uploadSuccess;
        } catch (\Exception $e) {
            $this->logger->error('Video generation and upload failed: ' . $e->getMessage());
            return false;
        }
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

    private function uploadToChannel(string $channelKey, string $videoPath, array $story, ?string $thumbnailPath): bool
    {
        if (!$this->youtubeUploader->shouldUploadNow($channelKey)) {
            $this->logger->info("Channel {$channelKey} is not scheduled for upload at this time");
            return false;
        }

        $success = $this->youtubeUploader->uploadToChannel($channelKey, $videoPath, $story, $thumbnailPath);

        if ($success) {
            $this->youtubeUploader->incrementUploadCount($channelKey);
        }

        return $success;
    }

    private function uploadToAvailableChannels(string $videoPath, array $story, ?string $thumbnailPath): bool
    {
        $channels = $this->youtubeUploader->getAllChannels();
        $uploadSuccess = false;

        foreach ($channels as $channelKey) {
            if ($this->youtubeUploader->shouldUploadNow($channelKey)) {
                $success = $this->youtubeUploader->uploadToChannel($channelKey, $videoPath, $story, $thumbnailPath);

                if ($success) {
                    $this->youtubeUploader->incrementUploadCount($channelKey);
                    $uploadSuccess = true;

                    // Only upload to one channel per video generation
                    break;
                }
            }
        }

        return $uploadSuccess;
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

        $maxVideos = $this->config['automation']['max_videos_per_run'];
        $generated = 0;
        $skipUpload = $this->config['automation']['skip_upload'] ?? false;

        while ($generated < $maxVideos) {
            // Check if any channel needs content (skip this check if uploads are disabled)
            if (!$skipUpload) {
                $channelNeedsContent = false;
                foreach ($this->youtubeUploader->getAllChannels() as $channelKey) {
                    if ($this->youtubeUploader->shouldUploadNow($channelKey)) {
                        $channelNeedsContent = true;
                        break;
                    }
                }

                if (!$channelNeedsContent) {
                    $this->logger->info('No channels need content at this time');
                    break;
                }
            } else {
                $this->logger->info('Upload disabled, generating video ' . ($generated + 1) . '/' . $maxVideos);
            }

            // Generate and upload video
            $success = $this->generateAndUploadVideo();

            if ($success) {
                $generated++;
                $this->logger->info("Successfully generated video {$generated}/{$maxVideos}");
            } else {
                $this->logger->error("Failed to generate video " . ($generated + 1));

                // Wait before retrying
                sleep($this->config['automation']['retry_delay']);
            }

            // Small delay between generations
            sleep(30);
        }

        $this->logger->info("Automation run completed. Generated {$generated} videos.");

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
        $status = [
            'app_version' => '1.0.0',
            'automation_enabled' => $this->config['automation']['enabled'],
            'channels' => [],
            'last_run' => null
        ];

        // Get channel status
        foreach ($this->youtubeUploader->getAllChannels() as $channelKey) {
            $status['channels'][$channelKey] = [
                'name' => $this->config['youtube']['channels'][$channelKey]['name'],
                'should_upload_now' => $this->youtubeUploader->shouldUploadNow($channelKey),
                'upload_history' => $this->youtubeUploader->getUploadHistory($channelKey, 7)
            ];
        }

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
