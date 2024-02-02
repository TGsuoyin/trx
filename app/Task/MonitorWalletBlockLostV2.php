<?php
namespace App\Task;

use App\Model\Monitor\MonitorWallet;
use App\Library\Log;
use App\Service\Bus\TronServices;

class MonitorWalletBlockLostV2
{
    public function execute()
    { 
        try {
            $lostblock = json_decode(getRedis('lostblock'),true) ?? [];
            
            if(!empty($lostblock)){
                $data = MonitorWallet::from('monitor_wallet as a')
                    ->Join('telegram_bot as b','a.bot_rid','b.rid')
                    ->where('a.status',0)
                    ->whereNotNull('a.monitor_wallet')
                    ->where('a.chain_type','trc')
                    ->select('a.monitor_wallet','a.tg_notice_obj','b.bot_token','a.comments','a.monitor_usdt_transaction','a.monitor_trx_transaction','a.monitor_approve_transaction','a.monitor_multi_transaction','a.monitor_pledge_transaction')
                    ->get()
                    ->toArray();
                    
                if(!empty($data) && !empty($lostblock)){
                    $api_key = config('apikey.gridapikey');
                    $apikeyrand = $api_key[array_rand($api_key)];
                    
                    //波场接口API
                    $TronApiConfig = [
                        'url' => 'https://api.trongrid.io',
                        'api_key' => $apikeyrand,
                    ]; 
                    
                    $tron = new TronServices($TronApiConfig,'1111111','222222');
                    $tronres = $tron->getBlock(current($lostblock));
                    
                    if(!empty($tronres['transactions'])){
                        $currentblock = $tronres['block_header']['raw_data']['number'];
                        $blocktimestamp = $tronres['block_header']['raw_data']['timestamp'];
                        
                        array_shift($lostblock);
                        setRedis('lostblock',json_encode($lostblock));
                        
                        //区块的交易详细
                        foreach ($tronres['transactions'] as $x => $y) {
                            //如果是合约事件
                            if($y['raw_data']['contract'][0]['type'] == 'TriggerSmartContract'){
                                $dataaa = $y['raw_data']['contract'][0]['parameter']['value']['data'];
                                $contract_address = $y['raw_data']['contract'][0]['parameter']['value']['contract_address']; //USDT:41a614f803b6fd780986a42c78ec9c7f77e6ded13c
                                
                                //取合约的transfer方法
                                if(in_array(mb_substr($dataaa,0,8),['d73dd623','a9059cbb','095ea7b3']) && $contract_address == '41a614f803b6fd780986a42c78ec9c7f77e6ded13c'){
                                    $toaddress = $tron->addressFromHex('41' . mb_substr($dataaa,32,40));
                                    $fromaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['owner_address']);
                                    $amount = $tron->dataAmountFormat(mb_substr($dataaa,72,64));
                                    
                                    //转入地址是否在监控列表
                                    $isto = array_search($toaddress,array_column($data,'monitor_wallet'));
                                    $isfrom = array_search($fromaddress,array_column($data,'monitor_wallet'));
                                    
                                    //如果是转入
                                    if(($isto !== false && $amount > 0 && mb_substr($dataaa,0,8) == 'a9059cbb') || ($isto !== false && mb_substr($dataaa,0,8) != 'a9059cbb')){
                                        $contractret = $y['ret'][0]['contractRet'];
                                        $found_obj = $data[$isto];
                                        $type = mb_substr($dataaa,0,8) == 'a9059cbb' ?'1':($amount == 0 ?'11':'12');
                                        
                                        //判断功能开关
                                        if(($type == 1 && mb_substr($found_obj['monitor_usdt_transaction'],0,1) == 'Y') || ($type == 11 && mb_substr($found_obj['monitor_approve_transaction'],1,1) == 'Y') ||  ($type == 12 && mb_substr($found_obj['monitor_approve_transaction'],0,1) == 'Y')){
                                            $this->sendTgMessage($contractret,$toaddress,$type,$fromaddress,$toaddress,'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                        }
                                    }
                                    
                                    //如果是转出
                                    if(($isfrom !== false && $amount > 0 && mb_substr($dataaa,0,8) == 'a9059cbb') || ($isfrom !== false && mb_substr($dataaa,0,8) != 'a9059cbb')){
                                        $contractret = $y['ret'][0]['contractRet'];
                                        $found_obj = $data[$isfrom];
                                        $type = mb_substr($dataaa,0,8) == 'a9059cbb' ?'2':($amount == 0 ?'21':'22');
                                        
                                        //判断功能开关
                                        if(($type == 2 && mb_substr($found_obj['monitor_usdt_transaction'],1,1) == 'Y') || ($type == 21 && mb_substr($found_obj['monitor_approve_transaction'],1,1) == 'Y') ||  ($type == 22 && mb_substr($found_obj['monitor_approve_transaction'],0,1) == 'Y')){
                                            $this->sendTgMessage($contractret,$fromaddress,$type,$fromaddress,$toaddress,'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                        }
                                    }
                                    
                                //取合约的transferFrom方法
                                }elseif(mb_substr($dataaa,0,8) == '23b872dd' && $contract_address == '41a614f803b6fd780986a42c78ec9c7f77e6ded13c'){
                                    $toaddress = $tron->addressFromHex('41' . mb_substr($dataaa,96,40));
                                    $fromaddress = $tron->addressFromHex('41' . mb_substr($dataaa,32,40));
                                    $amount = $tron->dataAmountFormat(mb_substr($dataaa,136,64));
                                    
                                    //转入地址是否在监控列表
                                    $isto = array_search($toaddress,array_column($data,'monitor_wallet'));
                                    $isfrom = array_search($fromaddress,array_column($data,'monitor_wallet'));
                                    
                                    //如果是转入
                                    if($isto !== false && $amount > 0){
                                        $contractret = $y['ret'][0]['contractRet'];
                                        $found_obj = $data[$isto];
                                        
                                        //判断功能开关
                                        if(mb_substr($found_obj['monitor_usdt_transaction'],0,1) == 'Y'){
                                            $this->sendTgMessage($contractret,$toaddress,3,$fromaddress,$toaddress,'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                        }
                                    }
                                    
                                    //如果是转出
                                    if($isfrom !== false && $amount > 0){
                                        $contractret = $y['ret'][0]['contractRet'];
                                        $found_obj = $data[$isfrom];
                                        
                                        //判断功能开关
                                        if(mb_substr($found_obj['monitor_usdt_transaction'],1,1) == 'Y'){
                                            $this->sendTgMessage($contractret,$fromaddress,4,$fromaddress,$toaddress,'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                        }
                                    }
                                }
                            
                            // trx交易
                            }elseif($y['raw_data']['contract'][0]['type'] == 'TransferContract'){
                                $toaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['to_address']);
                                $fromaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['owner_address']);
                                $amount = calculationExcept($y['raw_data']['contract'][0]['parameter']['value']['amount'],6);
                                
                                //转入地址是否在监控列表
                                $isto = array_search($toaddress,array_column($data,'monitor_wallet'));
                                $isfrom = array_search($fromaddress,array_column($data,'monitor_wallet'));
                                
                                //如果是转入
                                if($isto !== false && $amount > 0){
                                    $contractret = $y['ret'][0]['contractRet'];
                                    $found_obj = $data[$isto];
                                    
                                    //判断功能开关
                                    if(mb_substr($found_obj['monitor_trx_transaction'],0,1) == 'Y'){
                                        $this->sendTgMessage($contractret,$toaddress,1,$fromaddress,$toaddress,'TRX',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                    }
                                }
                                
                                //如果是转出
                                if($isfrom !== false && $amount > 0){
                                    $contractret = $y['ret'][0]['contractRet'];
                                    $found_obj = $data[$isfrom];
                                    
                                    //判断功能开关
                                    if(mb_substr($found_obj['monitor_trx_transaction'],1,1) == 'Y'){
                                        $this->sendTgMessage($contractret,$fromaddress,2,$fromaddress,$toaddress,'TRX',$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                    }
                                }
                                
                            // 代理资源或者回收资源
                            }elseif(in_array($y['raw_data']['contract'][0]['type'],['UnDelegateResourceContract','DelegateResourceContract'])){
                                $toaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['receiver_address']);
                                $fromaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['owner_address']);
                                $amount = calculationExcept($y['raw_data']['contract'][0]['parameter']['value']['balance'],6);
                                $resource = $y['raw_data']['contract'][0]['parameter']['value']['resource'] ?? ' ';
                                
                                //转入地址是否在监控列表
                                $isto = array_search($toaddress,array_column($data,'monitor_wallet'));
                                $isfrom = array_search($fromaddress,array_column($data,'monitor_wallet'));
                                
                                //如果是转入
                                if($isto !== false && $amount > 0){
                                    $contractret = $y['ret'][0]['contractRet'];
                                    $found_obj = $data[$isto];
                                    $type = $y['raw_data']['contract'][0]['type'] == 'DelegateResourceContract' ?6:61;
                                    
                                    //判断功能开关
                                    if(($type == 6 && mb_substr($found_obj['monitor_pledge_transaction'],0,1) == 'Y') || ($type == 61 && mb_substr($found_obj['monitor_pledge_transaction'],1,1) == 'Y')){
                                        $this->sendTgMessage($contractret,$toaddress,$type,$fromaddress,$toaddress,$resource,$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                    }
                                }
                                
                                //如果是转出
                                if($isfrom !== false && $amount > 0){
                                    $contractret = $y['ret'][0]['contractRet'];
                                    $found_obj = $data[$isfrom];
                                    $type = $y['raw_data']['contract'][0]['type'] == 'DelegateResourceContract' ?7:71;
                                    
                                    //判断功能开关
                                    if(($type == 7 && mb_substr($found_obj['monitor_pledge_transaction'],0,1) == 'Y') || ($type == 71 && mb_substr($found_obj['monitor_pledge_transaction'],1,1) == 'Y')){
                                        $this->sendTgMessage($contractret,$fromaddress,$type,$fromaddress,$toaddress,$resource,$amount,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                    }
                                }
                                
                            // 账号更新权限
                            }elseif($y['raw_data']['contract'][0]['type'] == 'AccountPermissionUpdateContract'){
                                $fromaddress = $tron->addressFromHex($y['raw_data']['contract'][0]['parameter']['value']['owner_address']);
                                
                                $returnlist = '';
                                $isOwn_set = 'N'; //监控地址是否有在所有权限中
                                $isActive_set = 'N'; //监控地址是否有在活跃权限中
                                $isJiankong = 'N'; //是否监控地址变更权限
                                $isFound = 'N'; //是否找到监控钱包
                                
                                //监控地址变更权限
                                $isfrom = array_search($fromaddress,array_column($data,'monitor_wallet'));
                                
                                if($isfrom !== false){
                                    $found_obj = $data[$isfrom];
                                    $isJiankong = 'Y';
                                    $isFound = 'Y';
                                }
                                
                                //查询所有者权限
                                if(isset($y['raw_data']['contract'][0]['parameter']['value']['owner'])){
                                    $ownerPermission = $y['raw_data']['contract'][0]['parameter']['value']['owner'];
                                    $returnlist = $returnlist . "\n🟠🟠所有权限-阈值：".$ownerPermission['threshold']."🟠🟠\n";
                                    $ownerPermissionList = '';
                                    for($i=0;$i<count($ownerPermission['keys']);$i++){
                                        $ownerAddress = $tron->addressFromHex($ownerPermission['keys'][$i]['address']);
                                        
                                        //检测是否存在所有者地址权限
                                        $isOwn = array_search($ownerAddress,array_column($data,'monitor_wallet'));
                                        if($isOwn !== false){
                                            $isOwn_set = 'Y';
                                            if($isFound == 'N'){
                                                $found_obj = $data[$isOwn];
                                                $isFound == 'Y';
                                            }
                                        }
                                        
                                        $ownerPermissionList = $ownerPermissionList."地址：<code>".$ownerAddress."</code> (权重：".$ownerPermission['keys'][$i]['weight'].")\n";
                                    }
                                    $returnlist = $returnlist.$ownerPermissionList;
                                }
                                
                                //查询活跃权限
                                if(isset($y['raw_data']['contract'][0]['parameter']['value']['actives'])){
                                    $activePermissions = $y['raw_data']['contract'][0]['parameter']['value']['actives'];
                                    if(count($activePermissions) > 0){
                                        $returnlist = $returnlist . "\n🔴🔴活跃权限-共：".count($activePermissions)."个🔴🔴\n";
                                        for($i=0;$i<count($activePermissions);$i++){
                                            $activepermissionname = isset($activePermissions[$i]['permission_name']) ?$activePermissions[$i]['permission_name']:$activePermissions[$i]['type'];
                                            $returnlist = $returnlist . "第". ($i+1) ."个-权限名称：".$activepermissionname." 权限阈值：".$activePermissions[$i]['threshold']."\n";
                                            $activePermissionList = '';
                                            for($j=0;$j<count($activePermissions[$i]['keys']);$j++){
                                                $activeAddress = $tron->addressFromHex($activePermissions[$i]['keys'][$j]['address']);
                                                //检测是否存在活跃地址权限
                                                $isActive = array_search($activeAddress,array_column($data,'monitor_wallet'));
                                                if($isActive !== false){
                                                    $isActive_set = 'Y';
                                                    if($isFound == 'N'){
                                                        $found_obj = $data[$isActive];
                                                        $isFound == 'Y';
                                                    }
                                                }
                                                
                                                $activePermissionList = $activePermissionList."地址：<code>".$activeAddress."</code> (权重：".$activePermissions[$i]['keys'][$j]['weight'].")\n";
                                            }
                                            $returnlist = $returnlist.$activePermissionList;
                                        }
                                    }
                                }
                                
                                //判断发送消息
                                if($isfrom !== false || $isActive_set == 'Y' || $isOwn_set == 'Y'){
                                    $contractret = $y['ret'][0]['contractRet'];
                                    $type = $isJiankong == 'Y' ?5:51;
                                    
                                    //判断功能开关
                                    if(($type == 5 && mb_substr($found_obj['monitor_multi_transaction'],0,1) == 'Y') || ($type == 51 && mb_substr($found_obj['monitor_multi_transaction'],1,1) == 'Y')){
                                        $this->sendTgMessage($contractret,$found_obj['monitor_wallet'],$type,$fromaddress,'',$returnlist,0,$currentblock,$blocktimestamp,$y['txID'],$found_obj['tg_notice_obj'],$found_obj['bot_token'],$found_obj['comments']);
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
        }catch (\Exception $e){
            $this->log('monitorwallet','----------Lost任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
    }
    
    /**
     * 发送tg消息
    */
    protected function sendTgMessage($contractret,$monitoraddress,$type,$fromaddress,$toaddress,$contract_address,$value,$currentblock,$blocktimestamp,$txid,$tg_notice_obj,$bot_token,$comments){
        if($type == 1){
            $transtype = '正常转账 ↓';
        }elseif($type == 2){
            $transtype = '正常转账 ↑';
        }elseif($type == 3){
            $transtype = '授权转账 ↓';
        }elseif($type == 4){
            $transtype = '授权转账 ↑';
        }elseif($type == 11){
            $transtype = '给监控地址取消授权';
        }elseif($type == 21){
            $transtype = '监控地址给其他地址取消授权';
        }elseif($type == 12){
            $transtype = '给监控地址授权 ↓';
        }elseif($type == 22){
            $transtype = '监控地址给其他地址授权 ↑';
        }elseif($type == 5){
            $transtype = '监控地址变更多签账户';
        }elseif($type == 51){
            $transtype = '其他地址变更监控地址为多签';
        }elseif($type == 6){
            $transtype = '给监控地址代理质押 ↓';
        }elseif($type == 61){
            $transtype = '给监控地址回收质押 ↑';
        }elseif($type == 7){
            $transtype = '监控地址给其他地址代理质押 ↑';
        }elseif($type == 71){
            $transtype = '监控地址给其他地址回收质押 ↓';
        }else{
            $transtype = '其他';
        }
        
        if(empty($comments) || $comments == ''){
            $comments = '无';
        }
        
        if($type == 5 || $type == 51){
            $replytext = "监控钱包：<code>".$monitoraddress."</code>\n"
                    ."监控钱包备注：".$comments."\n"
                    ."变更钱包：<code>".$fromaddress."</code>\n"
                    ."---------------------------------------\n"
                    ."交易类型：<b>".$transtype."</b>\n"
                    ."交易结果：".$contractret."\n"
                    .$contract_address."\n"
                    ."---------------------------------------\n"
                    ."交易时间：<code>".date('Y-m-d H:i:s', $blocktimestamp/1000)."</code>\n"
                    ."当前区块号：<code>".$currentblock."</code>\n"
                    ."当前交易哈希：<code>".$txid."</code>\n";
        }elseif(in_array($type,[6,61,7,71])){
            $replytext = "监控钱包：<code>".$monitoraddress."</code>\n"
                    ."监控钱包备注：".$comments."\n"
                    ."---------------------------------------\n"
                    ."交易类型：<b>".$transtype."</b>\n"
                    ."交易结果：".$contractret."\n"
                    ."代理资源：".$contract_address."\n"
                    ."代理数量：".$value."\n"
                    ."---------------------------------------\n"
                    ."交易时间：<code>".date('Y-m-d H:i:s', $blocktimestamp/1000)."</code>\n"
                    ."当前区块号：<code>".$currentblock."</code>\n"
                    ."当前交易哈希：<code>".$txid."</code>\n";
        //转账
        }elseif(in_array($type,[1,2,3,4])){
            $replytext = "监控钱包：<code>".$monitoraddress."</code>\n"
                    ."监控钱包备注：".$comments."\n"
                    ."---------------------------------------\n"
                    ."<b>".(in_array($type,[1,3]) ?'🟢收入':'🔴支出').($contract_address == 'TRX' ?'TRX':'USDT')."提醒 ".(in_array($type,[1,3]) ?'+':'-').$value." ".($contract_address == 'TRX' ?'TRX':'USDT')."</b>\n\n"
                    ."付款地址：<code>".$fromaddress."</code>\n"
                    ."收款地址：<code>".$toaddress."</code>\n"
                    ."交易时间：<code>".date('Y-m-d H:i:s', $blocktimestamp/1000)."</code>\n"
                    ."交易金额：<b>".(in_array($type,[1,3]) ?'+':'-').$value." ".($contract_address == 'TRX' ?'TRX':'USDT')."</b>\n"
                    ."---------------------------------------\n"
                    ."<tg-spoiler>交易结果：".$contractret."\n"
                    ."交易类型：<b>".$transtype."</b></tg-spoiler>\n";
        }else{
            $replytext = "监控钱包：<code>".$monitoraddress."</code>\n"
                    ."监控钱包备注：".$comments."\n"
                    ."---------------------------------------\n"
                    ."转出地址：<code>".$fromaddress."</code>\n"
                    ."接收地址：<code>".$toaddress."</code>\n"
                    ."交易类型：<b>".$transtype."</b>\n"
                    ."交易金额：<b>".$value."</b>\n"
                    ."交易结果：".$contractret."\n"
                    ."---------------------------------------\n"
                    ."交易时间：<code>".date('Y-m-d H:i:s', $blocktimestamp/1000)."</code>\n"
                    ."合约地址：<code>".$contract_address."</code>\n"
                    ."当前区块号：<code>".$currentblock."</code>\n"
                    ."当前交易哈希：<code>".$txid."</code>\n";
        }
        
        $url = 'https://tronscan.io/#/transaction/'.$txid;
        
        //内联按钮
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '查看交易', 'url' => $url]
                ]
            ]
        ];
        
        $encodedKeyboard = json_encode($keyboard);
        
        $sendlist = explode(',',$tg_notice_obj);
        
        foreach ($sendlist as $x => $y) {
            $sendmessageurl = 'https://api.telegram.org/bot'.$bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
            
            Get_Pay($sendmessageurl);
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