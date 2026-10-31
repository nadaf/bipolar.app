<?php

namespace App\Domain\Care;

/**
 * Bits de categoria das concessoes (iguais ao openapi CareGrant.scope_mask).
 * Padrao: humor + sono + estresse/alimentacao. Modulos sensiveis exigem marcacao explicita.
 * A anotacao livre so e compartilhada com o bit THOUGHTS (pensamentos).
 */
final class CareScope
{
    public const MOOD = 1;

    public const SLEEP = 2;

    public const STRESS_FOOD = 4;

    public const THOUGHTS = 8;

    public const SUBSTANCES = 16;

    public const SEXUALITY = 32;

    public const DEATH = 64;

    public const RELIGION = 128;

    public const DEFAULT = self::MOOD | self::SLEEP | self::STRESS_FOOD;

    public const SENSITIVE = self::SUBSTANCES | self::SEXUALITY | self::DEATH | self::RELIGION;
}
