<?php
/**
 * Seu Nivaldo — Truco Paulista
 * Arquivo único, usando PHP sessions e imagens em img/cartas/.
 */
session_start();

$valores = ['4','5','6','7','Q','J','K','A','2','3'];
$naipes = ['E','C','O','P'];
$urlCartas = 'img/cartas/';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function criarBaralho(array $valores, array $naipes): array {
    $baralho = [];
    foreach ($naipes as $naipe) foreach ($valores as $valor) $baralho[] = $valor.$naipe.'.png';
    // Um baralho de Truco tem exatamente 40 cartas únicas.
    $baralho = array_values(array_unique($baralho));
    shuffle($baralho);
    return $baralho;
}
function retirarCarta(array &$e): string {
    if (!isset($e['cartas_usadas']) || !is_array($e['cartas_usadas'])) $e['cartas_usadas'] = [];
    while (!empty($e['baralho'])) {
        $carta = array_shift($e['baralho']); // sai definitivamente do baralho
        if (isset($e['cartas_usadas'][$carta])) continue;
        $e['cartas_usadas'][$carta] = true;
        return $carta;
    }
    throw new RuntimeException('O baralho acabou antes da distribuição.');
}
function valorCarta(string $arquivo): string {
    return substr(pathinfo($arquivo, PATHINFO_FILENAME), 0, -1);
}
function forcaCarta(string $arquivo, string $vira): int {
    $sequencia = ['4','5','6','7','Q','J','K','A','2','3'];
    $nome = pathinfo($arquivo, PATHINFO_FILENAME);
    $valor = substr($nome, 0, -1);
    $naipe = substr($nome, -1);
    $indice = array_search(valorCarta($vira), $sequencia, true);
    $manilha = $sequencia[((($indice === false) ? 0 : $indice) + 1) % count($sequencia)];
    if ($valor === $manilha) {
        // Truco Paulista: paus (zap), copas, espadas, ouros.
        $forcaNaipe = ['P'=>4, 'C'=>3, 'E'=>2, 'O'=>1];
        return 100 + ($forcaNaipe[$naipe] ?? 0);
    }
    $forcas = ['4'=>1,'5'=>2,'6'=>3,'7'=>4,'Q'=>5,'J'=>6,'K'=>7,'A'=>8,'2'=>9,'3'=>10];
    return $forcas[$valor] ?? 0;
}
function nomeCarta(string $arquivo): string {
    $nome = pathinfo($arquivo, PATHINFO_FILENAME);
    $naipe = substr($nome, -1);
    $valor = substr($nome, 0, -1);
    $nomes = ['E'=>'Espadas','C'=>'Copas','O'=>'Ouros','P'=>'Paus'];
    return $valor.' de '.($nomes[$naipe] ?? $naipe);
}
function novaMao(array &$e): void {
    $e['numero_mao'] = ($e['numero_mao'] ?? 0) + 1;
    $e['fase'] = 'mesa';
    $e['baralho'] = criarBaralho(['4','5','6','7','Q','J','K','A','2','3'], ['E','C','O','P']);
    $e['cartas_usadas'] = [];
    $e['mao_jogador'] = [];
    $e['mao_ia'] = [];
    $e['vira'] = null;
    $e['jogadas'] = [];
    $e['historico_vazas'] = [];
    $e['vazas'] = [];
    $e['vaza_numero'] = 1;
    $e['lider_vaza'] = $e['proxima_mao'] ?? 'jogador';
    $e['vez'] = null;
    $e['valor_mao'] = 1;
    $e['truco_nivel'] = 1;
    $e['truco_pendente'] = false;
    $e['valor_recusa'] = 0;
    $e['mensagem'] = 'Clique no baralho para distribuir as cartas.';
}
function resultadoVaza(array $jogadas, string $vira): ?string {
    if (count($jogadas) < 2) return null;
    // Carta encoberta não revela nem exerce sua força: conta como carta jogada, mas perde para qualquer carta aberta.
    $a = !empty($jogadas[0]['escondida']) ? 0 : forcaCarta($jogadas[0]['carta'], $vira);
    $b = !empty($jogadas[1]['escondida']) ? 0 : forcaCarta($jogadas[1]['carta'], $vira);
    if ($a === $b) return null;
    return $a > $b ? $jogadas[0]['dono'] : $jogadas[1]['dono'];
}
/** Decide a mão assim que as regras já permitem, sem esperar a terceira vaza à toa. */
function vencedorMao(array $vazas, string $maoInicial): ?string {
    $n = count($vazas);
    if ($n >= 2) {
        $a = $vazas[0]; $b = $vazas[1];
        if ($a !== null && $a === $b) return $a;       // ganhou a primeira e a segunda
        if ($a === null && $b !== null) return $b;     // empatou a primeira, ganhou a segunda
        if ($a !== null && $b === null) return $a;     // ganhou a primeira, empatou a segunda
    }
    if ($n < 3) return null;
    $a = $vazas[0]; $b = $vazas[1]; $c = $vazas[2];
    $contagem = ['jogador'=>0,'ia'=>0];
    foreach ($vazas as $v) if ($v !== null) $contagem[$v]++;
    if ($contagem['jogador'] >= 2) return 'jogador';
    if ($contagem['ia'] >= 2) return 'ia';
    if ($a === null && $b === null && $c === null) return $maoInicial;
    // Primeira e segunda foram vencidas por lados diferentes; se a terceira empatar,
    // a mão fica com quem venceu a primeira.
    if ($a !== null && $b !== null && $a !== $b && $c === null) return $a;
    // Regras de desempate restantes: primeira empatada, segunda/terceira decide.
    if ($a === null && $b !== null) return $b;
    if ($a !== null && $b === null) return $a;
    if ($b === null && $c !== null) return $c;
    if ($b !== null && $c === null && $a === null) return $b;
    return $maoInicial;
}

