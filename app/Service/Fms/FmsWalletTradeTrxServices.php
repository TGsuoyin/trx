<?php

namespace App\Service\Fms;

use App\Model\Telegram\FmsWalletTradeList;
use App\Library\Log;
use Hyperf\DbConnection\Db;

class FmsWalletTradeTrxServices
{
    private $list_success_count = 0;      //交易列表拉取成功数
    private $list_error_count = 0;        //交易列表拉取失败数
    private $limit = 50;        //每次获取多少条

    /**
     * 获取能量钱包数据
     * @param $in_list [钱包数据]
     * @param $start_timestamp [开始时间 13位时间戳]
     * @param $end_timestamp [结束时间 13位时间戳]
     * @param $page [页数]
    */
    public function getList($in_list,$start_timestamp,$end_timestamp,$page=0){
        
        $limit = $this->limit;
        $start = $page * $limit;
        
        $url = 'https://apilist.tronscanapi.com/api/new/transfer?sort=-timestamp&count=true&limit='.$limit.'&start='.$start.'&address='.$in_list['recharge_wallet_addr'].'&toAddress='.$in_list['recharge_wallet_addr'].'&tokens=_&start_timestamp='.$start_timestamp.'&end_timestamp='.$end_timestamp;
        
        $api_key = config('apikey.tronapikey');
        
        $apikeyrand = $api_key[array_rand($api_key)];

        $heders = [
            "TRON-PRO-API-KEY:".$apikeyrand
        ];
        
        $data = Get_Pay($url,null,$heders);

        if(!empty($data) && $data){
            $data = json_decode($data,true);
            if(!empty($data['total']) && isset($data['data']) && count($data['data']) > 0){
                $data = $this->handleWalletData($data,$in_list,$start_timestamp,$end_timestamp,$page,$limit);
            }
        }

        return ['success_count'=>$this->list_success_count,'error_count'=>$this->list_error_count];
    }

    /**
     * 处理收款数据
     * @param $data [收款数据]
     * @param $in_list [钱包数据]
     * @param $start_timestamp [开始时间 13位时间戳]
     * @param $end_timestamp [结束时间 13位时间戳]
     * @param $page [页数]
     * @param $limit [每页获取数据]
    */
    public function handleWalletData($data,$in_list,$start_timestamp,$end_timestamp,$page,$limit){
        if(isset($data['data'])){
            $list = $data['data'];  
            $total = $data['total'];        //总数
    
            if($list){
                // 校验hash是否存在
                $hash_list = array_column($list,'transactionHash');
                $transaction_hash_list = FmsWalletTradeList::whereIn('tx_hash',$hash_list)->pluck('tx_hash')->toArray();
        
                $success_hash_list = [];    //成功hash值数组
                $error_hash_list = [];    //失败hash值数组
                $time = nowDate();
        
                foreach ($list as $k => $v) {
                    if(!in_array($v['transactionHash'],$transaction_hash_list) && $v['contractRet'] == 'SUCCESS' && calculationExcept($v['amount'],6) >= 1 && $v['tokenInfo']['tokenId'] == '_' && $v['tokenInfo']['tokenAbbr'] == 'trx'){
                        Db::beginTransaction();
                        try {
                            $res = $this->AddWalletData($v,$time,$in_list);
                            if($res['code'] == 200){
                                $success_hash_list[] = $v['transactionHash'];
                            }else{
                                $error_hash_list[] = $v['transactionHash'].'--------'.'不是收款记录或金额为0，交易失败';
                            }
                            Db::commit();
                        }catch (\Exception $e){
                            Db::rollBack();
                            $error_hash_list[] = $v['transactionHash'].'--------'.$e->getMessage();
                        }
                    }else{
                        $error_hash_list[] = $v['transactionHash'].'--------'.'已存在';
                    }
                }
        
                $success_hash_list_count = count($success_hash_list);
                $error_hash_list_count = count($error_hash_list);
        
                // $log_list = [
                //     'recharge_wallet_addr' => $in_list['recharge_wallet_addr'],
                //     'success_hash_list' => $success_hash_list,
                //     'error_hash_list' => $error_hash_list,
                // ];
        
                // $this->log('getwalletdetails','钱包地址：'.$in_list['recharge_wallet_addr'].'，成功hash值'.$success_hash_list_count.'条，失败hash值'.$error_hash_list_count.'条');
                // $this->log('getwalletdetails',json_encode($log_list,JSON_UNESCAPED_UNICODE));
        
                $this->list_success_count = $this->list_success_count + $success_hash_list_count;
                $this->list_error_count = $this->list_error_count + $error_hash_list_count;
        
                // 总数大于当前获取数时和当前获取数据总数等于设置条数，再次去获取
                $get_total = ($page+1) * $limit;
                if($total > $get_total && count($list) == $limit){
                    $this->getList($in_list,$start_timestamp,$end_timestamp,$page+1);
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
        
        $txid_list['tx_hash'] = $data['transactionHash'];       //交易hash 
        $txid_list['transferfrom_address'] = $data['transferFromAddress'];       //来源钱包地址  
        $txid_list['timestamp'] = $data['timestamp'];        //时间戳  
        
        $txid_list['transferto_address'] = $in_list['recharge_wallet_addr'];        //收款钱包地址  

        $txid_list['coin_name'] = 'trx';
        $txid_list['amount'] = calculationExcept($data['amount'],6);     //交易数额 
        $txid_list['get_time'] = $time;       //拉取时间 

        $txid_list['process_status'] = 1;      //待兑换
        $txid_list['process_comments'] = '待处理';      //处理备注  
        $txid_list['process_time'] = $time;        //处理时间

        FmsWalletTradeList::insert($txid_list);       //添加收款钱包交易列表

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
