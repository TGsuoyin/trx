<?php


namespace App\Library;

use Hyperf\Utils\ApplicationContext;

/**
 * @method static mixed get($key, $default = null)
 * @method static bool set($key, $value, $ttl = null)
 */
class Redis
{
    public static function getInstance()
    {
        $container = ApplicationContext::getContainer();

        return $container->get(\Hyperf\Redis\Redis::class);
    }

    public static function __callStatic($name, $arguments)
    {
        return self::getInstance()->$name(...$arguments);
    }
}