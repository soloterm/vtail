<?php

namespace SoloTerm\Vtail\Input;

class KeyCodes
{
    public const UP = "\e[A";

    public const DOWN = "\e[B";

    public const RIGHT = "\e[C";

    public const LEFT = "\e[D";

    public const PAGE_UP = "\e[5~";

    public const PAGE_DOWN = "\e[6~";

    public const HOME = "\e[H";

    public const END = "\e[F";

    // Alternative home/end sequences
    public const HOME_ALT = "\e[1~";

    public const END_ALT = "\e[4~";

    public const CTRL_C = "\x03";

    public const CTRL_D = "\x04";

    public const CTRL_X = "\x18";

    public const ESCAPE = "\e";

    public const ENTER = "\n";

    public const CARRIAGE_RETURN = "\r";

    public const SPACE = ' ';

    public const BACKSPACE = "\x7f";
}
