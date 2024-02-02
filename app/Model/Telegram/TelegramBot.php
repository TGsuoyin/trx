<?php

declare (strict_types=1);
namespace App\Model\Telegram;
use Hyperf\DbConnection\Model\Model;

/**
 */
class TelegramBot extends Model
{


    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'telegram_bot';

    public $primaryKey  = 'rid';

    public $keyType  = 'int';

    public $timestamps  = false;

    public $incrementing  = true;



    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [];

}