function avaliarMaoIA(array $e): int {
    $valores = [];
    foreach (($e['mao_ia'] ?? []) as $carta) {
        $forca = forcaCarta($carta, (string)($e['vira'] ?? '4E.png'));
        // Pontuação simples: manilha vale muito, seguida de 3, 2 e Ás.
        if ($forca >= 100) $p = 10;
        else {
            $v = valorCarta($carta);
            $p = ['3'=>8,'2'=>7,'A'=>6,'K'=>4,'J'=>3,'Q'=>2,'7'=>2,'6'=>1,'5'=>1,'4'=>0][$v] ?? 0;
        }
        $valores[] = $p;
    }
    rsort($valores);
    return array_sum($valores);
}
function encerrarPartida(array &$e, string $vencedor): void {
    if (!isset($e['partidas'])) $e['partidas'] = ['jogador'=>0, 'ia'=>0];
    $e['partidas'][$vencedor]++;
    $e['vencedor_partida'] = $vencedor;
    $e['placar_partida_final'] = $e['placar'];
    $nomeVencedor = $vencedor === 'jogador' ? (string)$e['nome'] : 'Seu Nivaldo';
    if ($e['partidas'][$vencedor] >= 3) {
        $e['fase'] = 'fim_jogo';
        $e['mensagem'] = $nomeVencedor.' venceu a série com '.$e['partidas'][$vencedor].' partidas!';
    } else {
        $e['fase'] = 'fim_partida';
        $e['mensagem'] = $nomeVencedor.' venceu esta partida. Placar da série: '.$e['partidas']['jogador'].' × '.$e['partidas']['ia'].'.';
    }
    $e['vez'] = null;
    $e['truco_pendente'] = false;
}
function encerrarMaoPorDesistencia(array &$e, string $vencedor, int $pontos, string $mensagem): void {
    $e['placar'][$vencedor] += $pontos;
    $e['ultimo_vencedor'] = $vencedor;
    $e['proxima_mao'] = $vencedor;
    $e['fase'] = 'fim_mao';
    $e['truco_pendente'] = false;
    $e['vez'] = null;
    $e['mensagem'] = $mensagem.' '.$pontos.' ponto(s).';
    if ($e['placar'][$vencedor] >= 12) encerrarPartida($e, $vencedor);
}

function jogarIA(array &$e): void {
    if (empty($e['mao_ia'])) return;
    // Escolha simples por enquanto; a prioridade é resolver corretamente a mão.
    $idx = array_rand($e['mao_ia']);
    $carta = $e['mao_ia'][$idx];
    array_splice($e['mao_ia'], $idx, 1);
    $e['jogadas'][] = ['dono'=>'ia','carta'=>$carta,'escondida'=>false];
}
function fecharVaza(array &$e): void {
    $resultado = resultadoVaza($e['jogadas'], $e['vira']);
    $e['vazas'][] = $resultado;
    $numero = count($e['vazas']);
    // Guardar as cartas da vaza no histórico permanente da mão.
    if (!isset($e['historico_vazas']) || !is_array($e['historico_vazas'])) $e['historico_vazas'] = [];
    $e['historico_vazas'][] = [
        'numero' => $numero,
        'jogadas' => $e['jogadas'],
        'resultado' => $resultado
    ];
    $textoResultado = $resultado === 'jogador' ? h($e['nome']).' venceu a vaza '.$numero.'.'
        : ($resultado === 'ia' ? 'Seu Nivaldo venceu a vaza '.$numero.'.' : 'A vaza '.$numero.' empatou.');
    $vencedor = vencedorMao($e['vazas'], $e['mao_da_mao'] ?? $e['lider_vaza']);
    if ($vencedor !== null) {
        $e['placar'][$vencedor] += $e['valor_mao'];
        $e['ultimo_vencedor'] = $vencedor;
        $e['fase'] = 'fim_mao';
        $e['proxima_mao'] = $vencedor;
        $e['vez'] = null;
        $e['mensagem'] = ($vencedor === 'jogador' ? h($e['nome']).' ganhou a mão!' : 'Seu Nivaldo ganhou a mão!')
            .' +'.$e['valor_mao'].' ponto'.($e['valor_mao'] > 1 ? 's' : '').'.';
        if ($e['placar'][$vencedor] >= 12) encerrarPartida($e, $vencedor);
        return;
    }
    $lider = $resultado ?? $e['lider_vaza']; // em empate, o líder anterior torna
    $e['lider_vaza'] = $lider;
    $e['vaza_numero'] = $numero + 1;
    $e['jogadas'] = [];
    $e['vez'] = $lider;
    $e['mensagem'] = $textoResultado.' '.($lider === 'jogador' ? 'Você torna e joga primeiro.' : 'Seu Nivaldo torna e joga primeiro.');
    if ($lider === 'ia') {
        jogarIA($e);
        $e['vez'] = 'jogador';
    }
}

