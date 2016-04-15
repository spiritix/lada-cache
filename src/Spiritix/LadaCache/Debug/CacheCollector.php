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
     * The time when a cache request was started.
     *
     * @var null|float
     */
    private $startTime = null;

    /**
     * Starts measuring a cache request.
     *
     * We can't provide the request information already at this point.
     * The time required for building the hash and the tags (cache overhead) should be included in the measured time.
     */
    public function startMeasuring()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Ends measuring a cache request.
     *
     * @param string    $type       Request type, either a miss or a hit
     * @param string    $hash       The hash of the cache request
     * @param array     $tags       The cache request tags
     * @param string    $sql        The underlying SQL query string
     * @param array     $parameters The SQL query parameters
     */
    public function endMeasuring($type, $hash, $tags, $sql, $parameters)
    {
        $name = '[' .  ucfirst($type) . '] ' . $sql;
        $endTime = microtime(true);

        $params = [
            'hash'       => $hash,
            'tags'       => $tags,
            'parameters' => $parameters,
        ];

        $this->addMeasure($name, $this->startTime, $endTime, $params);
    }

    /**
     * Adds the quantity of the cache requests to the collector data.
     *
     * @return array
     */
    public function collect()
    {
        $data = parent::collect();
        $data['lada-measures'] = count($data['measures']);

        return $data;
    }

    /**
     * Returns the name of the cache collector.
     *
     * @return string
     */
    public function getName()
    {
        return 'lada';
    }

    /**
     * Returns the widget markup of the cache collector.
     *
     * @return array
     */
    public function getWidgets()
    {
        return [
            'lada' => [
                'icon' => 'tasks',
                'widget' => 'PhpDebugBar.Widgets.TimelineWidget',
                'map' => 'lada',
                'default' => '{}',
            ],
            'lada:badge' => [
                'map' => 'lada.lada-measures',
                'default' => 0,
            ],
        ];
    }
}