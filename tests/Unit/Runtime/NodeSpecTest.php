<?php

declare(strict_types=1);

namespace Cpdeploy\Tests\Unit\Runtime;

use Cpdeploy\Runtime\NodeResolver;
use Cpdeploy\Runtime\NodeSpec;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Support\Errors\ErrorCode;
use PHPUnit\Framework\TestCase;

final class NodeSpecTest extends TestCase
{
    /**
     * @covers-req NODE-02
     */
    public function testEverySpecForm(): void
    {
        $cases = [
            ['20.11.1', '20.11.1', true],
            ['v20.11.1', '20.11.1', true],
            ['20.11.1', '20.11.2', false],
            ['20', '20.19.5', true],
            ['v20', '20.0.0', true],
            ['20', '21.0.0', false],
            ['20.11', '20.11.9', true],
            ['20.11', '20.12.0', false],
            ['>=18.17 <21', '20.19.5', true],
            ['>=18.17 <21', '18.16.0', false],
            ['>=v18.17 <v21', '18.20.0', true],
            ['^20', '20.1.0', true],
            ['^20', '22.0.0', false],
            ['18.x || 20.x', '18.20.4', true],
            ['18.x || 20.x', '22.1.0', false],
            ['~20.11', '20.11.5', true],
        ];
        foreach ($cases as [$spec, $version, $expected]) {
            self::assertSame($expected, NodeSpec::parse($spec, '.nvmrc')->matches($version), "{$spec} vs {$version}");
        }

        self::assertSame(NodeSpec::LATEST, NodeSpec::parse('node', '.nvmrc')->kind);
        self::assertSame(NodeSpec::LATEST, NodeSpec::parse('latest', '.nvmrc')->kind);
        self::assertSame(NodeSpec::LATEST, NodeSpec::parse('current', '.nvmrc')->kind);
        $lts = NodeSpec::parse('lts/*', '.nvmrc');
        self::assertSame(NodeSpec::LTS, $lts->kind);
        self::assertNull($lts->ltsName);
        self::assertSame('iron', NodeSpec::parse('lts/Iron', '.nvmrc')->ltsName);
    }

    /**
     * @covers-req NODE-02
     */
    public function testUnparseableSpecNamesItsSource(): void
    {
        try {
            NodeSpec::parse('banana', 'package.json (engines.node)');
            self::fail('No exception');
        } catch (CpdeployException $e) {
            self::assertSame(ErrorCode::NODE_SPEC, $e->errorCode);
            self::assertStringContainsString('"banana" in package.json (engines.node)', $e->getMessage());
        }
    }

    /**
     * @covers-req NODE-01
     */
    public function testSpecSourcesInOrder(): void
    {
        $files = [
            '.nvmrc' => "# pinned\nv22\n",
            '.node-version' => '20',
            'package.json' => '{"volta":{"node":"18.20.4"},"engines":{"node":">=16"}}',
        ];
        $read = static function (string $file) use (&$files): ?string {
            return $files[$file] ?? null;
        };

        self::assertSame('site.yml (node.version)', NodeResolver::specFromProject('21', $read)?->source);
        self::assertSame('.nvmrc', NodeResolver::specFromProject('auto', $read)?->source);
        self::assertTrue(NodeResolver::specFromProject(null, $read)?->matches('22.1.0'));
        unset($files['.nvmrc']);
        self::assertSame('.node-version', self::source(NodeResolver::specFromProject(null, $read)));
        unset($files['.node-version']);
        self::assertSame('package.json (volta.node)', self::source(NodeResolver::specFromProject(null, $read)));
        $files['package.json'] = '{"engines":{"node":">=16"}}';
        self::assertSame('package.json (engines.node)', self::source(NodeResolver::specFromProject(null, $read)));
        $files['package.json'] = '{"name":"x"}';
        self::assertNull(NodeResolver::specFromProject(null, $read));
    }

    private static function source(?\Cpdeploy\Runtime\NodeSpec $spec): ?string
    {
        return $spec?->source;
    }

    public function testParseIndexSortsAndNormalises(): void
    {
        $index = NodeResolver::parseIndex('[{"version":"v20.1.0","lts":false,"files":["linux-x64"]},{"version":"v22.20.0","lts":"Jod","files":["linux-x64","linux-arm64"]},{"version":"junk"}]');

        self::assertSame('22.20.0', $index[0]['version']);
        self::assertSame('Jod', $index[0]['lts']);
        self::assertFalse($index[1]['lts']);
        self::assertCount(2, $index);
    }
}
