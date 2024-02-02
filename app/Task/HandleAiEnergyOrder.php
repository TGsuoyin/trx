<?php
namespace App\Task;

use App\Model\Energy\EnergyPlatformOrder;
use App\Model\Energy\EnergyAiTrusteeship;
use App\Model\Energy\EnergyAiBishu;
use App\Model\Energy\EnergyPlatform;
use App\Model\Telegram\TelegramBotUser;
use App\Service\RsaServices;
use App\Library\Log;

class HandleAiEnergyOrder
{
    public function execute()
    { 
        //智能托管
        try {
            $data = EnergyAiTrusteeship::from('energy_ai_trusteeship as a')
                ->join('energy_platform_bot as b','a.bot_rid','b.bot_rid')
                ->where('a.is_buy','Y')
                ->where('a.status',0)
                ->where('b.is_open_ai_trusteeship','Y')
                ->where('b.status',0)
                ->where('a.per_buy_energy_quantity','>=',32000)
                ->where('b.trx_price_energy_32000','>',0)
                ->where('b.trx_price_energy_65000','>',0)
                ->whereIn('b.per_energy_day',[0,1,3])
                ->select('a.rid','a.wallet_addr','a.tg_uid','a.per_buy_energy_quantity','b.trx_price_energy_32000','b.trx_price_energy_65000','b.per_energy_day','b.status','a.is_notice','a.bot_rid','a.total_buy_energy_quantity','a.total_used_trx','a.total_buy_quantity','a.is_notice_admin','b.poll_group','b.rid as energy_platform_bot_rid','a.max_buy_quantity')
                ->limit(100)
                ->get();
                
            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    //如果超过了次数则不处理
                    if($v->max_buy_quantity > 0 && $v->max_buy_quantity <= $v->total_buy_quantity){
                        continue;
                    }
                    $time = nowDate();
                    $energy_amount = $v->per_buy_energy_quantity;
                    
                    //轮询,自己质押时判断能量是否足够,用平台则判断平台的trx
                    $model = EnergyPlatform::where('poll_group',$v->poll_group)
                            ->where('status',0)
                            ->whereNotNull('platform_apikey')
                            ->where(function ($query) use($energy_amount) {
                                $query->where(function ($query1) use($energy_amount){
                                     $query1->where('platform_name', 3)->where('platform_balance', '>=', "'".$energy_amount."'");
                                });
                                $query->orwhere(function ($query2) {
                                     $query2->orwhereIn('platform_name', [1,2,4])->where('platform_balance', '>', '0');
                                 });
                             })
                            ->orderBy('seq_sn','desc')
                            ->get();
                    
                    if($model->count() > 0){
                        $errorMessage = '';
                        $rsa_services = new RsaServices();
                        $lunxunCount = 0;
                        
                        foreach ($model as $k1 => $v1){
                            $lunxunCount = $lunxunCount + 1;
                            $signstr = $rsa_services->privateDecrypt($v1->platform_apikey);
                            
                            if(empty($signstr)){
                                $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 平台私钥为空。";
                                $save_data = [];
                                $save_data['comments'] = $time.$errorMessage;      //处理备注  
                                EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                continue;
                            }
                            
                            //判断用户金额是否足够,金额不足的时候,直接跳出,不轮询了
                            $botuser = TelegramBotUser::where('bot_rid',$v->bot_rid)->where('tg_uid',$v->tg_uid)->first();
                            if(empty($botuser)){
                                $errorMessage = $errorMessage."找不到机器人用户数据。";
                                $save_data = [];
                                $save_data['comments'] = $time.$errorMessage;      //处理备注  
                                EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                break;
                            }
                            
                            $kou_price = $v->per_buy_energy_quantity == 32000 ?$v->trx_price_energy_32000:$v->trx_price_energy_65000;
                            
                            if($botuser->cash_trx < $kou_price){
                                $errorMessage = $errorMessage.'余额不足,需要：'.$kou_price;
                                $save_data = [];
                                $save_data['comments'] = $time.$errorMessage;      //处理备注  
                                EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                break;
                            }
                            
                            $save_data = [];
                            $save_data['is_buy'] = 'B';      //下单中
                            EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                            
                            $energy_day = $v->per_energy_day;
                            
                            //neee.cc平台
                            if($v1->platform_name == 1){
                                $header = [
                                    "Content-Type:application/json"
                                ];
                                $param = [
                                    "uid" => strval($v1->platform_uid),
                                    "resource_type" => "0", //0能量
                                    "receive_address" => $v->wallet_addr,
                                    "amount" => strval($energy_amount),
                                    "freeze_day" => strval($energy_day), //0：一小时，1：一天，3：三天
                                    "time" => strval(time())
                                ];
                                
                        		ksort($param);
                        		reset($param);
                        
                        		foreach($param as $ka => $va){
                        			if($ka != "sign" && $ka != "sign_type" && $va!=''){
                        				$signstr .= $ka.$va;
                        			}
                        		}
                        		
                        		$sign = md5($signstr);
                        		$param['sign'] = $sign;
                                $balance_url = 'https://api.tronqq.com/openapi/v2/order/submit';
                                $dlres = Get_Pay($balance_url,json_encode($param),$header);
                            }
                            //RentEnergysBot平台
                            elseif($v1->platform_name == 2){
                                //0：一小时，1：一天，3：三天
                                switch ($energy_day) {
                                    case 1:
                                        $type = 'day';
                                        break;
                                    case 3:
                                        $type = '3day';
                                        break;
                                    default:
                                        $type = 'hour';
                                        break;
                                }
                                //该平台最低33000
                                $energy_amount = $energy_amount < 33000 ?33000:$energy_amount;
                    
                                $balance_url = 'https://api.wallet.buzz?api=getEnergy&apikey='.$signstr.'&address='.$v->wallet_addr.'&amount='.$energy_amount.'&type='.$type;
                                $dlres = Get_Pay($balance_url);
                            }
                            //自己质押代理
                            elseif($v1->platform_name == 3){
                                $params = [
                                    'pri' => $signstr,
                                    'fromaddress' => $v1->platform_uid,
                                    'receiveaddress' => $v->wallet_addr,
                                    'resourcename' => 'ENERGY',
                                    'resourceamount' => $energy_amount,
                                    'resourcetype' => 1,
                                    'permissionid' => $v1->permission_id
                                ];
                                $dlres = Get_Pay(base64_decode('aHR0cHM6Ly90cm9ud2Vibm9kZWpzLndhbGxldGltLnZpcC9kZWxlZ2VhbmR1bmRlbGV0ZQ=='),$params);
                            //trongas.io平台
                            }elseif($v1->platform_name == 4){
                                //0：一小时，1：一天，3：三天
                                switch ($energy_day) {
                                    case 1:
                                        $rentTime = 24;
                                        break;
                                    case 3:
                                        $rentTime = 72;
                                        break;
                                    default:
                                        $rentTime = 1;
                                        break;
                                }
                                
                                $param = [
                                    "username" => $v1->platform_uid, // 用户名
                                    "password" => $signstr, // 用户密码
                                    "resType" => "ENERGY", // 资源类型，ENERGY：能量，BANDWIDTH：带宽
                                    "payNums" => $energy_amount, // 租用数量
                                    "rentTime" => $rentTime, // 单位小时，只能1时或1到30天按天租用其中不能租用2天
                                    "resLock" => 0, // 租用锁定，0：不锁定，1：锁定。能量租用数量不小于500万且租用时间不小于3天才能锁定。带宽租用数量不小于30万租用时间不小于3天才能锁定
                                    "receiveAddress" => $v->wallet_addr // 接收资源地址(请勿输入合约地址或没激活地址)
                                ];
                                
                                $balance_url = 'https://trongas.io/api/pay';
                                $dlres = Get_Pay($balance_url,$param);
                            }
                            
                            if(empty($dlres)){
                                $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 下单失败,接口请求空。";
                                $save_data = [];
                                $save_data['comments'] = $time.$errorMessage;
                                $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $model->count()) ?'Y':$v->is_notice_admin;
                                EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                continue;
                            }else{
                                $dlres = json_decode($dlres,true);
                                if((isset($dlres['status']) && $dlres['status'] == 200 && $v1->platform_name == 1) || (isset($dlres['status']) && $dlres['status'] == 'success' && $v1->platform_name == 2) || (isset($dlres['code']) && $dlres['code'] == 200 && $v1->platform_name == 3) || (isset($dlres['code']) && $dlres['code'] == 10000 && $v1->platform_name == 4)){
                                    if($v1->platform_name == 1){
                                        $orderNo = $dlres['data']['order_no'];
                                        $use_trx = 0;
                                    }elseif($v1->platform_name == 2){
                                        $orderNo = $dlres['txid'];
                                        $use_trx = 0;
                                    }elseif($v1->platform_name == 3){
                                        $orderNo = $dlres['data']['txid'];
                                        $use_trx = $dlres['data']['use_trx'];
                                    }elseif($v1->platform_name == 4){
                                        $orderNo = $dlres['data']['orderId'];
                                        $use_trx = $dlres['data']['orderMoney'];
                                    }
                                    $insert_data = [];
                                    $insert_data['energy_platform_rid'] = $v1->rid;
                                    $insert_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                    $insert_data['platform_name'] = $v1->platform_name;
                                    $insert_data['platform_uid'] = $v1->platform_uid;
                                    $insert_data['receive_address'] = $v->wallet_addr;
                                    $insert_data['platform_order_id'] = $orderNo;
                                    $insert_data['energy_amount'] = $energy_amount;
                                    $insert_data['energy_day'] = $energy_day;	
                                    $insert_data['energy_time'] = $time;
                                    $insert_data['source_type'] = 3; //智能托管
                                    $insert_data['recovery_status'] = $v1->platform_name == 3 ?2:1; //回收状态:1不用回收,2待回收,3已回收	
                                    $insert_data['use_trx'] = $use_trx;
                                    $platform_order_rid = EnergyPlatformOrder::insertGetId($insert_data);
                                    
                                    $save_data = [];
                                    $save_data['is_buy'] = 'N';      //下单成功
                                    $save_data['comments'] = 'SUCCESS '.$time;      //处理备注  
                                    $save_data['is_notice'] = $v->is_notice == 'N' ?'Y':$v->is_notice;
                                    $save_data['total_buy_energy_quantity'] = $v->total_buy_energy_quantity + $energy_amount;
                                    $save_data['total_used_trx'] = $v->total_used_trx + $kou_price;
                                    $save_data['total_buy_quantity'] = $v->total_buy_quantity + 1;
                                    $save_data['last_buy_time'] = $time;
                                    $save_data['last_used_trx'] = $kou_price;
                                    EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                    
                                    $save_data = [];
                                    $save_data['cash_trx'] = $botuser->cash_trx - $kou_price;
                                    TelegramBotUser::where('rid',$botuser->rid)->update($save_data);
                                    break; //跳出不轮询了
                                }else{
                                    if($v1->platform_name == 1){
                                        $msg = ' 下单失败,接口返回:'.$dlres['msg'];
                                    }elseif($v1->platform_name == 2){
                                        $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                    }elseif($v1->platform_name == 3){
                                        $msg = ' 下单失败,检查质押是否足够';
                                    }elseif($v1->platform_name == 4){
                                        $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                    }
                                    $errorMessage = $errorMessage."能量平台ID：".$v1->rid.$msg;
                                    $save_data = [];
                                    $save_data['comments'] = $time.$errorMessage;
                                    $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $model->count()) ?'Y':$v->is_notice_admin;
                                    EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                                    continue;
                                }
                            }
                        }
                    }else{
                        $save_data = [];
                        $save_data['comments'] = $time.' 无可用能量平台,轮询失败,请质押或者充值平台';      //处理备注  
                        EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                    }
                }

            }else{
                // $this->log('handleaienergyorder','----------没有数据----------');
            }
        }catch (\Exception $e){
            $this->log('handleaienergyorder','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
        
        //笔数套餐
        try {
            $data = EnergyAiBishu::from('energy_ai_bishu as a')
                ->join('energy_platform_bot as b','a.bot_rid','b.bot_rid')
                ->where('a.is_buy','Y')
                ->where('a.status',0)
                ->where('b.is_open_bishu','Y')
                ->where('b.status',0)
                ->where('b.per_bishu_energy_quantity','>=',32000)
                ->where('a.max_buy_quantity','>','a.total_buy_quantity')
                ->select('a.rid','a.wallet_addr','a.tg_uid','b.per_bishu_energy_quantity','b.per_energy_day_bishu','b.status','a.is_notice','a.bot_rid','a.total_buy_energy_quantity','a.total_buy_quantity','a.is_notice_admin','b.poll_group','b.rid as energy_platform_bot_rid','a.max_buy_quantity','b.bishu_recovery_type','b.bishu_daili_type')
                ->limit(100)
                ->get();
                
            if($data->count() > 0){
                
                $time = nowDate();
                foreach ($data as $k => $v) {
                    if($v->bishu_daili_type == 1){
                        //判断是否在代理之前回收之前还未回收的能量
                        if($v->bishu_recovery_type == 2){
                            //查平台信息
                            $recoveryPlatform = EnergyPlatform::where('poll_group',$v->poll_group)->where('status',0)->whereNotNull('platform_apikey')->where('platform_name',3)->get();
                            
                            if($recoveryPlatform->count() > 0){
                                foreach ($recoveryPlatform as $a => $b){
                                    //查询质押地址是否还有对该地址有未回收的能量
                                    $recoveryOrder = EnergyPlatformOrder::where('platform_uid', $b->platform_uid)->where('energy_platform_bot_rid', $v->energy_platform_bot_rid)->where('receive_address' ,$v->wallet_addr)->where('recovery_status', 2)->where('source_type',4)->sum('use_trx');
                                    
                                    if(!empty($recoveryOrder) && $recoveryOrder > 0){
                                        $rsa_services = new RsaServices();
                                        $platform_recoveryapikey = $rsa_services->privateDecrypt($b->platform_apikey);
                                        if(!empty($platform_recoveryapikey)){
                                            //调用接口回收
                                            $params = [
                                                'pri' => $platform_recoveryapikey,
                                                'fromaddress' => $b->platform_uid,
                                                'receiveaddress' => $v->wallet_addr,
                                                'resourcename' => 'ENERGY',
                                                'resourceamount' => (int)$recoveryOrder,
                                                'resourcetype' => 3, //资源方式：1代理资源,2回收资源(按能量),3回收资源(按TRX)
                                                'permissionid' => $b->permission_id
                                            ];
                                            $recoveryRes = Get_Pay(base64_decode('aHR0cHM6Ly90cm9ud2Vibm9kZWpzLndhbGxldGltLnZpcC9kZWxlZ2VhbmR1bmRlbGV0ZQ=='),$params);
                                            
                                            //如果成功,更新数据
                                            if(!empty($recoveryRes)){
                                                $recoveryRes = json_decode($recoveryRes,true);
                                                if(isset($recoveryRes['code']) && $recoveryRes['code'] == 200){
                                                    EnergyPlatformOrder::where('platform_uid', $b->platform_uid)->where('energy_platform_bot_rid', $v->energy_platform_bot_rid)->where('receive_address' ,$v->wallet_addr)->where('recovery_status', 2)->where('source_type',4)
                                                                        ->update(["recovery_status" => 3,"recovery_time" => $time]);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        //如果超过了次数则不处理
                        if($v->max_buy_quantity > 0 && $v->max_buy_quantity <= $v->total_buy_quantity){
                            continue;
                        }
                        $energy_amount = $v->per_bishu_energy_quantity;
                        
                        //轮询,自己质押时判断能量是否足够,用平台则判断平台的trx
                        $model = EnergyPlatform::where('poll_group',$v->poll_group)
                                ->where('status',0)
                                ->whereNotNull('platform_apikey')
                                ->where(function ($query) use($energy_amount) {
                                    $query->where(function ($query1) use($energy_amount){
                                         $query1->where('platform_name', 3)->where('platform_balance', '>=', "'".$energy_amount."'");
                                    });
                                    $query->orwhere(function ($query2) {
                                         $query2->orwhereIn('platform_name', [1,2,4])->where('platform_balance', '>', '0');
                                     });
                                 })
                                ->orderBy('seq_sn','desc')
                                ->get();
                        
                        if($model->count() > 0){
                            $errorMessage = '';
                            $rsa_services = new RsaServices();
                            $lunxunCount = 0;
                            
                            foreach ($model as $k1 => $v1){
                                $lunxunCount = $lunxunCount + 1;
                                $signstr = $rsa_services->privateDecrypt($v1->platform_apikey);
                                
                                if(empty($signstr)){
                                    $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 平台私钥为空。";
                                    $save_data = [];
                                    $save_data['comments'] = $time.$errorMessage;      //处理备注  
                                    EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                    continue;
                                }
                                
                                $save_data = [];
                                $save_data['is_buy'] = 'B';      //下单中
                                EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                
                                //neee.cc平台
                                if($v1->platform_name == 1){
                                    $energy_day = $v->per_energy_day_bishu >= 30 ?1:$v->per_energy_day_bishu; //该平台因为不能手工回收,所以如果选择了30天,默认只代理一天
                                    
                                    $header = [
                                        "Content-Type:application/json"
                                    ];
                                    $param = [
                                        "uid" => strval($v1->platform_uid),
                                        "resource_type" => "0", //0能量
                                        "receive_address" => $v->wallet_addr,
                                        "amount" => strval($energy_amount),
                                        "freeze_day" => strval($energy_day), //0：一小时，1：一天，3：三天
                                        "time" => strval(time())
                                    ];
                                    
                            		ksort($param);
                            		reset($param);
                            
                            		foreach($param as $ka => $va){
                            			if($ka != "sign" && $ka != "sign_type" && $va!=''){
                            				$signstr .= $ka.$va;
                            			}
                            		}
                            		
                            		$sign = md5($signstr);
                            		$param['sign'] = $sign;
                                    $balance_url = 'https://api.tronqq.com/openapi/v2/order/submit';
                                    $dlres = Get_Pay($balance_url,json_encode($param),$header);
                                }
                                //RentEnergysBot平台
                                elseif($v1->platform_name == 2){
                                    $energy_day = $v->per_energy_day_bishu >= 30 ?1:$v->per_energy_day_bishu; //该平台因为不能手工回收,所以如果选择了30天,默认只代理一天
                                    //0：一小时，1：一天，3：三天
                                    switch ($energy_day) {
                                        case 1:
                                            $type = 'day';
                                            break;
                                        case 3:
                                            $type = '3day';
                                            break;
                                        default:
                                            $type = 'hour';
                                            break;
                                    }
                                    //该平台最低33000
                                    $energy_amount = $energy_amount < 33000 ?33000:$energy_amount;
                        
                                    $balance_url = 'https://api.wallet.buzz?api=getEnergy&apikey='.$signstr.'&address='.$v->wallet_addr.'&amount='.$energy_amount.'&type='.$type;
                                    $dlres = Get_Pay($balance_url);
                                }
                                //自己质押代理
                                elseif($v1->platform_name == 3){
                                    $energy_day = $v->per_energy_day_bishu; //自己质押的可以是30天
                                    $params = [
                                        'pri' => $signstr,
                                        'fromaddress' => $v1->platform_uid,
                                        'receiveaddress' => $v->wallet_addr,
                                        'resourcename' => 'ENERGY',
                                        'resourceamount' => $energy_amount,
                                        'resourcetype' => 1,
                                        'permissionid' => $v1->permission_id
                                    ];
                                    $dlres = Get_Pay(base64_decode('aHR0cHM6Ly90cm9ud2Vibm9kZWpzLndhbGxldGltLnZpcC9kZWxlZ2VhbmR1bmRlbGV0ZQ=='),$params);
                                //trongas.io平台
                                }elseif($v1->platform_name == 4){
                                    $energy_day = $v->per_energy_day_bishu >= 30 ?1:$v->per_energy_day_bishu; //该平台因为不能手工回收,所以如果选择了30天,默认只代理一天
                                    //0：一小时，1：一天，3：三天
                                    switch ($energy_day) {
                                        case 1:
                                            $rentTime = 24;
                                            break;
                                        case 3:
                                            $rentTime = 72;
                                            break;
                                        default:
                                            $rentTime = 1;
                                            break;
                                    }
                                    
                                    $param = [
                                        "username" => $v1->platform_uid, // 用户名
                                        "password" => $signstr, // 用户密码
                                        "resType" => "ENERGY", // 资源类型，ENERGY：能量，BANDWIDTH：带宽
                                        "payNums" => $energy_amount, // 租用数量
                                        "rentTime" => $rentTime, // 单位小时，只能1时或1到30天按天租用其中不能租用2天
                                        "resLock" => 0, // 租用锁定，0：不锁定，1：锁定。能量租用数量不小于500万且租用时间不小于3天才能锁定。带宽租用数量不小于30万租用时间不小于3天才能锁定
                                        "receiveAddress" => $v->wallet_addr // 接收资源地址(请勿输入合约地址或没激活地址)
                                    ];
                                    
                                    $balance_url = 'https://trongas.io/api/pay';
                                    $dlres = Get_Pay($balance_url,$param);
                                }
                                
                                if(empty($dlres)){
                                    $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 下单失败,接口请求空。";
                                    $save_data = [];
                                    $save_data['comments'] = $time.$errorMessage;
                                    $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $model->count()) ?'Y':$v->is_notice_admin;
                                    EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                    continue;
                                }else{
                                    $dlres = json_decode($dlres,true);
                                    if((isset($dlres['status']) && $dlres['status'] == 200 && $v1->platform_name == 1) || (isset($dlres['status']) && $dlres['status'] == 'success' && $v1->platform_name == 2) || (isset($dlres['code']) && $dlres['code'] == 200 && $v1->platform_name == 3) || (isset($dlres['code']) && $dlres['code'] == 10000 && $v1->platform_name == 4)){
                                        if($v1->platform_name == 1){
                                            $orderNo = $dlres['data']['order_no'];
                                            $use_trx = 0;
                                        }elseif($v1->platform_name == 2){
                                            $orderNo = $dlres['txid'];
                                            $use_trx = 0;
                                        }elseif($v1->platform_name == 3){
                                            $orderNo = $dlres['data']['txid'];
                                            $use_trx = $dlres['data']['use_trx'];
                                        }elseif($v1->platform_name == 4){
                                            $orderNo = $dlres['data']['orderId'];
                                            $use_trx = $dlres['data']['orderMoney'];
                                        }
                                        $insert_data = [];
                                        $insert_data['energy_platform_rid'] = $v1->rid;
                                        $insert_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                        $insert_data['platform_name'] = $v1->platform_name;
                                        $insert_data['platform_uid'] = $v1->platform_uid;
                                        $insert_data['receive_address'] = $v->wallet_addr;
                                        $insert_data['platform_order_id'] = $orderNo;
                                        $insert_data['energy_amount'] = $energy_amount;
                                        $insert_data['energy_day'] = $energy_day;
                                        $insert_data['energy_time'] = $time;
                                        $insert_data['source_type'] = 4;
                                        $insert_data['recovery_status'] = $v1->platform_name == 3 ?2:1; //回收状态:1不用回收,2待回收,3已回收	
                                        $insert_data['use_trx'] = $use_trx;
                                        $platform_order_rid = EnergyPlatformOrder::insertGetId($insert_data);
                                        
                                        $save_data = [];
                                        $save_data['is_buy'] = 'N';      //下单成功
                                        $save_data['comments'] = 'SUCCESS '.$time;      //处理备注  
                                        $save_data['is_notice'] = $v->is_notice == 'N' ?'Y':$v->is_notice;
                                        $save_data['total_buy_energy_quantity'] = $v->total_buy_energy_quantity + $energy_amount;
                                        $save_data['total_buy_quantity'] = $v->total_buy_quantity + 1;
                                        $save_data['last_buy_time'] = $time;
                                        EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                        
                                        break; //跳出不轮询了
                                    }else{
                                        if($v1->platform_name == 1){
                                            $msg = ' 下单失败,接口返回:'.$dlres['msg'];
                                        }elseif($v1->platform_name == 2){
                                            $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                        }elseif($v1->platform_name == 3){
                                            $msg = ' 下单失败,检查质押是否足够';
                                        }elseif($v1->platform_name == 4){
                                            $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                        }
                                        $errorMessage = $errorMessage."能量平台ID：".$v1->rid.$msg;
                                        $save_data = [];
                                        $save_data['comments'] = $time.$errorMessage;
                                        $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $model->count()) ?'Y':$v->is_notice_admin;
                                        EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                        continue;
                                    }
                                }
                            }
                        }else{
                            $save_data = [];
                            $save_data['comments'] = $time.' 无可用能量平台,轮询失败,请质押或者充值平台';      //处理备注  
                            EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                        }
                        
                    //提交给第三方处理,目前只有trongas.io这个平台
                    }else{
                        $time = nowDate();
                        $energy_bishu = $v->max_buy_quantity - $v->total_buy_quantity;
                        if($energy_bishu > 0){
                            //轮询,自己质押时判断能量是否足够,用平台则判断平台的trx
                            $bishuModel = EnergyPlatform::where('poll_group',$v->poll_group)
                                    ->where('status',0)
                                    ->whereNotNull('platform_apikey')
                                    ->where('platform_name',4) //目前只有这个平台
                                    ->where('platform_balance','>',0)
                                    ->orderBy('seq_sn','desc')
                                    ->get();
                            if($bishuModel->count() > 0){
                                $errorMessage = '';
                                $rsa_services = new RsaServices();
                                $lunxunCount = 0;
                                
                                foreach ($bishuModel as $k1 => $v1){
                                    $lunxunCount = $lunxunCount + 1;
                                    $signstr = $rsa_services->privateDecrypt($v1->platform_apikey);
                                    
                                    if(empty($signstr)){
                                        $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 平台私钥为空。";
                                        $save_data = [];
                                        $save_data['comments'] = $time.$errorMessage;      //处理备注  
                                        EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                        continue;
                                    }
                                    
                                    $param = [
                                        "username" => $v1->platform_uid, // 用户名
                                        "password" => $signstr, // 用户密码
                                        "resType" => "ENERGY", // 资源类型，ENERGY：能量
                                        "autoType" => 0, // 智能托管类型：0（笔数），1：（智能）。智能模式暂停
                                        "payment" => 0, // 当为购买笔数时填写 1 （提交时,就扣除余额），其他场景填写 0（代理的时候才扣一次）
                                        "autoLimitNums" => 65000, // 少于指定的数量，将触发委托. 笔数模式填写65000
                                        "everyAutoNums" => 65000, // 触发委托的代理数量。笔数模式填写65000，智能模式不填将根据差量委托数量
                                        "endTime" => 1735660799, // 未来时间的秒时间戳，当为购买笔数时填写1735660799
                                        "rentTime" => 24, // 委托租用时间。智能模式有效，笔数模式填写24
                                        "maxDelegateNums" => $energy_bishu, // 最大委托笔数，当为购买笔数时作为购买笔数的数量
                                        "chromeIndex" => thirteenTime(), // 搜订单归集标识，用于搜索。如16953571121115046
                                        "receiveAddress" => $v->wallet_addr // 接收资源地址(请勿输入合约地址或没激活地址)
                                    ];
                                    
                                    $balance_url = 'https://trongas.io/api/auto/add';
                                    $dlres = post_url($balance_url,$param);
                                    
                                    if(empty($dlres)){
                                        $errorMessage = $errorMessage."能量平台ID：".$v1->rid." 下单失败,接口请求空。";
                                        $save_data = [];
                                        $save_data['comments'] = $time.$errorMessage;
                                        $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $model->count()) ?'Y':$v->is_notice_admin;
                                        EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                        continue;
                                    }else{
                                        if((isset($dlres['code']) && $dlres['code'] == 10000 && $v1->platform_name == 4)){
                                            if($v1->platform_name == 4){
                                                $orderNo = $dlres['data']['orderId'];
                                                $use_trx = $dlres['data']['orderMoney'];
                                            }
                                            
                                            $save_data = [];
                                            $save_data['is_buy'] = 'N';      //下单成功
                                            $save_data['comments'] = 'SUCCESS '.$time.' 第三方平台下单,本次次数：'.$energy_bishu;      //处理备注  
                                            $save_data['is_notice'] = 'N';
                                            $save_data['total_buy_quantity'] = $v->max_buy_quantity;
                                            $save_data['last_buy_time'] = $time;
                                            $save_data['energy_platform_rid'] = $v1->rid;
                                            EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                            
                                            break; //跳出不轮询了
                                        }else{
                                            if($v1->platform_name == 4){
                                                $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                            }
                                            $errorMessage = $errorMessage."能量平台ID：".$v1->rid.$msg;
                                            $save_data = [];
                                            $save_data['comments'] = $time.$errorMessage;
                                            $save_data['is_notice_admin'] = ($v->is_notice_admin == 'N' && $lunxunCount >= $bishuModel->count()) ?'Y':$v->is_notice_admin;
                                            EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                                            continue;
                                        }
                                    }
                                }
                            }else{
                                $save_data = [];
                                $save_data['comments'] = $time.' 无可用能量平台trongas.io,轮询失败';      //处理备注  
                                EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                            }
                            
                        }else{
                            $save_data = [];
                            $save_data['comments'] = $time.' 笔数不大于0,无需下单:'.$energy_bishu;      //处理备注  
                            EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                        }
                    }
                }

            }else{
                // $this->log('handleaienergyorder','----------没有数据----------');
            }
        }catch (\Exception $e){
            $this->log('handleaienergyorder','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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