<?php

declare(strict_types=1);

namespace App\Util;

use App\Exception\BusinessException;

/**
 * 加解密服务：AES-256-GCM（docs/04 §2.3/§2.4，密钥来自环境变量 TOTP_ENCRYPTION_KEY）。
 * 用于 TOTP secret、OAuth 第三方 token、PKCE code_verifier 密文存储。
 */
class CryptoService
{
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct()
    {
        $key = (string) env('TOTP_ENCRYPTION_KEY', '');
        if (strlen($key) < 32) {
            throw new BusinessException(10008, 'TOTP_ENCRYPTION_KEY 未配置或长度不足');
        }
        $this->key = base64_decode($key) ?: $key;
        if (strlen($this->key) !== 32) {
            $this->key = hash('sha256', $key, true);
        }
    }

    /**
     * 加密：返回 base64(nonce || ciphertext || tag)。
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            throw new BusinessException(10008, '加密失败');
        }
        return base64_encode($nonce . $ciphertext . $tag);
    }

    /**
     * 解密：输入 encrypt() 的输出；失败抛业务异常（不返回明文）。
     */
    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new BusinessException(10008, '密文格式非法');
        }
        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new BusinessException(10008, '解密失败（密钥可能已轮换）');
        }
        return $plaintext;
    }
}
