<?php

echo "Reddit Story Shorts Auto Generator - Setup\n";
echo "==========================================\n\n";

// Check PHP version
if (version_compare(PHP_VERSION, '8.0.0') < 0) {
    echo "Error: PHP 8.0 or higher is required. Current version: " . PHP_VERSION . "\n";
    exit(1);
}

echo "PHP version check passed (" . PHP_VERSION . ")\n";

// Check if composer is installed
exec('composer --version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Error: Composer is not installed or not in PATH\n";
    echo "Please install Composer from https://getcomposer.org/\n";
    exit(1);
}

echo "Composer is available\n";

// Install PHP dependencies
echo "Installing PHP dependencies...\n";
exec('composer install 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Error: Failed to install PHP dependencies\n";
    echo implode("\n", $output) . "\n";
    exit(1);
}

echo "PHP dependencies installed\n";

// Check for FFmpeg
exec('ffmpeg -version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Warning: FFmpeg is not installed or not in PATH\n";
    echo "FFmpeg is required for video processing. Please install it:\n";
    echo "- macOS: brew install ffmpeg\n";
    echo "- Ubuntu: sudo apt update && sudo apt install ffmpeg\n";
    echo "- Windows: Download from https://ffmpeg.org/download.html\n\n";
} else {
    echo "FFmpeg is available\n";
}

// Check for Python 3
exec('python3 --version 2>&1', $output, $returnCode);
if ($returnCode !== 0) {
    echo "Warning: Python 3 is not installed or not in PATH\n";
    echo "Python 3 is required for text-to-speech. Please install it:\n";
    echo "- macOS: brew install python3\n";
    echo "- Ubuntu: sudo apt update && sudo apt install python3 python3-pip\n";
    echo "- Windows: Download from https://python.org/downloads/\n\n";
} else {
    echo "Python 3 is available\n";

    // Install edge-tts
    echo "Installing edge-tts (Text-to-Speech)...\n";
    exec('python3 -m pip install edge-tts 2>&1', $output, $returnCode);
    if ($returnCode !== 0) {
        echo "Warning: Failed to install edge-tts\n";
        echo "You can install it manually with: python3 -m pip install edge-tts\n\n";
    } else {
        echo "edge-tts installed\n";
    }
}

// Create configuration file
if (!file_exists('config/config.php')) {
    if (file_exists('config/config.example.php')) {
        copy('config/config.example.php', 'config/config.php');
        echo "Configuration file created (config/config.php)\n";
    } else {
        echo "Warning: Could not create configuration file\n";
    }
} else {
    echo "Configuration file already exists\n";
}

// Create directories
$directories = [
    'assets/backgrounds',
    'assets/fonts',
    'output',
    'temp',
    'logs',
    'scripts'
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        echo "Created directory: {$dir}\n";
    } else {
        echo "Directory exists: {$dir}\n";
    }
}

// Create .gitignore if it doesn't exist
if (!file_exists('.gitignore')) {
    $gitignoreContent = <<<EOL
# Dependencies
/vendor/

# Configuration (contains sensitive data)
config/config.php

# Generated content
/output/
/temp/
/logs/

# IDE files
.vscode/
.idea/
*.swp
*.swo

# OS files
.DS_Store
Thumbs.db

# Environment files
.env
.env.local
EOL;

    file_put_contents('.gitignore', $gitignoreContent);
    echo "Created .gitignore file\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Setup completed!\n\n";

echo "Next steps:\n";
echo "1. Edit config/config.php and add your API keys:\n";
echo "   - Hugging Face API token (free at https://huggingface.co/)\n";
echo "   - YouTube API credentials (from Google Cloud Console)\n";
echo "   - Pexels API key (optional, for background videos)\n\n";

echo "2. Test your configuration:\n";
echo "   php generate_video.php --test\n\n";

echo "3. Generate your first video:\n";
echo "   php generate_video.php\n\n";

echo "4. Set up automation (optional):\n";
echo "   - Run: php automate.php\n";
echo "   - Or set up a cron job to run automate.php periodically\n\n";

echo "For detailed setup instructions, see README.md\n";
echo "If you encounter issues, check the logs/ directory\n\n";

echo "Happy content creating!\n";
