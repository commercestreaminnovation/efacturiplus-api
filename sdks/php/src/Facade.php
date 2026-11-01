<?php

namespace EFacturiPlus;

/** @mixin Client */
class Facade extends \Illuminate\Support\Facades\Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
