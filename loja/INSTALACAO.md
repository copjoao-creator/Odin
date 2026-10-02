# Loja Odin Focus: guia de instalação e uso

Este guia foi escrito para quem **não tem experiência com MySQL**. Siga os passos na ordem.
Você só vai clicar em botões do cPanel da HostGator e preencher formulários: nenhum código.

O que existe na pasta `loja/`:

| Endereço | Para quê |
|---|---|
| `https://www.odinfocus.com.br/loja/` | A loja (landing page) que o cliente vê |
| `https://www.odinfocus.com.br/loja/admin/` | O painel onde você cadastra tudo e vê o financeiro |
| `https://www.odinfocus.com.br/loja/pagar.html?...` | Página de pagamento de um pedido (link que você envia ao cliente) |
| `https://www.odinfocus.com.br/loja/instalar.php` | Instalador (usado uma única vez) |

Pagamentos aceitos, todos pelo **Asaas**: **Pix**, **boleto** e **cartão de crédito** (à vista ou parcelado
sem juros), além das **assinaturas** de serviços com renovação automática no cartão.

---

## Passo 1. Criar o banco de dados MySQL (5 minutos)

1. Entre no **cPanel** da HostGator (Portal do Cliente › seu plano › botão **cPanel**).
2. No quadro **Bancos de dados**, clique em **Database Wizard**
   (em algumas versões aparece como *Assistente de Banco de Dados MySQL*). Não use o PHPMyAdmin.
3. **Etapa 1 – Create A Database:** no campo **New Database**, digite `loja` e clique em **Next Step**.
   O cPanel coloca um prefixo na frente, por exemplo `odinfo12_loja`. **Anote o nome completo.**
4. **Etapa 2 – Create Database Users:** em **Username**, digite `lojaadm` e clique em **Password Generator**.
   Copie a senha, marque *“I have copied this password in a safe place”*, clique em **Use Password**
   e depois em **Create User**.
   **Anote o nome completo do usuário** (ex.: `odinfo12_lojaadm`) **e a senha.**
5. **Etapa 3 – Add User to the Database:** marque **ALL PRIVILEGES** e clique em **Next Step**.
6. **Etapa 4:** aparece a mensagem de conclusão.

Pronto. Você **não precisa** abrir o phpMyAdmin nem criar tabelas: o instalador faz isso sozinho.

> Guarde os três dados anotados (nome do banco, usuário e senha). Eles são pedidos no passo 4.

---

## Passo 2. Enviar os arquivos para a hospedagem

1. No cPanel, abra o **Gerenciador de Arquivos** e entre na pasta **public_html**.
2. Envie a pasta **loja** inteira (com as subpastas `admin`, `api`, `css`, `img`, `js` e `uploads`).
   O jeito mais fácil: compacte a pasta `loja` em um arquivo `.zip` no seu computador, clique em
   **Carregar**, envie o `.zip`, volte, clique com o botão direito no arquivo e escolha **Extrair**.
3. Confira que ficou assim: `public_html/loja/index.html`, `public_html/loja/instalar.php` etc.

> Os arquivos ocultos `.htaccess` protegem as pastas internas. Se o Gerenciador de Arquivos não
> mostrar, clique em **Configurações** (canto superior direito) e marque **Mostrar arquivos ocultos**.

---

## Passo 3. Conferir o PHP e o cadeado (HTTPS)

1. No cPanel, abra **Selecionar versão do PHP** (ou **MultiPHP Manager**) e escolha **PHP 8.1 ou mais novo**.
2. Na aba **Extensões** da mesma tela, deixe marcadas: **pdo_mysql**, **curl** e **gd**
   (normalmente já vêm marcadas).
3. Abra `https://www.odinfocus.com.br` e confira se aparece o **cadeado** no navegador.
   Se não aparecer, no cPanel abra **SSL/TLS Status** e clique em **Run AutoSSL**.
   Sem o cadeado (HTTPS) a loja não pode receber cartões: os dados do cartão passam pelo site a caminho do Asaas.

