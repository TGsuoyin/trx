<?php
namespace App\Task;

use App\Model\Energy\EnergyPlatform;
use App\Library\Log;
use App\Service\RsaServices;

class GetEnergyPlatformBalance
{
    public function execute()
    { 
        try {
            $data = EnergyPlatform::from('energy_platform as a')
                    ->leftJoin('telegram_bot as b','a.tg_notice_bot_rid','b.rid')
                    ->where('a.status',0)
                    ->select('a.*','b.bot_token')
                    ->get();
            
            if($data->count() > 0){
                $rsa_services = new RsaServices();
                
                foreach ($data as $k => $v) {
                    //neee.cc平台
                    if($v['platform_name'] == 1){
                        $header = [
                            "Content-Type:application/json"
                        ];
                        
                        $param = [
                            "uid" => $v['platform_uid'],
                            "time" => time()
                        ];
                        
                		ksort($param);
                		reset($param);
                		
                		$signstr = $rsa_services->privateDecrypt($v['platform_apikey']);
                	
                		foreach($param as $k1 => $v1){
                			if($k1 != "sign" && $k1 != "sign_type" && $v1!=''){
                				$signstr .= $k1.$v1;
                			}
                		}
                		
                		$sign = md5($signstr);
                		$param['sign'] = $sign;
                        $balance_url = 'https://api.tronqq.com/openapi/v1/user/balance';
                        $res = Get_Pay($balance_url,json_encode($param),$header);
                        
                        if(empty($res)){
                            $this->log('energyplatformbalance',$v['rid'].'平台请求失败');
                        }else{
                            $res = json_decode($res,true);
                            if($res['status'] == 200){
                                if(empty($res['data'])){
                                    $this->log('energyplatformbalance',$v['rid'].'平台请求失败2:'.json_encode($res));
                                }else{
                                    $balance = $res['data']['balance'];
                                    $balance = $balance <= 0 ?0:$balance;
                                    
                                    $updatedata1['platform_balance'] = $balance;
                                    //间隔10分钟通知一次
                                    if($balance <= $v['alert_platform_balance'] && $v['alert_platform_balance'] > 0 && strtotime($v['last_alert_time']) + 600 <= strtotime(nowDate())){
                                        $updatedata1['last_alert_time'] = nowDate();
                                        
                                        //余额通知管理员
                                        if($v['tg_notice_obj'] && !empty($v['tg_notice_obj'])){
                                            $replytext = "能量平台(neee.cc)，余额不足，请立即前往平台充值！\n"
                                                        ."能量平台ID：".$v['rid']."\n"
                                                        ."平台用户UID：".$v['platform_uid']."\n"
                                                        ."当前余额：".$balance."\n"
                                                        ."告警金额：".$v['alert_platform_balance']."\n\n"
                                                        ."不处理会一直告警通知！间隔10分钟告警一次";
                                                        
                                            $sendlist = explode(',',$v['tg_notice_obj']);
                            
                                            foreach ($sendlist as $x => $y) {
                                                $sendmessageurl = 'https://api.telegram.org/bot'.$v['bot_token'].'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                                                
                                                Get_Pay($sendmessageurl);
                                            }
                                        }
                                    }
                                    
                                    EnergyPlatform::where('rid',$v['rid'])->update($updatedata1);
                                }
                            }else{
                                $this->log('energyplatformbalance',$v['rid'].'平台请求失败3:'.json_encode($res));
                            }
                        }
                    }
                    
                    //RentEnergysBot平台
                    elseif($v['platform_name'] == 2){
                        $signstr = $rsa_services->privateDecrypt($v['platform_apikey']);
                        $balance_url = 'https://api.wallet.buzz?api=getBalance&apikey='.$signstr;
                        $res = Get_Pay($balance_url);
                        
                        if(!isset($res) || $res === ""){
                            $this->log('energyplatformbalance',$v['rid'].'平台请求失败1:');
                        }else{
                            $balance = $res <= 0 ?0:$res;
                                    
                            $updatedata2['platform_balance'] = $balance;
                            
                            if($balance <= $v['alert_platform_balance'] && $v['alert_platform_balance'] > 0 && strtotime($v['last_alert_time']) + 600 <= strtotime(nowDate())){
                                $updatedata2['last_alert_time'] = nowDate();
                                
                                //余额通知管理员
                                if($v['tg_notice_obj'] && !empty($v['tg_notice_obj'])){
                                    $replytext = "能量平台(RentEnergysBot)，余额不足，请立即前往平台充值！\n"
                                                ."能量平台ID：".$v['rid']."\n"
                                                ."平台用户UID：".$v['platform_uid']."\n"
                                                ."当前余额：".$balance."\n"
                                                ."告警金额：".$v['alert_platform_balance']."\n\n"
                                                ."不处理会一直告警通知！间隔10分钟告警一次";
                                                
                                    $sendlist = explode(',',$v['tg_notice_obj']);
                    
                                    foreach ($sendlist as $x => $y) {
                                        $sendmessageurl = 'https://api.telegram.org/bot'.$v['bot_token'].'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                                        
                                        Get_Pay($sendmessageurl);
                                    }
                                }
                            }
                            
                            EnergyPlatform::where('rid',$v['rid'])->update($updatedata2);
                        }
                    }
                    
                    //自己质押代理
                    elseif($v['platform_name'] == 3 && mb_strlen($v['platform_uid']) == 34){
                        $tronurl = 'https://api.trongrid.io/wallet/getaccountresource';
                
                        $api_key = config('apikey.gridapikey');
                        $apikeyrand = $api_key[array_rand($api_key)];
                        
                        $heders = [
                            "TRON-PRO-API-KEY:".$apikeyrand
                        ];
                        
                        $body = [
                          "address" => $v['platform_uid'],
                          "visible" => true
                        ];
                        
                        $tronres = Get_Pay($tronurl,json_encode($body),$heders);

                        if(empty($tronres)){
                            $this->log('energyplatformbalance',$v['rid'].'平台请求失败1:');
                        }else{
                            $tronres = json_decode($tronres,true);

                            if(isset($tronres['EnergyLimit'])){
                                $balance = $tronres['EnergyLimit'] - ($tronres['EnergyUsed'] ?? 0);
                                $updatedata3['platform_balance'] = $balance;
                                
                                if($balance <= $v['alert_platform_balance'] && $v['alert_platform_balance'] > 0 && strtotime($v['last_alert_time']) + 600 <= strtotime(nowDate())){
                                    $updatedata3['last_alert_time'] = nowDate();
                                    
                                    //余额通知管理员
                                    if($v['tg_notice_obj'] && !empty($v['tg_notice_obj'])){
                                        $replytext = "能量平台(自己质押代理)，能量不足，请立即质押！\n"
                                                    ."能量平台ID：".$v['rid']."\n"
                                                    ."质押钱包地址：".$v['platform_uid']."\n"
                                                    ."当前能量剩余：".$balance."\n"
                                                    ."告警能量值：".$v['alert_platform_balance']."\n\n"
                                                    ."不处理会一直告警通知！间隔10分钟告警一次";
                                                    
                                        $sendlist = explode(',',$v['tg_notice_obj']);
                        
                                        foreach ($sendlist as $x => $y) {
                                            $sendmessageurl = 'https://api.telegram.org/bot'.$v['bot_token'].'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                                            
                                            Get_Pay($sendmessageurl);
                                        }
                                    }
                                }
                                
                                EnergyPlatform::where('rid',$v['rid'])->update($updatedata3);
                            }
                        }
                    }
                    
                    //trongas.io平台
                    elseif($v['platform_name'] == 4){
                        $param = [
                            "username" => $v['platform_uid']
                        ];
                        $balance_url = 'https://trongas.io/api/userInfo';
                        $res = Get_Pay($balance_url,$param);
                        
                        if(empty($res)){
                            $this->log('energyplatformbalance',$v['rid'].'平台请求失败');
                        }else{
                            $res = json_decode($res,true);
                            if($res['code'] == 10000){
                                if(empty($res['data'])){
                                    $this->log('energyplatformbalance',$v['rid'].'平台请求失败2:'.json_encode($res));
                                }else{
                                    $balance = $res['data']['balance'];
                                    $balance = $balance <= 0 ?0:$balance;
                                    
                                    $updatedata4['platform_balance'] = $balance;
                                    //间隔10分钟通知一次
                                    if($balance <= $v['alert_platform_balance'] && $v['alert_platform_balance'] > 0 && strtotime($v['last_alert_time']) + 600 <= strtotime(nowDate())){
                                        $updatedata4['last_alert_time'] = nowDate();
                                        
                                        //余额通知管理员
                                        if($v['tg_notice_obj'] && !empty($v['tg_notice_obj'])){
                                            $replytext = "能量平台(trongas.io)，余额不足，请立即前往平台充值！\n"
                                                        ."能量平台ID：".$v['rid']."\n"
                                                        ."平台用户UID：".$v['platform_uid']."\n"
                                                        ."当前余额：".$balance."\n"
                                                        ."告警金额：".$v['alert_platform_balance']."\n\n"
                                                        ."不处理会一直告警通知！间隔10分钟告警一次";
                                                        
                                            $sendlist = explode(',',$v['tg_notice_obj']);
                            
                                            foreach ($sendlist as $x => $y) {
                                                $sendmessageurl = 'https://api.telegram.org/bot'.$v['bot_token'].'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                                                
                                                Get_Pay($sendmessageurl);
                                            }
                                        }
                                    }
                                    
                                    EnergyPlatform::where('rid',$v['rid'])->update($updatedata4);
                                }
                            }else{
                                $this->log('energyplatformbalance',$v['rid'].'平台请求失败3:'.json_encode($res));
                            }
                        }
                    }
                }
            }
            
        }catch (\Exception $e){
            $this->log('energyplatformbalance','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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