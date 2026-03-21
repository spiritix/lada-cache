<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Debug\CacheCollector;

class CacheCollectorTest extends TestCase
{
    public function testStartAndEndMeasuringAddsMeasureAndCollectCountsIt(): void
    {
        $collector = new CacheCollector();

        $collector->startMeasuring();
        $collector->endMeasuring(
            'miss',
            'hash-abc',
            ['tag1', 'tag2'],
            'select * from cars where id = ?',
            [1]
        );

        $data = $collector->collect();

        $this->assertIsArray($data);
        $this->assertArrayHasKey('measures', $data);
        $this->assertArrayHasKey('lada-measures', $data);
        $this->assertGreaterThanOrEqual(1, $data['lada-measures']);
        $this->assertNotEmpty($data['measures']);

        $first = $data['measures'][0] ?? null;
        $this->assertIsArray($first);
        $this->assertArrayHasKey('label', $first);
        $this->assertStringContainsString('[Miss]', $first['label']);
        $this->assertArrayHasKey('params', $first);

        $params = $first['params'];
        $this->assertIsArray($params);
        $this->assertArrayHasKey('hash', $params);
        $this->assertArrayHasKey('tags', $params);
        $this->assertArrayHasKey('parameters', $params);

        $this->assertIsString($params['hash']);
        $this->assertStringContainsString('hash-abc', $params['hash']);

        $this->assertIsString($params['tags']);
        $this->assertStringContainsString('tag1', $params['tags']);
        $this->assertStringContainsString('tag2', $params['tags']);

        $this->assertIsString($params['parameters']);
        $this->assertStringContainsString('1', $params['parameters']);
    }

    public function testGetNameAndWidgets(): void
    {
        $collector = new CacheCollector();

        $this->assertSame('lada', $collector->getName());

        $widgets = $collector->getWidgets();
        $this->assertIsArray($widgets);
        $this->assertArrayHasKey('Lada Cache', $widgets);
        $this->assertArrayHasKey('Lada Cache:badge', $widgets);

        $timeline = $widgets['Lada Cache'];
        $this->assertSame('PhpDebugBar.Widgets.TimelineWidget', $timeline['widget']);
        $this->assertSame('lada', $timeline['map']);

        $badge = $widgets['Lada Cache:badge'];
        $this->assertSame('lada.lada-measures', $badge['map']);
    }
}