<?php

namespace Tests\Unit;

use App\Services\SubtitleTextSanitizerService;
use Tests\TestCase;

class SubtitleTextSanitizerServiceTest extends TestCase
{
    private SubtitleTextSanitizerService $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new SubtitleTextSanitizerService;
    }

    public function test_it_compacts_only_long_repeated_vocalizations(): void
    {
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(str_repeat('Ah... ', 20)));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize('Ahhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhhh...'));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(str_repeat('U', 80)));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(str_repeat('E', 80)));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(str_repeat('ㅋ', 30)));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(str_repeat('A', 80).'�'));
        $this->assertSame('Ah, it feels good.', $this->sanitizer->sanitize('Ah, it feels good.'));
        $this->assertSame('Oh, I see.', $this->sanitizer->sanitize('Oh, I see.'));
        $this->assertSame(
            "I was not afraid of you.\n[vocalization]",
            $this->sanitizer->sanitize(
                "I was not afraid of you.\n".str_repeat('U', 80)."\n".str_repeat('E', 80)
            )
        );
    }

    public function test_it_compacts_only_long_repeated_breathing(): void
    {
        $this->assertSame('[breathing]', $this->sanitizer->sanitize(str_repeat('Exhale, ', 20)));
        $this->assertSame('[breathing]', $this->sanitizer->sanitize(str_repeat('Exhaling, ', 10)));
        $this->assertSame('[breathing]', $this->sanitizer->sanitize('Inhale, '.str_repeat('exhale, ', 10)));
        $this->assertSame('I need to exhale slowly.', $this->sanitizer->sanitize('I need to exhale slowly.'));
    }

    public function test_it_removes_standalone_boilerplate_only_from_suspiciously_long_cues(): void
    {
        $this->assertSame('[inaudible]', $this->sanitizer->sanitize('Thank you for watching.', 30));
        $this->assertSame('[inaudible]', $this->sanitizer->sanitize('by H.', 29.95));
        $this->assertSame('[inaudible]', $this->sanitizer->sanitize('by H.', 0.5));
        $this->assertSame('Thank you for watching.', $this->sanitizer->sanitize('Thank you for watching.', 3));
        $this->assertSame(
            'Thank you for watching the presentation.',
            $this->sanitizer->sanitize('Thank you for watching the presentation.', 30)
        );
        $this->assertSame('He said, "Ah, I understand."', $this->sanitizer->sanitize('He said, "Ah, I understand."'));
    }

    public function test_it_removes_known_editorial_artifacts_without_touching_dialogue(): void
    {
        $this->assertSame('Whoa!', $this->sanitizer->sanitize('Whoa! (continued)'));
        $this->assertSame('[vocalization]', $this->sanitizer->sanitize(
            'Ah.. Ah.. Ah.. Ah.. Ah.. Ah.. Ah.. Ah.. (Note: repeated breathing sounds)'
        ));
        $this->assertSame(
            "Let's sleep now!",
            $this->sanitizer->sanitize(
                "Let's sleep now! Show more of the scene and consider pacing the dialogue more naturally if needed for the viewers to follow. (This was emoji-heavy and probably not literal speech.)"
            )
        );
        $this->assertSame(
            'He said (continued fractions) are difficult.',
            $this->sanitizer->sanitize('He said (continued fractions) are difficult.')
        );
    }

    public function test_local_result_files_keep_every_timestamp_unchanged(): void
    {
        $paths = [
            base_path('results/756201_480p_hiroto-hunk-channel_English_2.srt'),
            base_path('results/756201_480p_hiroto-hunk-channel_English_curated.srt'),
        ];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                $this->markTestSkipped('Local comparison SRT files are not available.');
            }

            $before = file_get_contents($path);
            $after = preg_replace_callback(
                '/(?m)^(?!\d+$)(?!\d{2}:\d{2}:\d{2},\d{3} --> ).+$/u',
                fn (array $match): string => $this->sanitizer->sanitize($match[0]),
                $before
            );

            preg_match_all('/(?m)^\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}$/', $before, $beforeTimes);
            preg_match_all('/(?m)^\d{2}:\d{2}:\d{2},\d{3} --> \d{2}:\d{2}:\d{2},\d{3}$/', (string) $after, $afterTimes);

            $this->assertSame($beforeTimes[0], $afterTimes[0], basename($path));

            if (str_ends_with($path, '_English_curated.srt')) {
                $this->assertStringContainsString('Please tell me your height, weight, and age.', (string) $after);
                $this->assertStringContainsString('[vocalization]', (string) $after);
                $this->assertStringNotContainsString('(continued)', (string) $after);
                $this->assertStringNotContainsString('(Note:', (string) $after);
                $this->assertStringNotContainsString('Show more of the scene', (string) $after);
            } else {
                $this->assertStringContainsString('First of all, please tell me your height, weight, and age.', (string) $after);
                $this->assertStringContainsString('It feels so good.', (string) $after);
            }
        }
    }
}
