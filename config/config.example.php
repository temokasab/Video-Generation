<?php

// Reddit Story Shorts - Direct Configuration
// No .env file needed - all settings in this file

return [
    // AI Story Generation Settings
    'ai' => [
        'provider' => 'ollama', // Free local AI
        'ollama' => [
            'endpoint' => 'http://localhost:11434/api/generate',
            'model' => 'llama3.2:3b',
            'temperature' => 0.8,
            'max_tokens' => 300,
        ],
        'huggingface' => [
            'api_token' => 'YOUR_HUGGINGFACE_TOKEN',
            'model' => 'microsoft/DialoGPT-small',
            'endpoint' => 'https://api-inference.huggingface.co/models/',
        ],
        'story_settings' => [
            'min_length' => 150,
            'max_length' => 300,
            'themes' => [
                'relationship_drama',
                'workplace_stories',
                'family_issues',
                'friendship_conflicts',
                'life_decisions',
                'revenge_stories',
                'college_stories',
                'neighbor_drama',
                'dating_disasters',
                'karen_encounters',
                'wedding_disasters',
                'travel_nightmares',
                'customer_service_horror',
                'roommate_hell',
                'social_media_drama',
                'prank_wars',
                'inheritance_drama',
                'pet_stories',
                'gaming_drama',
                'fitness_fails',
                'cooking_disasters'
            ]
        ]
    ],

    // Text-to-Speech Settings
    'tts' => [
        'provider' => 'edge_tts',
        'voice' => 'en-US-AndrewNeural',
        'speed' => 1.0,
        'pitch' => 0,
        'volume' => 0.8
    ],

    // Video Generation Settings
    'video' => [
        'resolution' => '1080x1920',
        'fps' => 30,
        'duration_range' => [45, 90],
        'background_videos' => [
            'animals',
            'asmr',
            'workout',
            'working',
            'cooking',
            'nature',
            'gameplay',
            'relaxing'
        ],
        'text_style' => [
            'font' => 'Arial-Bold',
            'font_size' => 14,
            'font_color' => '#FFFFFF',
            'outline_color' => '#000000',
            'outline_width' => 2,
            'shadow' => false
        ]
    ],

    // YouTube Settings
    'youtube' => [
        'channels' => [
            'channel_1' => [
                'name' => 'Reddit Stories Daily',
                'client_id' => '', // Google Client ID
                'client_secret' => '', // Google Client Secret
                'refresh_token' => '', // Refresh token
                'upload_schedule' => [
                    'posts_per_day' => 10,
                    'start_hour' => 6,
                    'end_hour' => 22,
                    'timezone' => 'America/New_York'
                ]
            ]
            // Add channel_2 here if needed:
            // 'channel_2' => [
            //     'name' => 'Reddit Stories Plus',
            //     'client_id' => 'YOUR_GOOGLE_CLIENT_ID_2',
            //     'client_secret' => 'YOUR_GOOGLE_CLIENT_SECRET_2',
            //     'refresh_token' => 'YOUR_REFRESH_TOKEN_2',
            //     'upload_schedule' => [
            //         'posts_per_day' => 8,
            //         'start_hour' => 7,
            //         'end_hour' => 21,
            //         'timezone' => 'America/New_York'
            //     ]
            // ]
        ],
        'default_settings' => [
            'privacy_status' => 'public',
            'category_id' => '24',
            'tags' => [
                'reddit stories',
                'reddit',
                'stories',
                'shorts',
                'reddit shorts',
                'askreddit',
                'reddit drama'
            ],
            'description_template' => "CRAZY Reddit Story!\n\n#RedditStories #Shorts #Reddit #Drama #Stories"
        ]
    ],

    // Content Generation Rules
    'content' => [
        'avoid_keywords' => [],
        'required_disclaimers' => [
            'This story is AI-generated for entertainment purposes.',
            'Any resemblance to real events is purely coincidental.'
        ],
        'content_filters' => [
            'profanity_filter' => true,
            'violence_filter' => true,
            'adult_content_filter' => true
        ]
    ],

    // Automation Settings
    'automation' => [
        'enabled' => true,
        'skip_upload' => true, // Set false to upload to YouTube
        'max_videos_per_run' => 5,
        'retry_attempts' => 3,
        'retry_delay' => 300,
        'cleanup_old_videos' => true,
        'keep_videos_days' => 7
    ],

    // File Paths
    'paths' => [
        'background_videos' => 'assets/backgrounds/',
        'fonts' => 'assets/fonts/',
        'output' => 'output/',
        'temp' => 'temp/',
        'logs' => 'logs/'
    ],

    // Logging
    'logging' => [
        'level' => 'info',
        'file' => 'logs/app.log',
        'max_files' => 10
    ],

    // External APIs
    'apis' => [
        'background_videos' => [
            'pexels' => [
                'api_key' => '', // Optional: Pexels API key
                'endpoint' => 'https://api.pexels.com/videos/search'
            ]
        ]
    ]
];