if (isset($_GET['reiniciar'])) { unset($_SESSION['truco']); header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'entrar') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome !== '') {
            $_SESSION['truco'] = [
                'nome'=>function_exists('mb_substr') ? mb_substr($nome,0,30) : substr($nome,0,30),
                'fase'=>'mesa','baralho'=>criarBaralho($valores,$naipes),'cartas_usadas'=>[],
                'mao_jogador'=>[],'mao_ia'=>[],'vira'=>null,
                'mao_inicial'=>random_int(0,1) === 0 ? 'jogador' : 'ia',
                'numero_mao'=>0,'jogadas'=>[],'historico_vazas'=>[],'vazas'=>[],'vaza_numero'=>1,
                'lider_vaza'=>'jogador','vez'=>null,'placar'=>['jogador'=>0,'ia'=>0],
                'valor_mao'=>1,'truco_nivel'=>1,'truco_pendente'=>false,'valor_recusa'=>0,'proxima_mao'=>'jogador','partidas'=>['jogador'=>0,'ia'=>0],
                'mensagem'=>'Clique no baralho para distribuir as cartas.'
            ];
            novaMao($_SESSION['truco']);
            // primeira mão: sorteia quem começa
            $_SESSION['truco']['lider_vaza'] = $_SESSION['truco']['mao_inicial'];
        }
        header('Location: index.php'); exit;
    }

    if (isset($_SESSION['truco'])) {
        $e = &$_SESSION['truco'];
        if ($acao === 'distribuir' && $e['fase'] === 'mesa') {
            // Reconstruir um baralho limpo e único no começo da mão.
            $e['baralho'] = criarBaralho($valores, $naipes);
            $e['cartas_usadas'] = [];
            $e['mao_jogador'] = [];
            $e['mao_ia'] = [];
            for ($i=0;$i<3;$i++) {
                $e['mao_jogador'][] = retirarCarta($e);
                $e['mao_ia'][] = retirarCarta($e);
            }
            $e['vira'] = retirarCarta($e);
            // Auditoria da distribuição: 6 cartas nas mãos + vira = 7 retiradas únicas.
            $distribuidas = array_merge($e['mao_jogador'], $e['mao_ia'], [$e['vira']]);
            if (count($distribuidas) !== 7 || count(array_unique($distribuidas)) !== 7 || count($e['cartas_usadas']) !== 7) {
                throw new RuntimeException('Falha de integridade: uma carta foi repetida ou não saiu do monte.');
            }
            $e['fase'] = 'jogo';
            $e['jogadas'] = [];
            $e['historico_vazas'] = [];
            $e['vazas'] = [];
            $e['vaza_numero'] = 1;
            $e['lider_vaza'] = $e['numero_mao'] === 1 ? $e['mao_inicial'] : ($e['proxima_mao'] ?? 'jogador');
            $e['mao_da_mao'] = $e['lider_vaza'];
            $e['vez'] = $e['lider_vaza'];
            $e['mensagem'] = $e['vez'] === 'jogador' ? 'Sua vez de jogar!' : 'Seu Nivaldo começa a vaza.';
            if ($e['vez'] === 'ia') { jogarIA($e); $e['vez'] = 'jogador'; }
        } elseif ($acao === 'jogar' && $e['fase'] === 'jogo' && $e['vez'] === 'jogador') {
            $carta = (string)($_POST['carta'] ?? '');
            $idx = array_search($carta, $e['mao_jogador'], true);
            if ($idx !== false) {
                array_splice($e['mao_jogador'], $idx, 1);
                $escondida = (($_POST['escondida'] ?? '0') === '1');
                if ($escondida && (int)($e['vaza_numero'] ?? 1) < 2) $escondida = false;
                $e['jogadas'][] = ['dono'=>'jogador','carta'=>$carta,'escondida'=>$escondida];
                if (count($e['jogadas']) === 1) {
                    jogarIA($e);
                }
                if (count($e['jogadas']) >= 2) {
                    fecharVaza($e);
                } else {
                    $e['vez'] = 'jogador';
                }
            }
        } elseif ($acao === 'truco' && $e['fase'] === 'jogo' && $e['vez'] === 'jogador'
            && empty($e['truco_pendente']) && in_array((int)($e['valor_mao'] ?? 1), [1, 6], true)) {
            $valorAtual = (int)($e['valor_mao'] ?? 1);
            $forca = avaliarMaoIA($e);
            if ($valorAtual === 1) {
                // O jogador pediu truco: Seu Nivaldo olha a própria mão antes de responder.
                if ($forca <= 8 && random_int(1, 100) <= 80) {
                    encerrarMaoPorDesistencia($e, 'jogador', 1, 'Seu Nivaldo olhou as cartas e resmungou: “Minhas cartas estão baixas... vou correr.” Você ganhou');
                } elseif ($forca >= 15 && random_int(1, 100) <= 75) {
                    $e['valor_mao'] = 6; $e['truco_nivel'] = 6; $e['valor_recusa'] = 3; $e['truco_pendente'] = true;
                    $e['mensagem'] = 'Seu Nivaldo gostou do que viu e pediu SEIS! Você aceita, pede nove ou corre?';
                } else {
                    $e['valor_mao'] = 3; $e['truco_nivel'] = 3; $e['truco_pendente'] = false; $e['vez'] = 'jogador';
                    $e['mensagem'] = $forca <= 11 ? 'Seu Nivaldo pensou e aceitou o truco. “Vamos ver no que dá!” A mão vale 3 pontos.' : 'Seu Nivaldo conferiu a mão e aceitou o truco. A mão vale 3 pontos.';
                }
            } else {
                // O jogador pede nove depois que a mão já chegou a seis.
                if ($forca <= 8 && random_int(1, 100) <= 80) {
                    encerrarMaoPorDesistencia($e, 'jogador', 6, 'Seu Nivaldo olhou as cartas e correu do nove. Você ganhou');
                } elseif ($forca >= 15 && random_int(1, 100) <= 75) {
                    $e['valor_mao'] = 12; $e['truco_nivel'] = 12; $e['valor_recusa'] = 9; $e['truco_pendente'] = true;
                    $e['mensagem'] = 'Seu Nivaldo viu que está forte e pediu DOZE! Você aceita ou corre?';
                } else {
                    $e['valor_mao'] = 9; $e['truco_nivel'] = 9; $e['truco_pendente'] = false; $e['vez'] = 'jogador';
                    $e['mensagem'] = 'Seu Nivaldo aceitou o nove. A mão vale 9 pontos. Sua vez de jogar.';
                }
            }
        } elseif ($acao === 'pedir_nove' && $e['fase'] === 'jogo' && !empty($e['truco_pendente'])
            && (int)($e['valor_mao'] ?? 0) === 6) {
            // O jogador aumenta para nove; a IA avalia se aceita, corre ou contrapede doze.
            $forca = avaliarMaoIA($e);
            if ($forca <= 8 && random_int(1, 100) <= 80) {
                encerrarMaoPorDesistencia($e, 'jogador', 6, 'Seu Nivaldo conferiu as cartas e correu do nove. Você ganhou');
            } elseif ($forca >= 15 && random_int(1, 100) <= 75) {
                $e['valor_recusa'] = 9; $e['valor_mao'] = 12; $e['truco_nivel'] = 12;
                $e['mensagem'] = 'Seu Nivaldo está confiante e pediu DOZE! Você aceita ou corre?';
            } else {
                $e['valor_mao'] = 9; $e['truco_nivel'] = 9; $e['truco_pendente'] = false; $e['vez'] = 'jogador';
                $e['mensagem'] = 'Seu Nivaldo aceitou o nove. A mão vale 9 pontos. Sua vez de jogar.';
            }
        } elseif (in_array($acao, ['aceitar_seis', 'aceitar_doze'], true) && $e['fase'] === 'jogo' && !empty($e['truco_pendente'])) {
            $e['truco_pendente'] = false;
            $e['mensagem'] = 'Você aceitou! A mão vale '.$e['valor_mao'].' pontos. Sua vez de jogar.';
            $e['vez'] = 'jogador';
        } elseif ($acao === 'correr_seis' && $e['fase'] === 'jogo' && !empty($e['truco_pendente'])) {
            $pontosRecusa = (int)($e['valor_recusa'] ?? 3);
            encerrarMaoPorDesistencia($e, 'ia', $pontosRecusa, 'Você correu. Seu Nivaldo marca');
        } elseif ($acao === 'proxima' && $e['fase'] === 'fim_mao') {
            novaMao($e);
        } elseif ($acao === 'proxima_partida' && $e['fase'] === 'fim_partida') {
            $e['placar'] = ['jogador'=>0,'ia'=>0];
            $e['numero_mao'] = 0;
            $e['mao_inicial'] = random_int(0,1) === 0 ? 'jogador' : 'ia';
            $e['proxima_mao'] = $e['mao_inicial'];
            novaMao($e);
            $e['mensagem'] = 'Nova partida! A série está '.$e['partidas']['jogador'].' × '.$e['partidas']['ia'].'.';
        }
        unset($e);
        header('Location: index.php'); exit;
    }
}

