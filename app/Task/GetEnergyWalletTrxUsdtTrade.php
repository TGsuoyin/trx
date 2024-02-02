<?php
namespace App\Task;

use App\Service\Energy\EnergyWalletServices;
use App\Service\Energy\EnergyWalletTradeTrxServices;
use App\Service\Energy\EnergyWalletTradeUsdtServices;
use App\Model\Energy\EnergyWalletTradeList;
use App\Library\Log;
// use Swoole\Coroutine\WaitGroup;
use Hyperf\Utils\Coroutine\Concurrent;

class GetEnergyWalletTrxUsdtTrade
{
    public function execute()
    {
        try {
            $energyWallet_services = new EnergyWalletServices();
            $list = $energyWallet_services->getList();      //获取收款列表

            if(!empty($list)){
                try {
                    // $this->log('getenergywallettrxtrade','-----------开始执行:拉取钱包交易列表数据，钱包总数：'.count($list).'个--------------');
                    //协程通知
                    // $wg = new WaitGroup();
                    //协程数量
                    $concurrent = new Concurrent(5);
                    
                    foreach ($list as $k => $v) {
                        // $wg->add();

                        // go(function () use ($wg,$v) {
                        //     $energyWalletTradeTrx_services = new EnergyWalletTradeTrxServices();
                        //     $res = $this->handle($wg,$v,$energyWalletTradeTrx_services);
                            
                        //     $energyWalletTradeUsdt_services = new EnergyWalletTradeUsdtServices();
                        //     $res = $this->handleUsdt($wg,$v,$energyWalletTradeUsdt_services);
                            
                        //     $wg->done();
                        // });
                        
                        $concurrent->create(function () use ($v) {
                            // sleep(1); //不容易被api限制
                            $energyWalletTradeTrx_services = new EnergyWalletTradeTrxServices();
                            $res = $this->handle($v,$energyWalletTradeTrx_services);
                            
                            $energyWalletTradeUsdt_services = new EnergyWalletTradeUsdtServices();
                            $res = $this->handleUsdt($v,$energyWalletTradeUsdt_services);
                        });
                    }
                    // $wg->wait();

                    // $this->log('getenergywallettrxtrade','-----------结束执行:拉取钱包交易列表数据--------------');
                }catch (\Exception $e){
                    $this->log('getenergywallettrxtrade','拉取失败'.$e->getMessage().'----------');
                }
            }else{
                // $this->log('getenergywallettrxtrade','-----------没有钱包需要拉取交易数据--------');
            }
        }catch (\Exception $e){
            $this->log('getenergywallettrxtrade','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
    }

    public function handle($v,$energyWalletTradeTrx_services){
        try {
            $start_time = EnergyWalletTradeList::where('transferto_address',$v['receive_wallet'])->where('coin_name','trx')->orderBy('timestamp','desc')->value('timestamp');
            if(empty($start_time)){
                $start_time = 0;
            }
            $get_tx_time = strtotime($v['get_tx_time'])*1000-10;
            if($get_tx_time > $start_time){
                $start_time = $get_tx_time;
            }

            // 获取收款数据-通过tronscan获取===选一种拉取就行
            $res = $energyWalletTradeTrx_services->getList($v,$start_time,thirteenTime());
            // 获取收款数据-通过trongrid获取===选一种拉取就行
            $res = $energyWalletTradeTrx_services->getListByGrid($v,$start_time);

        }catch (\Exception $e){
            return ['code' => 400,'msg'=>$v['receive_wallet'].',拉取地址失败,'.$e->getMessage().'----------'];
        }
    }
    
    public function handleUsdt($v,$energyWalletTradeUsdt_services){
        try {
            $start_time = EnergyWalletTradeList::where('transferto_address',$v['receive_wallet'])->where('coin_name','usdt')->orderBy('timestamp','desc')->value('timestamp');
            if(empty($start_time)){
                $start_time = 0;
            }
            $get_tx_time = strtotime($v['get_tx_time'])*1000-10;
            if($get_tx_time > $start_time){
                $start_time = $get_tx_time;
            }

            // 获取收款数据
            $res = $energyWalletTradeUsdt_services->getList($v,$start_time);
            return ['code' => 200,'msg'=>'充值钱包地址：'.$v['receive_wallet'].'，成功总数'.$res['success_count'].'，失败总数'.$res['error_count']];
        }catch (\Exception $e){
            return ['code' => 400,'msg'=>$v['receive_wallet'].',拉取地址失败,'.$e->getMessage().'----------'];
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