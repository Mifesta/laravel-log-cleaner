<?php

namespace Mifesta\LogCleaner\Facades;

use Mifesta\LogCleaner\Cleaner as _Cleaner;

/**
 * Class Cleaner
 * @method static _Cleaner dir(string $dirname)
 * @method static _Cleaner rotate(string $dirname)
 * @method static boolean clear(string $dirname)
 *
 * @package Mifesta\LogCleaner\Facades
 */
class Cleaner
{
    /**
     * Handle dynamic static method calls into the method.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return (new _Cleaner)->$method(...$parameters);
    }
}
