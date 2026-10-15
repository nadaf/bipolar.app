<?php
const MOOD_LEVELS = [
    -3 => ['Depressão grave', 'Grande prejuízo: dificuldade para sair da cama, trabalhar ou cuidar de si.', '▼▼▼'],
    -2 => ['Depressão moderada', 'Funciona com muito esforço; tristeza, lentidão, perda de interesse.', '▼▼'],
    -1 => ['Depressão leve / desânimo', 'Desânimo perceptível, mas sem prejuízo significativo.', '▼'],
     0 => ['Eutimia / estável', 'Humor equilibrado, dentro do seu habitual.', '●'],
     1 => ['Hipomania leve / elevado', 'Mais energia e otimismo, sem prejuízo.', '▲'],
     2 => ['Hipomania', 'Aceleração, menos sono, impulsividade com algum prejuízo.', '▲▲'],
     3 => ['Mania / euforia', 'Grande prejuízo: euforia ou irritação intensa, pensamento acelerado, riscos.', '▲▲▲'],
];
const PERIODS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite'];
const MOOD_COLORS = [-3 => '#3b4f9a', -2 => '#4f69b8', -1 => '#93a8e0', 0 => '#5fae8f', 1 => '#f2c46d', 2 => '#ef9a4b', 3 => '#c0392b'];
const INVITE_TTL_DAYS = 14;
const ROLES = ['psicologo' => 'Psicólogo(a)', 'psiquiatra' => 'Psiquiatra', 'familiar' => 'Familiar', 'outro' => 'Outro'];
function mood_label(int $m): string { return MOOD_LEVELS[$m][0] ?? '?'; }
