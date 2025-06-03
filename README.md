# YouTube Reddit Story Automation

Automated YouTube Shorts generator that creates Reddit story videos with AI narration and background video. Generates unique videos for multiple channels simultaneously.

## Installation (macOS)

### Prerequisites
```bash
# Install Homebrew if not installed
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

# Install PHP and Composer
brew install php composer

# Install Python and edge-tts
brew install python
pip3 install edge-tts

# Install FFmpeg
brew install ffmpeg

# Install Ollama (Local AI)
brew install ollama
brew services start ollama
ollama pull llama3.2:3b
```

### Project Setup
```bash
# Clone and setup
git clone <your-repo-url>
cd youtube_automation
composer install

# Copy and configure
cp config/config.example.php config/config.php
# Edit config/config.php with your YouTube API credentials
```

## Multi-Channel Configuration

The system now supports multiple YouTube channels using a simplified approach:

1. **Common Settings**: Client ID, Client Secret, and upload schedule are shared across all channels
2. **Channel Tokens**: Just add refresh tokens for each channel to the `channels` array
3. **Automatic Generation**: Each run creates unique videos for ALL channels

### Adding Channels
```php
'channels' => [
    'REFRESH_TOKEN_1',    // Channel 1
    'REFRESH_TOKEN_2',    // Channel 2  
    'REFRESH_TOKEN_3',    // Channel 3
    // Add as many as you want
]
```

## Usage

### Generate Videos for All Channels
```bash
# Test video generation (no upload)
php generate_video.php --skip-upload

# Generate and upload to all channels
php generate_video.php
```

### Batch Generation
```bash
# Generate 5 video sets (5 videos per channel)
php generate_batch.php --count=5 --skip-upload

# With 3 channels: generates 15 total videos
```

### Test Setup
```bash
# Test Ollama AI
php test_ollama.php

# Test video generation
php setup.php
```

### YouTube Setup
```bash
# Setup YouTube OAuth for each channel
php setup_youtube_auth.php
# Repeat for each channel, adding tokens to config
```

## Configuration

Edit `config/config.php`:
- YouTube API credentials (shared)
- Channel refresh tokens (one per channel)
- Video settings (resolution, duration)
- Story themes
- Upload schedule

## Commands Reference

| Command | Description |
|---------|-------------|
| `php generate_video.php` | Generate and upload videos for all channels |
| `php generate_video.php --skip-upload` | Generate videos without upload |
| `php generate_batch.php --count=N` | Generate N video sets (N videos per channel) |
| `php automate.php` | Run scheduled automation |
| `php test_ollama.php` | Test AI story generation |
| `php setup_youtube_auth.php` | Setup YouTube authentication |

## Scaling Examples

**1 Channel, 10 videos/day**: 10 total videos
**5 Channels, 10 videos/day**: 50 total videos  
**10 Channels, 10 videos/day**: 100 total videos

Each channel gets unique, fresh content automatically.

## Output

Videos saved to `output/` directory with format: `video_YYYY-MM-DD_HH-MM-SS_[hash].mp4`

## Features

- AI story generation (Ollama - 100% free)
- Natural voice synthesis (edge-tts)
- Automatic video creation
- Multi-channel YouTube upload automation
- Unique content for each channel
- Customizable themes and styles
- Batch processing
- Progress monitoring

