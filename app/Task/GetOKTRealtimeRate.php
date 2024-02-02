<?php
namespace App\Task;

use App\Model\Transit\TransitWalletCoin;
use App\Library\Log;

class GetOKTRealtimeRate
{
    public function execute()
    { 
        try {
            $url = 'https://www.okx.com/api/v5/market/index-tickers?instId=TRX-USDT&quoteCcy=USDT';
        
            $data = Get_Pay($url);
            $usdttrx = 6; #默认汇率
            $is_update = 'N'; #是否更新：N不更新,Y更新
    
            if(!empty($data)){
                $data = json_decode($data,true);
                if(isset($data['data']) && count($data['data']) > 0){
                    $trxusdt = $data['data'][0]['idxPx'];
                    $usdttrx = number_format(1 / $trxusdt, 2);
                    $is_update = 'Y';
                }
            }else{
                $this->log('getoktrealtimerate','获取okx实时汇率失败');
            }
            
            //查询汇率成功的时候才更新
            if($is_update == 'Y'){
                #更新实时汇率
                $walletdata = TransitWalletCoin::where('in_coin_name','usdt')->where('out_coin_name','trx')->whereIn('is_realtime_rate',[1,3])->get();
    
                if($walletdata->count() > 0){
                    foreach ($walletdata as $k => $v) {
                        if($v['is_realtime_rate'] == 1){
                            $realrate = $usdttrx - $v['profit_rate']; //直接扣
                        }else{
                            $realrate = bcmul($usdttrx, (1 - $v['profit_rate']),2); //按百分比扣
                        }
                        
                        $updatedata['exchange_rate'] = $realrate > 0 ? $realrate:6; #如果汇率计算后小于0,则取默认汇率
                        TransitWalletCoin::where('rid',$v['rid'])->update($updatedata);
                    }
                }
            }
            
        }catch (\Exception $e){
            $this->log('getoktrealtimerate','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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