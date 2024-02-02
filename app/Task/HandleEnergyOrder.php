<?php
namespace App\Task;

use App\Model\Energy\EnergyWalletTradeList;
use App\Model\Energy\EnergyPlatformPackage;
use App\Model\Energy\EnergyPlatformOrder;
use App\Model\Energy\EnergyPlatform;
use App\Model\Energy\EnergyAiBishu;
use App\Service\RsaServices;
use App\Library\Log;

class HandleEnergyOrder
{
    public function execute()
    { 
        //trx闪租能量
        try {
            $data = EnergyWalletTradeList::from('energy_wallet_trade_list as a')
                ->join('energy_platform_bot as b','a.transferto_address','b.receive_wallet')
                ->where('a.process_status',1)
                ->where('a.coin_name','trx')
                ->select('a.rid','a.transferfrom_address','a.amount','b.poll_group','b.status','b.bot_rid','b.rid as energy_platform_bot_rid')
                ->limit(100)
                ->get();
                    
            if($data->count() > 0){
                $time = nowDate();
                
                foreach ($data as $k => $v) {
                    if($v->status == 1){
                        $save_data = [];
                        $save_data['process_status'] = 6;  //能量钱包未启用
                        $save_data['process_comments'] = '能量钱包未启用';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                        EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    //匹配金额
                    $res = EnergyPlatformPackage::where('bot_rid',$v->bot_rid)->where('trx_price',$v->amount)->first();
                    if(empty($res)){
                        $save_data = [];
                        $save_data['process_status'] = 7;  //金额无对应套餐
                        $save_data['process_comments'] = '金额无对应套餐';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                        EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    $energy_amount = $res->energy_amount;
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
                        
                        foreach ($model as $k1 => $v1){
                            $signstr = $rsa_services->privateDecrypt($v1->platform_apikey);
                            
                            if(empty($signstr)){
                                // $save_data = [];
                                // $save_data['process_status'] = 5;  //能量钱包未配置私钥
                                // $save_data['process_comments'] = '能量钱包未配置私钥2';      //处理备注  
                                // $save_data['process_time'] = $time;      //处理时间
                                // $save_data['energy_platform_rid'] = $v1->rid;
                                // $save_data['energy_package_rid'] = $res['rid'];
                                // $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                // EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                                $errorMessage = $errorMessage."能量平台：".$v1->platform_name." 平台私钥为空。";
                                $save_data = [];
                                $save_data['process_status'] = 5;      //下单失败
                                $save_data['process_comments'] = $errorMessage;      //处理备注  
                                $save_data['process_time'] = $time;      //处理时间
                                $save_data['energy_platform_rid'] = $v1->rid;
                                $save_data['energy_package_rid'] = $res['rid'];
                                $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                                continue;
                            }
                            
                            $save_data = [];
                            $save_data['process_status'] = 8;      //下单中
                            $save_data['process_comments'] = '下单中';      //处理备注  
                            $save_data['process_time'] = $time;      //处理时间
                            $save_data['energy_platform_rid'] = $v1->rid;
                            $save_data['energy_package_rid'] = $res['rid'];
                            $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                            EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                            
                            $energy_day = $res['energy_day'];
                            //neee.cc平台
                            if($v1->platform_name == 1){
                                $header = [
                                    "Content-Type:application/json"
                                ];
                                $param = [
                                    "uid" => strval($v1->platform_uid),
                                    "resource_type" => "0", //0能量
                                    "receive_address" => $v->transferfrom_address,
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
                    
                                $balance_url = 'https://api.wallet.buzz?api=getEnergy&apikey='.$signstr.'&address='.$v->transferfrom_address.'&amount='.$energy_amount.'&type='.$type;
                                $dlres = Get_Pay($balance_url);
                            }
                            //自己质押代理
                            elseif($v1->platform_name == 3){
                                $params = [
                                    'pri' => $signstr,
                                    'fromaddress' => $v1->platform_uid,
                                    'receiveaddress' => $v->transferfrom_address,
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
                                    "receiveAddress" => $v->transferfrom_address // 接收资源地址(请勿输入合约地址或没激活地址)
                                ];
                                
                                $balance_url = 'https://trongas.io/api/pay';
                                $dlres = Get_Pay($balance_url,$param);
                            }
                            
                            if(empty($dlres)){
                                // $save_data = [];
                                // $save_data['process_status'] = 4;      //下单失败
                                // $save_data['process_comments'] = '下单失败,接口请求空';      //处理备注  
                                // $save_data['process_time'] = $time;      //处理时间
                                // $save_data['energy_platform_rid'] = $v1->rid;
                                // $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                // EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                                $errorMessage = $errorMessage."能量平台：".$v1->platform_name." 能量平台接口返回为空。";
                                $save_data = [];
                                $save_data['process_status'] = 4;      //下单失败
                                $save_data['process_comments'] = $errorMessage;      //处理备注  
                                $save_data['process_time'] = $time;      //处理时间
                                $save_data['energy_platform_rid'] = $v1->rid;
                                $save_data['energy_package_rid'] = $res['rid'];
                                $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
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
                                    $insert_data['receive_address'] = $v->transferfrom_address;
                                    $insert_data['platform_order_id'] = $orderNo;
                                    $insert_data['energy_amount'] = $energy_amount;
                                    $insert_data['energy_day'] = $energy_day;	
                                    $insert_data['energy_time'] = $time;
                                    $insert_data['source_type'] = 2; //自动下单
                                    $insert_data['recovery_status'] = $v1->platform_name == 3 ?2:1; //回收状态:1不用回收,2待回收,3已回收	
                                    $insert_data['use_trx'] = $use_trx;
                                     
                                    $platform_order_rid = EnergyPlatformOrder::insertGetId($insert_data);
                                    $save_data = [];
                                    $save_data['process_status'] = 9;      //下单成功
                                    $save_data['process_comments'] = 'SUCCESS';      //处理备注  
                                    $save_data['platform_order_rid'] = $platform_order_rid;      //能量订单表ID	
                                    $save_data['process_time'] = $time;      //处理时间
                                    $save_data['energy_platform_rid'] = $v1->rid;
                                    $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                    $save_data['tg_notice_status_send'] = 'N';      //重新通知
                                    
                                    EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                                    break; //跳出不轮询了
                                }else{
                                    if($v1->platform_name == 1){
                                        $msg = '下单失败,接口返回:'.$dlres['msg'];
                                    }elseif($v1->platform_name == 2){
                                        $msg = '下单失败,接口返回:'.json_encode($dlres);
                                    }elseif($v1->platform_name == 3){
                                        $msg = '下单失败,检查质押是否足够';
                                    }elseif($v1->platform_name == 4){
                                        $msg = ' 下单失败,接口返回:'.json_encode($dlres);
                                    }
                                    $errorMessage = $errorMessage."能量平台：".$v1->platform_name.$msg;
                                    $save_data = [];
                                    $save_data['process_status'] = 4;      //下单失败
                                    $save_data['process_comments'] = $errorMessage;      //处理备注  
                                    $save_data['process_time'] = $time;      //处理时间
                                    $save_data['energy_platform_rid'] = $v1->rid;
                                    $save_data['energy_package_rid'] = $res['rid'];
                                    $save_data['energy_platform_bot_rid'] = $v->energy_platform_bot_rid;
                                    EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                                    continue;
                                }
                            }
                        }
                        
                    }else{
                        $save_data = [];
                        $save_data['process_status'] = 4;      //下单失败
                        $save_data['process_comments'] = "机器人无可用能量平台,请质押或者充值平台";      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                    }
                }

            }else{
                // $this->log('shanduibonus','----------没有数据----------');
            }
        }catch (\Exception $e){
            // $this->log('shanduibonus','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
        
        //usdt笔数套餐
        try {
            $data = EnergyWalletTradeList::from('energy_wallet_trade_list as a')
                ->join('energy_platform_bot as b','a.transferto_address','b.receive_wallet')
                ->leftJoin('telegram_bot as c','b.bot_rid','c.rid')
                ->where('a.process_status',1)
                ->where('a.coin_name','usdt')
                ->select('a.rid','a.transferfrom_address','a.amount','b.bot_rid','b.per_bishu_usdt_price','b.tg_notice_obj_send','c.bot_token','c.bot_username','c.bot_admin_username')
                ->limit(100)
                ->get();
                    
            if($data->count() > 0){
                $time = nowDate();
                
                foreach ($data as $k => $v) {
                    //查询笔数套餐钱包是否存在
                    $energyAiBishu = EnergyAiBishu::where('wallet_addr',$v->transferfrom_address)->first();
                    if($energyAiBishu){
                        $save_data = [];
                        $save_data['total_buy_usdt'] = $energyAiBishu->total_buy_usdt + $v->amount;
                        $save_data['max_buy_quantity'] = $energyAiBishu->max_buy_quantity + floor($v->amount / $v->per_bishu_usdt_price);
                        EnergyAiBishu::where('rid',$energyAiBishu->rid)->update($save_data);
                        
                    }else{
                        $insert_data = [];
                        $insert_data['bot_rid'] = $v->bot_rid;
                        $insert_data['wallet_addr'] = $v->transferfrom_address;
                        $insert_data['status'] = 0;
                        $insert_data['total_buy_usdt'] = $v->amount;
                        $insert_data['max_buy_quantity'] = floor($v->amount / $v->per_bishu_usdt_price);
                        $insert_data['create_time'] = $time;
                        EnergyAiBishu::insert($insert_data);
                    }
                    
                    $save_data = [];
                    $save_data['process_status'] = 9;      //下单成功
                    $save_data['process_comments'] = "成功,笔数套餐增加：".floor($v->amount / $v->per_bishu_usdt_price);      //处理备注  
                    $save_data['process_time'] = $time;      //处理时间
                    EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                    
                    //通知到群
                    if(!empty($v->tg_notice_obj_send) && $v->tg_notice_obj_send != ''){
                        $replytext = "<b>🖌新的笔数能量订单成功</b> \n"
                            ."➖➖➖➖➖➖➖➖\n"
                            ."<b>下单模式</b>：笔数套餐\n"
                            ."<b>能量次数</b>：". floor($v->amount / $v->per_bishu_usdt_price) ." 次\n"
                            ."<b>下单地址</b>：".mb_substr($v->transferfrom_address,0,8).'****'.mb_substr($v->transferfrom_address,-8,8) ."\n\n"
                            ."<b>按笔数购买能量，智能监控地址补足能量</b>\n"
                            ."发送 /buyenergy 继续购买能量！\n"
                            ."➖➖➖➖➖➖➖➖";
                        
                        //内联按钮
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '能量闪租', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '笔数套餐', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '智能托管', 'url' => 'https://t.me/'.$v->bot_username]
                                ],
                                [
                                    ['text' => '联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)],
                                    ['text' => 'TRX闪兑', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => 'TRX预支', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)]
                                ]
                            ]
                        ];
                        $encodedKeyboard = json_encode($keyboard);
                        
                        $sendlist = explode(',',$v->tg_notice_obj_send);
                    
                        foreach ($sendlist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            Get_Pay($sendmessageurl);
                        }
                        
                    }
                }

            }else{
                // $this->log('shanduibonus','----------没有数据----------');
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