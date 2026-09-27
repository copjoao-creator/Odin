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

Pagamentos aceitos, todos pelo **Mercado Pago**: **Pix**, **boleto**, **cartão de crédito** (parcelado) e **cartão de débito**.

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
   O Mercado Pago só aceita cartões em sites com cadeado (HTTPS).

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

## Passo 5. Ligar o Mercado Pago

Você precisa de uma conta do Mercado Pago (a mesma usada para vender).

1. Acesse **https://www.mercadopago.com.br/developers/panel/app** e clique em **Criar aplicação**.
   - Nome: `Loja Odin Focus`
   - Tipo de pagamento: **Pagamentos on-line**
   - Produto: **Checkout Transparente** (ou *CheckoutAPI / Checkout Bricks*)
2. Dentro da aplicação, abra **Credenciais de produção** e copie a **Public Key** e o **Access Token**.
3. Entre no painel da loja (`/loja/admin/`) › **Configurações** › **Mercado Pago** e cole as duas chaves.
   Clique em **Salvar configurações**.
4. Ainda na aplicação do Mercado Pago, abra **Webhooks** (Notificações):
   - Cole a **URL** que aparece no painel da loja (algo como
     `https://www.odinfocus.com.br/loja/api/?r=webhook/mercadopago`).
   - Marque o evento **Pagamentos** e salve.
   - Copie a **assinatura secreta** e cole no campo *Assinatura secreta do webhook* no painel da loja. Salve.
5. **Pix:** para receber Pix, a sua conta do Mercado Pago precisa ter uma **chave Pix cadastrada**
   (app do Mercado Pago › Seu perfil › Chaves Pix).

### Quer testar antes, sem dinheiro de verdade?

Use as **Credenciais de teste** (em vez das de produção) no passo 3. Os pagamentos serão simulados.
Os números de cartão de teste ficam na documentação do Mercado Pago: em
https://www.mercadopago.com.br/developers procure por **“cartões de teste”**.
Quando terminar os testes, troque pelas credenciais de produção.

> O Access Token e a assinatura secreta são senhas: o painel mostra só o começo e o fim delas.
> Para trocar, cole a nova. Deixar o campo em branco mantém a atual.

### Aplicação de serviços e assinaturas no cartão

Os serviços podem usar uma **segunda aplicação** do Mercado Pago, na **mesma conta**. Com ela:

- os pedidos de serviços são cobrados por essa aplicação (produtos continuam na da loja);
- os **serviços recorrentes** (assinaturas) são pagos **só com cartão de crédito** e o Mercado Pago
  **cobra sozinho** a cada período. Cada cobrança vira um pedido de renovação no painel.
- produtos e serviços ficam em **pedidos separados**, e cada assinatura vai sozinha no pedido
  (a loja avisa o cliente ao tentar misturar no carrinho).

Para ligar:

1. Na aplicação de serviços, ative as **Credenciais de produção** e copie a **Public Key** e o **Access Token**.
2. No painel da loja › **Configurações** › **Mercado Pago · Serviços**, cole as duas chaves e salve.
3. Na aplicação de serviços › **Webhooks**, cole a URL mostrada nessa seção (termina em `&app=servicos`),
   marque **Pagamentos** e **Planos e assinaturas**, salve e cole a **assinatura secreta** no painel.

**Data final:** em **Assinaturas › Data final** você define, muda ou tira a data em que cada assinatura
termina (também dá para informar ao criar um *Novo link de pagamento*). Nesse dia a rotina diária marca a
assinatura como **Encerrada** e, no cartão, cancela no Mercado Pago; cobranças que cairiam nessa data ou
depois não acontecem. Para terminar hoje, use **Cancelar**.

Sem essa seção preenchida, os serviços usam a aplicação da loja (as assinaturas também).
Assinaturas criadas antes desta mudança continuam no modelo antigo: link de pagamento por e-mail
antes de cada vencimento. Para cancelar uma assinatura no cartão, use **Assinaturas › Cancelar** no painel:
ela é cancelada também no Mercado Pago.

> **Teste com credenciais de teste:** nas assinaturas, o Mercado Pago exige que o comprador seja um
> **usuário de teste** (Suas integrações › Contas de teste) e que o e-mail informado seja o dele.

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

## Como usar o painel

Endereço: **`https://www.odinfocus.com.br/loja/admin/`**

- **Painel financeiro:** faturamento, lucro estimado, valor líquido (após as taxas do Mercado Pago),
  ticket médio, pedidos em aberto, estornos, receita recorrente das assinaturas, capital em estoque,
  gráfico por dia, vendas por forma de pagamento e mais vendidos. Botão **Exportar planilha** (abre no Excel).
- **Pedidos:** busque por número, nome, e-mail ou CPF. Clique num pedido para ver itens, pagamentos,
  **estornar** (total ou parcial), **cancelar**, copiar o **link de pagamento**, enviar pelo WhatsApp ou por e-mail
  e registrar observações (ex.: código de rastreio).
  O botão **Novo link de pagamento** serve para vendas feitas pelo WhatsApp ou telefone.