---

## Passo 4. Rodar o instalador

1. Abra no navegador: **`https://www.odinfocus.com.br/loja/instalar.php`**
2. A primeira parte mostra a verificação do servidor. Tudo deve aparecer com ✓.
   Se algum item aparecer com ✗, a própria tela explica o que fazer.
3. Preencha:
   - **Servidor do banco:** `localhost` (já vem preenchido).
   - **Nome do banco**, **usuário** e **senha**: os dados anotados no passo 1.
   - **Nome da loja**, **seu nome**, **seu e-mail** e uma **senha** para entrar no painel.
4. Clique em **Instalar**. Em alguns segundos aparece *“Pronto! A loja foi instalada.”*
5. **Importante:** volte ao Gerenciador de Arquivos e **apague o arquivo `instalar.php`**.
   (Se você esquecer, ele fica bloqueado sozinho, mas apagar é mais seguro.)

---

## Passo 5. Ligar os pagamentos (Asaas)

Todos os pagamentos da loja passam pelo **Asaas**. O cliente paga **na própria página da loja**:

- **Pix:** o QR code e o "copia e cola" aparecem na hora; a confirmação chega em segundos.
- **Boleto:** vence em 3 dias e também é enviado para o e-mail do cliente.
- **Cartão de crédito:** à vista ou parcelado **sem juros para o cliente** (até 12x, cada parcela de no mínimo
  R$ 5,00; o máximo de parcelas fica em **Configurações › Vendas e frete**). A taxa de parcelamento do Asaas sai do
  valor que a loja recebe. Se o banco recusar o cartão, nada é cobrado e o cliente pode tentar outro.
- **Assinaturas** (serviços recorrentes): só no cartão de crédito. O Asaas cobra o **primeiro período na hora** e
  depois **renova sozinho** a cada mês, trimestre, semestre ou ano (se o cartão recusar, tenta de novo no dia do
  vencimento). Cada cobrança vira um pedido de renovação no painel.
- **Cartão de débito não é aceito:** o Asaas não recebe débito digitado na loja.

Sobre o cartão:

- os dados do cartão passam pelo servidor da loja só para serem enviados ao Asaas: **não são
  gravados** nem vão para os logs. Por isso o site precisa estar com o cadeado (HTTPS) ativo.
- o nome e o CPF enviados são os do **titular do cartão**. Se o cartão for de outra pessoa, o cliente
  marca "O cartão é de outra pessoa" e informa também CEP, número do endereço e celular dela.
- para evitar que alguém use a página para testar cartões roubados, cada pedido aceita até 5
  tentativas por hora, e cada IP até 10.
- produtos e serviços ficam em **pedidos separados**, e cada assinatura vai sozinha no pedido
  (a loja avisa o cliente ao tentar misturar no carrinho).

Para ligar (comece pelo **ambiente de teste**; só o perfil **Técnico** vê esta seção do painel):

1. Crie uma conta de teste em **https://sandbox.asaas.com**.
2. No Asaas, em **Integrações › Chaves de API**, gere uma chave.
3. No painel da loja › **Configurações** › **Asaas · Pagamentos**, escolha o ambiente
   **Teste (sandbox)**, cole a chave e salve.
4. No Asaas, em **Integrações › Webhooks**, crie um webhook:
   - **URL:** a mostrada na seção do Asaas no painel (termina em `webhook/asaas`);
   - **Versão da API:** v3; **Fila de sincronização:** ativada;
   - **Token de autenticação:** crie um token (sem espaços) e cole o **mesmo** no painel da loja;
   - **Eventos:** marque os de **Cobranças** e os de **Assinaturas**.
5. **Pix:** cadastre uma **chave Pix** na conta do Asaas (Pix › Minhas chaves). Sem ela, o Pix não é gerado.
6. Faça compras de teste na loja (Pix, boleto e cartão com os cartões de teste da documentação do sandbox do
   Asaas) e uma assinatura de teste. Confira no painel: o pedido fica **Pago** e a assinatura aparece em
   **Assinaturas**.
