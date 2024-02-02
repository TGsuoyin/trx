<?php

namespace App\Service\Bus;

use App\Service\AipHttpClient;
use App\Library\Log;

class TronServices
{
    /*** @var string $NET 网络*/
    private $NET;

    /*** @var string $OWNER_ADDRESS 平台账户 */
    private $OWNER_ADDRESS;

    /*** @var string $OWNER_PRIVATE_KEY 平台私钥 */
    private $OWNER_PRIVATE_KEY;

    private $HEADER;

    /*** @var string $CONTRACT_ADDRESS 合约地址 */
    private $CONTRACT_ADDRESS = [
        "USDT" => "TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t",
        "USDD" => "TPYmHEhy5n8TCEfYGqW2rPxsghSfzghPDn"
    ];

    private $tron;

    public function __construct($TronApiConfig,$OWNER_ADDRESS,$OWNER_PRIVATE_KEY){
        $this->NET = $TronApiConfig['url'];
        $this->HEADER = [
            "Content-Type"=> "application/json",
            "TRON-PRO-API-KEY"=> $TronApiConfig['api_key']
        ];
        $this->OWNER_ADDRESS = $OWNER_ADDRESS;
        $this->OWNER_PRIVATE_KEY = $OWNER_PRIVATE_KEY;

        $fullNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->NET);
        $solidityNode = new \IEXBase\TronAPI\Provider\HttpProvider($this->NET);
        $eventServer = new \IEXBase\TronAPI\Provider\HttpProvider($this->NET);

