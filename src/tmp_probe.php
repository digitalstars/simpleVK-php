<?php

namespace DigitalStars\SimpleVK\Probe;

use PhpParser\ParserFactory;
use Psr\Log\NullLogger;

final class TmpProbe
{
    public function make(): object
    {
        return [(new ParserFactory())->createForNewestSupportedVersion(), new NullLogger()];
    }
}
