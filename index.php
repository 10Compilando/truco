<?php
/**
 * Seu Nivaldo — Truco Paulista
 * Arquitetura MVC em classes (Model, Controller e View), sem banco de dados.
 * Arquivo único para manter a instalação simples.
 */
session_start();
$valores = ['4','5','6','7','Q','J','K','A','2','3'];
$naipes = ['E','C','O','P'];

/**
 * MODEL — regras, estado da partida, baralho e decisões do rival.
 * Mantido no mesmo arquivo para facilitar a instalação no HostGator.
 */
class TrucoModel {
public static function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
public static function criarBaralho(array $valores, array $naipes): array {
    $baralho = [];
    foreach ($naipes as $naipe) foreach ($valores as $valor) $baralho[] = $valor.$naipe.'.png';
    // Um baralho de Truco tem exatamente 40 cartas únicas.
    $baralho = array_values(array_unique($baralho));
    shuffle($baralho);
    return $baralho;
}
public static function retirarCarta(array &$e): string {
    if (!isset($e['cartas_usadas']) || !is_array($e['cartas_usadas'])) $e['cartas_usadas'] = [];
    while (!empty($e['baralho'])) {
        $carta = array_shift($e['baralho']); // sai definitivamente do baralho
        if (isset($e['cartas_usadas'][$carta])) continue;
        $e['cartas_usadas'][$carta] = true;
        return $carta;
    }
    throw new RuntimeException('O baralho acabou antes da distribuição.');
}
public static function valorCarta(string $arquivo): string {
    return substr(pathinfo($arquivo, PATHINFO_FILENAME), 0, -1);
}
public static function forcaCarta(string $arquivo, string $vira): int {
    $sequencia = ['4','5','6','7','Q','J','K','A','2','3'];
    $nome = pathinfo($arquivo, PATHINFO_FILENAME);
    $valor = substr($nome, 0, -1);
    $naipe = substr($nome, -1);
    $indice = array_search(self::valorCarta($vira), $sequencia, true);
    $manilha = $sequencia[((($indice === false) ? 0 : $indice) + 1) % count($sequencia)];
    if ($valor === $manilha) {
        // Truco Paulista: paus (zap), copas, espadas, ouros.
        $forcaNaipe = ['P'=>4, 'C'=>3, 'E'=>2, 'O'=>1];
        return 100 + ($forcaNaipe[$naipe] ?? 0);
    }
    $forcas = ['4'=>1,'5'=>2,'6'=>3,'7'=>4,'Q'=>5,'J'=>6,'K'=>7,'A'=>8,'2'=>9,'3'=>10];
    return $forcas[$valor] ?? 0;
}
public static function nomeCarta(string $arquivo): string {
    $nome = pathinfo($arquivo, PATHINFO_FILENAME);
    $naipe = substr($nome, -1);
    $valor = substr($nome, 0, -1);
    $nomes = ['E'=>'Espadas','C'=>'Copas','O'=>'Ouros','P'=>'Paus'];
    return $valor.' de '.($nomes[$naipe] ?? $naipe);
}
public static function novaMao(array &$e): void {
    $e['numero_mao'] = ($e['numero_mao'] ?? 0) + 1;
    $e['fase'] = 'mesa';
    $e['baralho'] = self::criarBaralho(['4','5','6','7','Q','J','K','A','2','3'], ['E','C','O','P']);
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
public static function resultadoVaza(array $jogadas, string $vira): ?string {
    if (count($jogadas) < 2) return null;
    // Carta encoberta não revela nem exerce sua força: conta como carta jogada, mas perde para qualquer carta aberta.
    $a = !empty($jogadas[0]['escondida']) ? 0 : self::forcaCarta($jogadas[0]['carta'], $vira);
    $b = !empty($jogadas[1]['escondida']) ? 0 : self::forcaCarta($jogadas[1]['carta'], $vira);
    if ($a === $b) return null;
    return $a > $b ? $jogadas[0]['dono'] : $jogadas[1]['dono'];
}
/** Decide a mão assim que as regras já permitem, sem esperar a terceira vaza à toa. */
public static function vencedorMao(array $vazas, string $maoInicial): ?string {
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

public static function avaliarMaoIA(array $e): int {
    $valores = [];
    foreach (($e['mao_ia'] ?? []) as $carta) {
        $forca = self::forcaCarta($carta, (string)($e['vira'] ?? '4E.png'));
        // Pontuação simples: manilha vale muito, seguida de 3, 2 e Ás.
        if ($forca >= 100) $p = 10;
        else {
            $v = self::valorCarta($carta);
            $p = ['3'=>8,'2'=>7,'A'=>6,'K'=>4,'J'=>3,'Q'=>2,'7'=>2,'6'=>1,'5'=>1,'4'=>0][$v] ?? 0;
        }
        $valores[] = $p;
    }
    rsort($valores);
    return array_sum($valores);
}
public static function encerrarPartida(array &$e, string $vencedor): void {
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
public static function encerrarMaoPorDesistencia(array &$e, string $vencedor, int $pontos, string $mensagem): void {
    $e['placar'][$vencedor] += $pontos;
    $e['ultimo_vencedor'] = $vencedor;
    $e['proxima_mao'] = $vencedor;
    $e['fase'] = 'fim_mao';
    $e['truco_pendente'] = false;
    $e['vez'] = null;
    $e['mensagem'] = $mensagem.' '.$pontos.' ponto(s).';
    if ($e['placar'][$vencedor] >= 12) self::encerrarPartida($e, $vencedor);
}

public static function jogarIA(array &$e): void {
    if (empty($e['mao_ia'])) return;
    // Escolha simples por enquanto; a prioridade é resolver corretamente a mão.
    $idx = array_rand($e['mao_ia']);
    $carta = $e['mao_ia'][$idx];
    array_splice($e['mao_ia'], $idx, 1);
    $e['jogadas'][] = ['dono'=>'ia','carta'=>$carta,'escondida'=>false];
}
public static function fecharVaza(array &$e): void {
    $resultado = self::resultadoVaza($e['jogadas'], $e['vira']);
    $e['vazas'][] = $resultado;
    $numero = count($e['vazas']);
    // Guardar as cartas da vaza no histórico permanente da mão.
    if (!isset($e['historico_vazas']) || !is_array($e['historico_vazas'])) $e['historico_vazas'] = [];
    $e['historico_vazas'][] = [
        'numero' => $numero,
        'jogadas' => $e['jogadas'],
        'resultado' => $resultado
    ];
    $textoResultado = $resultado === 'jogador' ? self::h($e['nome']).' venceu a vaza '.$numero.'.'
        : ($resultado === 'ia' ? 'Seu Nivaldo venceu a vaza '.$numero.'.' : 'A vaza '.$numero.' empatou.');
    $vencedor = self::vencedorMao($e['vazas'], $e['mao_da_mao'] ?? $e['lider_vaza']);
    if ($vencedor !== null) {
        $e['placar'][$vencedor] += $e['valor_mao'];
        $e['ultimo_vencedor'] = $vencedor;
        $e['fase'] = 'fim_mao';
        $e['proxima_mao'] = $vencedor;
        $e['vez'] = null;
        $e['mensagem'] = ($vencedor === 'jogador' ? self::h($e['nome']).' ganhou a mão!' : 'Seu Nivaldo ganhou a mão!')
            .' +'.$e['valor_mao'].' ponto'.($e['valor_mao'] > 1 ? 's' : '').'.';
        if ($e['placar'][$vencedor] >= 12) self::encerrarPartida($e, $vencedor);
        return;
    }
    $lider = $resultado ?? $e['lider_vaza']; // em empate, o líder anterior torna
    $e['lider_vaza'] = $lider;
    $e['vaza_numero'] = $numero + 1;
    $e['jogadas'] = [];
    $e['vez'] = $lider;
    $e['mensagem'] = $textoResultado.' '.($lider === 'jogador' ? 'Você torna e joga primeiro.' : 'Seu Nivaldo torna e joga primeiro.');
    if ($lider === 'ia') {
        self::jogarIA($e);
        $e['vez'] = 'jogador';
    }
}

}

/**
 * CONTROLLER — recebe ações HTTP, valida a fase/vez e chama o Model.
 */
class TrucoController {
    public static function handleRequest(array $valores, array $naipes): void {
if (isset($_GET['reiniciar'])) {
    // Reinício total: apaga todos os dados da sessão, não apenas o estado do truco.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: index.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'entrar') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        if ($nome !== '') {
            $_SESSION['truco'] = [
                'nome'=>function_exists('mb_substr') ? mb_substr($nome,0,30) : substr($nome,0,30),
                'fase'=>'mesa','baralho'=>TrucoModel::criarBaralho($valores,$naipes),'cartas_usadas'=>[],
                'mao_jogador'=>[],'mao_ia'=>[],'vira'=>null,
                'mao_inicial'=>'jogador',
                'numero_mao'=>0,'jogadas'=>[],'historico_vazas'=>[],'vazas'=>[],'vaza_numero'=>1,
                'lider_vaza'=>'jogador','vez'=>null,'placar'=>['jogador'=>0,'ia'=>0],
                'valor_mao'=>1,'truco_nivel'=>1,'truco_pendente'=>false,'valor_recusa'=>0,'proxima_mao'=>'jogador','partidas'=>['jogador'=>0,'ia'=>0],
                'mensagem'=>'Clique no baralho para distribuir as cartas.'
            ];
            TrucoModel::novaMao($_SESSION['truco']);
            // primeira mão: sorteia quem começa
            $_SESSION['truco']['lider_vaza'] = $_SESSION['truco']['mao_inicial'];
        }
        header('Location: index.php'); exit;
    }

    if (isset($_SESSION['truco'])) {
        $e = &$_SESSION['truco'];
        if ($acao === 'distribuir' && $e['fase'] === 'mesa') {
            // Reconstruir um baralho limpo e único no começo da mão.
            $e['baralho'] = TrucoModel::criarBaralho($valores, $naipes);
            $e['cartas_usadas'] = [];
            $e['mao_jogador'] = [];
            $e['mao_ia'] = [];
            for ($i=0;$i<3;$i++) {
                $e['mao_jogador'][] = TrucoModel::retirarCarta($e);
                $e['mao_ia'][] = TrucoModel::retirarCarta($e);
            }
            $e['vira'] = TrucoModel::retirarCarta($e);
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
            if ($e['vez'] === 'ia') { TrucoModel::jogarIA($e); $e['vez'] = 'jogador'; }
        } elseif ($acao === 'jogar' && $e['fase'] === 'jogo' && $e['vez'] === 'jogador') {
            $carta = (string)($_POST['carta'] ?? '');
            $idx = array_search($carta, $e['mao_jogador'], true);
            if ($idx !== false) {
                array_splice($e['mao_jogador'], $idx, 1);
                $escondida = (($_POST['escondida'] ?? '0') === '1');
                if ($escondida && (int)($e['vaza_numero'] ?? 1) < 2) $escondida = false;
                $e['jogadas'][] = ['dono'=>'jogador','carta'=>$carta,'escondida'=>$escondida];
                if (count($e['jogadas']) === 1) {
                    TrucoModel::jogarIA($e);
                }
                if (count($e['jogadas']) >= 2) {
                    TrucoModel::fecharVaza($e);
                } else {
                    $e['vez'] = 'jogador';
                }
            }
        } elseif ($acao === 'truco' && $e['fase'] === 'jogo' && $e['vez'] === 'jogador'
            && empty($e['truco_pendente']) && in_array((int)($e['valor_mao'] ?? 1), [1, 6], true)) {
            $valorAtual = (int)($e['valor_mao'] ?? 1);
            $forca = TrucoModel::avaliarMaoIA($e);
            if ($valorAtual === 1) {
                // O jogador pediu truco: Seu Nivaldo olha a própria mão antes de responder.
                if ($forca <= 8 && random_int(1, 100) <= 80) {
                    TrucoModel::encerrarMaoPorDesistencia($e, 'jogador', 1, 'Seu Nivaldo olhou as cartas e resmungou: “Minhas cartas estão baixas... vou correr.” Você ganhou');
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
                    TrucoModel::encerrarMaoPorDesistencia($e, 'jogador', 6, 'Seu Nivaldo olhou as cartas e correu do nove. Você ganhou');
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
            $forca = TrucoModel::avaliarMaoIA($e);
            if ($forca <= 8 && random_int(1, 100) <= 80) {
                TrucoModel::encerrarMaoPorDesistencia($e, 'jogador', 6, 'Seu Nivaldo conferiu as cartas e correu do nove. Você ganhou');
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
            TrucoModel::encerrarMaoPorDesistencia($e, 'ia', $pontosRecusa, 'Você correu. Seu Nivaldo marca');
        } elseif ($acao === 'proxima' && $e['fase'] === 'fim_mao') {
            TrucoModel::novaMao($e);
        } elseif ($acao === 'proxima_partida' && $e['fase'] === 'fim_partida') {
            $e['placar'] = ['jogador'=>0,'ia'=>0];
            $e['numero_mao'] = 0;
            $e['mao_inicial'] = random_int(0,1) === 0 ? 'jogador' : 'ia';
            $e['proxima_mao'] = $e['mao_inicial'];
            TrucoModel::novaMao($e);
            $e['mensagem'] = 'Nova partida! A série está '.$e['partidas']['jogador'].' × '.$e['partidas']['ia'].'.';
        }
        unset($e);
        header('Location: index.php'); exit;
    }
}

    }
}

/**
 * VIEW — renderiza a interface HTML a partir do estado preparado pelo Controller/Model.
 */
class TrucoView {
    public static function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
    public static function render(): void {
        $urlCartas = 'img/cartas/';
$estado = $_SESSION['truco'] ?? null;
$maoAtual = $estado['lider_vaza'] ?? 'jogador';
$peAtual = $maoAtual === 'jogador' ? 'ia' : 'jogador';
$mensagemEstado = mb_strtolower((string)($estado['mensagem'] ?? ''), 'UTF-8');
$nivaldoExpressao = 'NivaldoEsperando.png';
if ($estado) {
    $faseNivaldo = (string)($estado['fase'] ?? '');
    $vencedorMaoNivaldo = (string)($estado['ultimo_vencedor'] ?? '');
    $vencedorPartidaNivaldo = (string)($estado['vencedor_partida'] ?? '');

    // Expressões finais são definidas pelo vencedor real, nunca pelo texto da mensagem.
    // Isso evita o bug anterior em que "Seu Nivaldo ganhou a mão" deixava o avatar bravo.
    if (in_array($faseNivaldo, ['fim_jogo', 'fim_partida'], true)) {
        $nivaldoExpressao = ($vencedorPartidaNivaldo === 'ia')
            ? 'NivaldoComemorando.png'
            : 'NivaldoBravo.png';
    } elseif ($faseNivaldo === 'fim_mao') {
        $nivaldoExpressao = ($vencedorMaoNivaldo === 'ia')
            ? 'NivaldoComemorando.png'
            : 'NivaldoBravo.png';
    } elseif (!empty($estado['truco_pendente'])
        || str_contains($mensagemEstado, 'pediu doze')
        || str_contains($mensagemEstado, 'pediu seis')
        || str_contains($mensagemEstado, 'pediu nove')) {
        $nivaldoExpressao = 'NivaldoTrucando.png';
    } elseif (str_contains($mensagemEstado, 'pensou')
        || str_contains($mensagemEstado, 'olhou as cartas')
        || str_contains($mensagemEstado, 'conferiu as cartas')) {
        $nivaldoExpressao = 'NivaldoPensando.png';
    } elseif (str_contains($mensagemEstado, 'seu nivaldo venceu a vaza')) {
        $nivaldoExpressao = 'NivaldoComemorando.png';
    } elseif (str_contains($mensagemEstado, 'você venceu a vaza')
        || str_contains($mensagemEstado, 'venceu a vaza')) {
        $nivaldoExpressao = 'NivaldoBravo.png';
    } elseif (($estado['vez'] ?? '') === 'jogador') {
        $nivaldoExpressao = 'NivaldoEsperando.png';
    } else {
        $nivaldoExpressao = 'NivaldoDesafiador.png';
    }
}
?>

<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Seu Nivaldo — Truco Paulista</title>
<style>
/* ===== AJUSTES RÁPIDOS DE LAYOUT: edite aqui ===== */
:root {
    --fundo:#173d30; --feltro:#205640; --painel:rgba(12,32,24,.72);
    --texto:#fff6df; --texto-suave:#d8e5d8; --destaque:#e9bd70;
    --carta-largura:92px; --carta-altura:136px; --espaco-cartas:12px;
    --largura-conteudo:1000px; --padding-pagina:10px; --raio-painel:18px;

    /* ===== POSIÇÃO E TAMANHO DE CADA CAIXA DA MESA =====
       Edite estes valores para mover cada elemento sem mexer no restante do CSS.
       Posições em % da tela; tamanhos em vw/vh ou %. */
    --placar-topo:1%; --placar-largura:60vw;
    --reiniciar-topo:2%; --reiniciar-direita:1.5%;
    --nivaldo-topo:23%; --nivaldo-direita:0%; --nivaldo-largura:16vw; --nivaldo-altura:23vh;
    /* POSIÇÃO INDEPENDENTE DAS DUAS MÃOS: ajuste livremente */
    --mao-nivaldo-topo:24%; --mao-nivaldo-esquerda:60%; --mao-nivaldo-largura:24%;
    --mao-jogador-topo:auto; --mao-jogador-esquerda:15%;
    --mao-jogador-baixo:1.5%; --mao-jogador-largura:70%;
    --vira-topo:60%; --vira-esquerda:60%;
    --cartas-jogadas-topo:25%; --cartas-jogadas-esquerda:50%;
    --historico-topo:26%; --historico-esquerda:1.2%; --historico-largura:23%; --historico-altura:44vh;
    /* POSIÇÃO DA MENSAGEM DURANTE O JOGO Quem ganha cada mao*/
    --mensagem-baixo:28.5%; --mensagem-esquerda:50%; --mensagem-largura:38%;

    /* POSIÇÃO DA MENSAGEM QUANDO A MÃO TERMINA */
    --fim-mao-mensagem-topo:60%; --fim-mao-mensagem-esquerda:50%; --fim-mao-mensagem-largura:60%;

    /* POSIÇÃO DO BOTÃO 'COMEÇAR A PRÓXIMA MÃO' */
    --proxima-mao-baixo:12%; --proxima-mao-esquerda:50%;

    /* POSIÇÕES DA TELA: FIM DA PARTIDA (mensagem, placares e botão) */
    --fim-partida-titulo-topo:60%; --fim-partida-titulo-esquerda:50%;
    --fim-partida-mensagem-topo:35%; --fim-partida-mensagem-esquerda:50%; --fim-partida-mensagem-largura:60%;
    --fim-partida-placar-topo:44%; --fim-partida-placar-esquerda:50%;
    --fim-partida-serie-topo:51%; --fim-partida-serie-esquerda:50%;
    --fim-partida-botao-baixo:12%; --fim-partida-botao-esquerda:50%;

    /* POSIÇÕES DA TELA: FIM DA SÉRIE */
    --fim-serie-titulo-topo:30%; --fim-serie-titulo-esquerda:50%;
    --fim-serie-mensagem-topo:42%; --fim-serie-mensagem-esquerda:50%; --fim-serie-mensagem-largura:60%;
    --fim-serie-placar-topo:52%; --fim-serie-placar-esquerda:50%;
    --fim-serie-botao-baixo:12%; --fim-serie-botao-esquerda:50%;
    --carta-mao-largura:9vw; --carta-mao-altura:15vw;
    --carta-vira-largura:10vw; --carta-vira-altura:17vw;
    --carta-jogada-largura:9vw; --carta-jogada-altura:15vw;

    /* ===== POSIÇÃO INDIVIDUAL DAS 6 CARTAS JOGADAS NA MESA =====
       P1/P2/P3 = cartas do jogador nas vazas 1/2/3.
       IA1/IA2/IA3 = cartas do Seu Nivaldo nas vazas 1/2/3.
       Ajuste top, left e width de cada slot livremente. */
    --p1-top:53%; --p1-left:32%; --p1-width:clamp(58px,7vw,98px);
    --ia1-top:43%; --ia1-left:30%; --ia1-width:clamp(58px,7vw,98px);
    --p2-top:53%; --p2-left:42%; --p2-width:clamp(58px,7vw,98px);
    --ia2-top:43%; --ia2-left:40%; --ia2-width:clamp(58px,7vw,98px);
    --p3-top:53%; --p3-left:52%; --p3-width:clamp(58px,7vw,98px);
    --ia3-top:43%; --ia3-left:50%; --ia3-width:clamp(58px,7vw,98px);

    /* ===== AJUSTES DO BOTÃO PEDIR TRUCO =====
       esquerda: 50% = centro; diminua para esquerda, aumente para direita
       baixo: distância da parte inferior da mesa
       tamanho: tamanho da letra
       largura: largura mínima do botão */
    --truco-esquerda:50%;
    --truco-baixo:22vh;
    --truco-tamanho:16px;
    --truco-largura:160px;
    --truco-padding-vertical:12px;
    --truco-padding-horizontal:18px;
}
/* ===== FIM DOS AJUSTES RÁPIDOS ===== */
*{box-sizing:border-box}
body{margin:0;min-height:100vh;padding:clamp(8px,2vw,24px);color:var(--texto);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:url('img/mesa.png') center center/cover fixed,var(--fundo);display:flex;justify-content:center;align-items:center}
.app{width:100%;max-width:1180px;min-width:0}
.painel{padding:clamp(12px,2.5vw,30px);border:1px solid rgba(255,255,255,.18);border-radius:var(--raio-painel);background:rgba(8,24,17,.22);backdrop-filter:blur(2px);box-shadow:0 18px 55px rgba(0,0,0,.35);text-align:center}
.marca{margin:0 0 8px;color:var(--destaque);font-size:12px;font-weight:800;letter-spacing:.18em;text-transform:uppercase}
h1{margin:0 0 12px;font-size:clamp(30px,5vw,48px)}
.subtitulo{margin:0 auto 26px;max-width:580px;color:var(--texto-suave);line-height:1.6}
.campo-nome{display:block;width:100%;max-width:380px;margin:0 auto 14px;padding:14px 16px;border:1px solid rgba(255,255,255,.25);border-radius:10px;background:#fffaf0;color:#18392c;font:inherit}
.botao{display:inline-block;border:0;border-radius:10px;padding:13px 22px;color:#2b2418;background:var(--destaque);font:inherit;font-weight:800;cursor:pointer;text-decoration:none}
.botao:hover{filter:brightness(1.06);transform:translateY(-1px)}
.mesa{display:grid;gap:clamp(10px,1.7vw,20px);position:relative;overflow:hidden}.mesa > *{position:relative;z-index:1}.area{padding:clamp(10px,1.8vw,18px);border-radius:14px;background:rgba(4,20,13,.68);border:1px solid rgba(255,255,255,.12)}
.cabecalho-jogo{display:flex;align-items:center;justify-content:center;gap:clamp(8px,2vw,24px);flex-wrap:wrap}.nivaldo-avatar{width:clamp(105px,17vw,190px);height:auto;max-height:220px;object-fit:contain;filter:drop-shadow(0 8px 10px rgba(0,0,0,.5));flex-shrink:0}.cabecalho-texto{min-width:0;flex:1 1 260px}.cabecalho-texto h1{overflow-wrap:anywhere}
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
@media(max-width:600px){:root{--carta-largura:clamp(48px,15vw,70px);--carta-altura:clamp(72px,22vw,104px);--espaco-cartas:6px;--padding-pagina:8px}.area{padding:10px}.subtitulo{margin-bottom:14px}.identificacoes{gap:8px}.nivaldo-avatar{width:clamp(88px,26vw,125px);max-height:145px}.historico-matriz{grid-template-columns:65px repeat(3,minmax(0,1fr))}.matriz-celula{min-width:0}.carta-mini{width:clamp(24px,7vw,34px);height:auto;max-height:50px}.jogadas{gap:12px}}
.painel.mesa{background-image:linear-gradient(rgba(7,23,15,.12),rgba(7,23,15,.22)),url('img/mesa.png');background-size:cover;background-position:center}

/* ===== MESA IMERSIVA: viewport inteiro, sem rolagem ===== */
html{width:100%;height:100%;overflow:hidden}
body{width:100%;height:100vh;height:100dvh;min-height:0;overflow:hidden;padding:0;display:block;background:#142e22 url('img/mesa.png') center center/100% 100% no-repeat}
.app{width:100%;height:100%;max-width:none;min-width:0;margin:0}
.painel.mesa{width:100%;height:100%;min-height:0;max-height:100dvh;margin:0;padding:clamp(6px,1.2vw,18px);border:0;border-radius:0;box-shadow:none;backdrop-filter:none;background:transparent;display:grid;grid-template-rows:auto minmax(0,1fr) auto;gap:clamp(4px,.7vh,10px);overflow:hidden}
.cabecalho-jogo{display:grid;grid-template-columns:auto 1fr auto;grid-template-areas:'avatar titulo placar';align-items:center;gap:clamp(5px,1vw,16px);padding:clamp(5px,.8vw,12px);background:rgba(5,18,12,.64);border:1px solid rgba(255,255,255,.18);border-radius:12px}
.nivaldo-avatar{grid-area:avatar;width:clamp(60px,9vw,132px);height:clamp(58px,13vh,145px);max-height:none;object-fit:contain}
.cabecalho-texto{grid-area:titulo;min-width:0}.cabecalho-texto h1{font-size:clamp(15px,2vw,30px);margin:0}.cabecalho-texto .subtitulo{font-size:clamp(10px,1.1vw,14px);margin:3px auto 0}.cabecalho-texto .marca{font-size:clamp(8px,.8vw,11px);margin:0 0 2px}
.cabecalho-jogo>.identificacoes:first-of-type{grid-area:placar;display:grid;gap:4px;text-align:right;font-size:clamp(9px,1vw,13px)}
.cabecalho-jogo>.identificacoes:last-child{grid-column:1/-1;display:flex;justify-content:center;gap:clamp(8px,2vw,28px);margin:0!important;padding-top:4px;border-top:1px solid rgba(255,255,255,.16);font-size:clamp(9px,1.1vw,14px)}
.painel.mesa>.area:has(.historico){display:none}
.painel.mesa>.area:has(h2){min-height:0}
.painel.mesa>.area:has(.mao){padding:clamp(5px,.8vw,12px);display:flex;flex-direction:column;justify-content:center;gap:4px}
.painel.mesa>.area:has(.mao) h2{font-size:clamp(11px,1.2vw,16px);margin:0 0 4px}
.painel.mesa>.area:has(.mao) .dica{font-size:clamp(9px,1vw,12px);margin:3px 0 0}
.painel.mesa>.area:has(.mao) .mao{gap:clamp(3px,.6vw,9px)}
.painel.mesa>.area:has(.mao) .carta{width:clamp(42px,6.2vw,88px);height:clamp(62px,9.2vw,128px)}
.painel.mesa>.area:has(.mao) .botao{padding:5px 8px;font-size:11px}
.painel.mesa>.area:has(.jogadas){display:none}
.painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)){padding:clamp(5px,.8vw,12px);display:flex;align-items:center;justify-content:center;gap:clamp(5px,1vw,12px);flex-wrap:wrap}
.painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) h2{font-size:clamp(11px,1.1vw,15px);margin:0}
.painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(40px,5.2vw,72px);height:clamp(58px,7.8vw,108px)}
.painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .dica{display:none}
.painel.mesa>.rodape{display:none}
.painel.mesa>.mensagem{align-self:center;max-width:96%;font-size:clamp(10px,1.1vw,14px);padding:6px 10px;max-height:9vh;overflow:hidden}
.painel.mesa>.area.centro{gap:4px}
.painel.mesa>.area.centro form{margin:0}
.painel.mesa .identificacoes form .botao{padding:7px 12px;font-size:clamp(10px,1vw,13px)}
.painel.mesa .identificacoes{flex-wrap:wrap}
@media(max-width:650px){
 .painel.mesa{padding:5px;gap:4px;grid-template-rows:auto minmax(0,1fr) auto}
 .cabecalho-jogo{grid-template-columns:auto 1fr;grid-template-areas:'avatar titulo' 'placar placar';gap:4px;padding:5px}
 .nivaldo-avatar{width:clamp(48px,16vw,82px);height:clamp(48px,12vh,85px)}
 .cabecalho-jogo>.identificacoes:first-of-type{grid-area:placar;display:flex;justify-content:space-around;text-align:center}
 .cabecalho-jogo>.identificacoes:last-child{grid-column:1/-1;gap:6px;padding-top:3px}
 .cabecalho-texto h1{font-size:clamp(14px,4vw,22px)}
 .painel.mesa>.area:has(.mao) .carta{width:clamp(37px,13vw,62px);height:clamp(55px,19vw,90px)}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(34px,10vw,48px);height:clamp(50px,15vw,72px)}
 .painel.mesa>.area:has(.mao){padding:4px}
 .painel.mesa>.area:has(.mao) h2{font-size:11px}
 .painel.mesa>.area:has(.mao) .dica{font-size:9px}
 .painel.mesa .identificacoes form .botao{padding:6px 8px;font-size:10px}
}
@media(max-height:650px){.nivaldo-avatar{height:8vh;width:auto}.painel.mesa>.area:has(.mao) .carta{height:10vh;width:auto}.painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{height:8vh;width:auto}}


/* ===== 6 SLOTS PERSISTENTES DAS CARTAS JOGADAS ===== */
.mesa-carta-slot{position:absolute;z-index:7;display:flex;flex-direction:column;align-items:center;gap:3px;pointer-events:none;transform:translate(-50%,-50%);}
.mesa-carta-slot img{display:block;width:var(--slot-width,clamp(58px,7vw,98px));height:auto;max-height:20vh;object-fit:contain;filter:drop-shadow(0 4px 5px rgba(0,0,0,.55));}
.mesa-carta-slot small{font-size:clamp(8px,.75vw,11px);font-weight:800;color:#fff6df;text-shadow:0 1px 3px #000;text-align:center;white-space:nowrap;}
.mesa-slot-p1{top:var(--p1-top);left:var(--p1-left);--slot-width:var(--p1-width)}
.mesa-slot-ia1{top:var(--ia1-top);left:var(--ia1-left);--slot-width:var(--ia1-width)}
.mesa-slot-p2{top:var(--p2-top);left:var(--p2-left);--slot-width:var(--p2-width)}
.mesa-slot-ia2{top:var(--ia2-top);left:var(--ia2-left);--slot-width:var(--ia2-width)}
.mesa-slot-p3{top:var(--p3-top);left:var(--p3-left);--slot-width:var(--p3-width)}
.mesa-slot-ia3{top:var(--ia3-top);left:var(--ia3-left);--slot-width:var(--ia3-width)}
@media(max-width:650px){
 .mesa-carta-slot img{max-height:15vh}
 .mesa-carta-slot small{font-size:7px}
}

/* ===== POSIÇÃO INDEPENDENTE DA MENSAGEM FINAL E DO BOTÃO PRÓXIMA MÃO ===== */
@media (min-width:651px){
 .painel.mesa>.area.centro:has(.fim-mao-proxima-form){display:block!important;position:absolute;inset:0;background:transparent;border:0;padding:0;z-index:6;pointer-events:none;}
 .painel.mesa .fim-mao-mensagem{position:absolute;top:var(--fim-mao-mensagem-topo);left:var(--fim-mao-mensagem-esquerda);transform:translate(-50%,-50%);width:var(--fim-mao-mensagem-largura);max-width:96%;margin:0;padding:10px 14px;border-radius:9px;background:rgba(0,0,0,.7);font-weight:800;pointer-events:none;}
 .painel.mesa .fim-mao-proxima-form{position:absolute;left:var(--proxima-mao-esquerda);bottom:var(--proxima-mao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;}
 .painel.mesa>.area.centro:has(.fim-mao-proxima-form)>h2,.painel.mesa>.area.centro:has(.fim-mao-proxima-form)>.dica{display:none;}
}
@media(max-width:650px){
 .painel.mesa>.area.centro:has(.fim-mao-proxima-form){display:block!important;position:absolute;inset:0;background:transparent;border:0;padding:0;z-index:6;pointer-events:none;}
 .painel.mesa .fim-mao-mensagem{position:absolute;top:var(--fim-mao-mensagem-topo);left:var(--fim-mao-mensagem-esquerda);transform:translate(-50%,-50%);width:var(--fim-mao-mensagem-largura);max-width:96%;margin:0;padding:7px 9px;border-radius:8px;background:rgba(0,0,0,.7);font-size:11px;font-weight:800;pointer-events:none;}
 .painel.mesa .fim-mao-proxima-form{position:absolute;left:var(--proxima-mao-esquerda);bottom:var(--proxima-mao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;white-space:nowrap;}
 .painel.mesa>.area.centro:has(.fim-mao-proxima-form)>h2,.painel.mesa>.area.centro:has(.fim-mao-proxima-form)>.dica{display:none;}
}

/* ===== POSIÇÕES PERSONALIZÁVEIS: FIM DA PARTIDA E FIM DA SÉRIE ===== */
@media (min-width:651px){
 .painel.mesa>.area.centro:has(.fim-partida-mensagem),
 .painel.mesa>.area.centro:has(.fim-serie-mensagem){display:block!important;position:absolute;inset:0;background:transparent;border:0;padding:0;z-index:6;pointer-events:none;}
 .painel.mesa .fim-partida-titulo,.painel.mesa .fim-serie-titulo{position:absolute;left:var(--fim-partida-titulo-esquerda);top:var(--fim-partida-titulo-topo);transform:translate(-50%,-50%);margin:0;white-space:nowrap;}
 .painel.mesa .fim-partida-mensagem,.painel.mesa .fim-serie-mensagem{position:absolute;transform:translate(-50%,-50%);margin:0;padding:10px 14px;border-radius:9px;background:rgba(0,0,0,.7);font-weight:800;text-align:center;pointer-events:none;}
 .painel.mesa .fim-partida-mensagem{top:var(--fim-partida-mensagem-topo);left:var(--fim-partida-mensagem-esquerda);width:var(--fim-partida-mensagem-largura);max-width:96%;}
 .painel.mesa .fim-partida-placar{position:absolute;top:var(--fim-partida-placar-topo);left:var(--fim-partida-placar-esquerda);transform:translate(-50%,-50%);margin:0;white-space:nowrap;}
 .painel.mesa .fim-partida-serie{position:absolute;top:var(--fim-partida-serie-topo);left:var(--fim-partida-serie-esquerda);transform:translate(-50%,-50%);margin:0;white-space:nowrap;}
 .painel.mesa .fim-partida-botao{position:absolute;left:var(--fim-partida-botao-esquerda);bottom:var(--fim-partida-botao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;}
 .painel.mesa .fim-serie-titulo{left:var(--fim-serie-titulo-esquerda);top:var(--fim-serie-titulo-topo);}
 .painel.mesa .fim-serie-mensagem{top:var(--fim-serie-mensagem-topo);left:var(--fim-serie-mensagem-esquerda);width:var(--fim-serie-mensagem-largura);max-width:96%;}
 .painel.mesa .fim-serie-placar{position:absolute;top:var(--fim-serie-placar-topo);left:var(--fim-serie-placar-esquerda);transform:translate(-50%,-50%);margin:0;white-space:nowrap;}
 .painel.mesa .fim-serie-botao{position:absolute;left:var(--fim-serie-botao-esquerda);bottom:var(--fim-serie-botao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;}
 .painel.mesa>.area.centro:has(.fim-partida-mensagem)>h2:not(.fim-partida-titulo),
 .painel.mesa>.area.centro:has(.fim-partida-mensagem)>.dica:not(.fim-partida-placar):not(.fim-partida-serie),
 .painel.mesa>.area.centro:has(.fim-serie-mensagem)>h2:not(.fim-serie-titulo),
 .painel.mesa>.area.centro:has(.fim-serie-mensagem)>.dica:not(.fim-serie-placar){display:none;}
}
@media(max-width:650px){
 .painel.mesa>.area.centro:has(.fim-partida-mensagem),
 .painel.mesa>.area.centro:has(.fim-serie-mensagem){display:block!important;position:absolute;inset:0;background:transparent;border:0;padding:0;z-index:6;pointer-events:none;}
 .painel.mesa .fim-partida-titulo,.painel.mesa .fim-serie-titulo{position:absolute;left:var(--fim-partida-titulo-esquerda);top:var(--fim-partida-titulo-topo);transform:translate(-50%,-50%);margin:0;font-size:clamp(13px,4vw,22px);white-space:nowrap;}
 .painel.mesa .fim-partida-mensagem,.painel.mesa .fim-serie-mensagem{position:absolute;transform:translate(-50%,-50%);margin:0;padding:7px 9px;border-radius:8px;background:rgba(0,0,0,.7);font-size:11px;font-weight:800;text-align:center;pointer-events:none;}
 .painel.mesa .fim-partida-mensagem{top:var(--fim-partida-mensagem-topo);left:var(--fim-partida-mensagem-esquerda);width:var(--fim-partida-mensagem-largura);max-width:96%;}
 .painel.mesa .fim-partida-placar{position:absolute;top:var(--fim-partida-placar-topo);left:var(--fim-partida-placar-esquerda);transform:translate(-50%,-50%);margin:0;font-size:10px;white-space:nowrap;}
 .painel.mesa .fim-partida-serie{position:absolute;top:var(--fim-partida-serie-topo);left:var(--fim-partida-serie-esquerda);transform:translate(-50%,-50%);margin:0;font-size:10px;white-space:nowrap;}
 .painel.mesa .fim-partida-botao{position:absolute;left:var(--fim-partida-botao-esquerda);bottom:var(--fim-partida-botao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;white-space:nowrap;}
 .painel.mesa .fim-serie-titulo{left:var(--fim-serie-titulo-esquerda);top:var(--fim-serie-titulo-topo);}
 .painel.mesa .fim-serie-mensagem{top:var(--fim-serie-mensagem-topo);left:var(--fim-serie-mensagem-esquerda);width:var(--fim-serie-mensagem-largura);max-width:96%;}
 .painel.mesa .fim-serie-placar{position:absolute;top:var(--fim-serie-placar-topo);left:var(--fim-serie-placar-esquerda);transform:translate(-50%,-50%);margin:0;font-size:10px;white-space:nowrap;}
 .painel.mesa .fim-serie-botao{position:absolute;left:var(--fim-serie-botao-esquerda);bottom:var(--fim-serie-botao-baixo);transform:translateX(-50%);margin:0;pointer-events:auto;white-space:nowrap;}
 .painel.mesa>.area.centro:has(.fim-partida-mensagem)>h2:not(.fim-partida-titulo),.painel.mesa>.area.centro:has(.fim-partida-mensagem)>.dica:not(.fim-partida-placar):not(.fim-partida-serie),.painel.mesa>.area.centro:has(.fim-serie-mensagem)>h2:not(.fim-serie-titulo),.painel.mesa>.area.centro:has(.fim-serie-mensagem)>.dica:not(.fim-serie-placar){display:none;}
}

/* ===== LAYOUT VISUAL NO ESTILO DA REFERÊNCIA DO JOGADOR ===== */
@media (min-width:651px){
 body{background-image:url('img/mesa.png');background-position:center;background-size:100% 100%;}
 .painel.mesa{position:relative;display:block;width:100%;height:100%;padding:0;background:transparent!important;}
 .cabecalho-jogo{position:absolute;left:50%;top:var(--placar-topo);transform:translateX(-50%);width:min(var(--placar-largura),760px);min-height:62px;display:flex;flex-direction:column;gap:3px;background:rgba(10,8,8,.64);border:1px solid rgba(238,174,30,.55);border-radius:12px;padding:7px 14px;z-index:5}
 .nivaldo-avatar{position:absolute;right:1.5%;top:19%;width:clamp(100px,14vw,190px);height:clamp(100px,19vh,210px);object-fit:contain;z-index:4;}
 .cabecalho-texto{display:none}
 .cabecalho-jogo>.identificacoes:first-of-type{display:none!important}
 .cabecalho-jogo>.identificacoes:last-child{display:flex;justify-content:center;gap:clamp(12px,3vw,44px);border:0;padding:0;font-size:clamp(12px,1.2vw,17px);}
 .painel.mesa>.area:has(.historico){display:none!important}
 .painel.mesa>.area:has(.mao){position:absolute;left:1.5%;width:19%;padding:8px;background:rgba(15,10,10,.48);border:1px solid rgba(240,174,20,.35);border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;z-index:3}
 .painel.mesa>.area:has(.mao) h2{font-size:clamp(12px,1.2vw,17px);margin:0;text-transform:uppercase;text-shadow:1px 2px 3px #000}
 .painel.mesa>.area:has(.mao) .mao{gap:4px}
 .painel.mesa>.area:has(.mao) .carta{width:clamp(36px,5vw,72px);height:clamp(54px,7.2vw,104px)}
 .painel.mesa>.area:has(.mao) .dica{font-size:11px}
 .painel.mesa>.area:has(.mao):not(:has(button.carta-botao)){left:1.5%;top:31%;}
 .painel.mesa>.area:has(.mao):has(button.carta-botao){left:1.5%;bottom:2%;width:auto;max-width:48%;right:auto;align-items:flex-start;background:transparent;border:0;}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .mao{justify-content:flex-start;gap:clamp(5px,1vw,14px)}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .carta{width:clamp(58px,8vw,112px);height:clamp(86px,11.5vw,160px)}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)){position:absolute;left:50%;top:43%;transform:translate(-50%,-50%);background:transparent;border:0;padding:0;z-index:2;}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) h2{display:none}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(58px,7vw,98px);height:clamp(86px,10vw,140px)}
 .painel.mesa>.area:has(.jogadas){display:flex!important;position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);background:transparent;border:0;padding:0;z-index:3;}
 .painel.mesa>.area:has(.jogadas) h2{display:none}
 .painel.mesa>.area:has(.jogadas) .jogadas{gap:clamp(8px,1.5vw,24px)}
 .painel.mesa>.area:has(.jogadas) .carta{width:clamp(58px,7vw,98px);height:clamp(86px,10vw,140px)}
 .painel.mesa>.mensagem{position:absolute;left:var(--mensagem-esquerda);bottom:var(--mensagem-baixo);transform:translateX(-50%);width:var(--mensagem-largura);max-width:96%;z-index:6;background:rgba(0,0,0,.7)}
 .painel.mesa>.rodape{display:block!important;position:absolute;right:var(--reiniciar-direita);top:var(--reiniciar-topo);margin:0;z-index:8}
 .botao-reiniciar{padding:8px 12px;font-size:12px}
}
@media(max-width:650px){
 html,body{overflow:hidden;width:100%;height:100%;}
 .painel.mesa{position:relative;width:100%;height:100%;display:block;padding:4px;background:transparent!important;}
 .cabecalho-jogo{position:absolute;top:0;left:50%;transform:translateX(-50%);width:70%;display:flex;flex-direction:column;padding:4px;gap:2px;z-index:5;background:rgba(0,0,0,.65)}
 .cabecalho-texto{display:none}.nivaldo-avatar{position:absolute;right:0;top:15%;width:25vw;height:14vh;z-index:4}
 .cabecalho-jogo>.identificacoes:first-of-type{display:none!important}
 .cabecalho-jogo>.identificacoes:last-child{display:flex;gap:6px;font-size:9px;padding:0;border:0}
 .painel.mesa>.area:has(.historico){display:none!important}
 .painel.mesa>.area:has(.mao){position:absolute;left:0;width:36%;padding:3px;background:rgba(0,0,0,.25);border:0;display:flex;align-items:center;gap:3px}
 .painel.mesa>.area:has(.mao) h2{font-size:10px;margin:0}.painel.mesa>.area:has(.mao) .carta{width:clamp(34px,11vw,56px);height:auto;max-height:13vh}
 .painel.mesa>.area:has(.mao):not(:has(button.carta-botao)){top:31%}
 .painel.mesa>.area:has(.mao):has(button.carta-botao){bottom:1%;width:60%;max-width:60%;background:transparent;border:0;align-items:flex-start}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .carta{width:clamp(43px,14vw,72px);height:auto;max-height:18vh}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)){position:absolute;top:43%;left:50%;transform:translate(-50%,-50%);background:transparent;border:0;padding:0}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) h2{display:none}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(40px,12vw,62px);height:auto;max-height:15vh}
 .painel.mesa>.area:has(.jogadas){display:flex!important;position:absolute;top:52%;left:50%;transform:translate(-50%,-50%);background:transparent;border:0;padding:0}
 .painel.mesa>.area:has(.jogadas) h2{display:none}.painel.mesa>.area:has(.jogadas) .carta{width:clamp(40px,12vw,62px);height:auto;max-height:15vh}
 .painel.mesa>.mensagem{position:absolute;bottom:21%;left:50%;transform:translateX(-50%);max-width:95%;font-size:10px;z-index:6}
 .painel.mesa>.rodape{display:block!important;position:absolute;right:2px;top:2px;z-index:8;margin:0}.botao-reiniciar{font-size:9px;padding:5px 7px}
}


.matriz-celula.carta-vencedora{background:rgba(233,189,112,.24)!important;border:1px solid #e9bd70!important;box-shadow:0 0 10px rgba(233,189,112,.45)}
.matriz-celula.carta-vencedora .carta-mini{filter:brightness(1.16) drop-shadow(0 0 5px rgba(255,220,130,.85));transform:scale(1.04)}
/* ===== AJUSTE DE MESA: manilha no topo, histórico permanente e cartas maiores ===== */
@media (min-width:651px){
 .painel.mesa>.area:has(.historico){display:block!important;position:absolute;left:var(--historico-esquerda);top:var(--historico-topo);width:var(--historico-largura);max-height:var(--historico-altura);overflow:hidden;padding:8px;background:rgba(8,8,8,.68);border:1px solid rgba(238,174,30,.65);border-radius:12px;z-index:4}
 .painel.mesa>.area:has(.historico)>h2{font-size:clamp(12px,1.2vw,17px);margin:0 0 6px;color:#f4d28a}
 .historico-matriz{grid-template-columns:52px repeat(3,minmax(0,1fr));gap:4px}
 .matriz-cabecalho{font-size:clamp(9px,.9vw,12px)}
 .matriz-rotulo{font-size:clamp(9px,.9vw,12px)}
 .matriz-celula{min-height:0;padding:4px;background:rgba(0,0,0,.24)}
 .carta-mini{width:clamp(38px,4.3vw,62px);height:auto;max-height:9vh}
 .painel.mesa>.area:has(.mao):not(:has(button.carta-botao)){left:var(--mao-nivaldo-esquerda);top:var(--mao-nivaldo-topo);bottom:auto;width:var(--mao-nivaldo-largura);}
 .painel.mesa>.area:has(.mao):has(button.carta-botao){left:50%;transform:translateX(-50%);bottom:var(--mao-jogador-baixo);width:var(--mao-jogador-largura);max-width:90%;align-items:center;background:rgba(0,0,0,.2);border:1px solid rgba(255,255,255,.12);}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .mao{justify-content:center;gap:clamp(12px,2vw,28px)}
 .painel.mesa>.area:has(.mao) .carta{width:clamp(78px,var(--carta-mao-largura),132px);height:clamp(116px,var(--carta-mao-altura),190px)}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)){top:var(--vira-topo);left:var(--vira-esquerda);transform:translate(-50%,-50%);}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(72px,8vw,116px);height:clamp(108px,12vw,164px)}
 .painel.mesa>.area:has(.jogadas){top:var(--cartas-jogadas-topo);left:var(--cartas-jogadas-esquerda);}
 .painel.mesa>.area:has(.jogadas) .carta{width:clamp(78px,9vw,132px);height:clamp(116px,15vw,190px)}
 .nivaldo-avatar{right:var(--nivaldo-direita);top:var(--nivaldo-topo);width:clamp(120px,var(--nivaldo-largura),220px);height:clamp(130px,var(--nivaldo-altura),250px)}
}
@media(max-width:650px){
 /* Ajustes independentes para celular */
 :root{--historico-esquerda:1%;--historico-topo:23%;--historico-largura:34%;--historico-altura:25vh;
 --nivaldo-topo:15%;--nivaldo-direita:0%;--nivaldo-largura:25vw;--nivaldo-altura:14vh;
 --mao-nivaldo-topo:31%;--mao-nivaldo-esquerda:0%;--mao-nivaldo-largura:36%;
 --mao-jogador-baixo:1%;--mao-jogador-largura:76%;--vira-topo:16%;--cartas-jogadas-topo:49%;}
 .painel.mesa>.area:has(.historico){display:block!important;position:absolute;left:var(--historico-esquerda);top:var(--historico-topo);width:var(--historico-largura);max-height:var(--historico-altura);overflow:hidden;padding:3px;background:rgba(0,0,0,.52);border:1px solid rgba(238,174,30,.45);z-index:4}
 .painel.mesa>.area:has(.historico)>h2{font-size:9px;margin:0 0 3px}
 .historico-matriz{grid-template-columns:28px repeat(3,minmax(0,1fr));gap:2px}
 .matriz-cabecalho,.matriz-rotulo{font-size:7px;padding:2px 0}
 .matriz-celula{min-height:0;padding:1px;gap:1px}
 .carta-mini{width:clamp(20px,6vw,30px);height:auto;max-height:5vh}
 .matriz-celula small{font-size:6px}
 .painel.mesa>.area:has(.mao):has(button.carta-botao){left:50%;transform:translateX(-50%);width:76%;max-width:76%;bottom:1%;align-items:center}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .mao{justify-content:center;gap:clamp(7px,2vw,14px)}
 .painel.mesa>.area:has(.mao):has(button.carta-botao) .carta{width:clamp(55px,17vw,82px);height:auto;max-height:18vh}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)){top:16%}
 .painel.mesa>.area:has(h2):not(:has(.mao)):not(:has(.jogadas)):not(:has(.historico)) .carta{width:clamp(48px,14vw,68px);height:auto;max-height:13vh}
 .painel.mesa>.area:has(.jogadas){top:49%}
 .painel.mesa>.area:has(.jogadas) .carta{width:clamp(48px,14vw,68px);height:auto;max-height:13vh}
}


