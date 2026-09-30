<?php
/*
 * Carrega o núcleo da API da loja Odin Focus.
 * Usado pela API (api/index.php), pelas tarefas diárias (api/cron.php) e pelo instalador.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');
// Valores em JSON com a menor forma exata (5.69 em vez de 5.69000000000000039…): algumas
// hospedagens deixam serialize_precision em 17.
ini_set('serialize_precision', '-1');

define('API_RAIZ', dirname(__DIR__));           // loja/api
define('LOJA_RAIZ', dirname(__DIR__, 2));       // loja
define('ARQUIVO_CONFIG', LOJA_RAIZ . '/config.php');

require __DIR__ . '/Http.php';
require __DIR__ . '/Roteador.php';
require __DIR__ . '/Banco.php';
require __DIR__ . '/Config.php';
require __DIR__ . '/Validacao.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Imagem.php';
require __DIR__ . '/Marca.php';
require __DIR__ . '/MercadoPago.php';
require __DIR__ . '/Asaas.php';
require __DIR__ . '/Smtp.php';
require __DIR__ . '/Email.php';
require __DIR__ . '/Modulos.php';
require __DIR__ . '/Clientes.php';
require __DIR__ . '/Pedidos.php';
require __DIR__ . '/Tarefas.php';
require __DIR__ . '/Migracoes.php';
require __DIR__ . '/LojaAtual.php';
require __DIR__ . '/Plataforma.php';
