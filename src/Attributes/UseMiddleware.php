<?php
declare(strict_types=1);

namespace DigitalStars\SimpleVK\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class UseMiddleware
{
    /**
     * @param class-string $middleware
     */
    public function __construct(public string $middleware)
    {
    }
}