        try {
            $this->tron = new \IEXBase\TronAPI\Tron($fullNode, $solidityNode, $eventServer);
        } catch (\IEXBase\TronAPI\Exception\TronException $e) {
            return ['code' => 400,'msg' => $e->getMessage()];
        }
    }

    /**
     * TRC20转账
     * @param $to
     * @param $amount
     * @param $symbol
     * @return mixed
     * @throws Exception
     */
    public function trc20Transaction($to, $amount, $symbol = "USDT"){
        $url = $this->NET . "/wallet/triggersmartcontract";
        $_to = fill0($this->tron->toHex($to));
        $_amount = fill0(decToHex(handleAmount($amount),false));

        $postData = [
            'contract_address' => $this->tron->toHex($this->CONTRACT_ADDRESS[$symbol]),
            'owner_address' => $this->tron->toHex($this->OWNER_ADDRESS),
            'function_selector' => "transfer(address,uint256)",
            'parameter' => $_to . $_amount,
            'call_value' => 0,
            'fee_limit' => 40000000
        ];

        $http = new AipHttpClient($this->HEADER);
        $transactionResult = $http->post($url, jsonEncode($postData));

        if ($transactionResult['code'] != 200){
            return ['code' => 400,'msg' => 'TRC20转账访问失败'];
        }

        $content = jsonDecode($transactionResult['content']);
        if(isset($content['result']['message'])){
            return ['code' => 400,'msg' => $this->tron->fromHex($content['result']['message'])];
        }

        $data = $this->signAndBroadcast($this->tron, $content['transaction'], $this->OWNER_PRIVATE_KEY);

        if(isset($data['message'])){
            return ['code' => 400,'msg' => $this->tron->fromHex($data['message']),'data'=>$data];
        }else{
            return ['code' => 200,'data' => $data];
        }
    }

    /**
     * TRX转账
     * @param $to
     * @param $amount
     * @return mixed
     * @throws Exception
     */
    public function trxTransaction($to, $amount){
        try {
            $transaction = $this->tron->getTransactionBuilder()->sendTrx($to, (float)$amount, $this->OWNER_ADDRESS);
        }catch (\IEXBase\TronAPI\Exception\TronException $e){
            return ['code' => 400,'msg' => $e->getMessage()];
        }

        // 可能出现余额不足的情况
        if (isset($transaction['Error'])) {
            return ['code' => 400,'msg' => $transaction['Error']];
        }

        $data = $this->signAndBroadcast($this->tron,$transaction,$this->OWNER_PRIVATE_KEY);

        if(isset($data['result']) && $data['result'] == true){
            return ['code' => 200,'data' => $data];
        }else{
            return ['code' => 400,'data' => '转账失败'];
        }
        return $data;
    }
    
    /**
     * 获取TRX账户余额
     * @param $address
     * @return float
     */
    public function getAccount($address){
        $url = $this->NET . "/wallet/getaccount";
        $postData = [
            "address" => $address,
            "visible" => true
        ];
        $http = new AipHttpClient($this->HEADER);
        $result  = $http->post($url,jsonEncode($postData));

        $content = jsonDecode($result['content']);
        if (!isset($content['balance'])){
            $balance = 0;
        }else{
            $balance = $this->tron->fromTron($content['balance']);
        }
        return $balance;
    }

    /**
     * 获取Trc20余额
     * @param $address
     * @param $symbol
     * @return float|int
     */
    public function getTrc20Balance($address,$symbol = "USDT"){
        $url = $this->NET . "/wallet/triggersmartcontract";
        $_address = fill0($this->tron->toHex($address));

        $postData = [
            'contract_address' => $this->tron->toHex($this->CONTRACT_ADDRESS[$symbol]),
            'owner_address' => $this->tron->toHex($this->OWNER_ADDRESS),
            'function_selector' => "balanceOf(address)",
            'parameter' => $_address
        ];

        $http = new AipHttpClient($this->HEADER);
        $transactionResult = $http->post($url, jsonEncode($postData));

        $content = jsonDecode($transactionResult['content']);
        $constant_result = $content['constant_result'][0];
        # 移除前面的000占位符
        $s = preg_replace('/^0*/', '', $constant_result);

        // 为空 0 || 16进制转10进制后转换为一个trx单位的数字
        return empty($s) ? 0 : $this->tron->fromTron(hexToDec($s));
    }

    /**
     * 签名并且广播
     * @param Tron $tron
     * @param $transaction
     * @param $privateKey
     * @return mixed
     * @throws Exception
     */
    private  function signAndBroadcast($tron,$transaction,$privateKey){
        $tron->setPrivateKey($privateKey);
        try {
            # 签名
            $signTransaction = $tron->signTransaction($transaction);
        }catch (TronException $e){
            return ['code' => 400,'msg' => "签名并且广播:". $e->getMessage()];
        }

        # 广播签名后的事务
        return $tron->sendRawTransaction($signTransaction);
    }

    /**
     * 生成地址并激活
     * @return array
     * @throws \IEXBase\TronAPI\Exception\TronException
     * @throws \think\Exception
     */
    public function createAddress(){
        # 新建一个账户
        $account = $this->tron->createAccount();

        # 地址
        $address = $account->getAddress(true);
        # 公钥
        $publicKey = $account->getPublicKey();
        # 私钥
        $privateKey = $account->getPrivateKey();
        
        return compact("address","publicKey","privateKey");
    }
    
    /**
     * 获取币种余额(2.0)
     * @param $address
     * @param $symbol   币种
     * @param $contract_address   币种合约
     * @return float|int
     */
    public function newGetTrc20Balance($address,$symbol,$contract_address){
        $url = $this->NET . "/wallet/triggersmartcontract";
        $_address = fill0($this->tron->toHex($address));

        $postData = [
            'contract_address' => $this->tron->toHex($contract_address),
            'owner_address' => $this->tron->toHex($this->OWNER_ADDRESS),
            'function_selector' => "balanceOf(address)",
            'parameter' => $_address
        ];

        $http = new AipHttpClient($this->HEADER);
        $transactionResult = $http->post($url, jsonEncode($postData));

        $content = jsonDecode($transactionResult['content']);
        if(!isset($content['constant_result'][0])){
            return 0;
        }
        $constant_result = $content['constant_result'][0];
        # 移除前面的000占位符
        $s = preg_replace('/^0*/', '', $constant_result);

        // 为空 0 || 16进制转10进制后转换为一个trx单位的数字
        return empty($s) ? 0 : $this->tron->fromTron(hexToDec($s));
    }
    
    /**
     * 地址从hex转为base58
     * @param $address 41a614f803b6fd780986a42c78ec9c7f77e6ded13c
     * @return string
     */
    public function addressFromHex($address){
        return $this->tron->fromHex($address);
    }
    
    /**
     * data中的金额转换为十进制
     * @param $amount 0000000000000000000000000000000000000000000000000000000000155cc0
     * @return string
     */
    public function dataAmountFormat($amount){
        # 移除前面的000占位符
        $s = preg_replace('/^0*/', '', $amount);

        // 为空 0 || 16进制转10进制后转换为一个trx单位的数字
        return empty($s) ? 0 : $this->tron->fromTron(hexToDec($s));
    }
    
    /**
     * 查区块交易
     * @param $block
     * @return string
     */
    public function getBlock($block){
        if(empty($block)){
            return $this->tron->getCurrentBlock();
        }else{
            return $this->tron->getBlockByNumber($block);
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
