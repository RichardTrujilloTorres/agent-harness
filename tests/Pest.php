<?php

declare(strict_types=1);

function fixture(string $path): string
{
    return __DIR__ . '/Fixtures/' . $path;
}

function projectRoot(): string
{
    return dirname(__DIR__);
}
