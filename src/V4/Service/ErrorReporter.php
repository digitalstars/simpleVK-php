<?php

namespace DigitalStars\SimpleVK\V4\Service;

use Psr\Log\LoggerInterface;

/**
 * Компактный отчёт об ошибках: разворачивает Throwable в короткое сообщение
 * с указанием строк кода проекта (фильтр путей), уведомляет разработчика
 * в VK и/или пишет в PSR-3 логгер.
 *
 * Порт идей v3 (setUserLogError/setTracePathFilter/shortTrace), но без
 * глобального стейта: экземпляр создаётся явно и подключается как middleware.
 */
final class ErrorReporter
{
    /** @var list<string> Подстроки: пути, которые попадают в сокращённый trace. */
    private array $tracePathFilters = [];

    private bool $shortTrace = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        /** ID пользователя VK для уведомлений; null — не отправлять. */
        private readonly ?int $notifyVkId = null,
        private readonly ?\Closure $messageSender = null,
        /** Заголовок приложения для сообщений об ошибках. */
        private readonly string $appTitle = 'App',
    ) {}

    /**
     * Показывать в trace только кадры, содержащие данные подстроки.
     */
    public function withTracePathFilter(string ...$substrings): self
    {
        $clone = clone $this;
        $clone->tracePathFilters = \array_values($substrings);
        $clone->shortTrace = true;

        return $clone;
    }

    /**
     * Короткий режим: только файл:строка верхнего кадра.
     */
    public function shortTrace(): self
    {
        $clone = clone $this;
        $clone->shortTrace = true;

        return $clone;
    }

    /**
     * Обработка исключения: лог + уведомление. Ничего не бросает.
     */
    public function report(\Throwable $e, string $contextDescription = ''): void
    {
        $compact = $this->compactMessage($e);

        $this->logger->error($contextDescription !== '' ? "{$contextDescription}: {$compact}" : $compact, [
            'exception' => $e::class,
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        if ($this->notifyVkId !== null && $this->messageSender instanceof \Closure) {
            try {
                ($this->messageSender)($this->userMessage($e, $contextDescription), $this->notifyVkId);
            } catch (\Throwable) {
                // Уведомление никогда не ломает основной поток.
            }
        }
    }

    /**
     * Middleware-обёртка: fn(callable $handler): callable.
     *
     * Использование:
     *   $reporter = new ErrorReporter($logger);
     *   $wrapped = $reporter->middleware();
     *   $wrapped(fn() => $bot->dispatch($update));
     */
    public function middleware(): callable
    {
        return function (callable $handler): callable {
            return function () use ($handler): void {
                try {
                    $handler(...\func_get_args());
                } catch (\Throwable $e) {
                    $this->report($e);
                }
            };
        };
    }

    /**
     * Компактное описание ошибки: класс + сообщение + место в коде проекта.
     */
    private function compactMessage(\Throwable $e): string
    {
        $lines = [
            \sprintf('💥 %s: %s', $e::class, $e->getMessage()),
        ];

        foreach ($this->filteredFrames($e) as $frame) {
            $lines[] = "  ↳ {$frame}";
        }

        if ($e->getPrevious() instanceof \Throwable) {
            $prev = $e->getPrevious();
            $lines[] = \sprintf('⬑ предыдущее: %s (%s:%d)', $prev->getMessage(), $prev->getFile(), $prev->getLine());
        }

        return \implode("\n", $lines);
    }

    /**
     * Сообщение пользователю-разработчику в VK (короче, чем в лог).
     */
    private function userMessage(\Throwable $e, string $context): string
    {
        $frames = $this->filteredFrames($e);
        $place = $frames[0] ?? $e->getFile() . ':' . $e->getLine();

        return (
            "⚠️ {$this->appTitle}: ошибка"
            . ($context !== '' ? " [{$context}]" : '')
            . "\n{$e->getMessage()}"
            . "\n📍 {$place}"
        );
    }

    /**
     * @return list<string>
     */
    private function filteredFrames(\Throwable $e): array
    {
        $frames = [];
        $full = [
            $e->getFile() . ':' . $e->getLine(),
            ...\array_map(
                static fn(array $f): string => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'),
                $e->getTrace(),
            ),
        ];

        foreach ($full as $frame) {
            if (!$this->shortTrace || $this->tracePathFilters === []) {
                $frames[] = $frame;
            } elseif ($this->matchesFilters($frame)) {
                $frames[] = $frame;
            }

            if (\count($frames) >= 3) {
                break;
            }
        }

        return \array_values(\array_unique($frames));
    }

    private function matchesFilters(string $frame): bool
    {
        foreach ($this->tracePathFilters as $filter) {
            if ($filter !== '' && \str_contains($frame, $filter)) {
                return true;
            }
        }

        return false;
    }
}
