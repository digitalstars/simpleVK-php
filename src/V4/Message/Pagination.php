<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\V4\Message;

/**
 * Билдер клавиатуры-пагинации: нарезает список кнопок по страницам
 * и добавляет строку навигации (◀️ ▶️) с payload для обработчика.
 *
 * Обработчик переключения страниц пишется пользователем:
 *   #[OnCallbackPreg('/^paginate_(\d+)_(\d+)$/')] — action, target page.
 *
 * Пример:
 *   $keyboard = Pagination::keyboard($itemButtons, page: 2, perPage: 5);
 *   $msg->text("Каталог (стр. 2 из {$total})")->keyboard($keyboard)->send();
 */
final class Pagination
{
    private function __construct() {}

    /**
     * @param list<Button> $items Все элементы списка (кнопки со своими payload).
     * @param positive-int $page Текущая страница, начиная с 1.
     * @param positive-int $perPage Элементов на странице.
     * @param positive-int $columns Кнопок в ряду.
     * @param non-empty-string $action Ключ payload навигационных кнопок:
     *                                 ['action' => 'prev'|'next', 'page' => int].
     */
    public static function keyboard(
        array $items,
        int $page = 1,
        int $perPage = 5,
        int $columns = 1,
        string $action = 'pagination',
    ): Keyboard {
        $totalPages = \max(1, (int) \ceil(\count($items) / $perPage));
        $page = \min(\max(1, $page), $totalPages);

        $pageItems = \array_slice($items, ($page - 1) * $perPage, $perPage);

        // Строки элементов с учётом колонок
        $rows = [];
        foreach (\array_chunk($pageItems, $columns) as $chunk) {
            $rows[] = $chunk;
        }

        // Навигация: добавляется всегда, кроме случая единственной страницы без элементов
        if ($totalPages > 1) {
            $nav = [];
            if ($page > 1) {
                $nav[] = Button::callback('◀️', [$action => ['to' => $page - 1]]);
            }
            $nav[] = Button::callback("{$page} / {$totalPages}", [$action => ['noop' => true]]);
            if ($page < $totalPages) {
                $nav[] = Button::callback('▶️', [$action => ['to' => $page + 1]]);
            }
            $rows[] = $nav;
        }

        return self::assemble($rows);
    }

    /**
     * Собирает Keyboard из готовых рядов, минуя лимиты row() через исключения не бросается:
     * количество рядов контролируется параметрами пагинации.
     *
     * @param list<list<Button>> $rows
     */
    private static function assemble(array $rows): Keyboard
    {
        $keyboard = Keyboard::make();
        foreach ($rows as $row) {
            $keyboard->row(...$row);
        }

        return $keyboard;
    }
}
