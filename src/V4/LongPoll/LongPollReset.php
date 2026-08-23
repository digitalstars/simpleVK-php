<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\LongPoll;

/**
 * Внутреннее исключение перезапуска LongPoll-сессии
 * (failed=1/2/3 в протоколе Long Poll API).
 */
final class LongPollReset extends \RuntimeException
{
    public function __construct(
        /** Новый ts из failed=1, если сервер его передал. */
        public readonly ?int $ts = null,
    ) {
        parent::__construct('LongPoll session reset');
    }
}
