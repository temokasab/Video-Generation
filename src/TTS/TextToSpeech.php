<?php

namespace RedditStoryShorts\TTS;

use Monolog\Logger;

class TextToSpeech
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function generateAudio(string $text, string $outputPath): bool
    {
        $this->logger->info('Generating TTS audio for text: ' . substr($text, 0, 50) . '...');

        try {
            switch ($this->config['tts']['provider']) {
                case 'edge_tts':
                    return $this->generateWithEdgeTTS($text, $outputPath);
                default:
                    throw new \Exception('Unsupported TTS provider: ' . $this->config['tts']['provider']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate TTS audio: ' . $e->getMessage());
            return false;
        }
    }

    private function generateWithEdgeTTS(string $text, string $outputPath): bool
    {
        $voice = $this->config['tts']['voice'];
        $rate = $this->config['tts']['speed'];
        $volume = $this->config['tts']['volume'];

        // Clean text for TTS
        $cleanText = $this->cleanTextForTTS($text);

        // Create temporary text file
        $tempTextFile = sys_get_temp_dir() . '/tts_text_' . uniqid() . '.txt';
        file_put_contents($tempTextFile, $cleanText);

        // Convert rate and volume to percentage format that edge-tts expects
        // Rate: 1.0 = +0%, 1.2 = +20%, 0.8 = -20%
        $ratePercent = round(($rate - 1.0) * 100);
        $rateStr = ($ratePercent >= 0 ? '+' : '') . $ratePercent . '%';

        // Volume: 1.0 = +0%, 0.8 = -20%, 1.2 = +20%
        $volumePercent = round(($volume - 1.0) * 100);
        $volumeStr = ($volumePercent >= 0 ? '+' : '') . $volumePercent . '%';

        // Use native edge-tts command directly
        $command = sprintf(
            'edge-tts --file "%s" --voice "%s" --rate="%s" --volume="%s" --write-media "%s"',
            $tempTextFile,
            $voice,
            $rateStr,
            $volumeStr,
            $outputPath
        );

        $this->logger->debug('Executing TTS command: ' . $command);

        exec($command . ' 2>&1', $output, $returnCode);

        // Cleanup temp file
        unlink($tempTextFile);

        if ($returnCode === 0 && file_exists($outputPath)) {
            $this->logger->info('TTS audio generated successfully: ' . $outputPath);
            return true;
        } else {
            $this->logger->error('TTS generation failed. Output: ' . implode("\n", $output));
            return false;
        }
    }

    private function createEdgeTTSScript(): string
    {
        $scriptPath = __DIR__ . '/../../scripts/edge_tts.py';

        if (!file_exists($scriptPath)) {
            $scriptContent = '#!/usr/bin/env python3
import asyncio
import argparse
import edge_tts

async def main():
    parser = argparse.ArgumentParser(description="Generate TTS using Edge TTS")
    parser.add_argument("--voice", required=True, help="Voice to use")
    parser.add_argument("--rate", default="+0%", help="Speech rate")
    parser.add_argument("--volume", default="+0%", help="Speech volume")
    parser.add_argument("--text-file", required=True, help="File containing text to speak")
    parser.add_argument("--output", required=True, help="Output audio file")
    
    args = parser.parse_args()
    
    # Read text from file
    with open(args.text_file, "r", encoding="utf-8") as f:
        text = f.read()
    
    # Generate speech
    communicate = edge_tts.Communicate(text, args.voice, rate=args.rate, volume=args.volume)
    await communicate.save(args.output)

if __name__ == "__main__":
    asyncio.run(main())
';

            // Create scripts directory if it doesn't exist
            $scriptsDir = dirname($scriptPath);
            if (!is_dir($scriptsDir)) {
                mkdir($scriptsDir, 0755, true);
            }

            file_put_contents($scriptPath, $scriptContent);
            chmod($scriptPath, 0755);
        }

        return $scriptPath;
    }

    private function cleanTextForTTS(string $text): string
    {
        // Remove Reddit formatting
        $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text); // Bold
        $text = preg_replace('/\*(.*?)\*/', '$1', $text); // Italic
        $text = preg_replace('/~~(.*?)~~/', '$1', $text); // Strikethrough
        $text = preg_replace('/`(.*?)`/', '$1', $text); // Code

        // Remove URLs
        $text = preg_replace('/https?:\/\/[^\s]+/', '', $text);

        // Clean up special characters that might cause TTS issues
        $text = str_replace(['&', '<', '>', '"', "'"], ['and', '', '', '', ''], $text);

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Add natural pauses
        $text = str_replace(['. ', '? ', '! '], ['. ', '? ', '! '], $text);
        $text = str_replace([',', ';', ':'], [', ', '; ', ': '], $text);

        return $text;
    }

    public function getAudioDuration(string $audioPath): float
    {
        // Use ffprobe to get audio duration
        $command = sprintf(
            'ffprobe -v quiet -show_entries format=duration -of csv="p=0" "%s"',
            $audioPath
        );

        $duration = trim(shell_exec($command));
        return floatval($duration);
    }

    public function adjustAudioSpeed(string $inputPath, string $outputPath, float $speedFactor): bool
    {
        // Use ffmpeg to adjust audio speed
        $command = sprintf(
            'ffmpeg -i "%s" -filter:a "atempo=%f" -y "%s" 2>/dev/null',
            $inputPath,
            $speedFactor,
            $outputPath
        );

        exec($command, $output, $returnCode);
        return $returnCode === 0 && file_exists($outputPath);
    }

    public function normalizeAudio(string $inputPath, string $outputPath): bool
    {
        // Normalize audio levels
        $command = sprintf(
            'ffmpeg -i "%s" -filter:a "loudnorm" -y "%s" 2>/dev/null',
            $inputPath,
            $outputPath
        );

        exec($command, $output, $returnCode);
        return $returnCode === 0 && file_exists($outputPath);
    }
}
