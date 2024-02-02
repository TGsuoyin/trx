<?php


namespace App\Library;

use Hyperf\Logger\LoggerFactory;
use Hyperf\Utils\ApplicationContext;

class Log
{
    public static function get(string $name = 'app',string $group = 'default')
    {
        return ApplicationContext::getContainer()->get(LoggerFactory::class)->get($name,$group);
    }
}