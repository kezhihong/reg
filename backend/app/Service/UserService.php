<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\VerificationScene;
use App\Exception\BusinessException;
use App\Model\User;
use App\Util\Masker;
use Hyperf\DbConnection\Db;

/**
 * 用户资料服务（docs/02 §8）：资料查询与修改、手机号/邮箱绑定（验证码一次性兜底）。
 */
class UserService
{
    public function __construct(
        protected VerificationCodeService $codeService,
        protected AuditService $auditService
    ) {
    }

    /**
     * 8.1 当前用户（全部脱敏，docs/02 §8.1）。
     */
    public function me(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'nickname' => (string) $user->nickname,
            'avatar_url' => (string) $user->avatar_url,
            'phone' => (string) $user->phone !== '' ? Masker::phone((string) $user->phone) : '',
            'email' => (string) $user->email !== '' ? Masker::email((string) $user->email) : '',
            'is_phone_verified' => (int) $user->is_phone_verified === 1,
            'is_email_verified' => (int) $user->is_email_verified === 1,
            'kyc_level' => (int) $user->kyc_level,
            'totp_enabled' => (int) $user->totp_enabled === 1,
            'created_at' => (int) $user->created_at,
        ];
    }

    /**
     * 8.2 修改资料（docs/02 §8.2）：昵称 1–32 字符、头像域名白名单。
     */
    public function updateProfile(User $user, string $nickname, string $avatarUrl): array
    {
        if ($avatarUrl !== '') {
            if (! str_starts_with($avatarUrl, '/uploads/')) {
                // 外链头像：域名白名单校验（docs/02 §8.2）
                $host = parse_url($avatarUrl, PHP_URL_HOST);
                $allowed = ['localhost', '127.0.0.1'];
                $scheme = parse_url($avatarUrl, PHP_URL_SCHEME);
                if (! in_array($scheme, ['http', 'https'], true) || ($host !== null && ! in_array($host, $allowed, true) && ! str_ends_with((string) $host, '.gravatar.com'))) {
                    throw new BusinessException(AppErrorCode::PARAM_INVALID, '头像地址域名不在白名单内');
                }
            }
            // 本地上传头像（/uploads/avatars/...）直接放行（8.2b 上传接口已做白名单校验）
        }

        $user->nickname = $nickname;
        $user->avatar_url = $avatarUrl;
        $user->updated_at = time();
        $user->save();

        return $this->me($user);
    }

    /**
     * 8.2b 上传头像（设计规范 §1.7 [必须]）：
     * 扩展名 + MIME 白名单、大小上限（2MB）、内容魔数校验、
     * 服务端生成随机文件名、存储目录不可执行脚本。
     * 保存至固定目录 public/uploads/avatars/，URL = /uploads/avatars/{filename}。
     */
    public function uploadAvatar(User $user, \Hyperf\HttpMessage\Upload\UploadedFile $file): array
    {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize = 2 * 1024 * 1024; // 2MB

        // 大小上限
        if ($file->getSize() > $maxSize) {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '头像大小不能超过 2MB');
        }
        // 扩展名白名单
        $ext = strtolower((string) pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (! in_array($ext, $allowedExt, true)) {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '不支持的图片格式（jpg/png/webp/gif）');
        }
        // 内容魔数校验（权威校验：客户端 MIME 可伪造/可能为 octet-stream，
        // 扩展名 + 魔数双白名单足以防伪造；设计规范 §1.7）
        $realMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer((string) file_get_contents((string) $file->getRealPath()));
        if (! in_array($realMime, $allowedMime, true)) {
            throw new BusinessException(AppErrorCode::PARAM_INVALID, '文件内容与图片格式不符');
        }

        // 固定目录 + 服务端随机文件名（不信任客户端文件名）
        $dir = BASE_PATH . '/public/uploads/avatars';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $file->moveTo($dir . '/' . $filename);

        // 更新头像地址（相对路径，前端同源展示）
        $user->avatar_url = '/uploads/avatars/' . $filename;
        $user->updated_at = time();
        $user->save();

        return $this->me($user);
    }

    /**
     * 8.3 绑定/更换手机号（docs/02 §8.3）：新手机号验证码（scene=4）→ 唯一约束兜底。
     */
    public function bindPhone(User $user, string $countryCode, string $phone, string $code): array
    {
        $this->codeService->verify($countryCode, $phone, VerificationScene::BIND_PHONE, $code);

        $exists = User::query()
            ->where('country_code', $countryCode)
            ->where('phone', $phone)
            ->where('is_deleted', 0)
            ->where('id', '<>', $user->id)
            ->exists();
        if ($exists) {
            throw new BusinessException(AppErrorCode::USER_PHONE_BOUND);
        }

        $user->country_code = $countryCode;
        $user->phone = $phone;
        $user->is_phone_verified = 1;
        $user->updated_at = time();
        $user->save();

        return $this->me($user);
    }

    /**
     * 8.4 绑定/更换邮箱（docs/02 §8.4）：新邮箱验证码（scene=5）。
     */
    public function bindEmail(User $user, string $email, string $code): array
    {
        $row = Db::table('verification_codes')
            ->where('scene', VerificationScene::CHANGE_EMAIL)
            ->where('email', $email)
            ->orderByDesc('id')
            ->first();
        if ($row === null || (int) $row->consumed_at > 0) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_USED);
        }
        if ((int) $row->expires_at < time()) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_EXPIRED);
        }
        if (! \App\Util\SecurityUtil::equals((string) $row->code_hash, \App\Util\SecurityUtil::hash((string) $row->salt, $code))) {
            throw new BusinessException(AppErrorCode::AUTH_CODE_WRONG);
        }
        Db::table('verification_codes')
            ->where('id', $row->id)
            ->where('consumed_at', 0)
            ->update(['consumed_at' => time(), 'updated_at' => time()]);

        $exists = User::query()
            ->where('email', $email)
            ->where('is_deleted', 0)
            ->where('id', '<>', $user->id)
            ->exists();
        if ($exists) {
            throw new BusinessException(AppErrorCode::USER_EMAIL_BOUND);
        }

        $user->email = $email;
        $user->is_email_verified = 1;
        $user->updated_at = time();
        $user->save();

        return $this->me($user);
    }
}
