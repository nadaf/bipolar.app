<?php
require __DIR__ . '/_boot.php';
page_head('Sobre a escala'); ?>
<article class="card narrow prose">
<h1>Sobre a escala de humor</h1>
<p>Usamos uma escala de 7 níveis inspirada no <strong>NIMH Life Chart Method (NIMH-LCM)</strong>, desenvolvido no Instituto Nacional de Saúde Mental dos EUA para acompanhar o transtorno bipolar. No LCM, a gravidade dos episódios é definida pelo <strong>prejuízo no funcionamento</strong> (leve, moderado, grave), tanto para o polo depressivo quanto para o maníaco, com a eutimia no centro.</p>
<ul class="scale-list">
<?php foreach (array_reverse(MOOD_LEVELS, true) as $v => $m): ?>
<li><span class="chip lvl<?= $v + 3 ?>"><span aria-hidden="true"><?= $m[2] ?></span> <?= $v > 0 ? '+' . $v : $v ?></span> <strong><?= e($m[0]) ?>:</strong> <?= e($m[1]) ?></li>
<?php endforeach; ?>
</ul>
<p>Registrar por período do dia (manhã, tarde e noite) ajuda a perceber <strong>ciclagem rápida</strong> e estados mistos, além da relação com o sono — um dos sinais mais precoces de virada para hipomania/mania. Irritabilidade, ansiedade e energia são registradas separadamente porque podem estar altas tanto na depressão quanto na mania.</p>
<h2>Um lembrete sobre o sono</h2>
<p>Horários regulares de dormir e acordar costumam ajudar muito na estabilidade do humor. Por isso não existe período “madrugada”: não é preciso ficar acordado(a) para registrar. Se a noite foi difícil, anote no dia seguinte — as horas de sono e uma anotação já contam essa história.</p>
<h2 id="alertas">Como funcionam os alertas automáticos</h2>
<p>De hora em hora o app compara seus últimos dias com o <strong>seu próprio padrão</strong> das 4 semanas anteriores (mediana e variação típica, que não se deixam levar por um dia fora da curva). Ele procura combinações de sinais descritas na literatura:</p>
<ul>
<li><strong>(Hipo)mania:</strong> sono bem abaixo do seu habitual e/ou humor elevado, somados a energia intensa, pensamentos ou fala acelerados, gastos, irritabilidade, desejo sexual acima do habitual, estimulantes, cafeína, deitar muito mais tarde, muitas notas em áudio/vídeo e seus sinais pessoais.</li>
<li><strong>Depressão:</strong> humor baixo na maioria dos últimos 7 dias, com mudanças de sono, energia e exposição ao sol.</li>
<li><strong>Estado misto:</strong> humor para baixo junto com aceleração ou irritabilidade, ou grandes variações no mesmo dia.</li>
<li><strong>Instabilidade / ciclagem rápida:</strong> muitas viradas entre humor alto e baixo em 14 dias, ou variabilidade bem acima da sua habitual.</li>
<li><strong>Também:</strong> vários dias sem registro, doses esquecidas, substâncias acima do habitual e seus sinais de alerta pessoais. Fase do ciclo menstrual e estresse aparecem como contexto.</li>
</ul>
<p>Cada alerta diz quais dados pesaram. Responder “faz sentido” ou “não faz sentido” ajusta a sensibilidade daquele tipo de alerta só para você. Regras, limitações e referências científicas: <a href="/como-funcionam-os-alertas.php">Como funcionam os alertas</a>.</p>
<p class="small">Esta escala é uma ferramenta de auto-observação, não de diagnóstico. Combine com seu psiquiatra como interpretar seus registros.</p>
</article>
<?php page_foot();
