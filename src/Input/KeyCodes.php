<?php

namespace SoloTerm\Vtail\Input;

/**
 * ANSI escape sequences and control characters for keyboard input.
 */
class KeyCodes
{
    public const UP = "\e[A";

    public const DOWN = "\e[B";

    public const PAGE_UP = "\e[5~";

    public const PAGE_DOWN = "\e[6~";

    public const CTRL_C = "\x03";

    public const CTRL_D = "\x04";

    public const SPACE = ' ';
}
