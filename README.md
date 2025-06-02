# YouTube Reddit Story Automation

Automated YouTube Shorts generator that creates Reddit story videos with AI narration and background video.

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

## Usage

### Generate Single Video
```bash
# Test video generation (no upload)
php generate_video.php --skip-upload

# Upload to YouTube
php generate_video.php
```

### Batch Generation
```bash
# Generate multiple videos
php generate_batch.php --count=5 --skip-upload
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
# Setup YouTube OAuth
php setup_youtube_auth.php
```

## Configuration

Edit `config/config.php`:
- YouTube API credentials
- Video settings (resolution, duration)
- Story themes
- Upload schedule

## Commands Reference

| Command | Description |
|---------|-------------|
| `php generate_video.php` | Generate and upload single video |
| `php generate_video.php --skip-upload` | Generate video without upload |
| `php generate_batch.php --count=N` | Generate N videos |
| `php automate.php` | Run scheduled automation |
| `php test_ollama.php` | Test AI story generation |
| `php setup_youtube_auth.php` | Setup YouTube authentication |

## Output

Videos saved to `output/` directory with format: `video_YYYY-MM-DD_HH-MM-SS_[hash].mp4`

## Features

- AI story generation (Ollama - 100% free)
- Natural voice synthesis (edge-tts)
- Automatic video creation
- YouTube upload automation
- Customizable themes and styles
- Batch processing
- Progress monitoring

