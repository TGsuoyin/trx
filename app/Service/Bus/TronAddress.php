<?php
namespace App\Service\Bus;

use Web3\Utils;
use IEXBase\TronAPI\Tron;
use IEXBase\TronAPI\Provider\HttpProvider;
use IEXBase\TronAPI\Exception\TronException;
use App\Library\Log;

class TronAddress
{
    private $hex;
    private $base58;

    public function __construct(string $address)
    {
        $fullNode = new HttpProvider('https://api.trongrid.io');
        $solidityNode = new HttpProvider('https://api.trongrid.io');
        $eventServer = new HttpProvider('https://api.trongrid.io');
        try {
            $this->tron = new Tron($fullNode, $solidityNode, $eventServer);
        } catch (TronException $exception) {
            $this->tron = null;
        }
        
        if ($address === '0x0000000000000000000000000000000000000000') {
            $this->hex = null;
            $this->base58 = null;
        } else {
            if (Utils::isHex($address)) {
                if (substr($address, 0, 2) === '0x') {
                    //set prefix
                    $address = '41'.substr($address, 2);
                }

                $this->hex = $address;
                $this->base58 = $this->tron->hexString2Address($address);
            } else {
                $this->base58 = $address;
                $this->hex = $this->tron->address2HexString($address);
            }
        }
    }

    public function getHex()
    {
        return $this->hex;
    }

    public function getBase58()
    {
        return $this->base58;
    }

    public function __toString()
    {
        return json_encode(['hex' => $this->hex, 'base58' => $this->base58]);
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