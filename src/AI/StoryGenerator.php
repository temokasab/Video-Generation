<?php

namespace RedditStoryShorts\AI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;

class StoryGenerator
{
    private array $config;
    private Client $httpClient;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'RedditStoryGenerator/1.0'
            ]
        ]);
    }

    public function generateStory(): array
    {
        $this->logger->info('Generating new Reddit story');

        try {
            $theme = $this->getRandomTheme();
            $prompt = $this->createPrompt($theme);

            switch ($this->config['ai']['provider']) {
                case 'huggingface':
                    return $this->generateWithHuggingFace($prompt, $theme);
                case 'ollama':
                    return $this->generateWithOllama($prompt, $theme);
                case 'fallback':
                    $this->logger->info('Using fallback story generation due to API issues');
                    return $this->generateFallbackStory($theme);
                default:
                    throw new \Exception('Unsupported AI provider: ' . $this->config['ai']['provider']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate story: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getRandomTheme(): string
    {
        $themes = $this->config['ai']['story_settings']['themes'];
        return $themes[array_rand($themes)];
    }

    private function createPrompt(string $theme): string
    {
        $promptsFile = __DIR__ . '/../../prompts/' . $theme . '.txt';

        if (!file_exists($promptsFile)) {
            $this->logger->warning("Prompts file not found for theme: $theme, falling back to relationship_drama");
            $promptsFile = __DIR__ . '/../../prompts/relationship_drama.txt';
        }

        if (!file_exists($promptsFile)) {
            throw new \Exception("No prompts file found for fallback theme");
        }

        $prompts = file($promptsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (empty($prompts)) {
            throw new \Exception("Prompts file is empty: $promptsFile");
        }

        $basePrompt = $prompts[array_rand($prompts)];

        return $basePrompt . " Write this as a compelling Reddit story with emotional details and a clear narrative arc. Keep it between " .
            $this->config['ai']['story_settings']['min_length'] . "-" .
            $this->config['ai']['story_settings']['max_length'] . " words.";
    }

    private function generateWithHuggingFace(string $prompt, string $theme): array
    {
        $endpoint = $this->config['ai']['huggingface']['endpoint'] . $this->config['ai']['huggingface']['model'];

        try {
            $response = $this->httpClient->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config['ai']['huggingface']['api_token'],
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'inputs' => $prompt,
                    'parameters' => [
                        'max_length' => $this->config['ai']['story_settings']['max_length'],
                        'temperature' => 0.8,
                        'top_p' => 0.9,
                        'do_sample' => true
                    ]
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result[0]['generated_text'])) {
                $story = $this->cleanStory($result[0]['generated_text'], $prompt);
                $title = $this->generateTitle($story, $theme);

                return [
                    'title' => $title,
                    'content' => $story,
                    'theme' => $theme,
                    'word_count' => str_word_count($story),
                    'generated_at' => date('Y-m-d H:i:s')
                ];
            } else {
                throw new \Exception('Invalid response from Hugging Face API');
            }
        } catch (RequestException $e) {
            $this->logger->error('Hugging Face API request failed: ' . $e->getMessage());

            // Fallback to template-based generation
            return $this->generateFallbackStory($theme);
        }
    }

    private function generateWithOllama(string $prompt, string $theme): array
    {
        $endpoint = $this->config['ai']['ollama']['endpoint'];

        try {
            $this->logger->info('Generating story with Ollama model: ' . $this->config['ai']['ollama']['model']);

            $response = $this->httpClient->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'model' => $this->config['ai']['ollama']['model'],
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => $this->config['ai']['ollama']['temperature'],
                        'num_predict' => $this->config['ai']['ollama']['max_tokens']
                    ]
                ],
                'timeout' => 30
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['response'])) {
                $story = $this->cleanStory($result['response'], $prompt);
                $title = $this->generateTitle($story, $theme);

                return [
                    'title' => $title,
                    'content' => $story,
                    'theme' => $theme,
                    'word_count' => str_word_count($story),
                    'generated_at' => date('Y-m-d H:i:s'),
                    'provider' => 'ollama'
                ];
            } else {
                throw new \Exception('Invalid response from Ollama API: ' . json_encode($result));
            }
        } catch (RequestException $e) {
            $this->logger->error('Ollama API request failed: ' . $e->getMessage());

            // Fallback to template-based generation
            return $this->generateFallbackStory($theme);
        }
    }

    private function cleanStory(string $rawStory, string $prompt): string
    {
        // Remove the prompt from the beginning
        $story = str_replace($prompt, '', $rawStory);

        // Clean up common artifacts
        $story = preg_replace('/\[.*?\]/', '', $story); // Remove [brackets]
        $story = preg_replace('/\s+/', ' ', $story); // Normalize whitespace
        $story = trim($story);

        // Apply content filters
        if ($this->config['content']['content_filters']['profanity_filter']) {
            $story = $this->filterProfanity($story);
        }

        // Ensure story meets length requirements
        $wordCount = str_word_count($story);
        $minLength = $this->config['ai']['story_settings']['min_length'];

        if ($wordCount < $minLength) {
            $story .= $this->expandStory($story, $minLength - $wordCount);
        }

        return $story;
    }

    private function generateTitle(string $story, string $theme): string
    {
        $titles = [
            'relationship_drama' => [
                'AITA for what I did to my partner?',
                'My relationship just ended in the worst way',
                'I caught my partner doing something unforgivable',
                'Reddit, I need advice about my relationship'
            ],
            'workplace_stories' => [
                'My coworker is driving me insane',
                'Office drama that ruined my career',
                'I witnessed something terrible at work',
                'My boss crossed the line today'
            ],
            'family_issues' => [
                'My family is tearing itself apart',
                'I discovered a family secret that changes everything',
                'My parents did something I can\'t forgive',
                'Family dinner from hell'
            ],
            'friendship_conflicts' => [
                'My best friend betrayed me',
                'I lost my closest friend over this',
                'Friendship drama that escalated quickly',
                'My friend showed their true colors'
            ],
            'life_decisions' => [
                'I made a decision that shocked everyone',
                'Should I have chosen differently?',
                'This choice changed my life forever',
                'Reddit, did I make the right call?'
            ],
            'revenge_stories' => [
                'Sweet revenge on someone who wronged me',
                'They messed with the wrong person',
                'Karma came back around',
                'Justice served cold'
            ],
            'college_stories' => [
                'College drama that ruined my semester',
                'My roommate destroyed my academic life',
                'University betrayal that shocked me',
                'Campus incident that changed everything'
            ],
            'neighbor_drama' => [
                'My neighbor is making my life hell',
                'Neighborhood war that went too far',
                'I can\'t stand my neighbor anymore',
                'Property line dispute from hell'
            ],
            'dating_disasters' => [
                'Worst date of my entire life',
                'Online dating nightmare came true',
                'This date ended with police involved',
                'Red flags I should have seen coming'
            ],
            'karen_encounters' => [
                'Karen demanded to ruin my day',
                'Entitled customer lost their mind',
                'I witnessed peak Karen behavior',
                'This Karen got what she deserved'
            ],
            'wedding_disasters' => [
                'My wedding day was completely ruined',
                'Wedding guest caused absolute chaos',
                'Marriage ceremony from hell',
                'Someone sabotaged my special day'
            ],
            'travel_nightmares' => [
                'Vacation from absolute hell',
                'Travel disaster that traumatized me',
                'Trip that ended in complete disaster',
                'Holiday nightmare I\'ll never forget'
            ],
            'customer_service_horror' => [
                'Customer made me question my job',
                'Retail horror story that broke me',
                'Worst customer complaint ever',
                'This customer crossed every line'
            ],
            'roommate_hell' => [
                'My roommate is destroying my sanity',
                'Living situation from absolute hell',
                'Roommate betrayal that shocked me',
                'I can\'t live with this person anymore'
            ],
            'social_media_drama' => [
                'Internet drama ruined my reputation',
                'Social media nightmare came true',
                'I got canceled for something ridiculous',
                'Online harassment that went too far'
            ],
            'prank_wars' => [
                'Prank war that escalated beyond belief',
                'Revenge prank that went perfectly',
                'This prank backfired spectacularly',
                'Epic prank war conclusion'
            ],
            'inheritance_drama' => [
                'Family inheritance tore us apart',
                'Will reading revealed shocking secrets',
                'Money destroyed my family',
                'Inheritance battle that changed everything'
            ],
            'pet_stories' => [
                'My pet saved my life today',
                'Animal drama with crazy neighbors',
                'Pet rescue story that amazed me',
                'My pet exposed the truth'
            ]
        ];

        $themeTitles = $titles[$theme] ?? $titles['relationship_drama'];
        return $themeTitles[array_rand($themeTitles)];
    }

    private function generateFallbackStory(string $theme): array
    {
        $this->logger->info('Using fallback story generation for theme: ' . $theme);

        $templates = [
            'relationship_drama' => "So this happened last week and I'm still processing it. My partner and I have been together for two years, and I thought everything was going well. But then I discovered something on their phone that completely shattered my trust. I don't want to go into all the details, but let's just say they weren't being honest about where they were spending their evenings. When I confronted them about it, they got defensive and turned it around on me, saying I was being paranoid and controlling. I ended up walking out, and now I'm staying at my friend's place trying to figure out what to do next. Part of me wants to work things out, but another part feels like the trust is completely broken. Has anyone been through something similar?",

            'workplace_stories' => "I work at a mid-sized company and thought I had a good relationship with my colleagues. But yesterday something happened that made me question everything. I was in the break room when I overheard two of my coworkers talking about me behind my back. They were saying things that were not only untrue but could potentially damage my reputation at work. The worst part is that one of them is someone I considered a friend. I'm not sure if I should confront them directly, report it to HR, or just let it slide. The whole situation has made the work environment feel toxic, and I'm dreading going back in tomorrow. I've been at this job for three years and generally like the work, but this has really thrown me off.",

            'family_issues' => "Family gatherings have always been a bit tense, but what happened at dinner last Sunday took things to a whole new level. My parents called a family meeting to tell us something they'd been hiding for months. Without going into too much detail, let's just say it involves money, lies, and decisions that affect our entire family's future. My siblings and I are all adults, but we were never consulted about this major life change that impacts all of us. The conversation escalated quickly, with everyone yelling and accusations flying. I ended up leaving early, and now no one is talking to each other. I love my family, but I'm not sure how we come back from this. The holidays are coming up and I honestly don't know if we'll all be in the same room again.",

            'friendship_conflicts' => "I've known my best friend since college, and we've been through everything together. Or so I thought. Last month, I found out they've been spreading personal information I shared with them in confidence. It wasn't malicious, but it was deeply personal stuff about my mental health struggles and family issues. When I found out, I felt completely betrayed. I confronted them about it, and they apologized but also tried to justify it by saying they were 'worried about me' and wanted to get other people's perspectives. I understand they meant well, but that's not their call to make. Trust is such a fundamental part of friendship, and I'm not sure if this is something we can move past. We haven't spoken in two weeks now, and I miss them, but I also feel like I can't trust them with anything personal anymore.",

            'life_decisions' => "I'm at a crossroads in my life and I honestly don't know which direction to go. I've been working the same job for five years, and while it's stable and pays well, I'm completely miserable. I have an opportunity to pursue something I'm passionate about, but it would mean taking a huge financial risk and potentially disappointing my family who have certain expectations for my career. The safe choice is to stay where I am, keep climbing the corporate ladder, and maintain the lifestyle I've built. The risky choice is to follow my dreams, but there's no guarantee it will work out, and I could end up worse off than I am now. I'm in my early thirties, so I feel like this might be my last chance to make a major change. Everyone keeps giving me advice, but ultimately I'm the one who has to live with whatever choice I make.",

            'revenge_stories' => "This is probably petty, but I don't care anymore. My neighbor has been making my life miserable for months. They play loud music at all hours, let their dog bark constantly, and have even blocked my driveway multiple times. I've tried talking to them politely, I've called the landlord, and I've even filed noise complaints, but nothing worked. They just laughed it off and kept doing whatever they wanted. So I decided to get creative with my response. Let's just say that what goes around comes around, and sometimes karma needs a little help. I won't go into specifics, but let's just say they're getting a taste of their own medicine now. Some people might think I went too far, but I tried being nice and reasonable first. Sometimes you have to stand up for yourself when people won't listen to common courtesy.",

            'college_stories' => "College was supposed to be the best years of my life, but my roommate situation turned into an absolute nightmare. I was randomly assigned to live with someone who seemed normal at first, but it quickly became clear they had some serious issues. They would stay up until 3 AM every night playing video games with their friends online, screaming and yelling. When I asked them to keep it down, they got hostile and started doing it even louder out of spite. The breaking point came when I discovered they had been eating my food and using my personal items without asking. When I confronted them about it, they denied everything even though the evidence was right there. I tried going through the housing department, but they basically told me to work it out ourselves. I ended up having to find somewhere else to live mid-semester, which was expensive and stressful during finals. It really taught me that you never know who you're going to end up living with.",

            'neighbor_drama' => "I thought I was lucky to find a house in a quiet neighborhood, but I quickly learned that quiet doesn't always mean peaceful. My next-door neighbor has made it their personal mission to make my life difficult. It started with small things like complaining about where I parked my car, even though it was completely legal and on my own property. Then they started calling the city about every little thing - my grass being too long, my garbage cans being out too early, you name it. The final straw came when they installed security cameras that are clearly pointed at my yard and windows. When I politely asked them about it, they claimed it was for their own security, but it's obvious they're trying to spy on my family. I've talked to other neighbors and apparently this person has a history of causing problems. I'm considering legal action, but I also don't want to escalate things further. It's exhausting having to deal with this kind of behavior when all I want is to live peacefully in my own home.",

            'dating_disasters' => "I thought I had heard every online dating horror story, but nothing prepared me for what happened on this date. We had been chatting for weeks and seemed to have a real connection. They were funny, intelligent, and claimed to share a lot of my interests. We finally decided to meet at a nice restaurant downtown. From the moment they walked in, I knew something was off. Not only did they look nothing like their photos, but their entire personality was completely different from our conversations. They were rude to the waitstaff, made inappropriate comments, and kept checking their phone constantly. Halfway through dinner, they excused themselves to use the bathroom and never came back. I waited for twenty minutes before realizing they had literally ditched me with the entire bill. The worst part was that they had the audacity to text me later asking if I wanted to go out again, as if the whole evening had gone perfectly. I blocked them immediately, but it really made me question whether online dating is worth the hassle.",

            'karen_encounters' => "I work in retail, so I've dealt with my fair share of difficult customers, but this woman took entitlement to a whole new level. She came into the store demanding a refund for an item she clearly bought somewhere else - it wasn't even a brand we carry. When I politely explained this, she started screaming about customer service and demanded to speak to my manager. My manager came over and repeated the same explanation, but she wasn't having it. She accused us of discrimination, threatened to call corporate, and even said she was going to leave bad reviews online. The whole time she was yelling, other customers were staring and some even left because of the commotion. What really got me was when she demanded I give her free merchandise to 'make up for the inconvenience' of not returning an item we never sold. After twenty minutes of this circus, she finally stormed out, but not before knocking over a display and shouting that she was never coming back. Honestly, that last part was the best news I heard all day.",

            'wedding_disasters' => "Planning a wedding is stressful enough without having to deal with family drama, but my mother-in-law decided to make my special day all about her. Despite repeated conversations about appropriate attire, she showed up to my wedding wearing a white dress that was clearly meant to upstage me. When my husband tried to talk to her about it, she played innocent and claimed she 'didn't realize' white was inappropriate for a wedding guest. Throughout the ceremony, she made snide comments loud enough for people to hear, and during the reception, she gave a speech that was supposedly about us but was really just her complaining about not being involved enough in the planning. The final straw was when she tried to change the music during our first dance because she didn't like our song choice. My husband finally had to have a serious conversation with her, but the damage was already done. Looking back at our wedding photos, all I can see is her white dress in the background of every shot. It's been six months and I'm still upset about it.",

            'travel_nightmares' => "I saved up for months for what was supposed to be my dream vacation, but it turned into the trip from hell almost immediately. The airline lost my luggage, so I arrived at my destination with nothing but the clothes on my back. The hotel claimed they had no record of my reservation, even though I had confirmation emails and had paid in advance. After hours of arguing, they finally found me a room, but it was dirty, the air conditioning didn't work, and there were mysterious stains on the bedding. The next day, I got food poisoning from the hotel restaurant and spent two days of my vacation sick in bed. When I tried to get a refund for the meals I couldn't eat and the activities I missed, they refused to help. To make matters worse, it rained for four out of the five days I was there. By the time I flew home, I was more exhausted than when I left. I spent more money on this disaster vacation than I would have on a much nicer trip if I had just planned better.",

            'customer_service_horror' => "Working in customer service has really opened my eyes to how some people behave when they think they have power over someone. This one customer came in absolutely furious about a product that had stopped working after the warranty expired. I explained our policy politely and offered some alternatives, but they weren't interested in solutions - they just wanted to yell at someone. They started personal attacks, calling me incompetent and stupid, and demanded I fix something that was physically impossible to fix. When I called my supervisor over, the customer lied about our entire interaction and claimed I had been rude to them first. Even after security cameras proved they were lying, they continued to escalate and threaten to get me fired. They left bad reviews online mentioning me by name and even found our social media pages to continue their harassment. It really made me realize how little protection retail workers have from abusive customers. Some people seem to think that paying for something gives them the right to treat employees however they want.",

            'roommate_hell' => "I thought living with roommates would be fun and a great way to save money, but I ended up in a situation that made me question my sanity. One of my roommates had absolutely no concept of personal boundaries or cleanliness. They would use my dishes and never wash them, eat my food without asking, and leave their belongings all over the common areas. When I tried to address these issues, they would either ignore me completely or get defensive and turn it around on me. The breaking point came when I discovered they had been having people over every night without telling anyone, and these strangers were using our bathroom and kitchen like they lived there. I tried setting ground rules and even suggested a roommate agreement, but they refused to follow any guidelines. The stress of coming home to a messy, chaotic environment every day was affecting my mental health and my ability to focus on work and school. I eventually had to break my lease early and find somewhere else to live, which cost me a lot of money, but my peace of mind was worth it.",

            'social_media_drama' => "I learned the hard way that anything you post online can be taken out of context and used against you. I made what I thought was a harmless joke on social media, but someone screenshot it and shared it without the original context. Before I knew it, people I had never even met were calling me horrible names and making assumptions about my character based on one post. The harassment spread to all my social media accounts and even spilled over into my professional life when people started tagging my employer. I tried to explain the context and apologize for any misunderstanding, but that just seemed to make things worse. Complete strangers were sending me death threats and trying to get me fired from my job. I had to make all my accounts private and basically disappeared from social media for months until things died down. It's terrifying how quickly a mob mentality can form online and how little regard people have for the real person behind the screen. The whole experience has made me much more careful about what I share online, but it's also made me sad about what social media has become.",

            'prank_wars' => "What started as harmless office pranks between my coworker and me quickly escalated into something that almost got us both in trouble. It began with simple things like putting tape on each other's computer mice or hiding personal items. We were both having fun with it and other people in the office were enjoying the entertainment. But then my coworker decided to take it up a notch and played a prank that involved my work computer and made me look unprofessional in front of a client. I felt like I had to respond with something equally elaborate, so I planned what I thought would be the perfect comeback. Unfortunately, my prank ended up causing some actual damage and disrupted an important meeting. Our boss had to get involved and we both got written up. What had been a fun way to break up the monotony of work turned into a serious HR issue. We both apologized and agreed to call a truce, but the damage was done. Our working relationship has been awkward ever since, and I learned that there's a fine line between harmless fun and workplace disruption.",

            'inheritance_drama' => "My grandmother's death was already difficult enough, but what happened with her will tore our family apart completely. She had always been very close to all her grandchildren and seemed to treat us all equally. When the lawyer read her will, we discovered that she had left the majority of her estate to one of my cousins, with the rest of us getting much smaller amounts. What made it worse was that this cousin had barely visited her in the last few years, while the rest of us had been taking care of her regularly. We later found out that this cousin had been manipulating my grandmother during her final months, convincing her to change her will by telling lies about the rest of the family. There were accusations flying in every direction, with people claiming that others had been trying to influence her or steal from her. Some family members hired lawyers to contest the will, which just created more animosity and legal fees. The whole process took over a year to resolve and by the end, relationships that had existed for decades were completely destroyed. We went from being a close-knit family to people who can barely be in the same room together.",

            'pet_stories' => "My dog has always been protective of our family, but I never realized just how intuitive animals can be until this incident. We had hired a new babysitter who came highly recommended and seemed perfectly normal during the interview. But from the moment she walked into our house, my usually friendly dog started acting strange. He would bark at her constantly, refuse to let her near my kids, and generally seemed agitated whenever she was around. I thought maybe he just needed time to get used to her, but his behavior only got worse. One day I came home early and caught the babysitter yelling at my children and being physically rough with them. When she saw me, she tried to act like nothing had happened, but my kids later told me she had been mean to them every time I left. I realized that my dog had been trying to protect my children all along and I should have trusted his instincts from the beginning. I fired the babysitter immediately and reported her to the agency. Now I always pay attention when my dog reacts strongly to someone new, because animals often sense things about people that we miss."
        ];

        $content = $templates[$theme] ?? $templates['relationship_drama'];
        $title = $this->generateTitle($content, $theme);

        return [
            'title' => $title,
            'content' => $content,
            'theme' => $theme,
            'word_count' => str_word_count($content),
            'generated_at' => date('Y-m-d H:i:s'),
            'is_fallback' => true
        ];
    }

    private function filterProfanity(string $text): string
    {
        // Basic profanity filter - replace with asterisks
        $profanity = ['damn', 'hell', 'crap', 'stupid', 'idiot'];
        foreach ($profanity as $word) {
            $replacement = str_repeat('*', strlen($word));
            $text = str_ireplace($word, $replacement, $text);
        }
        return $text;
    }

    private function expandStory(string $story, int $wordsNeeded): string
    {
        $expansions = [
            " I've been thinking about this situation for days now, and I still can't believe it happened.",
            " The whole experience has really made me question a lot of things about my life.",
            " I keep replaying the events in my mind, wondering if I could have handled things differently.",
            " It's been really hard to focus on anything else since this happened.",
            " I'm hoping that sharing this here will help me process everything and maybe get some outside perspective."
        ];

        $expansion = $expansions[array_rand($expansions)];
        return $story . $expansion;
    }
}
