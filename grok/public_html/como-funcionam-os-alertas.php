<?php
require __DIR__ . '/_boot.php';
page_head('Como funcionam os alertas'); ?>
<article class="card narrow prose">
<h1>Como funcionam os alertas automáticos</h1>
<p>Os alertas são um apoio à auto-observação, <strong>não um diagnóstico nem um tratamento</strong>. Combine com seu psiquiatra o que fazer quando eles aparecerem.</p>

<h2>Como o app analisa</h2>
<ul>
<li>A análise roda <strong>de hora em hora</strong>. Abrir o Histórico também atualiza a análise (sem enviar notificação).</li>
<li>O app compara seus últimos dias com o <strong>seu próprio padrão</strong> dos 28 dias anteriores, usando mediana e desvio absoluto mediano, que não se deixam levar por um dia fora da curva.</li>
<li>Entram: humor por período, sono e horário de deitar, energia, irritabilidade, pensamentos e fala acelerados, gastos, sol, cafeína, álcool, estresse, doses esquecidas, sinais pessoais, frequência de notas em áudio/vídeo e dias sem registro. Sexualidade e Substâncias só entram se você ligar esses módulos.</li>
<li>Níveis: <strong>Atenção</strong>, <strong>Alerta</strong> e <strong>Alerta forte</strong>. Humor −3 sempre gera Alerta forte e mostra os contatos de crise (CVV 188, SAMU 192).</li>
<li>O mesmo tipo de alerta só se repete depois de 72 horas, a não ser que fique mais grave.</li>
</ul>

<h2>Quando cada alerta aparece</h2>
<dl>
<dt>(Hipo)mania</dt><dd>Humor elevado ou sono bem abaixo do seu habitual nos últimos 3 dias, somados a outros sinais, como energia intensa, pensamentos ou fala acelerados, gastos, irritabilidade, estimulantes, cafeína, deitar muito mais tarde e seus sinais pessoais.</dd>
<dt>Depressão</dt><dd>Humor para baixo na maioria dos últimos 7 dias, ou bem para baixo em 2 dos últimos 3 dias.</dd>
<dt>Estado misto</dt><dd>Humor para baixo junto com aceleração, irritabilidade ou energia alta no mesmo dia, ou grandes variações dentro do dia.</dd>
<dt>Instabilidade</dt><dd>Muitas viradas entre dias altos e baixos em 14 dias, ou variação bem acima da sua habitual.</dd>
<dt>Lacuna</dt><dd>3 ou mais dias seguidos sem registro, para quem costumava registrar com regularidade.</dd>
<dt>Medicação</dt><dd>2 ou mais doses marcadas como não tomadas em 7 dias.</dd>
<dt>Substâncias</dt><dd>Uso acima do habitual, estimulantes ou beber pesado (5 doses ou mais num dia).</dd>
<dt>Sinais pessoais</dt><dd>2 ou mais dos sinais de alerta que você cadastrou, marcados hoje ou ontem.</dd>
</dl>
<p>Fase do ciclo menstrual e estresse aparecem só como contexto e, sozinhos, não disparam alertas.</p>
<p>O indicador de humor no topo do Histórico e a lista de alertas usam a mesma análise: se o indicador mostra “Alerta”, a lista mostra o alerta correspondente.</p>

<h2>Você ajusta a sensibilidade</h2>
<p>Em cada alerta, responda “faz sentido” ou “não faz sentido”. Isso deixa aquele tipo de alerta mais ou menos sensível <strong>só para você</strong>. Em Conta → Alertas dá para voltar ao padrão.</p>

<h2>Privacidade</h2>
<ul>
<li>As notificações no celular têm texto genérico (“Seu diário tem um novo aviso para você”): nada sobre humor, sono ou o tipo de alerta aparece na tela bloqueada.</li>
<li>Pessoas de apoio só recebem algo se você as confirmou e ligou “Receber alertas de apoio”; no máximo 1 aviso por semana, sem motivo e sem dados.</li>
</ul>

<h2>Limitações</h2>
<p>Os limites foram escolhidos a partir da literatura científica e não foram validados neste app. Espere alguns alarmes falsos e alguns sinais que passam despercebidos. Com menos de 7 dias de registros, o app ainda não conhece seu padrão de sono.</p>

<h2>Referências</h2>
<ol class="small">
<li>Bauer M, et al. Temporal relation between sleep and mood in patients with bipolar disorder. <em>Bipolar Disord</em> 2006;8(2):160-7.</li>
<li>Jackson A, Cavanagh J, Scott J. A systematic review of manic and depressive prodromes. <em>J Affect Disord</em> 2003;74(3):209-17.</li>
<li>Perry A, et al. Teaching patients with bipolar disorder to identify early symptoms of relapse. <em>BMJ</em> 1999;318:149-53.</li>
<li>Swann AC, et al. Bipolar mixed states: an ISBD task force report. <em>Am J Psychiatry</em> 2013;170(1):31-42.</li>
<li>Ebner-Priemer UW, et al. Analytic strategies for understanding affective (in)stability. <em>J Abnorm Psychol</em> 2009;118(1):195-202.</li>
<li>Carvalho AF, et al. Rapid cycling in bipolar disorder: a systematic review. <em>J Clin Psychiatry</em> 2014;75(6):e578-86.</li>
<li>Scott J, Pope M. Nonadherence with mood stabilizers: prevalence and predictors. <em>J Clin Psychiatry</em> 2002;63(5):384-90.</li>
<li>Faurholt-Jepsen M, et al. Daily electronic self-monitoring in bipolar disorder using smartphones (MONARCA I). <em>Psychol Med</em> 2015;45(13):2691-704.</li>
<li>Leys C, et al. Detecting outliers: use absolute deviation around the median. <em>J Exp Soc Psychol</em> 2013;49(4):764-6.</li>
</ol>
<p><a href="/escala.php">Sobre a escala de humor</a> · <a href="/">Voltar ao app</a></p>
</article>
<?php page_foot();
