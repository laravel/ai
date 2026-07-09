<?php

namespace Laravel\Ai\Enums;

enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Required = 'required';
    case Tool = 'tool';
}
