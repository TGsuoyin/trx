<?php

namespace App\Service\Energy;

use App\Model\Energy\EnergyWalletTradeList;
use App\Library\Log;
use Hyperf\DbConnection\Db;
use App\Service\Bus\TronServices;

class EnergyWalletTradeTrxServices
{
    private $limit = 50;        //每次获取多少条

    /**
     * 获取能量钱包数据-通过tronscan获取
     * @param $in_list [钱包数据]
     * @param $start_timestamp [开始时间 13位时间戳]
     * @param $end_timestamp [结束时间 13位时间戳]
     * @param $page [页数]
    */
    public function getList($in_list,$start_timestamp,$end_timestamp,$page=0){
        
        $limit = $this->limit;
        $start = $page * $limit;
        
        //通过tronscan拉取交易
        $url = 'https://apilist.tronscanapi.com/api/new/transfer?sort=-timestamp&count=true&limit='.$limit.'&start='.$start.'&address='.$in_list['receive_wallet'].'&toAddress='.$in_list['receive_wallet'].'&tokens=_&start_timestamp='.$start_timestamp.'&end_timestamp='.$end_timestamp;
        
        $api_key = config('apikey.tronapikey');
        
        $apikeyrand = $api_key[array_rand($api_key)];

        $heders = [
            'TRON-PRO-API-KEY:'.$apikeyrand
        ];
        
        $data = Get_Pay($url,null,$heders);

        if(!empty($data)){
            $data = json_decode($data,true);
            if(!empty($data['total']) && isset($data['data']) && count($data['data']) > 0){
                $data = $this->handleWalletData($data,$in_list,$start_timestamp,$end_timestamp,$page,$limit);
            }
        }
    }

    /**
     * 处理收款数据-通过tronscan获取
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
                $transaction_hash_list = EnergyWalletTradeList::whereIn('tx_hash',$hash_list)->pluck('tx_hash')->toArray();
        
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
        
                // $log_list = [
                //     'receive_wallet' => $in_list['receive_wallet'],
                //     'success_hash_list' => $success_hash_list,
                //     'error_hash_list' => $error_hash_list,
                // ];
        
                // $this->log('getenergywallettrxtrade','钱包地址：'.$in_list['receive_wallet'].'，成功hash值'.$success_hash_list_count.'条，失败hash值'.$error_hash_list_count.'条');
                // $this->log('getenergywallettrxtrade',json_encode($log_list,JSON_UNESCAPED_UNICODE));
        
                // 总数大于当前获取数时和当前获取数据总数等于设置条数，再次去获取
                $get_total = ($page+1) * $limit;
                if($total > $get_total && count($list) == $limit){
                    $this->getList($in_list,$start_timestamp,$end_timestamp,$page+1);
                }
            }
        }
    }

    /**
     * 整合添加收款数据-通过tronscan获取
     * @param $data [收款数据]
     * @param $time [当前时间]
     * @param $in_list [钱包数据]
    */
    public function AddWalletData($data,$time,$in_list){
        $txid_list = [];
        
        $txid_list['tx_hash'] = $data['transactionHash'];       //交易hash 
        $txid_list['transferfrom_address'] = $data['transferFromAddress'];       //来源钱包地址  
        $txid_list['timestamp'] = $data['timestamp'];        //时间戳  
        
        $txid_list['transferto_address'] = $in_list['receive_wallet'];        //收款钱包地址  

        $txid_list['coin_name'] = 'trx';
        $txid_list['amount'] = calculationExcept($data['amount'],6);     //交易数额 
        $txid_list['get_time'] = $time;       //拉取时间 

        $txid_list['process_status'] = 1;      //待兑换
        $txid_list['process_comments'] = '待处理';      //处理备注  
        $txid_list['process_time'] = $time;        //处理时间

        EnergyWalletTradeList::insert($txid_list);       //添加收款钱包交易列表

        return ['code' => 200];
    }
    
