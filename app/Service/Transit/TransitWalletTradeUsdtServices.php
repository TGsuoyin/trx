<?php

namespace App\Service\Transit;

use App\Model\Transit\TransitWalletTradeList;
use App\Library\Log;
use Hyperf\DbConnection\Db;

class TransitWalletTradeUsdtServices
{
    private $list_success_count = 0;      //交易列表拉取成功数
    private $list_error_count = 0;        //交易列表拉取失败数

    /**
     * 获取闪兑钱包数据
     * @param $in_list [钱包数据]
     * @param $start_timestamp [开始时间 13位时间戳]
     * @param $nexturl [下一页]
    */
    public function getList($in_list,$start_timestamp,$nexturl='0'){
        if($nexturl != '0'){
            $url = $nexturl;
        }else{
            $url = 'https://api.trongrid.io/v1/accounts/'.$in_list['receive_wallet'].'/transactions/trc20?limit=50&only_to=true&min_timestamp='.$start_timestamp.'&contract_address=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
        }
        
        $api_key = config('apikey.gridapikey');
        $apikeyrand = $api_key[array_rand($api_key)];
        
        $heders = [
            "TRON-PRO-API-KEY:".$apikeyrand
        ];
        
        $data = Get_Pay($url,null,$heders);

        if(!empty($data) && $data){
            $data = json_decode($data,true);
            $data = $this->handleWalletData($data,$in_list);
        }

        return ['success_count'=>$this->list_success_count,'error_count'=>$this->list_error_count];
    }

    /**
     * 处理收款数据
     * @param $data [收款数据]
     * @param $in_list [钱包数据]
    */
    public function handleWalletData($data,$in_list){
        if(isset($data['data'])){
            $list = $data['data'];
            
            if($list){
                // 校验hash是否存在
                $hash_list = array_column($list,'transaction_id');
                $transaction_hash_list = TransitWalletTradeList::whereIn('tx_hash',$hash_list)->pluck('tx_hash')->toArray();
        
                $success_hash_list = [];    //成功hash值数组
                $error_hash_list = [];    //失败hash值数组
                $time = nowDate();
        
                foreach ($list as $k => $v) {
                    if(!in_array($v['transaction_id'],$transaction_hash_list) && $v['type'] == 'Transfer'){
                        Db::beginTransaction();
                        try {
                            $res = $this->AddWalletData($v,$time,$in_list);
                            if($res['code'] == 200){
                                $success_hash_list[] = $v['transaction_id'];
                            }else{
                                $error_hash_list[] = $v['transaction_id'].'--------'.'不是收款记录或金额为0，交易失败';
                            }
                            Db::commit();
                        }catch (\Exception $e){
                            Db::rollBack();
                            $error_hash_list[] = $v['transaction_id'].'--------'.$e->getMessage();
                        }
                    }else{
                        $error_hash_list[] = $v['transaction_id'].'--------'.'已存在';
                    }
                }
        
                $success_hash_list_count = count($success_hash_list);
                $error_hash_list_count = count($error_hash_list);
        
                // $log_list = [
                //     'receive_wallet' => $in_list['receive_wallet'],
                //     'success_hash_list' => $success_hash_list,
                //     'error_hash_list' => $error_hash_list,
                // ];
        
                // $this->log('getwalletdetails','钱包地址：'.$in_list['receive_wallet'].'，成功hash值'.$success_hash_list_count.'条，失败hash值'.$error_hash_list_count.'条');
                // $this->log('getwalletdetails',json_encode($log_list,JSON_UNESCAPED_UNICODE));
        
                $this->list_success_count = $this->list_success_count + $success_hash_list_count;
                $this->list_error_count = $this->list_error_count + $error_hash_list_count;
        
                // 如果有下一页，再次去获取
                if(isset($data['meta']['links']['next'])){
                    $this->getList($in_list,0,$data['meta']['links']['next']);
                }
            }
        }
    }

    /**
     * 整合添加收款数据
     * @param $data [收款数据]
     * @param $time [当前时间]
     * @param $in_list [钱包数据]
    */
    public function AddWalletData($data,$time,$in_list){
        $txid_list = [];
        
        $txid_list['tx_hash'] = $data['transaction_id'];       //交易hash 
        $txid_list['transferfrom_address'] = $data['from'];       //来源钱包地址  
        $txid_list['timestamp'] = $data['block_timestamp'];        //区块号时间戳  
        
        $txid_list['transferto_address'] = $in_list['receive_wallet'];        //收款钱包地址  
        $txid_list['sendback_address'] = $data['from'];      //来源钱包地址
        
        $txid_list['coin_name'] = 'usdt';
        $txid_list['amount'] = calculationExcept($data['value'],6);     //交易数额 
        $txid_list['get_time'] = $time;       //拉取时间 

        $txid_list['process_status'] = 1;      //待兑换
        $txid_list['process_comments'] = '待兑换';      //处理备注  
        $txid_list['sendback_coin_name'] = 'trx';        //出款币名
        $txid_list['process_time'] = $time;        //处理时间

        TransitWalletTradeList::insert($txid_list);       //添加收款钱包交易列表

        return ['code' => 200];
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
