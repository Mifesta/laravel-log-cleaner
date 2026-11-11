<?php

namespace Mifesta\LogCleaner\Facades;

use Illuminate\Support\Facades\Facade;
use Mifesta\LogCleaner\Cleaner as CleanerInstance;

/**
 * @method static CleanerInstance dir(string|null $dirname)
 * @method static CleanerInstance rotate(int|string|null $days)
 * @method static bool clear()
 */
class Cleaner extends Facade
{
    /**
     * Instance used when the container binding is not registered.
     *
     * @var CleanerInstance|null
     */
    private static $fallback;

    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'mifesta.log-cleaner';
    }

    /**
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        $application = static::getFacadeApplication();

        if ($application && $application->bound(static::getFacadeAccessor())) {
            return parent::__callStatic($method, $parameters);
        }

        if (!self::$fallback) {
            self::$fallback = new CleanerInstance();
        }

        return self::$fallback->$method(...$parameters);
    }
}
