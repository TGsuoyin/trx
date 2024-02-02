<?php

namespace App\Service;

class RsaServices
{
    protected $tronkey_public_key;       //tron密钥-公钥
    protected $tronkey_private_key;      //tron密钥-私钥
    protected $tronkey_key_len;          //tron密钥-公钥长度

    public function __construct()
    {
        // tron密钥-公钥
        $this->tronkey_public_key = config('secretkey.tronkey_publickey');

        // tron密钥-私钥
        $this->tronkey_private_key = config('secretkey.tronkey_privatekey');

        // tron密钥-公钥长度
        $tronkey_pub_id = openssl_get_publickey($this->tronkey_public_key);
        $this->tronkey_key_len = openssl_pkey_get_details($tronkey_pub_id)['bits'];
    }

    /**
     * 公钥加密
     * @param $data json数据
     */
    public function publicEncrypt($data)
    {
        $key_len = $this->tronkey_key_len;
        $public_key = $this->tronkey_public_key;

        $encrypted = '';
        $part_len = $key_len / 8 - 11;
        $parts = str_split($data, $part_len);

        foreach ($parts as $part) {
            $encrypted_temp = '';
            openssl_public_encrypt($part, $encrypted_temp, $public_key);
            $encrypted .= $encrypted_temp;
        }

        return $this->url_safe_base64_encode($encrypted);
    }

    /**
     * 私钥解密
     * @param $encrypted json数据
     */
    public function privateDecrypt($encrypted)
    {
        $key_len = $this->tronkey_key_len;
        $private_key = $this->tronkey_private_key;

        $decrypted = "";
        $part_len = $key_len / 8;
        $base64_decoded = $this->url_safe_base64_decode($encrypted);
        $parts = str_split($base64_decoded, $part_len);

        foreach ($parts as $part) {
            $decrypted_temp = '';
            openssl_private_decrypt($part, $decrypted_temp,$private_key);
            $decrypted .= $decrypted_temp;
        }
        return $decrypted;
    }

    /**
     * 私钥加密
     * @param $data json数据
     */
    public function privateEncrypt($data)
    {
        $key_len = $this->tronkey_key_len;
        $private_key = $this->tronkey_private_key;

        $encrypted = '';
        $part_len = $key_len / 8 - 11;
        $parts = str_split($data, $part_len);

        foreach ($parts as $part) {
            $encrypted_temp = '';
            openssl_private_encrypt($part, $encrypted_temp, $private_key);
            $encrypted .= $encrypted_temp;
        }

        return $this->url_safe_base64_encode($encrypted);
    }

    /**
     * 公钥解密
     * @param $encrypted json数据
     */
    public function publicDecrypt($encrypted)
    {
        $key_len = $this->tronkey_key_len;
        $public_key = $this->tronkey_public_key;

        $decrypted = "";
        $part_len = $key_len / 8;
        $base64_decoded = $this->url_safe_base64_decode($encrypted);
        $parts = str_split($base64_decoded, $part_len);

        foreach ($parts as $part) {
            $decrypted_temp = '';
            openssl_public_decrypt($part, $decrypted_temp,$public_key);
            $decrypted .= $decrypted_temp;
        }
        return $decrypted;
    }

    private function url_safe_base64_encode ($data) {
       // return str_replace(array('+','/', '='),array('-','_', ''), base64_encode($data));
        return base64_encode($data);
    }

    private function url_safe_base64_decode ($data) {
       // $base_64 = str_replace(array('-','_'),array('+','/'), $data);
        return base64_decode($data);
    }
}