7. Quando estiver tudo certo, crie (ou use) a sua conta em **https://www.asaas.com**, gere a chave
   de **produção**, crie o webhook de novo nessa conta e, no painel, troque o ambiente para
   **Produção** e cole a nova chave e o novo token.

> A chave de teste só funciona com o ambiente **Teste** e a de produção só com **Produção**. Se
> aparecer "chave de API do Asaas inválida", confira se o ambiente escolhido combina com a chave.
> A chave e o token são senhas: o painel mostra só o começo e o fim. Para trocar, cole a nova;
> deixar o campo em branco mantém a atual.

**Data final das assinaturas:** em **Assinaturas › Data final** você define, muda ou tira a data em que cada
assinatura termina (também dá para informar ao criar um *Novo link de pagamento*). Nesse dia a rotina diária
marca a assinatura como **Encerrada** e cancela no Asaas; cobranças que cairiam nessa data ou depois não
acontecem. Para terminar hoje, use **Cancelar**: a assinatura é cancelada também no Asaas. Se ela for
removida direto no Asaas, também é cancelada na loja (pelo aviso do webhook).

**Estornos:** pelo painel, total ou parcial. Compras **parceladas** são estornadas pelo valor total
(todas as parcelas); estorno parcial delas se faz no painel do Asaas.

### Lojas que usavam o Mercado Pago

Ao atualizar, a loja passa a usar **só o Asaas**. O que acontece com o que já existia:

- as chaves do Mercado Pago são **apagadas** do banco (atualização automática do banco, versão 6);
- os **pagamentos antigos** continuam no histórico dos pedidos, marcados como *Mercado Pago (antigo)*, só para
  consulta. Para **estornar** um pagamento antigo, use o site do Mercado Pago;
- um Pix ou boleto antigo do Mercado Pago que ainda estava em aberto **não é mais acompanhado**: se o cliente pagar,
  confira no Mercado Pago e combine com ele;
- **assinaturas antigas** do Mercado Pago no cartão: a loja não recebe mais as cobranças delas. Cancele cada uma no
  site do Mercado Pago (e em **Assinaturas › Cancelar** no painel) e peça ao cliente para assinar de novo pela loja;
- no Mercado Pago Developers, **apague os webhooks** que apontavam para a loja (`webhook/mercadopago`).

---

## Passo 6. Rotina diária (renovações e pedidos vencidos)

Uma vez por dia o sistema: cancela pedidos que ficaram sem pagamento e envia por e-mail as
cobranças de renovação dos serviços recorrentes.

1. No painel da loja, abra **Configurações › Rotina diária** e clique em **Copiar** ao lado do comando.
2. No cPanel, abra **Trabalhos Cron** (*Cron Jobs*).
3. Em **Configurações comuns**, escolha **Uma vez por dia**.
4. Cole o comando no campo **Comando** e clique em **Adicionar novo Cron Job**.

> Se você não configurar o cron, a rotina roda mesmo assim quando você abre o painel
> (no máximo uma vez a cada 24 horas). O cron só garante que ela rode todo dia.

---

## Passo 7. E-mails da loja

A loja envia e-mails de *pagamento confirmado*, *link de pagamento* e *renovação de assinatura*.
Para que cheguem sem cair no spam:

1. No cPanel, abra **Contas de e-mail** e crie, por exemplo, `contato@odinfocus.com.br`.
2. No painel da loja, em **Configurações › Loja › E-mail de contato**, informe esse mesmo e-mail.
   Ele será o remetente e também recebe o aviso de *novo pedido pago*.
3. **E-mail no Titan (HostGator):** só os servidores do Titan podem enviar em nome do domínio. Para os
   e-mails (inclusive o código do master) chegarem na caixa de entrada, acrescente no `loja/config.php`
   do servidor, antes do `];` final:
   `'smtp' => ['host' => 'smtp.titan.email', 'porta' => 465, 'usuario' => 'contato@odinfocus.com.br', 'senha' => 'SENHA DA CAIXA'],`

