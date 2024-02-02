<?php

namespace App\Service\Fms;

use App\Model\Telegram\TelegramBot;
use App\Library\Log;
use Hyperf\DbConnection\Db;

class FmsWalletServices
{
    /**
     * 获取机器人钱包列表
     * @param $type [0.读取 1.更新]
    */
    public function getList($type=0){
        $res = TelegramBot::select('rid','recharge_wallet_addr','get_tx_time')->whereNotNull('recharge_wallet_addr')->where('recharge_wallet_addr','like','T%')->whereRaw('length(recharge_wallet_addr) = 34')->orderBy('rid')->get();

        $data = array();
        if($res->count() > 0){
            $res = $res->toArray();
            foreach ($res as $key => $v) {
                $data[$v['rid']] = $v;
            }
        }

        return $res;
    }
    
    /**
     * 记入日志
     * @param $log_title [日志路径]
     * @param $message [内容，不支持数组]
     * @param $remarks [备注]
    */
    protected function log($log_title,$message,$remarks='info'){
        Log::get($remarks,$log_title)->info($message);
    }
}
