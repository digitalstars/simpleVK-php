<?php

declare(strict_types=1);

namespace DigitalStars\SimpleVK;

/**
 * Версия библиотеки. Единственный источник истины;
 * релизный workflow подставляет git-тег в bootstrap, константа — для рантайм-проверок.
 */
final class Version
{
    public const string CURRENT = '4.0.0';
}