---

## Passo 8. Página Fale conosco (EmailJS)

Os links **Contato** e **Fale conosco** abrem a página `contato.html`, com um formulário
(nome, e-mail, telefone, assunto e mensagem) e os outros canais (WhatsApp, e-mail e Instagram).
As mensagens chegam no seu e-mail pelo **EmailJS** (https://www.emailjs.com).

1. **Email Services:** copie o **Service ID** do serviço que você criou (ex.: `service_abc123`).
2. **Email Templates › Create New Template:**
   - **Subject:** `[{{loja}}] {{assunto}} - {{nome}}`
   - **Content:**
     ```
     Nome: {{nome}}
     E-mail: {{email}}
     Telefone: {{telefone}}
     Assunto: {{assunto}}

     {{mensagem}}
     ```
   - **To Email:** o e-mail que vai receber as mensagens. **Reply To:** `{{email}}`
     (assim, ao clicar em *Responder*, a resposta vai direto para o cliente).
   - Salve e copie o **Template ID** (ex.: `template_xyz789`).
3. **Account › General:** copie a **Public Key**.
4. **Account › Security:** em *Allowed origins*, coloque `https://www.odinfocus.com.br`
   para que só o seu site consiga usar a sua conta do EmailJS.
5. No painel da loja, em **Configurações › Formulário de contato (EmailJS)**, cole as três chaves e salve.

Sem as três chaves, a página mostra só os outros canais. O plano gratuito do EmailJS
envia até 200 mensagens por mês.

---

## Plataforma: várias lojas

O mesmo sistema atende **várias lojas independentes**. Cada uma fica em `odinfocus.com.br/{endereço}/`,
com vitrine, painel, configurações, personalização, pagamentos, clientes e pedidos **só dela**.
A loja original continua em `odinfocus.com.br/loja/` (é a loja 1).

| Endereço | Para quê |
|---|---|
| `odinfocus.com.br/` | Página da Odin Focus, com o botão **Entrar** |
| `odinfocus.com.br/entrar` | Login único: o sistema descobre se é o master ou o lojista de qual loja |
| `odinfocus.com.br/loja/master/` | Painel do **administrador master** (todas as lojas) |
| `odinfocus.com.br/{loja}/` e `/{loja}/admin/` | Vitrine e painel de cada loja |

**Instalar a plataforma** (uma vez):

1. Envie a pasta `loja` atualizada (sem `config.php`, sem `uploads/`) e o `index.html` da raiz.
2. **Regras de endereço:** no `public_html`, abra o `.htaccess` que já existe (arquivo oculto) e
   **acrescente no fim** o bloco entre "início do bloco da plataforma" e "fim do bloco" do arquivo
   `.htaccess` da raiz do projeto. Não apague o que já existe (por exemplo, a regra de forçar HTTPS).
3. Abra o site uma vez: as tabelas da plataforma são criadas sozinhas e a loja atual vira a loja 1,
   sem mudar nada nos dados dela.

**Administrador master** (`admin@odinfocus.com.br`): acesso total a todas as lojas. O e-mail de
verificação é `copjoao@gmail.com`. No primeiro acesso, em **Entrar**, clique em
**Criar ou recuperar a senha**: o código de 6 dígitos vai para o e-mail de verificação. O mesmo vale
para esquecer a senha, e trocar a senha (Painel master › Minha conta) também pede o código.

**Cadastrar uma loja** (só o master): Painel master › **Nova loja** › nome, endereço (não pode ser
mudado depois) e o primeiro acesso do lojista (perfil *Administrador*). A loja já nasce no ar, vazia.
Em seguida, pelo **Abrir painel**, configure a chave de pagamento (Asaas) da loja.

- **Acessos:** cria outro acesso ou **redefine a senha** de um lojista que esqueceu.
- **Suspender:** a loja sai do ar para clientes e lojista; os avisos de pagamento continuam chegando.
- **Rotina diária:** o mesmo comando do cron atende todas as lojas ativas.

---

## Como usar o painel

Endereço: **`https://www.odinfocus.com.br/loja/admin/`**

- **Painel financeiro:** faturamento, lucro estimado, valor líquido (após as taxas do Asaas),
  ticket médio, pedidos em aberto, estornos, receita recorrente das assinaturas, capital em estoque,
  gráfico por dia, vendas por forma de pagamento e mais vendidos. Botão **Exportar planilha** (abre no Excel).
- **Pedidos:** busque por número, nome, e-mail ou CPF. Clique num pedido para ver itens, pagamentos,
  **estornar** (total ou parcial), **cancelar**, copiar o **link de pagamento**, enviar pelo WhatsApp ou por e-mail
  e registrar observações (ex.: código de rastreio).
  O botão **Novo link de pagamento** serve para vendas feitas pelo WhatsApp ou telefone.
- **Recebimento manual:** no pedido aguardando pagamento, **Registrar recebimento manual** informa um pagamento feito
  fora da loja: **dinheiro**, **maquininha de cartão** de outra empresa (crédito ou débito), **Pix em outra conta**,
  transferência/depósito ou outro. Informe a forma, o valor, a data e, se quiser, as **taxas descontadas** (ex.: taxa
  da maquininha, para o lucro sair certo) e uma observação (ex.: NSU). Pode ser **parcial**: o pedido fica pago
  quando a soma dos recebimentos chega ao total, e o link de pagamento passa a cobrar só o que falta. Ao registrar,
  Pix e boleto em aberto no Asaas são cancelados (para o cliente não pagar de novo). Lançou errado? Use **Desfazer**.
  Devolveu o dinheiro ao cliente? Use **Estornar** (total ou parcial), que só registra a devolução. Não vale para
  renovações cobradas automaticamente no cartão (assinaturas no Asaas), para o cliente não pagar duas vezes.
- **Clientes:** cadastro com CPF validado e e-mail únicos, endereço com busca pelo CEP e histórico de compras.
- **Produtos:** código, categoria, subcategoria, descrição, estoque, preço de custo e de venda, e **até 3 fotos**
  (PNG ou JPG, até 800×800 pixels; fotos maiores são reduzidas automaticamente). O estoque baixa sozinho quando o pedido é pago
  e volta se houver estorno.
- **Serviços:** mesmos dados, mais **recorrente (sim/não)** e **renovação** (única, mensal, trimestral, semestral, anual)
  e **1 foto** (PNG ou JPG, até 250×250 pixels).
- **Assinaturas:** criadas automaticamente quando um serviço recorrente é pago. As do cartão são renovadas pelo
  Asaas sozinhas; nas antigas (sem cartão), antes de cada vencimento o sistema gera o pedido de renovação e manda o
  link de pagamento ao cliente. Você pode cobrar na hora, mudar a data final ou cancelar.
- **Configurações:** textos da loja, contatos, frete (valor fixo e frete grátis acima de um valor), parcelas,
  Asaas (só o perfil Técnico), abas da loja, módulos, rotina diária, administradores e troca de senha.

**Dica da descrição:** a primeira linha da descrição vira o **título** do item na loja. As linhas seguintes são os detalhes.

**Abas da loja:** em **Configurações › Abas da loja** você habilita ou desabilita, de forma independente, as abas
**Produtos** e **Serviços**. Desabilitada, a aba some da loja por completo (menu, página inicial, categorias, busca,
rodapé e carrinho) e a loja não aceita mais pedidos daquele tipo. O cadastro continua no painel e você ainda pode
vender por **Novo link de pagamento**.

**Módulos independentes:** produtos e serviços são módulos separados. Em **Configurações › Módulos** você pode
desligar qualquer um deles: ele some da loja e não aceita novas vendas (nem por link), mas os dados e as vendas
antigas continuam no painel.

### Situações de um pedido

| Situação | O que significa |
|---|---|
| Aguardando pagamento | Pedido criado; Pix/boleto ainda não pago, cliente ainda não pagou ou pagou só uma parte (recebimento manual parcial) |
| Em análise | O Asaas está analisando o cartão (análise de segurança) |
| Pago | Dinheiro aprovado. Estoque baixado e assinatura criada/renovada |
| Cancelado | Cancelado por você ou por falta de pagamento no prazo configurado |
| Estornado | O valor foi devolvido ao cliente |

Tempo de confirmação: **Pix** na hora · **cartões** na hora (ou em análise) · **boleto** em até 3 dias úteis.

---

## Cópia de segurança (backup)

Faça uma vez por semana:

1. No cPanel, abra **phpMyAdmin**.
2. Clique no nome do banco à esquerda (ex.: `odinfo12_loja`) e depois na aba **Exportar**.
3. Clique em **Exportar** (modo rápido, formato SQL). Guarde o arquivo baixado.
4. Guarde também a pasta `public_html/loja/uploads` (as fotos). No Gerenciador de Arquivos, clique com o botão
   direito › **Compactar** e baixe o `.zip`.

---

## Problemas comuns

**“A loja ainda não foi instalada”** – rode o `instalar.php` (passo 4).

**O instalador diz que o usuário ou a senha do banco estão errados** – no cPanel, em **Bancos de dados MySQL**,
confira na parte *Usuários atuais* se o usuário existe; use **Alterar senha** para definir uma nova e tente de novo.
Confira também, em *Adicionar usuário ao banco de dados*, se ele foi ligado ao banco com **Todos os privilégios**.

**“Pagamento online em configuração” aparece para o cliente** – falta a chave de API do Asaas (passo 5).

**“Não foi possível gerar o Pix agora”** – a conta do Asaas ainda não tem **chave Pix** cadastrada (passo 5, item 5).
O motivo exato fica no `api/error_log`.

**“A chave de API do Asaas é inválida”** – a chave é de outro ambiente: chave de teste só com **Teste (sandbox)**,
chave de produção só com **Produção** (Configurações › Asaas · Pagamentos).

**O pagamento foi feito, mas o pedido continua “Aguardando pagamento”** – confira o webhook do passo 5 (URL, token e
eventos de cobranças). Enquanto isso, abra o pedido no painel e clique em **Atualizar** no pagamento: o sistema
consulta o Asaas na hora.

**Os e-mails não chegam** – veja o passo 7 e peça ao cliente para olhar a caixa de spam. Você sempre pode copiar
o link de pagamento do pedido e enviar pelo WhatsApp.

**Esqueci a senha do painel** – se houver outro administrador, ele pode criar um novo acesso para você.
Se não houver: no Gerenciador de Arquivos, renomeie `loja/config.php` para `config-antigo.php`, envie de novo o
`instalar.php`, abra-o e preencha **os mesmos dados do banco**, o mesmo nome da loja e **o mesmo e-mail** com uma senha nova.
Nada é apagado: as tabelas e os dados continuam lá. Depois apague o `instalar.php` e o `config-antigo.php`.

**Plano B para criar as tabelas (só se o instalador der erro ao criá-las):** no phpMyAdmin, selecione o banco,
abra a aba **Importar** e importe, nesta ordem, os arquivos `api/nucleo/tabelas.sql`,
`api/modulos/produtos/tabelas.sql` e `api/modulos/servicos/tabelas.sql`. Depois abra o `instalar.php` de novo e
preencha o formulário: ele aproveita as tabelas que já existem e cria o seu acesso.

---

## Para quem for mexer no código

- PHP 8 puro (sem frameworks nem Composer) + MySQL 5.7+/MariaDB, próprio para hospedagem compartilhada.
- A API responde em `api/?r=rota` (não depende de reescrita de URL). Valores, frete e estoque são sempre
  calculados no servidor; o navegador nunca define preço.
- Pagamentos pelo **Asaas** (`api/nucleo/Asaas.php`, API v3): Pix (`/payments` + `/pixQrCode`), boleto
  (`/payments` + `/identificationField`), cartão de crédito à vista ou parcelado (`installmentCount` +
  `totalValue`; o parcelamento inteiro é gravado como um pagamento, com o id do parcelamento) e assinaturas
  (`/subscriptions`). O cartão é digitado no formulário da loja (`js/pagamento.js`) e validado em
  `api/nucleo/Cartao.php`; não é gravado nem registrado em log.
- O webhook `webhook/asaas` é autenticado pelo cabeçalho `asaas-access-token` e a cobrança é sempre reconsultada
  na API antes de mudar o pedido. Cobranças com `subscription` vão para o módulo de serviços, que decide se são do
  pedido de origem ou de um pedido de renovação.
- `Asaas::comoPagamento()` converte a cobrança para a tabela `pagamentos` (os status seguem `approved`, `pending`,
  `refunded` etc.). As colunas `mp_id`/`mp_assinatura` guardam os ids do Asaas (o nome ficou do Mercado Pago);
  `pagamentos.app` = `asaas`, `manual` (recebimento informado no painel: `mp_id` "man_…", com `observacao` e
  `registrado_por`) e `loja`/`servicos` marcam pagamentos antigos do Mercado Pago (só histórico).
- O pedido fica pago quando a **soma** dos pagamentos aprovados cobre o total (`Pedidos::sincronizar`), e a cobrança
  online é sempre do que falta (`Pedidos::restanteCentavos`).
- Mudanças no banco de lojas já instaladas ficam em `api/nucleo/Migracoes.php` e rodam sozinhas na primeira chamada à API.
- Plataforma (`Plataforma.php`, `LojaAtual.php`, `plataforma.sql`, `rotas_plataforma.php`): a loja vem do primeiro
  trecho do endereço; cada loja tem prefixo de tabelas (`l{id}_`) aplicado em `Banco::sql()` — o SQL continua com os
  nomes simples. Imagens em `uploads/lojas/{endereço}/` (a loja 1 usa `uploads/`). Sessão: `$_SESSION['lojas'][id]`
  por loja e `$_SESSION['master_id']` para o master. Testes locais: `config.php` aceita `email_arquivo` (grava os
  e-mails num arquivo) e `url_base`.
- Checkout pelo CPF (`clientes/identificar`): quem já tem cadastro vê só um resumo mascarado e o pedido usa os
  dados do banco (`pedidos.dados_protegidos`), sem devolvê-los ao navegador. Consultas limitadas a 10 por IP a cada
  15 minutos (tabela `login_tentativas`, chave `cpf:IP`).
- Senhas com `password_hash`, sessão com cookie `HttpOnly`/`SameSite=Strict`, token CSRF no painel e limite de
  tentativas de login por IP. Fotos são validadas e regravadas (PNG/JPG) antes de salvar.

```
loja/
├── index.html · pagar.html     loja e página de pagamento
├── css/loja.css · js/          vitrine, carrinho e pagamento (Pix, boleto e cartão pelo Asaas)
├── admin/                      painel administrativo
├── instalar.php                instalador (apague depois de usar)
├── config.php                  criado pelo instalador (acesso ao banco)
├── uploads/                    fotos enviadas pelo painel
└── api/
    ├── index.php               entrada da API
    ├── cron.php                rotina diária
    ├── nucleo/                 clientes, pedidos, pagamentos (Asaas), relatórios, segurança
    └── modulos/
        ├── produtos/           módulo de produtos (tabelas + rotas + estoque)
        └── servicos/           módulo de serviços (tabelas + rotas + assinaturas)
```

Cada módulo implementa a interface `ModuloCatalogo` (`api/nucleo/Modulos.php`): o núcleo de pedidos não conhece
estoque nem assinaturas. Para criar um módulo novo, copie a pasta de um deles e ajuste.