- **Clientes:** cadastro com CPF validado e e-mail únicos, endereço com busca pelo CEP e histórico de compras.
- **Produtos:** código, categoria, subcategoria, descrição, estoque, preço de custo e de venda, e **até 3 fotos**
  (PNG ou JPG, até 800×800 pixels; fotos maiores são reduzidas automaticamente). O estoque baixa sozinho quando o pedido é pago
  e volta se houver estorno.
- **Serviços:** mesmos dados, mais **recorrente (sim/não)** e **renovação** (única, mensal, trimestral, semestral, anual)
  e **1 foto** (PNG ou JPG, até 250×250 pixels).
- **Assinaturas:** criadas automaticamente quando um serviço recorrente é pago. Antes de cada vencimento o sistema gera
  o pedido de renovação e manda o link de pagamento ao cliente. Você pode cobrar na hora ou cancelar.
- **Configurações:** textos da loja, contatos, frete (valor fixo e frete grátis acima de um valor), parcelas,
  Mercado Pago, módulos, rotina diária, administradores e troca de senha.

**Dica da descrição:** a primeira linha da descrição vira o **título** do item na loja. As linhas seguintes são os detalhes.

**Módulos independentes:** produtos e serviços são módulos separados. Em **Configurações › Módulos** você pode
desligar qualquer um deles: ele some da loja, mas os dados e as vendas antigas continuam no painel.

### Situações de um pedido

| Situação | O que significa |
|---|---|
| Aguardando pagamento | Pedido criado; Pix/boleto ainda não pago ou cliente ainda não pagou |
| Em análise | O Mercado Pago está analisando o cartão (resposta em até 2 dias úteis) |
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

**“Pagamento online em configuração” aparece para o cliente** – faltam a Public Key e o Access Token (passo 5).

**“As credenciais de produção do Mercado Pago ainda não foram ativadas”** (ou, no `api/error_log`,
*Unauthorized use of live credentials*) – em Mercado Pago Developers › sua aplicação › **Credenciais de produção**,
clique em **Ativar credenciais**, preencha o ramo e o site da loja, copie de novo a Public Key e o Access Token
e salve no painel. Para testar antes disso, use as **Credenciais de teste**.

**O pagamento foi feito, mas o pedido continua “Aguardando pagamento”** – confira o webhook do passo 5.
Enquanto isso, abra o pedido no painel e clique em **Atualizar** no pagamento: o sistema consulta o Mercado Pago na hora.

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
- Integração com o Mercado Pago pelo **Payment Brick** (Checkout Transparente): o cartão é digitado no formulário
  do Mercado Pago e a loja recebe só um token. O webhook tem a assinatura conferida e o pagamento é sempre
  reconsultado na API antes de mudar o pedido.
- Duas aplicações do Mercado Pago (`MercadoPago::APPS`): `loja` e `servicos`. Cada pagamento guarda a aplicação
  que o criou (`pagamentos.app`). Assinaturas usam a API de *preapproval* com o **Card Payment Brick**; o webhook
  `&app=servicos` recebe `subscription_preapproval` e `subscription_authorized_payment`, e o módulo de serviços
  decide se a cobrança é do pedido de origem ou de um pedido de renovação.
- Mudanças no banco de lojas já instaladas ficam em `api/nucleo/Migracoes.php` e rodam sozinhas na primeira chamada à API.
- Checkout pelo CPF (`clientes/identificar`): quem já tem cadastro vê só um resumo mascarado e o pedido usa os
  dados do banco (`pedidos.dados_protegidos`), sem devolvê-los ao navegador. Consultas limitadas a 10 por IP a cada
  15 minutos (tabela `login_tentativas`, chave `cpf:IP`).
- Senhas com `password_hash`, sessão com cookie `HttpOnly`/`SameSite=Strict`, token CSRF no painel e limite de
  tentativas de login por IP. Fotos são validadas e regravadas (PNG/JPG) antes de salvar.

```
loja/
├── index.html · pagar.html     loja e página de pagamento
├── css/loja.css · js/          vitrine, carrinho e pagamento (Payment Brick)
├── admin/                      painel administrativo
├── instalar.php                instalador (apague depois de usar)
├── config.php                  criado pelo instalador (acesso ao banco)
├── uploads/                    fotos enviadas pelo painel
└── api/
    ├── index.php               entrada da API
    ├── cron.php                rotina diária
    ├── nucleo/                 clientes, pedidos, pagamentos, relatórios, Mercado Pago, segurança
    └── modulos/
        ├── produtos/           módulo de produtos (tabelas + rotas + estoque)
        └── servicos/           módulo de serviços (tabelas + rotas + assinaturas)
```

Cada módulo implementa a interface `ModuloCatalogo` (`api/nucleo/Modulos.php`): o núcleo de pedidos não conhece
estoque nem assinaturas. Para criar um módulo novo, copie a pasta de um deles e ajuste.
