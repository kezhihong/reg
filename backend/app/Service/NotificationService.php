<?php

declare(strict_types=1);

namespace App\Service;

use App\Constants\AppErrorCode;
use App\Constants\NotificationStatus;
use App\Exception\BusinessException;
use App\Model\Notification;
use App\Util\Masker;

/**
 * 通知服务（docs/02 §9）：列表（不返回 content）与详情（仅本人）。
 */
class NotificationService
{
    /**
     * 9.1 通知列表（游标分页，docs/02 §9.1）。
     */
    public function list(int $userId, int $cursor, int $perPage): array
    {
        $query = Notification::query()->where('user_id', $userId)->where('is_deleted', 0);
        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }
        $rows = $query->orderByDesc('id')->limit($perPage + 1)->get();

        $items = [];
        foreach ($rows as $row) {
            if (count($items) >= $perPage) {
                break;
            }
            $items[] = [
                'id' => (int) $row->id,
                'scene' => (int) $row->scene,
                'channel' => (int) $row->channel,
                'title' => (string) $row->title,
                'status' => (int) $row->status,
                'sent_at' => (int) $row->sent_at,
                'created_at' => (int) $row->created_at,
            ];
        }
        $nextCursor = count($rows) > $perPage ? (int) end($items)['id'] : null;

        return ['items' => $items, 'next_cursor' => $nextCursor];
    }

    /**
     * 9.2 通知详情（docs/02 §9.2）：仅本人，收件地址脱敏。
     */
    public function detail(int $userId, int $id): array
    {
        $row = Notification::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('is_deleted', 0)
            ->first();
        if ($row === null) {
            throw new BusinessException(AppErrorCode::NOTIFICATION_NOT_FOUND);
        }

        $recipient = (string) $row->recipient;
        $masked = str_contains($recipient, '@')
            ? Masker::email($recipient)
            : Masker::phone($recipient);

        return [
            'id' => (int) $row->id,
            'scene' => (int) $row->scene,
            'channel' => (int) $row->channel,
            'title' => (string) $row->title,
            'recipient' => $masked,
            'content' => (string) $row->content,
            'status' => (int) $row->status,
            'sent_at' => (int) $row->sent_at,
            'created_at' => (int) $row->created_at,
        ];
    }
}
