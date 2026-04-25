<?php

namespace Laravel\Ai\Gateway\Enums;

enum GeminiVoice: string
{
    case ZEPHYR = 'Zephyr';
    case PUCK = 'Puck';
    case CHARON = 'Charon';
    case KORE = 'Kore';
    case FENRIR = 'Fenrir';
    case LEDA = 'Leda';
    case ORUS = 'Orus';
    case AOEDE = 'Aoede';
    case CALLIRRHOE = 'Callirrhoe';
    case AUTONOE = 'Autonoe';
    case ENCELADUS = 'Enceladus';
    case IAPETUS = 'Iapetus';
    case UMBRIEL = 'Umbriel';
    case ALGIEBA = 'Algieba';
    case DESPINA = 'Despina';
    case ERINOME = 'Erinome';
    case ALGENIB = 'Algenib';
    case RASALGETHI = 'Rasalgethi';
    case LAOMEDEIA = 'Laomedeia';
    case ACHERNAR = 'Achernar';
    case ALNILAM = 'Alnilam';
    case SCHEDAR = 'Schedar';
    case GACRUX = 'Gacrux';
    case PULCHERRIMA = 'Pulcherrima';
    case ACHIRD = 'Achird';
    case ZUBENELGENUBI = 'Zubenelgenubi';
    case VINDEMIATRIX = 'Vindemiatrix';
    case SADACHBIA = 'Sadachbia';
    case SADALTAGER = 'Sadaltager';
    case SULAFAT = 'Sulafat';

    public static function all(): array
    {
        return array_map(fn (self $voice) => $voice->value, self::cases());
    }

    public static function defaultFemale(): self
    {
        return self::KORE;
    }

    public static function defaultMale(): self
    {
        return self::PUCK;
    }

    public static function random(): self
    {
        $voices = self::cases();

        return $voices[array_rand($voices)];
    }
}
