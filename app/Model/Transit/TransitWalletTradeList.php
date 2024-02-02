<?php

declare (strict_types=1);
namespace App\Model\Transit;
use Hyperf\DbConnection\Model\Model;

/**
 */
class TransitWalletTradeList extends Model
{


    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transit_wallet_trade_list';

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