<?php

declare(strict_types=1);

namespace App\Util;

use App\Exception\ValidationException;

/**
 * 轻量参数校验器（设计规范 §1.5 [必须]）：白名单校验 + trim + 类型强转。
 * Controller 只做「取参 → 校验 → 调用 → 响应」，校验失败抛 ValidationException(400)。
 *
 * 用法：
 *   $input = Validator::validate($request->all(), [
 *       'username' => 'required|string|username',
 *       'password' => 'required|string|password',
 *       'scene'    => 'required|int|enum:1,2,3',
 *       'code'     => 'optional|string|len:6',
 *   ]);
 */
final class Validator
{
    /**
     * @param array $input  原始输入
     * @param array $rules  字段规则（'field' => 'rule1|rule2:arg'）
     * @return array 清洗后的参数（仅保留规则中声明的字段，白名单）
     */
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $result = [];

        foreach ($rules as $field => $ruleStr) {
            $hasValue = array_key_exists($field, $input);
            $value = $hasValue ? $input[$field] : null;

            // 类型规范化（字符串 trim；数字/布尔强转）
            if ($hasValue) {
                $value = self::normalize($value);
            }

            $ruleList = explode('|', $ruleStr);
            $required = in_array('required', $ruleList, true);

            if (! $hasValue || $value === '' || $value === null) {
                if ($required) {
                    $errors[$field] = $field . ' 为必填项';
                }
                // 未提供或为空的可选项：不进入后续规则
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required') {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                if (! self::check($name, $arg, $value)) {
                    $errors[$field] = self::message($field, $name, $arg);
                    break;
                }
            }

            $result[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $result;
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value;
        }
        return $value;
    }

    private static function check(string $name, ?string $arg, mixed $value): bool
    {
        return match ($name) {
            'string' => is_string($value),
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($value),
            'bool', 'boolean' => is_bool($value) || $value === '0' || $value === '1' || $value === 'true' || $value === 'false',
            'min' => is_string($value) ? mb_strlen($value) >= (int) $arg : (float) $value >= (float) $arg,
            'max' => is_string($value) ? mb_strlen($value) <= (int) $arg : (float) $value <= (float) $arg,
            'len' => mb_strlen((string) $value) === (int) $arg,
            'enum' => in_array((string) $value, explode(',', (string) $arg), true),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen((string) $value) <= 128,
            'phone' => (bool) preg_match('/^\d{11,15}$/', (string) $value),
            'country_code' => (bool) preg_match('/^\+\d{1,4}$/', (string) $value),
            'username' => (bool) preg_match('/^[A-Za-z0-9_]{3,32}$/', (string) $value),
            'password' => self::checkPassword((string) $value),
            'totp' => (bool) preg_match('/^\d{6}$/', (string) $value),
            'recovery_code' => (bool) preg_match('/^[a-f0-9]{8}$/i', (string) $value),
            'regex' => (bool) preg_match((string) $arg, (string) $value),
            default => true, // 注意：match 默认分支不带引号（'default' 是字符串键）
        };
    }

    /**
     * 密码规则：8–72 位，须含字母与数字（docs/02 §2.1；bcrypt 72 字节上限）。
     */
    private static function checkPassword(string $value): bool
    {
        $len = strlen($value);
        if ($len < 8 || $len > 72) {
            return false;
        }
        return (bool) preg_match('/[A-Za-z]/', $value) && (bool) preg_match('/\d/', $value);
    }

    private static function message(string $field, string $name, ?string $arg): string
    {
        return match ($name) {
            'string' => $field . ' 必须为字符串',
            'int' => $field . ' 必须为整数',
            'numeric' => $field . ' 必须为数字',
            'bool', 'boolean' => $field . ' 必须为布尔值',
            'min' => $field . ' 长度/数值不能小于 ' . $arg,
            'max' => $field . ' 长度/数值不能大于 ' . $arg,
            'len' => $field . ' 长度必须为 ' . $arg,
            'enum' => $field . ' 取值不合法',
            'email' => $field . ' 格式不正确',
            'phone' => $field . ' 格式不正确（11-15 位数字）',
            'country_code' => $field . ' 格式不正确（E.164 区号）',
            'username' => $field . ' 须为 3-32 位字母、数字或下划线',
            'password' => $field . ' 须为 8-72 位且包含字母与数字',
            'totp' => $field . ' 必须为 6 位数字',
            'recovery_code' => $field . ' 格式不正确',
            'regex' => $field . ' 格式不正确',
            default => $field . ' 不合法',
        };
    }
}
