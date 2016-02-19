<?php
/**
 * This file is part of the spiritix/lada-cache package.
 *
 * @copyright Copyright (c) Matthias Isler <mi@matthias-isler.ch>
 * @license   MIT
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spiritix\LadaCache\Debug;

use DebugBar\DataCollector\TimeDataCollector;

/**
 * Custom collector for the Laravel Debugbar package.
 *
 * @see https://github.com/barryvdh/laravel-debugbar
 *
 * @package Spiritix\LadaCache\Debug
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class CacheCollector extends TimeDataCollector
{
    /**
     * The time when an action was started.
     *
     * @var null|float
     */
    private $startTime = null;

    /**
     * Starts time measuring.
     */
    public function startMeasure()
    {
        $this->startTime = microtime(true);
    }

    public function registerHit($hash, $sql, $tags, $parameters)
    {
        $this->registerEvent('Hit', '')
    }

    public function registerHit($hash, $sql, $tags, $parameters)
    {
        $name = '[Miss] ' . $sql;
        $time = microtime(true);
        $params = [
            'hash' => $hash,
            'tags' => $tags,
            'parameters' => $parameters,
        ];

        $this->addMeasure($name, $this->measure, $time, $params);
    }

    public function collect()
    {
        $data = parent::collect();
        $data['lada_measures'] = count($data['measures']);

        return $data;
    }

    public function getName()
    {
        return 'lada';
    }

    public function getWidgets()
    {
        return array(
            "lada" => array(
                "icon" => "tasks",
                "widget" => "PhpDebugBar.Widgets.TimelineWidget",
                "map" => "lada",
                "default" => "{}",
            ),
            'lada:badge' => array(
                'map' => 'lada.lada_measures',
                'default' => 0,
            ),
        );
    }

    private function registerEvent($event)
    {
        $name = '[Miss] ' . $sql;
        $time = microtime(true);
        $params = [
            'hash' => $hash,
            'tags' => $tags,
            'parameters' => $parameters,
        ];

        $this->addMeasure($name, $this->measure, $time, $params);
    }
}