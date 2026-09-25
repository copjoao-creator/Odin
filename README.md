# ODIN · Monitor de engajamento do Instagram

O ODIN acompanha suas publicações no Instagram em tempo quase real, avisa quando alguma
ganha engajamento (visualizações, alcance, curtidas, comentários, compartilhamentos e
salvamentos) e sugere ações para aumentar o alcance.

Ele usa somente a **API oficial do Instagram (Graph API da Meta)**. Não guarda sua senha
nem faz "raspagem" de páginas, práticas que violam os termos do Instagram e podem
bloquear a conta.

## Como rodar

1. Dê um duplo clique em `index.html` **ou**, de preferência, rode o servidor local:
   ```
   powershell -ExecutionPolicy Bypass -File servidor.ps1
   ```
   e abra http://localhost:8787 no navegador.
2. O sistema inicia no **modo demonstração**, com dados simulados, para você conhecer as telas.
3. Em **Configurações**, escolha *Minha conta do Instagram* e cole o token de acesso.

Deixe a aba aberta: o ODIN consulta a API no intervalo configurado (padrão de 5 min)
e dispara um alerta na tela, um som e, se você ativar, uma notificação na área de trabalho.

## Como obter o token de acesso (Instagram API com login do Instagram)

Pré-requisito: a conta do Instagram precisa ser **Profissional** (Empresa ou Criador de
conteúdo). No app: *Configurações › Tipo de conta e ferramentas › Mudar para conta profissional*.

1. Acesse https://developers.facebook.com, entre com sua conta e clique em **Meus apps › Criar app**.
2. Escolha o caso de uso **"Gerenciar mensagens e conteúdo no Instagram"** (Instagram API).
3. Em **Instagram › Configuração da API com login do Instagram**, adicione sua conta do
   Instagram como **testadora** (*Funções do app › Funções › Testadores do Instagram*) e
   aceite o convite no Instagram (*Configurações › Apps e sites › Convites de testador*).
4. Na mesma tela, clique em **Gerar token** ao lado da sua conta. Permissões necessárias:
   `instagram_business_basic` e `instagram_business_manage_insights`.
5. Cole o token no ODIN. Mantenha **Tipo de login** = `graph.instagram.com` e **ID da conta** = `me`.

O token gerado dura cerca de 60 dias. Para renová-lo antes de vencer:
`https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=SEU_TOKEN`

### Alternativa: login do Facebook

Se a sua conta do Instagram estiver ligada a uma Página do Facebook, dá para usar o
Graph API Explorer (permissões `instagram_basic`, `instagram_manage_insights`,
`pages_show_list`, `pages_read_engagement`). Nesse caso, em Configurações, escolha
`graph.facebook.com` e informe o **ID numérico da conta profissional do Instagram**
(obtido em `GET /me/accounts?fields=instagram_business_account`).

## O que é monitorado

| Métrica | Origem |
|---|---|
| Visualizações, alcance, compartilhamentos, salvamentos | `/{media-id}/insights` |
| Curtidas e comentários | campos `like_count` e `comments_count` da mídia |
| Seguidores | campo `followers_count` da conta |

Um alerta é gerado quando, entre duas leituras, uma métrica sobe mais que o gatilho
configurado (valor absoluto ou percentual). Aumentos três vezes maiores que o gatilho
aparecem como **🔥 Pico de engajamento**.

## Recomendações

O painel **Ações recomendadas** analisa os seus próprios dados e sugere, por exemplo:
- impulsionar ou repostar nos Stories o post que está em alta agora;
- responder aos comentários novos enquanto a conversa está ativa;
- o formato (Reels, carrossel, foto) e o período do dia com melhor desempenho;
- frequência de publicação, uso de CTA e de hashtags;
- conteúdos mais compartilháveis ou salváveis, quando esses sinais estão baixos;
- reaproveitar posts antigos de alto desempenho.

Clique em uma publicação para ver a evolução de cada métrica e as ações sugeridas para ela.

## Limites e observações

- **Limite da API**: cerca de 200 chamadas por hora por conta. Cada leitura faz de 2 a
  N+2 chamadas (N = publicações monitoradas). Com 12 posts, use intervalos de 5 min ou mais.
- **Tempo real**: a API não envia visualizações e curtidas em tempo real, então o ODIN
  consulta periodicamente. Os números de insights da Meta podem ter alguns minutos de atraso.
- Navegadores reduzem a frequência de timers em abas em segundo plano. Se possível,
  deixe o ODIN numa janela própria.
- O token e o histórico ficam no `localStorage` deste navegador. Não use o ODIN em
  computadores compartilhados.
- Para monitoramento 24 h com a aba fechada (e webhooks de comentários), o próximo passo
  é um pequeno servidor (por exemplo, em Java/Spring ou Node) que faça as consultas e
  envie os alertas por e-mail, Telegram ou push.

## Estrutura

```
odin/
├── index.html        interface
├── css/odin.css      estilos
├── js/util.js        utilidades (formatação, gráficos)
├── js/api.js         cliente da Graph API do Instagram
├── js/demo.js        dados simulados (modo demonstração)
├── js/monitor.js     histórico, variações e alertas
├── js/advisor.js     motor de recomendações
├── js/app.js         telas, ciclo de monitoramento e notificações
└── servidor.ps1      servidor local opcional (PowerShell)
```
