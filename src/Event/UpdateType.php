<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Event;

/**
 * Типы событий LongPoll/Callback API VK.
 */
enum UpdateType: string
{
    case MessageNew = 'message_new';
    case MessageReply = 'message_reply';
    case MessageEdit = 'message_edit';
    case MessageTypingState = 'message_typing_state';
    case MessageAllow = 'message_allow';
    case MessageDeny = 'message_deny';
    case Callback = 'message_event'; // нажатие callback-кнопки
    case WallReplyNew = 'wall_reply_new';
    case WallReplyEdit = 'wall_reply_edit';
    case WallReplyDelete = 'wall_reply_delete';
    case WallPostNew = 'wall_post_new';
    case PhotoNew = 'photo_new';
    case VideoNew = 'video_new';
    case AudioNew = 'audio_new';
    case GroupJoin = 'group_join';
    case GroupLeave = 'group_leave';
    case UserBlock = 'user_block';
    case UserUnblock = 'user_unblock';
    case PollVoteNew = 'poll_vote_new';
    case LeadFormsNew = 'lead_forms_new';
    case AppPayload = 'app_payload';
    case MarketOrderNew = 'market_order_new';
    case MarketOrderEdit = 'market_order_edit';
    case Unknown = 'unknown';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Unknown;
    }
}
