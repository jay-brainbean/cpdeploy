<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Scenario;

use Cpdeploy\Tests\Support\DeployScenario;
use Cpdeploy\Tests\Support\JsonShape;

/**
 * S-37: every --json output matches its schema in resources/schemas/ (§10.5),
 * and stdout holds only the JSON document (NI-05).
 */
final class JsonOutputTest extends DeployScenario
{
    /**
     * @covers-req NI-05
     */
    public function testJsonOutputsMatchTheirSchemas(): void
    {
        $this->assertExit(0, $this->deploy());
        $this->env['CPD_FAKE_NPM'] = 'fail';
        $this->change(['README.md' => "changed\n"], 'Second');
        $this->assertExit(4, $this->deploy(['--yes', '--force']));
        unset($this->env['CPD_FAKE_NPM']);

        $cases = [
            'status' => [['status', '--json'], ['status', self::SITE, '--json']],
            'releases' => [['releases', self::SITE, '--json']],
            'logs' => [['logs', self::SITE, '--json'], ['logs', self::SITE, '--failed', '--json']],
            'check' => [['check', '--json'], ['check', self::SITE, '--json']],
        ];
        foreach ($cases as $schemaName => $runs) {
            $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/' . $schemaName . '.schema.json'), true);
            self::assertIsArray($schema);
            foreach ($runs as $args) {
                $r = $this->runCli($args);
                $label = implode(' ', $args);
                self::assertContains($r['exit'], [0, 3], $label . "\n" . $r['stderr']);
                $out = trim($r['stdout']);
                self::assertStringStartsWith('{', $out, "{$label}: stdout holds only JSON");
                self::assertStringEndsWith('}', $out, "{$label}: stdout holds only JSON");
                $data = json_decode($out, true);
                self::assertIsArray($data, "{$label}: " . json_last_error_msg());
                self::assertSame([], JsonShape::problems($data, $schema), $label);
            }
        }

        // The documents carry what the scenario did.
        $logs = json_decode($this->runCli(['logs', self::SITE, '--failed', '--json'])['stdout'], true);
        self::assertSame(['failed'], array_values(array_unique(array_column($logs['entries'], 'result'))));
        $status = json_decode($this->runCli(['status', '--json'])['stdout'], true);
        self::assertSame('failed', $status['sites'][0]['last']['result']);
        self::assertNotNull($status['sites'][0]['live']);
    }

    /**
     * The checker itself notices a wrong document.
     */
    public function testTheShapeCheckerFindsProblems(): void
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/releases.schema.json'), true);
        self::assertIsArray($schema);
        $problems = JsonShape::problems(['schema' => 1, 'site' => 'shop', 'live' => 3, 'releases' => [['id' => 'x', 'status' => 'odd']]], $schema);
        self::assertContains('$.live: expected string|null, got int', $problems);
        self::assertContains('$.releases[0]: missing protected', $problems);
        self::assertContains('$.releases[0].status: "odd" is not one of ["building","ready","live","failed","unknown"]', $problems);
    }
}
