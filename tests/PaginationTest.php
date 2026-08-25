<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK\Tests;

use DigitalStars\SimpleVK\Message\Button;
use DigitalStars\SimpleVK\Message\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    /** @return list<Button> */
    private function items(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = Button::callback("Товар $i", ['item' => $i]);
        }

        return $items;
    }

    public function testSlicesPageAndAddsNavigation(): void
    {
        // 12 товаров, по 5 на страницу → страница 2: товары 6..10 + навигация
        $keyboard = Pagination::keyboard($this->items(12), page: 2, perPage: 5);
        $array = $keyboard->toArray();

        self::assertCount(6, $array['buttons']); // 5 рядов элементов (columns=1) + навигация

        $itemLabels = [];
        foreach (\array_slice($array['buttons'], 0, 5) as $row) {
            foreach ($row as $button) {
                $itemLabels[] = $button['action']['label'];
            }
        }
        self::assertSame(['Товар 6', 'Товар 7', 'Товар 8', 'Товар 9', 'Товар 10'], $itemLabels);

        $navRow = end($array['buttons']);
        self::assertCount(3, $navRow);
        $navLabels = array_column(array_column($navRow, 'action'), 'label');
        self::assertSame(['◀️', '2 / 3', '▶️'], $navLabels);

        // Payload кнопок навигации несёт целевую страницу
        $nextPayload = json_decode($navRow[2]['action']['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['pagination' => ['to' => 3]], $nextPayload);

        $prevPayload = json_decode($navRow[0]['action']['payload'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['pagination' => ['to' => 1]], $prevPayload);
    }

    public function testFirstAndLastPagesOmitUnavailableDirection(): void
    {
        $first = Pagination::keyboard($this->items(10), page: 1, perPage: 4)->toArray();
        $labels = array_column(array_column(end($first['buttons']), 'action'), 'label');
        self::assertSame(['1 / 3', '▶️'], $labels);

        $last = Pagination::keyboard($this->items(10), page: 3, perPage: 4)->toArray();
        $labels = array_column(array_column(end($last['buttons']), 'action'), 'label');
        self::assertSame(['◀️', '3 / 3'], $labels);
    }

    public function testSinglePageHasNoNavigation(): void
    {
        $keyboard = Pagination::keyboard($this->items(3), page: 1, perPage: 5)->toArray();

        self::assertCount(3, $keyboard['buttons']); // только элементы, без ряда навигации
        $allLabels = [];
        foreach ($keyboard['buttons'] as $row) {
            foreach ($row as $button) {
                $allLabels[] = $button['action']['label'];
            }
        }
        self::assertSame(['Товар 1', 'Товар 2', 'Товар 3'], $allLabels);
    }

    public function testColumnsSplitItemsIntoRows(): void
    {
        $keyboard = Pagination::keyboard($this->items(9), page: 1, perPage: 9, columns: 3);
        $array = $keyboard->toArray();

        self::assertCount(3, $array['buttons']); // 3 ряда по 3 элемента, одна страница
        self::assertCount(3, $array['buttons'][0]);
    }

    public function testOutOfRangePageIsClamped(): void
    {
        $keyboard = Pagination::keyboard($this->items(10), page: 99, perPage: 4)->toArray();
        $nav = array_column(array_column(end($keyboard['buttons']), 'action'), 'label');

        self::assertSame(['◀️', '3 / 3'], $nav); // последняя страница
    }
}
