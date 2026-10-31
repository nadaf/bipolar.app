<?php

namespace App\Domain\Day;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** 423: bloco privado precisa de desbloqueio (PIN ou senha) recente. */
final class StepUpRequired extends HttpException
{
    public function __construct()
    {
        parent::__construct(423, 'Desbloqueie para ver ou gravar este bloco privado.');
    }
}
