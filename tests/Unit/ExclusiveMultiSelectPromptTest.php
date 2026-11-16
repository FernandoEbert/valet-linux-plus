<?php

namespace Valet\Tests\Unit;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Valet\Prompts\ExclusiveMultiSelectPrompt;
use Valet\Tests\TestCase;

class ExclusiveMultiSelectPromptTest extends TestCase
{
    private const OPTIONS = [
        'mailpit' => 'Mailpit',
        'mysql'   => 'MySQL',
        'mariadb' => 'MariaDB',
        'redis'   => 'Redis',
    ];

    private const EXCLUSIVE = [['mysql', 'mariadb']];

    /**
     * @test
     */
    public function itWillUncheckTheOtherOptionOfTheGroup(): void
    {
        // Cursor starts on "mailpit": move to "mariadb" and check it.
        Prompt::fake([Key::DOWN, Key::DOWN, Key::SPACE, Key::ENTER]);

        $this->assertSame(['mailpit', 'mariadb'], $this->prompt(['mailpit', 'mysql']));
    }

    /**
     * @test
     */
    public function itWillSwitchBackAndForthBetweenExclusiveOptions(): void
    {
        Prompt::fake([Key::DOWN, Key::DOWN, Key::SPACE, Key::UP, Key::SPACE, Key::ENTER]);

        $this->assertSame(['mysql'], $this->prompt([]));
    }

    /**
     * @test
     */
    public function itWillLetBothExclusiveOptionsBeUnchecked(): void
    {
        Prompt::fake([Key::DOWN, Key::SPACE, Key::ENTER]);

        $this->assertSame(['redis'], $this->prompt(['mysql', 'redis']));
    }

    /**
     * @test
     */
    public function itWillKeepOneOptionOfTheGroupOnToggleAll(): void
    {
        Prompt::fake([Key::CTRL_A, Key::ENTER]);

        $this->assertSame(['mailpit', 'mysql', 'redis'], $this->prompt([]));
    }

    /**
     * @test
     */
    public function itWillSanitizeConflictingDefaults(): void
    {
        Prompt::fake([Key::ENTER]);

        $this->assertSame(['mariadb', 'redis'], $this->prompt(['mariadb', 'mysql', 'redis']));
    }

    /**
     * @param array<int, string> $default
     * @return array<int, int|string>
     */
    private function prompt(array $default): array
    {
        /** @var array<int, int|string> $selected */
        $selected = (new ExclusiveMultiSelectPrompt(
            label: 'Services',
            options: self::OPTIONS,
            default: $default,
            exclusive: self::EXCLUSIVE,
        ))->prompt();

        return array_values($selected);
    }
}
