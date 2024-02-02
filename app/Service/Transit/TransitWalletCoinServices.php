<?php

namespace App\Service\Transit;

use App\Model\Transit\TransitWalletCoin;

class TransitWalletCoinServices
{
    /**
     * 获取闪兑钱包列表
     * @param $type [0.读取 1.更新]
    */
    public function getList($type=0){
        $res = TransitWalletCoin::select('rid','transit_wallet_id','in_coin_name','out_coin_name','exchange_rate','kou_out_amount','min_transit_amount','max_transit_amount','comments','create_time')->orderBy('rid')->get();
        $data= array();
        if($res->count() > 0){
            $res = $res->toArray();
            foreach ($res as $key => $v) {
                $data[$v['rid']] = $v;
            }
        }
        return $data;
    }

    /**
     * 获取闪兑钱包ID和名称列表 [key为ID value为名称]
     * @param $type [0.列表格式1 1.列表格式2 2.列表格式3]
    */
    public function IDList($type=0){
        $data = $this->getList();
        $list = [];
        if(!empty($data)){
            switch ($type) {
                case 1:
                    foreach ($data as $k => $v) {
                        $list[$v['transit_wallet_id']][] = $v;
                    }
                    break;
                
                default:
                    foreach ($data as $k => $v) {
                        $list[$v['transit_wallet_id'].strtolower($v['in_coin_name'])] = $v;
                    }
                    break;
            }
        }

        return $list;
    }
}
