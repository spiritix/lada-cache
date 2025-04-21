<?php

namespace Spiritix\LadaCache;

class LadaCacheToggle
{
    protected static $temporarilyDisabled = false;

    public static function disable()
    {
        self::$temporarilyDisabled = true;
    }

    public static function enable()
    {
        self::$temporarilyDisabled = false;
    }

    public static function isTemporarilyDisabled()
    {
        return self::$temporarilyDisabled;
    }
}
