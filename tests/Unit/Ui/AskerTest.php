<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Ui;

use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use Cpdeploy\Ui\NonInteractiveAsker;
use Cpdeploy\Ui\ScriptedAsker;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AskerTest extends TestCase
{
    /**
     * @covers-req NI-02
     * @covers-req NI-03
     */
    public function testNonInteractiveWithoutYesNeedsAnswers(): void
    {
        $asker = new NonInteractiveAsker(false);

        foreach ([
            static fn () => $asker->confirm('Deploy?'),
            static fn () => $asker->select('Composer install?', ['yes' => 'Yes', 'no' => 'Skip'], 'no'),
            static fn () => $asker->text('Site name', 'shop'),
            static fn () => $asker->password('Token'),
        ] as $question) {
            try {
                $question();
                self::fail('Answered without --yes');
            } catch (CpdeployException $e) {
                self::assertSame(ErrorCode::NEEDS_ANSWER, $e->errorCode);
                self::assertSame(2, $e->exitCode());
            }
        }
        self::assertFalse($asker->interactive());
    }

    /**
     * @covers-req NI-02
     */
    public function testNonInteractiveWithYesTakesDefaults(): void
    {
        $asker = new NonInteractiveAsker(true);

        self::assertTrue($asker->confirm('Deploy?'));
        self::assertFalse($asker->confirm('Seed?', false));
        self::assertSame('no', $asker->select('Composer install?', ['yes' => 'Yes', 'no' => 'Skip'], 'no'));
        self::assertSame('shop', $asker->text('Site name', 'shop'));

        $this->expectException(CpdeployException::class);
        $asker->select('Pick a site', ['a' => 'A']);
    }

    public function testScriptedAskerAnswersInOrderAndChecksLabels(): void
    {
        $asker = new ScriptedAsker(['yes', ['migrations', true], 'shop']);

        self::assertSame('yes', $asker->select('Composer install?', ['yes' => 'Yes', 'no' => 'Skip']));
        self::assertTrue($asker->confirm('Run migrations?'));
        self::assertSame('shop', $asker->text('Site name'));
        self::assertCount(3, $asker->asked);
        self::assertSame(0, $asker->remaining());

        $this->expectException(LogicException::class);
        $asker->confirm('Unexpected?');
    }

    public function testScriptedAskerRejectsWrongLabelAndInvalidOption(): void
    {
        $asker = new ScriptedAsker([['Seed', true], 'maybe']);
        try {
            $asker->confirm('Run migrations?');
            self::fail('Label mismatch not detected');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $asker->select('Composer?', ['yes' => 'Yes', 'no' => 'No']);
    }
}