    /**
     * 获取能量钱包数据-通过trongrid获取
     * @param $in_list [钱包数据]
     * @param $start_timestamp [开始时间 13位时间戳]
     * @param $nexturl [下一页]
    */
    public function getListByGrid($in_list,$start_timestamp,$nexturl='0'){
        $limit = $this->limit;

        if($nexturl != '0'){
            $url = $nexturl;
        }else{
            $url = 'https://api.trongrid.io/v1/accounts/'.$in_list['receive_wallet'].'/transactions?only_to=true&only_confirm=true&limit='.$limit.'&min_timestamp='.$start_timestamp.'&search_internal=false';
        }
        
        $api_key = config('apikey.gridapikey');
        $apikeyrand = $api_key[array_rand($api_key)];
        
        $heders = [
            'TRON-PRO-API-KEY:'.$apikeyrand
        ];
        
        $data = Get_Pay($url,null,$heders);
        
        if(!empty($data) && $data){
            //波场接口API
            $TronApiConfig = [
                'url' => 'https://api.trongrid.io',
                'api_key' => $apikeyrand,
            ]; 
            $tron = new TronServices($TronApiConfig,'1111111','222222');
            
            $data = json_decode($data,true);
            $data = $this->handleWalletDataByGrid($data,$in_list,$tron);
        }
    }

    /**
     * 处理收款数据-通过trongrid获取
     * @param $data [收款数据]
     * @param $in_list [钱包数据]
    */
    public function handleWalletDataByGrid($data,$in_list,$tron){
        if(isset($data['data'])){
            $list = $data['data'];
            if($list){
                
                // 校验hash是否存在
                $hash_list = array_column($list,'txID');
                
                $transaction_hash_list = EnergyWalletTradeList::whereIn('tx_hash',$hash_list)->pluck('tx_hash')->toArray();
        
                $success_hash_list = [];    //成功hash值数组
                $error_hash_list = [];    //失败hash值数组
                $time = nowDate();
        
                foreach ($list as $k => $v) {
                    if(!in_array($v['txID'],$transaction_hash_list) && $v['raw_data']['contract'][0]['type'] == 'TransferContract'){
                        Db::beginTransaction();
                        try {
                            $res = $this->AddWalletDataByGrid($v,$time,$in_list,$tron);
                            if($res['code'] == 200){
                                $success_hash_list[] = $v['txID'];
                            }else{
                                $error_hash_list[] = $v['txID'].'--------'.'不是收款记录或金额为0，交易失败';
                            }
                            Db::commit();
                        }catch (\Exception $e){
                            Db::rollBack();
                            $error_hash_list[] = $v['txID'].'--------'.$e->getMessage();
                        }
                    }else{
                        $error_hash_list[] = $v['txID'].'--------'.'已存在';
                    }
                }
        
                // $log_list = [
                //     'receive_wallet' => $in_list['receive_wallet'],
                //     'success_hash_list' => $success_hash_list,
                //     'error_hash_list' => $error_hash_list,
                // ];
        
                // $this->log('getenergywallettrxtrade','钱包地址：'.$in_list['receive_wallet'].'，成功hash值'.$success_hash_list_count.'条，失败hash值'.$error_hash_list_count.'条');
                // $this->log('getenergywallettrxtrade',json_encode($log_list,JSON_UNESCAPED_UNICODE));
        
                // 如果有下一页，再次去获取
                if(isset($data['meta']['links']['next'])){
                    $this->getList($in_list,0,$data['meta']['links']['next']);
                }
            }
        }
        
    }

    /**
     * 整合添加收款数据-通过trongrid获取
     * @param $data [收款数据]
     * @param $time [当前时间]
     * @param $in_list [钱包数据]
    */
    public function AddWalletDataByGrid($data,$time,$in_list,$tron){
        $amount = calculationExcept($data['raw_data']['contract'][0]['parameter']['value']['amount'],6);
        
        if($amount >= 0.1){
            $fromaddress = $tron->addressFromHex($data['raw_data']['contract'][0]['parameter']['value']['owner_address']);
            
            $txid_list = [];
            $txid_list['tx_hash'] = $data['txID'];       //交易hash 
            $txid_list['transferfrom_address'] = $fromaddress;       //来源钱包地址  
            $txid_list['timestamp'] = $data['raw_data']['timestamp'];        //时间戳  
            
            $txid_list['transferto_address'] = $in_list['receive_wallet'];        //收款钱包地址  
    
            $txid_list['coin_name'] = 'trx';
            $txid_list['amount'] = $amount;     //交易数额 
            $txid_list['get_time'] = $time;       //拉取时间 
    
            $txid_list['process_status'] = 1;      //待兑换
            $txid_list['process_comments'] = '待处理';      //处理备注  
            $txid_list['process_time'] = $time;        //处理时间
    
            EnergyWalletTradeList::insert($txid_list);       //添加收款钱包交易列表
        }
        
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
