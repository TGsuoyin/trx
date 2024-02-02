<?php
namespace App\Task;

use App\Service\Bus\ShanduiBonusServices;
use App\Model\Transit\TransitWalletTradeList;
use App\Library\Log;

class HandleShanduiBonus
{
    public function execute()
    { 
        try {
            $data = TransitWalletTradeList::from('transit_wallet_trade_list as twtl')
                ->leftJoin('transit_wallet as tw','twtl.transferto_address','tw.receive_wallet')
                ->where('twtl.process_status',1)
                ->select('twtl.rid','twtl.tx_hash','twtl.transferto_address','twtl.coin_name','twtl.amount','twtl.sendback_address','twtl.sendback_amount','twtl.sendback_coin_name','tw.rid as transit_wallet_id','tw.send_wallet','tw.send_wallet_privatekey')
                ->limit(100)
                ->get();

            if($data->count() > 0){
                // $this->log('shanduibonus','-----------开始执行:闪兑币种，总数:'.$data->count().'期--------------');
                $shanduiBonus_services = new ShanduiBonusServices();

                // 转账
                $res = $shanduiBonus_services->handleGrant($data);

            }else{
                // $this->log('shanduibonus','----------没有数据需要闪兑----------');
            }
        }catch (\Exception $e){
            // $this->log('shanduibonus','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
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