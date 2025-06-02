<?php

namespace RedditStoryShorts\Video;

use Monolog\Logger;

class VideoGenerator
{
    private array $config;
    private Logger $logger;
    private array $tempFiles = [];

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateVideo(array $story, string $audioPath, string $outputPath): bool
    {
        $this->logger->info('Generating video for story: ' . $story['title']);

        try {
            $backgroundVideo = $this->getBackgroundVideo();
            if (!$backgroundVideo) {
                throw new \Exception('Failed to get background video');
            }

            $this->tempFiles[] = $backgroundVideo;
            $audioDuration = $this->getAudioDuration($audioPath);
            $subtitlePath = $this->createSubtitles($story['content'], $audioDuration);
            $this->tempFiles[] = $subtitlePath;
            $success = $this->combineMediaFiles($backgroundVideo, $audioPath, $subtitlePath, $outputPath, $audioDuration);

            if ($success) {
                $this->cleanupTempFiles();
            }

            return $success;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate video: ' . $e->getMessage());
            $this->cleanupTempFiles();
            return false;
        }
    }

    private function getBackgroundVideo(): ?string
    {
        $this->logger->info('Downloading new background video for this generation');
        return $this->downloadBackgroundVideo();
    }

    private function downloadBackgroundVideo(): ?string
    {
        $this->logger->info('Downloading background video from free sources');

        $apiKey = $this->config['apis']['background_videos']['pexels']['api_key'] ?? null;
        if (!$apiKey || $apiKey === 'YOUR_PEXELS_API_KEY') {
            $this->logger->warning('Pexels API key not configured, falling back to simple background');
            return $this->createSimpleBackground();
        }

        $backgroundTypes = $this->config['video']['background_videos'];
        $selectedType = $backgroundTypes[array_rand($backgroundTypes)];
        $endpoint = $this->config['apis']['background_videos']['pexels']['endpoint'];
        $query = $selectedType;
        $url = $endpoint . '?query=' . urlencode($query) . '&per_page=10&orientation=portrait';

        $this->logger->info("Making Pexels API request: $url");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'YouTube Automation Bot/1.0');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error('cURL error: ' . $curlError);
            return $this->createSimpleBackground();
        }

        if ($httpCode !== 200) {
            $this->logger->error("Pexels API returned HTTP $httpCode: $response");
            return $this->createSimpleBackground();
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode Pexels API response: ' . json_last_error_msg());
            return $this->createSimpleBackground();
        }

        if (empty($data['videos'])) {
            $this->logger->info('No videos found for query: ' . $selectedType);
            return $this->createSimpleBackground();
        }

        $video = $data['videos'][array_rand($data['videos'])];
        $this->logger->info('Found ' . count($data['videos']) . ' videos, selected: ' . $video['url']);

        $videoFile = null;
        $qualities = ['hd', 'sd'];

        foreach ($qualities as $targetQuality) {
            foreach ($video['video_files'] as $file) {
                if ($file['quality'] === $targetQuality && !empty($file['link'])) {
                    $videoFile = $file;
                    break 2;
                }
            }
        }

        if (!$videoFile && !empty($video['video_files'])) {
            $videoFile = $video['video_files'][0];
        }

        if (!$videoFile || empty($videoFile['link'])) {
            $this->logger->warning('No suitable video file found in Pexels response');
            return $this->createSimpleBackground();
        }

        $this->logger->info('Found suitable Pexels video: ' . $video['url'] . ' (Quality: ' . $videoFile['quality'] . ')');

        $tempDir = $this->config['paths']['temp'];
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $tempPath = $tempDir . 'pexels_' . uniqid() . '.mp4';
        $this->logger->info('Downloading video to: ' . $tempPath);

        $downloadCh = curl_init();
        curl_setopt($downloadCh, CURLOPT_URL, $videoFile['link']);
        curl_setopt($downloadCh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($downloadCh, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($downloadCh, CURLOPT_TIMEOUT, 300);
        curl_setopt($downloadCh, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($downloadCh, CURLOPT_USERAGENT, 'YouTube Automation Bot/1.0');

        $videoData = curl_exec($downloadCh);
        $downloadHttpCode = curl_getinfo($downloadCh, CURLINFO_HTTP_CODE);
        $downloadError = curl_error($downloadCh);
        curl_close($downloadCh);

        if ($downloadError) {
            $this->logger->error('Video download cURL error: ' . $downloadError);
            return $this->createSimpleBackground();
        }

        if ($downloadHttpCode !== 200) {
            $this->logger->error("Video download failed with HTTP $downloadHttpCode");
            return $this->createSimpleBackground();
        }

        if (empty($videoData)) {
            $this->logger->error('Downloaded video data is empty');
            return $this->createSimpleBackground();
        }

        if (file_put_contents($tempPath, $videoData) === false) {
            $this->logger->error('Failed to save video to: ' . $tempPath);
            return $this->createSimpleBackground();
        }

        $fileSize = filesize($tempPath);
        $this->logger->info("Successfully downloaded video: $tempPath (Size: " . round($fileSize / 1024 / 1024, 2) . " MB)");

        return $tempPath;
    }

    private function createSimpleBackground(): ?string
    {
        $outputPath = $this->config['paths']['temp'] . 'bg_' . uniqid() . '.mp4';

        if (!is_dir($this->config['paths']['temp'])) {
            mkdir($this->config['paths']['temp'], 0755, true);
        }

        $colors = ['0x1a1a2e', '0x16213e', '0x0f3460', '0x533483', '0x7209b7'];
        $selectedColor = $colors[array_rand($colors)];

        $command = sprintf(
            'ffmpeg -f lavfi -i "color=c=%s:size=1080x1920:duration=300" -f lavfi -i "testsrc2=size=1080x1920:duration=300" -filter_complex "[0:v][1:v]blend=all_mode=overlay:all_opacity=0.3" -c:v libx264 -t 300 -y "%s" 2>/dev/null',
            $selectedColor,
            $outputPath
        );

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath)) {
            $this->logger->info('Created new background video: ' . $outputPath);
            return $outputPath;
        }

        return null;
    }

    private function getAudioDuration(string $audioPath): float
    {
        $command = sprintf(
            'ffprobe -v quiet -show_entries format=duration -of csv="p=0" "%s"',
            $audioPath
        );

        $duration = trim(shell_exec($command));
        return floatval($duration);
    }

    private function createSubtitles(string $text, float $duration): string
    {
        $words = explode(' ', $text);
        $totalWords = count($words);
        $wordsPerSecond = $totalWords / $duration;
        $subtitlePath = $this->config['paths']['temp'] . 'subtitles_' . uniqid() . '.srt';

        if (!is_dir($this->config['paths']['temp'])) {
            mkdir($this->config['paths']['temp'], 0755, true);
        }

        $srtContent = '';
        $segmentIndex = 1;
        $wordsPerSegment = 8;

        for ($i = 0; $i < $totalWords; $i += $wordsPerSegment) {
            $segmentWords = array_slice($words, $i, $wordsPerSegment);
            $segmentText = implode(' ', $segmentWords);

            $startTime = (float)$i / $wordsPerSecond;
            $endTime = min($duration, (float)($i + $wordsPerSegment) / $wordsPerSecond);

            $srtContent .= sprintf(
                "%d\n%s --> %s\n%s\n\n",
                $segmentIndex,
                $this->formatSRTTime($startTime),
                $this->formatSRTTime($endTime),
                $segmentText
            );

            $segmentIndex++;
        }

        file_put_contents($subtitlePath, $srtContent);
        return $subtitlePath;
    }

    private function formatSRTTime(float $seconds): string
    {
        $hours = intval(floor($seconds / 3600));
        $minutes = intval(floor(($seconds % 3600) / 60));
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%06.3f', $hours, $minutes, $remainingSeconds);
    }

    private function combineMediaFiles(string $backgroundVideo, string $audioPath, string $subtitlePath, string $outputPath, float $duration): bool
    {
        $textStyle = $this->config['video']['text_style'];

        $command = sprintf(
            'ffmpeg -i "%s" -i "%s" -filter_complex "[0:v]scale=1080:1920,crop=1080:1920[scaled];[scaled]subtitles=\'%s\':force_style=\'FontName=%s,FontSize=%d,PrimaryColour=&H%s,OutlineColour=&H%s,Outline=%d,Shadow=%d,Alignment=10,MarginL=5,MarginV=25\'[outv]" -map "[outv]" -map 1:a -c:v libx264 -c:a aac -ac 2 -ar 44100 -t %.2f -y "%s" 2>/dev/null',
            $backgroundVideo,
            $audioPath,
            str_replace("'", "\\'", $subtitlePath),
            $textStyle['font'],
            $textStyle['font_size'],
            $this->colorToHex($textStyle['font_color']),
            $this->colorToHex($textStyle['outline_color']),
            $textStyle['outline_width'],
            $textStyle['shadow'] ? 1 : 0,
            $duration,
            $outputPath
        );

        $this->logger->debug('Executing video generation command: ' . $command);

        exec($command, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath)) {
            $this->logger->info('Video generated successfully: ' . $outputPath);
            return true;
        } else {
            $this->logger->error('Video generation failed. Output: ' . implode("\n", $output));
            return false;
        }
    }

    private function colorToHex(string $color): string
    {
        $color = ltrim($color, '#');

        if (strlen($color) === 6) {
            $r = substr($color, 0, 2);
            $g = substr($color, 2, 2);
            $b = substr($color, 4, 2);
            return $b . $g . $r;
        }

        return '000000';
    }

    public function addThumbnail(string $videoPath, string $thumbnailPath): bool
    {
        $command = sprintf(
            'ffmpeg -i "%s" -ss 00:00:03 -vframes 1 -q:v 2 "%s" 2>/dev/null',
            $videoPath,
            $thumbnailPath
        );

        exec($command, $output, $returnCode);
        return $returnCode === 0 && file_exists($thumbnailPath);
    }

    public function getVideoInfo(string $videoPath): array
    {
        $command = sprintf(
            'ffprobe -v quiet -print_format json -show_format -show_streams "%s"',
            $videoPath
        );

        $output = shell_exec($command);
        $info = json_decode($output, true);

        return [
            'duration' => floatval($info['format']['duration'] ?? 0),
            'size' => intval($info['format']['size'] ?? 0),
            'width' => intval($info['streams'][0]['width'] ?? 0),
            'height' => intval($info['streams'][0]['height'] ?? 0),
            'fps' => $this->parseFPS($info['streams'][0]['r_frame_rate'] ?? '30/1')
        ];
    }

    private function parseFPS(string $fpsString): float
    {
        if (strpos($fpsString, '/') !== false) {
            list($num, $den) = explode('/', $fpsString);
            return floatval($num) / floatval($den);
        }
        return floatval($fpsString);
    }

    /**
     * Clean up all files from the temp folder after video generation
     */
    private function cleanupTempFiles(): void
    {
        $tempDir = $this->config['paths']['temp'];

        if (!is_dir($tempDir)) {
            return;
        }

        $files = glob($tempDir . '*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $this->logger->info('Cleaned up temp file: ' . basename($file));
            }
        }

        $this->logger->info('Completed cleanup of temp directory: ' . $tempDir);
        $this->tempFiles = [];
    }
}