/* Orientação: em celular vertical, orientar o jogador a girar o aparelho. */
.aviso-virar-tela{display:none}
@media screen and (max-width:650px) and (orientation:portrait){
  body:has(.painel.mesa) .aviso-virar-tela{position:fixed;inset:0;z-index:99999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:28px;text-align:center;background:rgba(8,22,15,.97);color:#fff6df}
  .aviso-virar-tela .icone-girar{font-size:clamp(52px,16vw,90px);line-height:1;animation:girar-pulso 1.8s ease-in-out infinite}
  .aviso-virar-tela h2{font-size:clamp(22px,6vw,32px);margin:0;color:#e9bd70}
  .aviso-virar-tela p{font-size:clamp(14px,4vw,18px);max-width:320px;margin:0;line-height:1.5}
  @keyframes girar-pulso{0%,100%{transform:rotate(0)}50%{transform:rotate(90deg)}}
}
@media screen and (orientation:landscape){.aviso-virar-tela{display:none!important}}
@media screen and (min-width:1600px){
  /* Em TVs e monitores grandes, a mesa ocupa o espaço sem distorcer os controles. */
  .painel.mesa{font-size:clamp(16px,1vw,22px)}
}


/* ===== CONTROLES DE TRUCO: POSIÇÃO E TAMANHO AJUSTÁVEIS ===== */
.painel.mesa>.area:has(button.carta-botao) > form:has(input[name="acao"][value="truco"]),
.painel.mesa>.area:has(button.carta-botao) > form:has(input[name="acao"][value="pedir_nove"]) {
  position:absolute !important;
  left:var(--truco-esquerda) !important;
  bottom:var(--truco-baixo) !important;
  transform:translateX(-50%) !important;
  z-index:50 !important;
  margin:0 !important;
}
.painel.mesa>.area:has(button.carta-botao) > form:has(input[name="acao"][value="truco"]) .botao,
.painel.mesa>.area:has(button.carta-botao) > form:has(input[name="acao"][value="pedir_nove"]) .botao {
  display:inline-flex !important;
  align-items:center;
  justify-content:center;
  width:var(--truco-largura) !important;
  min-width:var(--truco-largura) !important;
  padding:var(--truco-padding-vertical) var(--truco-padding-horizontal) !important;
  font-size:var(--truco-tamanho) !important;
  line-height:1.2 !important;
  white-space:nowrap !important;
  visibility:visible !important;
  opacity:1 !important;
}
.painel.mesa>.area:has(button.carta-botao) .identificacoes:has(input[name="acao"][value="aceitar_seis"]) {
  position:absolute !important;
  left:var(--truco-esquerda) !important;
  bottom:var(--truco-baixo) !important;
  transform:translateX(-50%) !important;
  z-index:50 !important;
  width:max-content;
  max-width:96%;
  display:flex;
  flex-wrap:wrap;
  justify-content:center;
  gap:8px;
  padding:8px;
  border-radius:12px;
  background:rgba(0,0,0,.78);
}
.painel.mesa>.area:has(button.carta-botao) .identificacoes:has(input[name="acao"][value="aceitar_seis"]) .botao {
  font-size:var(--truco-tamanho) !important;
  padding:var(--truco-padding-vertical) var(--truco-padding-horizontal) !important;
  white-space:nowrap;
}
@media(max-width:650px) {
  :root {
    --truco-baixo:20vh;
    --truco-tamanho:13px;
    --truco-largura:132px;
    --truco-padding-vertical:9px;
    --truco-padding-horizontal:10px;
  }
}


/* ===== MÃOS FIXAS NA MESA, COM POSIÇÕES INDEPENDENTES ===== */
@media (min-width:651px){
  .painel.mesa>.area:has(.mao):not(:has(button.carta-botao)){
    position:absolute!important;left:var(--mao-nivaldo-esquerda)!important;top:var(--mao-nivaldo-topo)!important;
    bottom:auto!important;width:var(--mao-nivaldo-largura)!important;z-index:7!important;
    display:flex!important;visibility:visible!important;opacity:1!important;
  }
  .painel.mesa>.area:has(.mao):has(button.carta-botao){
    position:absolute!important;left:var(--mao-jogador-esquerda)!important;top:var(--mao-jogador-topo)!important;
    bottom:var(--mao-jogador-baixo)!important;width:var(--mao-jogador-largura)!important;max-width:90%!important;
    transform:none!important;z-index:7!important;display:flex!important;visibility:visible!important;opacity:1!important;
    align-items:center!important;
  }
}
@media (max-width:650px){
  .painel.mesa>.area:has(.mao):not(:has(button.carta-botao)){
    position:absolute!important;left:var(--mao-nivaldo-esquerda)!important;top:var(--mao-nivaldo-topo)!important;
    bottom:auto!important;width:var(--mao-nivaldo-largura)!important;z-index:7!important;
    display:flex!important;visibility:visible!important;opacity:1!important;
  }
  .painel.mesa>.area:has(.mao):has(button.carta-botao){
    position:absolute!important;left:var(--mao-jogador-esquerda)!important;top:var(--mao-jogador-topo)!important;
    bottom:var(--mao-jogador-baixo)!important;width:var(--mao-jogador-largura)!important;max-width:90%!important;
    transform:none!important;z-index:7!important;display:flex!important;visibility:visible!important;opacity:1!important;
    align-items:center!important;
  }
}


/* ===== CARTAS JOGADAS FICAM VISÍVEIS NA LATERAL =====
   As cartas saem da mão quando jogadas, mas permanecem no histórico lateral
   da vaza; assim não desaparecem da mesa quando a jogada central é limpa. */
.painel.mesa>.area:has(.historico){
  display:block!important;visibility:visible!important;opacity:1!important;
  position:absolute!important;left:var(--historico-esquerda)!important;top:var(--historico-topo)!important;
  right:auto!important;bottom:auto!important;width:var(--historico-largura)!important;
  max-height:var(--historico-altura)!important;overflow:auto!important;
  padding:8px!important;background:rgba(8,8,8,.72)!important;
  border:1px solid rgba(238,174,30,.65)!important;border-radius:12px!important;z-index:6!important;
}
.painel.mesa>.area:has(.historico)>h2{display:block!important;color:#f4d28a!important;font-size:clamp(10px,1vw,15px)!important;margin:0 0 6px!important;}
.historico-matriz{grid-template-columns:clamp(34px,4vw,52px) repeat(3,minmax(0,1fr))!important;gap:3px!important;}
.matriz-celula{min-width:0!important;overflow:hidden!important;}
.carta-mini{width:clamp(22px,3.5vw,48px)!important;height:auto!important;max-height:8vh!important;object-fit:contain!important;}
@media(max-width:650px){
  .painel.mesa>.area:has(.historico){left:var(--historico-esquerda)!important;top:var(--historico-topo)!important;width:var(--historico-largura)!important;max-height:var(--historico-altura)!important;padding:3px!important;}
  .painel.mesa>.area:has(.historico)>h2{font-size:8px!important;margin-bottom:2px!important;}
  .historico-matriz{grid-template-columns:clamp(20px,5vw,28px) repeat(3,minmax(0,1fr))!important;gap:1px!important;}
  .matriz-cabecalho,.matriz-rotulo{font-size:6px!important;padding:1px 0!important;}
  .matriz-celula{padding:1px!important;gap:1px!important;}
  .carta-mini{width:clamp(14px,4.3vw,24px)!important;max-height:5vh!important;}
  .matriz-celula small{font-size:5px!important;overflow-wrap:anywhere;}
}

</style>
</head>
<body>
<div class="aviso-virar-tela" role="status" aria-live="polite">
  <div class="icone-girar" aria-hidden="true">📱 ↻</div>
  <h2>Vire o celular, mano!</h2>
  <p>O truco foi feito para jogar com a tela deitada. Gire o aparelho para a horizontal e bora jogar! 🃏</p>
</div>
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
<header class="cabecalho-jogo">
<img class="nivaldo-avatar" src="img/<?= TrucoView::h($nivaldoExpressao) ?>" alt="Seu Nivaldo">
<div class="cabecalho-texto"><p class="marca">Café do Seu Nivaldo</p>
<h1>Boa partida, <?= TrucoView::h($estado['nome']) ?>!</h1>
<p class="subtitulo">Você está à mesa com Seu Nivaldo.</p></div>
<div class="identificacoes">
<div class="identificacao"><strong>☕ <?= TrucoView::h($estado['nome']) ?></strong><span class="selo <?= $maoAtual==='jogador'?'mao':'' ?>"><?= $maoAtual==='jogador'?'MÃO':'PÉ' ?></span></div>
<div class="identificacao"><strong>🃏 Seu Nivaldo</strong><span class="selo <?= $maoAtual==='ia'?'mao':'' ?>"><?= $maoAtual==='ia'?'MÃO':'PÉ' ?></span></div>
</div>
<div class="identificacoes" style="margin-top:14px">
  <strong>Placar da partida: <?= TrucoView::h((string)$estado['placar']['jogador']) ?> × <?= TrucoView::h((string)$estado['placar']['ia']) ?></strong>
  <strong>Série (primeiro a 3): <?= TrucoView::h((string)($estado['partidas']['jogador'] ?? 0)) ?> × <?= TrucoView::h((string)($estado['partidas']['ia'] ?? 0)) ?></strong>
  <strong>Mão <?= TrucoView::h((string)$estado['numero_mao']) ?> · Vaza <?= TrucoView::h((string)($estado['vaza_numero'] ?? 1)) ?>/3 · Vale <?= TrucoView::h((string)($estado['valor_mao'] ?? 1)) ?> ponto(s)</strong>
</div>
</header>

<?php
/* As seis posições são fixas e independentes do histórico visual.
   A carta é obtida do histórico já fechado + das jogadas da vaza atual.
   Só somem quando uma nova mão começa e o estado da mão é reiniciado. */
$cartasSlots = [];
foreach (($estado['historico_vazas'] ?? []) as $vazaSlot) {
    $numSlot = (int)($vazaSlot['numero'] ?? 0);
    if ($numSlot < 1 || $numSlot > 3) continue;
    foreach (($vazaSlot['jogadas'] ?? []) as $jSlot) {
        $donoSlot = ($jSlot['dono'] ?? '') === 'jogador' ? 'p' : 'ia';
        $cartasSlots[$donoSlot.$numSlot] = $jSlot;
    }
}
if (in_array(($estado['fase'] ?? ''), ['jogo','fim_mao','fim_partida','fim_jogo'], true)) {
    $numSlot = (int)($estado['vaza_numero'] ?? 1);
    if ($numSlot >= 1 && $numSlot <= 3) {
        foreach (($estado['jogadas'] ?? []) as $jSlot) {
            $donoSlot = ($jSlot['dono'] ?? '') === 'jogador' ? 'p' : 'ia';
            $cartasSlots[$donoSlot.$numSlot] = $jSlot;
        }
    }
}
$definicoesSlots = [
    'p1'=>['dono'=>'jogador','numero'=>1,'rotulo'=>$estado['nome']],
    'ia1'=>['dono'=>'ia','numero'=>1,'rotulo'=>'Seu Nivaldo'],
    'p2'=>['dono'=>'jogador','numero'=>2,'rotulo'=>$estado['nome']],
    'ia2'=>['dono'=>'ia','numero'=>2,'rotulo'=>'Seu Nivaldo'],
    'p3'=>['dono'=>'jogador','numero'=>3,'rotulo'=>$estado['nome']],
    'ia3'=>['dono'=>'ia','numero'=>3,'rotulo'=>'Seu Nivaldo'],
];
foreach ($definicoesSlots as $idSlot=>$defSlot):
    $chaveSlot = ($defSlot['dono'] === 'jogador' ? 'p' : 'ia').$defSlot['numero'];
    $jSlot = $cartasSlots[$chaveSlot] ?? null;
?>
<div class="mesa-carta-slot mesa-slot-<?= $idSlot ?>" aria-label="Carta <?= $idSlot ?>">
<?php if ($jSlot): ?>
  <img src="<?= TrucoView::h($urlCartas.(!empty($jSlot['escondida']) ? 'costas.png' : $jSlot['carta'])) ?>" alt="<?= !empty($jSlot['escondida']) ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($jSlot['carta'])) ?>" title="<?= !empty($jSlot['escondida']) ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($jSlot['carta'])) ?>">
  <small><?= TrucoView::h($defSlot['rotulo']) ?> · Vaza <?= $defSlot['numero'] ?></small>
<?php endif; ?>
</div>
<?php endforeach; ?>

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
        : ($resultadoHist === 'ia' ? 'Seu Nivaldo venceu' : ($resultadoHist === 'jogador' ? TrucoView::h($estado['nome']).' venceu' : 'Empate')));
  ?>
  <div class="matriz-celula <?= ($vazaHist && empty($vazaHist['em_andamento']) && ($vazaHist['resultado'] ?? null)==='ia') ? 'carta-vencedora' : '' ?>">
    <?php if ($cartaHist): ?>
      <img class="carta-mini" src="<?= TrucoView::h($urlCartas.($escondidaHist ? 'costas.png' : $cartaHist)) ?>" alt="<?= $escondidaHist ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?>" title="<?= $escondidaHist ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?>">
      <small><?= $escondidaHist ? 'Escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?></small>
    <?php else: ?><small><?= $vazaHist ? 'Aguardando carta' : '—' ?></small><?php endif; ?>
    <?php if ($vazaHist && empty($vazaHist['em_andamento'])): ?><small><?= $rotuloResultado ?></small><?php endif; ?>
  </div>
  <?php endfor; ?>
  <div class="matriz-rotulo"><?= TrucoView::h($estado['nome']) ?></div>
  <?php for ($col=1; $col<=3; $col++):
    $vazaHist = $porVaza[$col] ?? null; $cartaHist = null; $escondidaHist = false;
    if ($vazaHist) foreach (($vazaHist['jogadas'] ?? []) as $jHist) {
        if (($jHist['dono'] ?? '') === 'jogador') { $cartaHist = $jHist['carta']; $escondidaHist = !empty($jHist['escondida']); }
    }
  ?>
  <div class="matriz-celula <?= ($vazaHist && empty($vazaHist['em_andamento']) && ($vazaHist['resultado'] ?? null)==='jogador') ? 'carta-vencedora' : '' ?>">
    <?php if ($cartaHist): ?>
      <img class="carta-mini" src="<?= TrucoView::h($urlCartas.($escondidaHist ? 'costas.png' : $cartaHist)) ?>" alt="<?= $escondidaHist ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?>" title="<?= $escondidaHist ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?>">
      <small><?= $escondidaHist ? 'Escondida' : TrucoView::h(TrucoModel::nomeCarta($cartaHist)) ?></small>
    <?php else: ?><small><?= $vazaHist ? 'Aguardando carta' : '—' ?></small><?php endif; ?>
  </div>
  <?php endfor; ?>
</div>
</div>
</section>

<?php if ($estado['fase'] === 'fim_jogo'): ?>
<div class="area centro fim-serie-tela">
<h2 class="fim-serie-titulo">Fim da série!</h2>
<p class="subtitulo fim-serie-mensagem"><?= TrucoView::h($estado['mensagem']) ?></p>
<p class="dica fim-serie-placar">Placar da série: <?= TrucoView::h((string)$estado['partidas']['jogador']) ?> × <?= TrucoView::h((string)$estado['partidas']['ia']) ?> partidas.</p>
<a class="botao fim-serie-botao" href="index.php?reiniciar=1">Começar uma nova série</a>
</div>
<?php elseif ($estado['fase'] === 'fim_partida'): ?>
<div class="area centro fim-partida-tela">
<h2 class="fim-partida-titulo">Partida encerrada!</h2>
<p class="subtitulo fim-partida-mensagem"><?= TrucoView::h($estado['mensagem']) ?></p>
<p class="dica fim-partida-placar">Placar desta partida: <?= TrucoView::h((string)($estado['placar_partida_final']['jogador'] ?? $estado['placar']['jogador'])) ?> × <?= TrucoView::h((string)($estado['placar_partida_final']['ia'] ?? $estado['placar']['ia'])) ?>.</p>
<p class="dica fim-partida-serie">Série: <?= TrucoView::h((string)$estado['partidas']['jogador']) ?> × <?= TrucoView::h((string)$estado['partidas']['ia']) ?>. Vence quem ganhar 3 partidas.</p>
<form class="fim-partida-botao" method="post" action="index.php"><input type="hidden" name="acao" value="proxima_partida"><button class="botao" type="submit">Começar a próxima partida</button></form>
</div>
<?php elseif ($estado['fase'] === 'fim_mao'): ?>
<div class="area centro">
<h2>Mão encerrada!</h2>
<p class="subtitulo fim-mao-mensagem"><?= TrucoView::h($estado['mensagem']) ?></p>
<p class="dica">O vencedor da mão será a MÃO na próxima distribuição.</p>
<form class="fim-mao-proxima-form" method="post" action="index.php"><input type="hidden" name="acao" value="proxima"><button class="botao" type="submit">Começar a próxima mão</button></form>
</div>
<?php elseif ($estado['fase'] === 'mesa'): ?>
<div class="area centro">
<h2>O baralho está na mesa</h2>
<form method="post" action="index.php">
<input type="hidden" name="acao" value="distribuir">
<button class="baralho-botao" type="submit" aria-label="Clicar no baralho para distribuir as cartas"><img class="carta" src="<?= TrucoView::h($urlCartas.'costas.png') ?>" alt="Costas do baralho"></button>
</form>
<p class="dica">Clique nas costas do baralho para distribuir três cartas para cada jogador e virar uma carta.</p>
</div>
<?php else: ?>
<div class="mensagem" aria-live="polite"><?= TrucoView::h($estado['mensagem'] ?? 'Sua vez de jogar!') ?></div>
<section class="area">
<h2>Seu Nivaldo</h2>
<div class="mao">
<?php foreach ($estado['mao_ia'] as $_carta): ?>
<img class="carta" src="<?= TrucoView::h($urlCartas.'costas.png') ?>" alt="Carta virada para baixo">
<?php endforeach; ?>
</div>
</section>
<section class="area centro">
<h2>Vira</h2>
<img class="carta" src="<?= TrucoView::h($urlCartas.$estado['vira']) ?>" alt="<?= TrucoView::h(TrucoModel::nomeCarta($estado['vira'])) ?>">
<p class="dica">A vira define a manilha desta mão. As cartas jogadas aparecem abaixo.</p>
</section>
<section class="area">
<h2>Mesa de jogo</h2>
<div class="jogadas">
<?php if (empty($estado['jogadas'])): ?>
<p class="dica">As cartas jogadas aparecerão aqui.</p>
<?php else: foreach ($estado['jogadas'] as $jogada): ?>
<div class="jogada">
<small><?= $jogada['dono']==='jogador' ? TrucoView::h($estado['nome']) : 'Seu Nivaldo' ?></small>
<img class="carta" src="<?= TrucoView::h($urlCartas.(!empty($jogada['escondida']) ? 'costas.png' : $jogada['carta'])) ?>" alt="<?= !empty($jogada['escondida']) ? 'Carta escondida' : TrucoView::h(TrucoModel::nomeCarta($jogada['carta'])) ?>">
</div>
<?php endforeach; endif; ?>
</div>
</section>
<section class="area">
<h2>Sua mão, <?= TrucoView::h($estado['nome']) ?></h2>
<div class="mao">
<?php foreach ($estado['mao_jogador'] as $_carta): ?>
<form method="post" action="index.php" style="margin:0;display:grid;justify-items:center;gap:5px">
<input type="hidden" name="acao" value="jogar">
<input type="hidden" name="carta" value="<?= TrucoView::h($_carta) ?>">
<button class="carta-botao" type="submit" name="escondida" value="0" <?= ($estado['vez'] ?? '')!=='jogador' || !empty($estado['truco_pendente']) ? 'disabled' : '' ?> aria-label="Jogar <?= TrucoView::h(TrucoModel::nomeCarta($_carta)) ?>">
<img class="carta" src="<?= TrucoView::h($urlCartas.$_carta) ?>" alt="<?= TrucoView::h(TrucoModel::nomeCarta($_carta)) ?>">
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
<div class="rodape"><a class="botao botao-reiniciar" href="index.php?reiniciar=1" onclick="return confirm('Tem certeza? Isso vai apagar a sessão e zerar toda a série.')">↻ Reiniciar do zero</a></div>
</section>
<?php endif; ?>
</main>
</body>
</html>
<?php
    }
}

TrucoController::handleRequest($valores, $naipes);
TrucoView::render();
