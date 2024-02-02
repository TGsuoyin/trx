<?php

namespace App\Service\Bus;

use App\Model\Transit\TransitWalletBlack;
use App\Model\Transit\TransitUserWallet;
use App\Model\Transit\TransitWalletTradeList;
use App\Service\Transit\TransitWalletCoinServices;
use App\Service\RsaServices;
use App\Library\Log;
use Hyperf\DbConnection\Db;
use App\Service\Bus\TronServices;

class ShanduiBonusServices
{
    // 转账逻辑处理
    public function handleGrant($data){
        $transitWalletCoin_services = new TransitWalletCoinServices();
        $transitWalletCoinList = $transitWalletCoin_services->IDList();
        // 黑钱包列表
        $black_list = TransitWalletBlack::pluck('black_wallet');
        if(empty($black_list)){
            $black_list = [];
        }else{
            $black_list = $black_list->toArray();
        }

        $success_count = 0;
        $rsa_services = new RsaServices();
        foreach ($data as $k => $v) {
            if(empty($transitWalletCoinList[$v->transit_wallet_id.strtolower($v->coin_name)])){
                $this->log('shanduibonus','----------闪兑钱包币种未设置,交易明细rid:'.$v->rid.',闪兑钱包ID:'.$v->transit_wallet_id.',转入币名:'.$v->coin_name.'----------');
            }else{
                if(in_array($v->sendback_address,$black_list)){
                    $save_data = [];
                    $save_data['process_status'] = 6;      //黑钱包
                    $save_data['process_comments'] = '黑钱包';      //处理备注  
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    continue;
                }

                //币种设置
                $coin_data = $transitWalletCoinList[$v->transit_wallet_id.strtolower($v->coin_name)];
                if($v->amount < $coin_data['min_transit_amount']){
                    $save_data = [];
                    $save_data['process_status'] = 7;      //金额不符合要求
                    $save_data['process_comments'] = '转入金额小于最低转入金额:'.$coin_data['min_transit_amount'];      //处理备注  
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    continue;
                }

                if($v->amount > $coin_data['max_transit_amount']){
                    $save_data = [];
                    $save_data['process_status'] = 7;      //金额不符合要求
                    $save_data['process_comments'] = '转入金额大于最高转入金额:'.$coin_data['max_transit_amount'];      //处理备注  
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    continue;
                }

                // 未设置汇率
                if(empty($coin_data['exchange_rate']) || $coin_data['exchange_rate'] == 0){
                    $this->log('shanduibonus','----------闪兑钱包币种未设置汇率,交易明细rid:'.$v->rid.',闪兑钱包ID:'.$v->transit_wallet_id.',转入币名:'.$v->coin_name.'----------');
                    continue;
                }
                
                $amount = bcsub($v->amount * $coin_data['exchange_rate'],$coin_data['kou_out_amount'],2);       //校验金额
                if($amount <= 0){
                    $save_data = [];
                    $save_data['process_status'] = 7;      //金额不符合要求
                    $save_data['process_comments'] = '计算汇率减去扣回款金额后为负数，汇率：'.$coin_data['exchange_rate'].'。扣回款金额：'.$amount;      //处理备注  
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    continue;
                }
                
                $address = $v->sendback_address;      //接收钱包地址
                
                //查询是否有预支
                $userdata = TransitUserWallet::where('chain_type','trc')->where('wallet_addr',$address)->first();
                $huan_yuzhi_amount = 0;
                if(empty($userdata)){
                    $userdata_exit = 1; //不存在,用于后面判断插入还是更新
                }else{
                    $userdata_exit = 2; //存在,用于后面判断插入还是更新
                    //如果预支未还
                    if($userdata->need_feedback_sxf > 0){
                        $huan_yuzhi_amount = min($amount,$userdata->need_feedback_sxf);
                        $amount = max(bcsub($amount,$userdata->need_feedback_sxf,2),0);
                    }
                }
                
                $time = nowDate();
                $OWNER_ADDRESS = $v->send_wallet;        //出款钱包
                //解密-佣金钱包私钥
                $OWNER_PRIVATE_KEY = $rsa_services->privateDecrypt($v->send_wallet_privatekey); 
                if(empty($OWNER_PRIVATE_KEY)){
                    $this->log('shanduibonus','----------闪兑钱包私钥错误,交易明细rid:'.$v->rid.',闪兑钱包ID:'.$v->transit_wallet_id.',转入币名:'.$v->coin_name.'----------');
                    continue;
                }

                $sendback_coin_name = $coin_data['out_coin_name'];      //出款币名
                $success_status = 0;
                $balance = 0;
                
                $api_key = config('apikey.gridapikey');
                $apikeyrand = $api_key[array_rand($api_key)];
        
                //波场接口API
                $TronApiConfig = [
                    'url' => 'https://api.trongrid.io',
                    'api_key' => $apikeyrand,
                ]; 
        
                $tron = new TronServices($TronApiConfig,$OWNER_ADDRESS,$OWNER_PRIVATE_KEY);
                
                #方法一：tronweb方法,偶尔获取不成功,间隔1秒重试3次
                for($i=1; $i<=3; $i++){
                    if($sendback_coin_name == 'trx'){
                        $balance = $tron->getAccount($OWNER_ADDRESS);
                    }else{
                        // 获取TRC20余额
                        $balance = $tron->newGetTrc20Balance($OWNER_ADDRESS,'USDT','TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t');
                    }
                    if($balance && $balance > 0){
                        break;
                    }
                    sleep(1);
                }

                if($balance >= $amount){
                    $save_data = [];
                    $save_data['process_status'] = 8;      //转帐中
                    $save_data['process_comments'] = '转帐中';      //处理备注  
                    $save_data['sendback_coin_name'] = $sendback_coin_name;      //出款币名
                    $save_data['sendback_amount'] = $amount;      //出款数额
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    
                    if($sendback_coin_name == 'trx'){
                        // TRX转账，默认是permission=0也就是owner,私钥需要为owner的私钥
                        if($amount > 0){
                            //php必须安装gmp扩展才能转账
                            $res = $tron->trxTransaction($address,$amount);
                            $trid = $res['data']['txid'];
                            $troncode = $res['code'];
                            
                            //多签如果不是owner，调用下面这种api方式，注意要先查询私钥对应地址的permissionid
                            // $apiparam = [
                            //     'fromaddress' => $OWNER_ADDRESS,
                            //     'toaddress' => $address,
                            //     'sendamount' => $amount,
                            //     'pri1' => $OWNER_PRIVATE_KEY,
                            //     'permissionid' => 2,
                            // ];
                            // $res = Get_Pay('https://tronwebnodejs.walletim.vip/sendtrxbypermid',$apiparam);
                            
                            // if(empty($res)){
                            //     $troncode = 400;
                            // }else{
                            //     $res = json_decode($res,true);
                            //     if(empty($res['code'])){
                            //         $troncode = 400;
                            //     }else{
                            //         if($res['code'] == 200){
                            //             $troncode = 200;
                            //             $trid = $res['data']['txid'];
                            //         }else{
                            //             $troncode = 400;
                            //         }
                            //     }
                            // }
                            
                        }else{
                            $trid = '扣预支后为0,不转账';
                            $troncode = 200;
                        }
                        
                        $type = 1;
                        $success_status = 1;
                    }else{
                        // TRC20转账
                        if($amount > 0){
                            $res = $tron->trc20Transaction($address,$amount,'USDT');
                            $trid = $res['data']['txid'];
                            $troncode = $res['code'];
                            
                        }else{
                            $trid = '扣预支后为0,不转账';
                            $troncode = 200;
                        }
                        
                        $type = 2;
                        $success_status = 1;
                    }
                }

                if($success_status == 1){
                    if($troncode == 200){
                        $save_data = [];
                        $save_data['process_status'] = 9;      //自动转账
                        $save_data['process_comments'] = '转帐成功,扣预支:'.$huan_yuzhi_amount;      //处理备注  
                        $save_data['sendback_tx_hash'] = $trid;      //出款交易hash
                        $save_data['sendback_contract_ret'] = 'SUCCESS';      //出款交易hash
                        $save_data['sendback_time'] = $time;      //出款时间
                        $save_data['current_exchange_rate'] = $coin_data['exchange_rate'];      //当前汇率
                        $save_data['current_huan_yuzhi_amount'] = $huan_yuzhi_amount;      //扣预支金额
                        TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                        
                        //记录会员汇总
                        if($userdata_exit == 1){
                            $save_data = [];
                            $save_data['chain_type'] = 'trc';
                            $save_data['wallet_addr'] = $address;
                            $save_data['total_transit_usdt'] = $v->amount;
                            $save_data['last_transit_time'] = $time;
                            TransitUserWallet::insert($save_data);
                        }else{
                            $save_data = [];
                            $save_data['total_transit_usdt'] = bcadd($userdata['total_transit_usdt'], $v->amount,2);
                            $save_data['last_transit_time'] = $time;
                            $save_data['need_feedback_sxf'] = bcsub($userdata['need_feedback_sxf'], $huan_yuzhi_amount,2);
                            $save_data['send_feedback_sxf'] = bcadd($userdata['send_feedback_sxf'], $huan_yuzhi_amount,2);
                            TransitUserWallet::where('rid',$userdata->rid)->update($save_data);
                        };
                        
                        $success_count++;
                    }else{
                        $save_data = [];
                        $save_data['process_status'] = 1;      //待兑换
                        $save_data['process_comments'] = '待兑换';      //处理备注  
                        TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    }
                }else{
                    $save_data = [];
                    $save_data['process_status'] = 10;      //余额不足
                    $save_data['process_comments'] = '余额不足,当前:'.$balance;     //处理备注  
                    TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    // $this->log('shanduibonus','----------余额不足，需人工处理----------');
                }
            }
        }

        // $this->log('shanduibonus','----------结束执行:闪兑钱包逻辑处理。成功转账'.$success_count.'条----------');
        return ['code' => 200,'msg'=>'成功转账'.$success_count.'条'];
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