$estado = $_SESSION['truco'] ?? null;
$maoAtual = $estado['lider_vaza'] ?? 'jogador';
$peAtual = $maoAtual === 'jogador' ? 'ia' : 'jogador';
?>

<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seu Nivaldo — Truco Paulista</title>
<style>
/* ===== AJUSTES RÁPIDOS DE LAYOUT: edite aqui ===== */
:root {
    --fundo:#173d30; --feltro:#205640; --painel:rgba(12,32,24,.72);
    --texto:#fff6df; --texto-suave:#d8e5d8; --destaque:#e9bd70;
    --carta-largura:92px; --carta-altura:136px; --espaco-cartas:12px;
    --largura-conteudo:1000px; --padding-pagina:24px; --raio-painel:18px;
}
/* ===== FIM DOS AJUSTES RÁPIDOS ===== */
*{box-sizing:border-box}
body{margin:0;min-height:100vh;padding:var(--padding-pagina);color:var(--texto);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(ellipse at center,rgba(62,115,79,.45),transparent 65%),var(--fundo);display:flex;justify-content:center;align-items:center}
.app{width:100%;max-width:var(--largura-conteudo)}
.painel{padding:clamp(20px,4vw,42px);border:1px solid rgba(255,255,255,.12);border-radius:var(--raio-painel);background:var(--painel);box-shadow:0 18px 55px rgba(0,0,0,.22);text-align:center}
.marca{margin:0 0 8px;color:var(--destaque);font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
h1{margin:0 0 12px;font-size:clamp(30px,5vw,48px)}
.subtitulo{margin:0 auto 26px;max-width:580px;color:var(--texto-suave);line-height:1.6}
.campo-nome{display:block;width:100%;max-width:380px;margin:0 auto 14px;padding:14px 16px;border:1px solid rgba(255,255,255,.25);border-radius:10px;background:#fffaf0;color:#18392c;font:inherit}
.botao{display:inline-block;border:0;border-radius:10px;padding:13px 22px;color:#2b2418;background:var(--destaque);font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
.botao:hover{filter:brightness(1.06);transform:translateY(-1px)}
.mesa{display:grid;gap:24px}.area{padding:18px;border-radius:14px;background:rgba(0,0,0,.15)}
.area h2{margin:0 0 14px;font-size:18px}.mao{display:flex;justify-content:center;flex-wrap:wrap;gap:var(--espaco-cartas)}
.carta{display:block;width:var(--carta-largura);height:var(--carta-altura);object-fit:contain;border-radius:7px;filter:drop-shadow(0 5px 5px rgba(0,0,0,.25))}
.baralho-botao{padding:0;border:0;border-radius:9px;background:transparent;cursor:pointer}.baralho-botao .carta{width:calc(var(--carta-largura)*1.08);height:calc(var(--carta-altura)*1.08)}
.centro{display:flex;flex-direction:column;align-items:center;gap:12px}.dica{color:var(--texto-suave);margin:8px 0 0;font-size:14px}
.rodape{margin-top:18px;text-align:center}.identificacoes{display:flex;justify-content:space-around;gap:16px;flex-wrap:wrap}
.identificacao{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}
.selo{padding:5px 10px;border-radius:6px;font-size:12px;font-weight:800;background:#555;color:white}
.selo.mao{background:var(--destaque);color:#30220c}.mensagem{padding:12px;border-radius:9px;background:rgba(0,0,0,.2);font-weight:800}
.jogadas{display:flex;justify-content:center;align-items:flex-start;gap:24px;flex-wrap:wrap}
.jogada{display:grid;justify-items:center;gap:7px}.jogada small{color:var(--texto-suave)}
.historico{display:grid;gap:14px;text-align:left}
.historico-matriz{display:grid;grid-template-columns:minmax(90px,130px) repeat(3,minmax(60px,1fr));gap:8px;align-items:center}
.matriz-cabecalho{font-size:12px;font-weight:800;color:var(--destaque);text-align:center;padding:6px 2px}
.matriz-rotulo{font-size:13px;font-weight:800;color:var(--texto-suave);padding:6px 2px}
.matriz-celula{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:82px;border:1px solid rgba(255,255,255,.12);border-radius:8px;background:rgba(0,0,0,.12);padding:5px}
.matriz-celula small{font-size:10px;color:var(--texto-suave);text-align:center}
.carta-mini{width:44px;height:65px;object-fit:contain;filter:drop-shadow(0 3px 3px rgba(0,0,0,.25))}
@media(max-width:540px){.historico-matriz{grid-template-columns:70px repeat(3,minmax(42px,1fr));gap:4px}.matriz-rotulo{font-size:11px}.carta-mini{width:30px;height:45px}.matriz-celula{min-height:62px;padding:3px}} 
.vaza-historico{padding:12px;border:1px solid rgba(255,255,255,.13);border-radius:12px;background:rgba(0,0,0,.13)}
.vaza-titulo{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;font-weight:800}
.linha-historico{display:grid;grid-template-columns:minmax(95px,130px) 1fr;align-items:center;gap:12px;padding:7px 0}
.rotulo-historico{color:var(--texto-suave);font-size:13px;font-weight:700}
.cartas-historico{display:flex;align-items:center;gap:8px;min-height:46px;flex-wrap:wrap}
.carta-mini{width:44px;height:65px;object-fit:contain;filter:drop-shadow(0 3px 3px rgba(0,0,0,.25))}
.historico-vazio{color:var(--texto-suave);font-size:14px;margin:0}
@media(max-width:540px){.linha-historico{grid-template-columns:82px 1fr;gap:7px}.carta-mini{width:34px;height:50px}}
.carta-botao{border:0;padding:0;background:transparent;cursor:pointer;border-radius:8px}.carta-botao:focus-visible{outline:3px solid var(--destaque);outline-offset:4px}
.carta-botao:hover .carta{transform:translateY(-5px)}
@media(max-width:540px){:root{--carta-largura:66px;--carta-altura:98px;--espaco-cartas:8px;--padding-pagina:12px}.area{padding:12px}}
</style>
</head>
<body>
<main class="app">
<?php if (!$estado): ?>
<section class="painel">
<p class="marca">Um café, uma mesa e uma boa partida</p>
<h1>Seja bem-vindo ao nosso café!</h1>
<p class="subtitulo">Puxe uma cadeira, escolha como quer ser chamado e venha jogar um truco com Seu Nivaldo.</p>
<form method="post" action="index.php">
<input type="hidden" name="acao" value="entrar">
<label for="nome">Como vamos chamar você?</label>
<input class="campo-nome" id="nome" name="nome" type="text" maxlength="30" autocomplete="nickname" placeholder="Digite seu nome" required autofocus>
<button class="botao" type="submit">Puxar uma cadeira</button>
</form>
</section>
<?php else: ?>
<section class="painel mesa">
<header>
<p class="marca">Café do Seu Nivaldo</p>
<h1>Boa partida, <?= h($estado['nome']) ?>!</h1>
<p class="subtitulo">Você está à mesa com Seu Nivaldo.</p>
<div class="identificacoes">
<div class="identificacao"><strong>☕ <?= h($estado['nome']) ?></strong><span class="selo <?= $maoAtual==='jogador'?'mao':'' ?>"><?= $maoAtual==='jogador'?'MÃO':'PÉ' ?></span></div>
<div class="identificacao"><strong>🃏 Seu Nivaldo</strong><span class="selo <?= $maoAtual==='ia'?'mao':'' ?>"><?= $maoAtual==='ia'?'MÃO':'PÉ' ?></span></div>
</div>
<div class="identificacoes" style="margin-top:14px">
  <strong>Placar da partida: <?= h((string)$estado['placar']['jogador']) ?> × <?= h((string)$estado['placar']['ia']) ?></strong>
  <strong>Série (primeiro a 3): <?= h((string)($estado['partidas']['jogador'] ?? 0)) ?> × <?= h((string)($estado['partidas']['ia'] ?? 0)) ?></strong>
  <strong>Mão <?= h((string)$estado['numero_mao']) ?> · Vaza <?= h((string)($estado['vaza_numero'] ?? 1)) ?>/3 · Vale <?= h((string)($estado['valor_mao'] ?? 1)) ?> ponto(s)</strong>
</div>
</header>

<section class="area">
<h2>Histórico das vazas desta mão</h2>
<div class="historico">
<?php
$historicoExibicao = $estado['historico_vazas'] ?? [];
if (($estado['fase'] ?? '') === 'jogo' && !empty($estado['jogadas'])) {
    $historicoExibicao[] = [
        'numero' => (int)($estado['vaza_numero'] ?? (count($historicoExibicao)+1)),
        'jogadas' => $estado['jogadas'],
        'resultado' => null,
        'em_andamento' => true
    ];
}
$porVaza = [];
foreach ($historicoExibicao as $vazaHist) $porVaza[(int)$vazaHist['numero']] = $vazaHist;
?>
<div class="historico-matriz">
  <div></div>
  <?php for ($col=1; $col<=3; $col++): ?><div class="matriz-cabecalho">Vaza <?= $col ?></div><?php endfor; ?>
  <div class="matriz-rotulo">Seu Nivaldo</div>
  <?php for ($col=1; $col<=3; $col++):
    $vazaHist = $porVaza[$col] ?? null; $cartaHist = null; $escondidaHist = false;
    if ($vazaHist) foreach (($vazaHist['jogadas'] ?? []) as $jHist) {
        if (($jHist['dono'] ?? '') === 'ia') { $cartaHist = $jHist['carta']; $escondidaHist = !empty($jHist['escondida']); }
    }
    $resultadoHist = $vazaHist['resultado'] ?? null;
    $rotuloResultado = !$vazaHist ? '—' : (!empty($vazaHist['em_andamento']) ? 'Em andamento'
        : ($resultadoHist === 'ia' ? 'Seu Nivaldo venceu' : ($resultadoHist === 'jogador' ? h($estado['nome']).' venceu' : 'Empate')));
  ?>
  <div class="matriz-celula">
    <?php if ($cartaHist): ?>
      <img class="carta-mini" src="<?= h($urlCartas.($escondidaHist ? 'costas.png' : $cartaHist)) ?>" alt="<?= $escondidaHist ? 'Carta escondida' : h(nomeCarta($cartaHist)) ?>" title="<?= $escondidaHist ? 'Carta escondida' : h(nomeCarta($cartaHist)) ?>">
      <small><?= $escondidaHist ? 'Escondida' : h(nomeCarta($cartaHist)) ?></small>
    <?php else: ?><small><?= $vazaHist ? 'Aguardando carta' : '—' ?></small><?php endif; ?>
    <?php if ($vazaHist && empty($vazaHist['em_andamento'])): ?><small><?= $rotuloResultado ?></small><?php endif; ?>
  </div>
  <?php endfor; ?>
  <div class="matriz-rotulo"><?= h($estado['nome']) ?></div>
  <?php for ($col=1; $col<=3; $col++):
    $vazaHist = $porVaza[$col] ?? null; $cartaHist = null; $escondidaHist = false;
    if ($vazaHist) foreach (($vazaHist['jogadas'] ?? []) as $jHist) {
        if (($jHist['dono'] ?? '') === 'jogador') { $cartaHist = $jHist['carta']; $escondidaHist = !empty($jHist['escondida']); }
    }
  ?>
  <div class="matriz-celula">
    <?php if ($cartaHist): ?>
      <img class="carta-mini" src="<?= h($urlCartas.($escondidaHist ? 'costas.png' : $cartaHist)) ?>" alt="<?= $escondidaHist ? 'Carta escondida' : h(nomeCarta($cartaHist)) ?>" title="<?= $escondidaHist ? 'Carta escondida' : h(nomeCarta($cartaHist)) ?>">
      <small><?= $escondidaHist ? 'Escondida' : h(nomeCarta($cartaHist)) ?></small>
    <?php else: ?><small><?= $vazaHist ? 'Aguardando carta' : '—' ?></small><?php endif; ?>
  </div>
  <?php endfor; ?>
</div>
</div>
</section>

<?php if ($estado['fase'] === 'fim_jogo'): ?>
<div class="area centro">
<h2>Fim da série!</h2>
<p class="subtitulo"><?= h($estado['mensagem']) ?></p>
<p class="dica">Placar da série: <?= h((string)$estado['partidas']['jogador']) ?> × <?= h((string)$estado['partidas']['ia']) ?> partidas.</p>
<a class="botao" href="index.php?reiniciar=1">Começar uma nova série</a>
</div>
<?php elseif ($estado['fase'] === 'fim_partida'): ?>
<div class="area centro">
<h2>Partida encerrada!</h2>
<p class="subtitulo"><?= h($estado['mensagem']) ?></p>
<p class="dica">Placar desta partida: <?= h((string)($estado['placar_partida_final']['jogador'] ?? $estado['placar']['jogador'])) ?> × <?= h((string)($estado['placar_partida_final']['ia'] ?? $estado['placar']['ia'])) ?>.</p>
<p class="dica">Série: <?= h((string)$estado['partidas']['jogador']) ?> × <?= h((string)$estado['partidas']['ia']) ?>. Vence quem ganhar 3 partidas.</p>
<form method="post" action="index.php"><input type="hidden" name="acao" value="proxima_partida"><button class="botao" type="submit">Começar a próxima partida</button></form>
</div>
<?php elseif ($estado['fase'] === 'fim_mao'): ?>
<div class="area centro">
<h2>Mão encerrada!</h2>
<p class="subtitulo"><?= h($estado['mensagem']) ?></p>
<p class="dica">O vencedor da mão será a MÃO na próxima distribuição.</p>
<form method="post" action="index.php"><input type="hidden" name="acao" value="proxima"><button class="botao" type="submit">Começar a próxima mão</button></form>
</div>
<?php elseif ($estado['fase'] === 'mesa'): ?>
<div class="area centro">
<h2>O baralho está na mesa</h2>
<form method="post" action="index.php">
<input type="hidden" name="acao" value="distribuir">
<button class="baralho-botao" type="submit" aria-label="Clicar no baralho para distribuir as cartas"><img class="carta" src="<?= h($urlCartas.'costas.png') ?>" alt="Costas do baralho"></button>
</form>
<p class="dica">Clique nas costas do baralho para distribuir três cartas para cada jogador e virar uma carta.</p>
</div>
<?php else: ?>
<div class="mensagem" aria-live="polite"><?= h($estado['mensagem'] ?? 'Sua vez de jogar!') ?></div>
<section class="area">
<h2>Seu Nivaldo</h2>
<div class="mao">
<?php foreach ($estado['mao_ia'] as $_carta): ?>
<img class="carta" src="<?= h($urlCartas.'costas.png') ?>" alt="Carta virada para baixo">
<?php endforeach; ?>
</div>
</section>
<section class="area centro">
<h2>Vira</h2>
<img class="carta" src="<?= h($urlCartas.$estado['vira']) ?>" alt="<?= h(nomeCarta($estado['vira'])) ?>">
<p class="dica">A vira define a manilha desta mão. As cartas jogadas aparecem abaixo.</p>
</section>
<section class="area">
<h2>Mesa de jogo</h2>
<div class="jogadas">
<?php if (empty($estado['jogadas'])): ?>
<p class="dica">As cartas jogadas aparecerão aqui.</p>
<?php else: foreach ($estado['jogadas'] as $jogada): ?>
<div class="jogada">
<small><?= $jogada['dono']==='jogador' ? h($estado['nome']) : 'Seu Nivaldo' ?></small>
<img class="carta" src="<?= h($urlCartas.(!empty($jogada['escondida']) ? 'costas.png' : $jogada['carta'])) ?>" alt="<?= !empty($jogada['escondida']) ? 'Carta escondida' : h(nomeCarta($jogada['carta'])) ?>">
</div>
<?php endforeach; endif; ?>
</div>
</section>
<section class="area">
<h2>Sua mão, <?= h($estado['nome']) ?></h2>
<div class="mao">
<?php foreach ($estado['mao_jogador'] as $_carta): ?>
<form method="post" action="index.php" style="margin:0;display:grid;justify-items:center;gap:5px">
<input type="hidden" name="acao" value="jogar">
<input type="hidden" name="carta" value="<?= h($_carta) ?>">
<button class="carta-botao" type="submit" name="escondida" value="0" <?= ($estado['vez'] ?? '')!=='jogador' || !empty($estado['truco_pendente']) ? 'disabled' : '' ?> aria-label="Jogar <?= h(nomeCarta($_carta)) ?>">
<img class="carta" src="<?= h($urlCartas.$_carta) ?>" alt="<?= h(nomeCarta($_carta)) ?>">
</button>
<?php if ((int)($estado['vaza_numero'] ?? 1) >= 2): ?>
<button class="botao" style="font-size:11px;padding:6px 8px" type="submit" name="escondida" value="1" <?= ($estado['vez'] ?? '')!=='jogador' || !empty($estado['truco_pendente']) ? 'disabled' : '' ?>>Esconder</button>
<?php endif; ?>
</form>
<?php endforeach; ?>
</div>
<p class="dica"><?= ($estado['vez'] ?? '')==='jogador' ? 'Sua vez de jogar: clique em uma carta.' : 'Aguardando o Seu Nivaldo.' ?></p>
<?php if (!empty($estado['truco_pendente'])): ?>
<div class="identificacoes">
<form method="post" action="index.php"><input type="hidden" name="acao" value="aceitar_seis"><button class="botao" type="submit">Aceitar <?= (int)$estado['valor_mao'] === 12 ? 'os doze' : ((int)$estado['valor_mao'] === 9 ? 'o nove' : 'os seis') ?></button></form>
<?php if ((int)$estado['valor_mao'] === 6): ?>
<form method="post" action="index.php"><input type="hidden" name="acao" value="pedir_nove"><button class="botao" type="submit">Pedir nove</button></form>
<?php endif; ?>
<form method="post" action="index.php"><input type="hidden" name="acao" value="correr_seis"><button class="botao" type="submit">Correr</button></form>
</div>
<?php elseif (($estado['vez'] ?? '')==='jogador' && in_array((int)($estado['valor_mao'] ?? 1), [1,6], true)): ?>
<form method="post" action="index.php"><input type="hidden" name="acao" value="truco"><button class="botao" type="submit"><?= (int)$estado['valor_mao'] === 1 ? 'Pedir truco' : 'Pedir nove' ?></button></form>
<?php endif; ?>
</section>
<?php endif; ?>
<div class="rodape"><a class="botao" href="index.php?reiniciar=1">Começar outra partida</a></div>
</section>
<?php endif; ?>
</main>
</body>
</html>
