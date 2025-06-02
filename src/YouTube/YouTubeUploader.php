<?php

namespace RedditStoryShorts\YouTube;

use Google\Client;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Monolog\Logger;

class YouTubeUploader
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function uploadToChannel(string $channelKey, string $videoPath, array $story, ?string $thumbnailPath = null): bool
    {
        $this->logger->info("Uploading video to channel: {$channelKey}");

        try {
            $channelConfig = $this->config['youtube']['channels'][$channelKey];
            $client = $this->createGoogleClient($channelConfig);
            $youtube = new YouTube($client);

            // Prepare video metadata
            $video = new Video();
            $videoSnippet = new VideoSnippet();
            $videoStatus = new VideoStatus();

            // Set video details
            $title = $this->generateTitle($story);
            $description = $this->generateDescription($story);
            $tags = $this->generateTags($story);

            $videoSnippet->setTitle($title);
            $videoSnippet->setDescription($description);
            $videoSnippet->setTags($tags);
            $videoSnippet->setCategoryId($this->config['youtube']['default_settings']['category_id']);

            $videoStatus->setPrivacyStatus($this->config['youtube']['default_settings']['privacy_status']);

            $video->setSnippet($videoSnippet);
            $video->setStatus($videoStatus);

            // Upload video
            $uploadedVideo = $this->performUpload($youtube, $video, $videoPath);

            if ($uploadedVideo && $thumbnailPath) {
                $this->uploadThumbnail($youtube, $uploadedVideo->getId(), $thumbnailPath);
            }

            if ($uploadedVideo) {
                $this->logger->info("Video uploaded successfully. Video ID: " . $uploadedVideo->getId());
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error("Failed to upload video to channel {$channelKey}: " . $e->getMessage());
            return false;
        }
    }

    private function createGoogleClient(array $channelConfig): Client
    {
        $client = new Client();
        $client->setClientId($channelConfig['client_id']);
        $client->setClientSecret($channelConfig['client_secret']);
        $client->setAccessType('offline');
        $client->setScopes([YouTube::YOUTUBE_UPLOAD, YouTube::YOUTUBE]);

        // Use refresh token to get access token
        $client->refreshToken($channelConfig['refresh_token']);

        return $client;
    }

    private function generateTitle(array $story): string
    {
        $title = $story['title'];

        // Ensure title is within YouTube's limits (100 characters)
        if (strlen($title) > 100) {
            $title = substr($title, 0, 97) . '...';
        }

        return $title;
    }

    private function generateDescription(array $story): string
    {
        $template = $this->config['youtube']['default_settings']['description_template'];
        $disclaimers = $this->config['content']['required_disclaimers'];

        $description = $template . "\n\n";

        // Add story preview (first 100 characters)
        $preview = substr($story['content'], 0, 100) . '...';
        $description .= "Story Preview: " . $preview . "\n\n";

        // Add disclaimers
        foreach ($disclaimers as $disclaimer) {
            $description .= $disclaimer . "\n";
        }

        $description .= "\n#RedditStories #Shorts #Reddit #Drama #Stories #AIGenerated";

        return $description;
    }

    private function generateTags(array $story): array
    {
        $baseTags = $this->config['youtube']['default_settings']['tags'];
        $themeTags = $this->getThemeTags($story['theme']);

        return array_merge($baseTags, $themeTags);
    }

    private function getThemeTags(string $theme): array
    {
        $themeTags = [
            'relationship_drama' => ['relationship', 'dating', 'breakup', 'love', 'AITA'],
            'workplace_stories' => ['work', 'office', 'job', 'career', 'workplace'],
            'family_issues' => ['family', 'parents', 'siblings', 'relatives'],
            'friendship_conflicts' => ['friends', 'friendship', 'betrayal', 'trust'],
            'life_decisions' => ['life', 'decisions', 'choices', 'advice'],
            'revenge_stories' => ['revenge', 'justice', 'karma', 'payback']
        ];

        return $themeTags[$theme] ?? [];
    }

    private function performUpload(YouTube $youtube, Video $video, string $videoPath): ?Video
    {
        try {
            // Read video file
            $videoData = file_get_contents($videoPath);

            if (!$videoData) {
                throw new \Exception('Failed to read video file: ' . $videoPath);
            }

            // Upload video
            $uploadResponse = $youtube->videos->insert(
                'snippet,status',
                $video,
                [
                    'data' => $videoData,
                    'mimeType' => 'video/mp4',
                    'uploadType' => 'multipart'
                ]
            );

            return $uploadResponse;
        } catch (\Exception $e) {
            $this->logger->error('Upload failed: ' . $e->getMessage());
            return null;
        }
    }

    private function uploadThumbnail(YouTube $youtube, string $videoId, string $thumbnailPath): bool
    {
        try {
            $thumbnailData = file_get_contents($thumbnailPath);

            if (!$thumbnailData) {
                $this->logger->warning('Failed to read thumbnail file: ' . $thumbnailPath);
                return false;
            }

            $youtube->thumbnails->set(
                $videoId,
                [
                    'data' => $thumbnailData,
                    'mimeType' => 'image/jpeg',
                    'uploadType' => 'media'
                ]
            );

            $this->logger->info('Thumbnail uploaded successfully for video: ' . $videoId);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to upload thumbnail: ' . $e->getMessage());
            return false;
        }
    }

    public function getAllChannels(): array
    {
        return array_keys($this->config['youtube']['channels']);
    }

    public function getChannelUploadSchedule(string $channelKey): array
    {
        $channelConfig = $this->config['youtube']['channels'][$channelKey];
        return $channelConfig['upload_schedule'] ?? [];
    }

    public function shouldUploadNow(string $channelKey): bool
    {
        $schedule = $this->getChannelUploadSchedule($channelKey);

        if (empty($schedule)) {
            return false;
        }

        $timezone = new \DateTimeZone($schedule['timezone'] ?? 'UTC');
        $now = new \DateTime('now', $timezone);
        $currentHour = (int)$now->format('H');

        // Check if current time is within upload window
        $startHour = $schedule['start_hour'] ?? 0;
        $endHour = $schedule['end_hour'] ?? 23;

        if ($currentHour < $startHour || $currentHour > $endHour) {
            return false;
        }

        // Check if we've already reached the daily upload limit
        $postsPerDay = $schedule['posts_per_day'] ?? 1;
        $todayUploads = $this->getTodayUploadCount($channelKey);

        return $todayUploads < $postsPerDay;
    }

    private function getTodayUploadCount(string $channelKey): int
    {
        // This would typically check a database or log file
        // For now, we'll implement a simple file-based counter
        $counterFile = $this->config['paths']['logs'] . "uploads_{$channelKey}_" . date('Y-m-d') . '.count';

        if (file_exists($counterFile)) {
            return (int)file_get_contents($counterFile);
        }

        return 0;
    }

    public function incrementUploadCount(string $channelKey): void
    {
        $counterFile = $this->config['paths']['logs'] . "uploads_{$channelKey}_" . date('Y-m-d') . '.count';
        $currentCount = $this->getTodayUploadCount($channelKey);
        file_put_contents($counterFile, $currentCount + 1);
    }

    public function getUploadHistory(string $channelKey, int $days = 7): array
    {
        $history = [];

        for ($i = 0; $i < $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $counterFile = $this->config['paths']['logs'] . "uploads_{$channelKey}_{$date}.count";

            $count = 0;
            if (file_exists($counterFile)) {
                $count = (int)file_get_contents($counterFile);
            }

            $history[$date] = $count;
        }

        return array_reverse($history, true);
    }
}
