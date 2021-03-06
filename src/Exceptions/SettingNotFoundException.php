<?php

namespace Scaleplan\Main\Exceptions;

/**
 * Class SettingNotFoundException
 *
 * @package Scaleplan\Main\Exceptions
 */
class SettingNotFoundException extends AbstractException
{
    public const MESSAGE = 'main.setting-not-found';
    public const CODE = 404;
}
