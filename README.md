# ODIN · Monitor de engajamento em redes sociais

O ODIN acompanha suas publicações no **Instagram**, no **TikTok** e no **YouTube** em tempo
quase real, avisa quando alguma ganha engajamento (visualizações, curtidas, comentários,
compartilhamentos etc.) e sugere ações para aumentar o alcance em cada rede.

Ele usa somente as **APIs oficiais** de cada plataforma. Não guarda senhas nem faz
"raspagem" de páginas, práticas que violam os termos das redes e podem bloquear a conta.

## Como rodar

1. Rode o servidor local (obrigatório para o TikTok, recomendado para as outras redes):
   ```
   powershell -ExecutionPolicy Bypass -File servidor.ps1
   ```
   e abra http://localhost:8787 no navegador.
2. O sistema inicia no **modo demonstração** nas três redes, com dados simulados.
3. Em **Configurações**, escolha para cada rede a fonte de dados: *Desligado*,
   *Demonstração* ou *Minha conta*, e informe as credenciais.

Deixe a aba aberta: o ODIN consulta cada rede no intervalo configurado (padrão de 5 min)
e dispara um alerta na tela, um som e, se você ativar, uma notificação na área de trabalho.
Use as abas no topo para ver todas as redes juntas ou uma de cada vez.

## Métricas disponíveis por rede

| Métrica | Instagram | TikTok | YouTube |
|---|:-:|:-:|:-:|
| Visualizações | ✅ | ✅ | ✅ |
| Alcance | ✅ | — | — |
| Curtidas | ✅ | ✅ | ✅ |
| Comentários | ✅ | ✅ | ✅ |
| Compartilhamentos | ✅ | ✅ | —¹ |
| Salvamentos | ✅ | — | — |
| Seguidores / inscritos | ✅ | ✅ | ✅ |

¹ No YouTube, compartilhamentos só existem na YouTube Analytics API, que exige login OAuth.

---

## Instagram (Instagram Graph API)

Pré-requisito: a conta precisa ser **Profissional** (Empresa ou Criador de conteúdo).
No app: *Configurações › Tipo de conta e ferramentas › Mudar para conta profissional*.

1. Acesse https://developers.facebook.com e clique em **Meus apps › Criar app**.
2. Escolha o caso de uso **"Gerenciar mensagens e conteúdo no Instagram"** (Instagram API).
3. Em **Instagram › Configuração da API com login do Instagram**, adicione sua conta como
   **testadora** (*Funções do app › Funções › Testadores do Instagram*) e aceite o convite
   no Instagram (*Configurações › Apps e sites › Convites de testador*).
4. Clique em **Gerar token** ao lado da sua conta. Permissões necessárias:
   `instagram_business_basic` e `instagram_business_manage_insights`.
5. No ODIN, mantenha **Tipo de login** = `graph.instagram.com`, **ID da conta** = `me` e cole o token.

O token dura cerca de 60 dias. Para renová-lo antes de vencer:
`https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=SEU_TOKEN`

**Alternativa (login do Facebook):** com a conta ligada a uma Página do Facebook, use o
Graph API Explorer (permissões `instagram_basic`, `instagram_manage_insights`,
`pages_show_list`, `pages_read_engagement`), escolha `graph.facebook.com` e informe o
**ID numérico da conta do Instagram** (`GET /me/accounts?fields=instagram_business_account`).

**Limite:** cerca de 200 chamadas por hora. Cada leitura faz de 2 a N+2 chamadas
(N = publicações monitoradas).

---

## TikTok (Display API)

A API do TikTok **não aceita chamadas diretas do navegador**. Por isso o `servidor.ps1`
faz a ponte: o ODIN chama `http://localhost:8787/api/tiktok/...` e o servidor repassa
para `https://open.tiktokapis.com/...`. O servidor só atende no próprio computador e só
repassa chamadas para o TikTok.

1. Crie uma conta em https://developers.tiktok.com e, em **Manage apps**, crie um app.
2. Adicione o produto **Login Kit** e informe uma **Redirect URI** em HTTPS que você
   controle (por exemplo, a página do GitHub Pages deste repositório). O ODIN não precisa
   que essa página faça nada: basta você conseguir copiar o endereço depois do login.
3. Adicione os escopos `user.info.basic`, `user.info.profile`, `user.info.stats` e `video.list`.
4. Enquanto o app não for aprovado, use o modo **Sandbox** e adicione sua conta do TikTok
   como **usuário de teste** (*Sandbox › Target users*).
5. No ODIN, em **Configurações › TikTok**, escolha *Minha conta* e preencha **Client key**,
   **Client secret** e **Redirect URI** (exatamente como no app).
6. Clique em **1. Autorizar no TikTok**, faça login e autorize. O navegador vai abrir a
   Redirect URI com `?code=...` no endereço.
7. Copie o endereço inteiro, cole no campo **2** e clique em **3. Gerar tokens**. Depois, **Salvar**.

O access token vale 24 h e o refresh token, 1 ano. Com client key, client secret e refresh
token preenchidos, o ODIN **renova o acesso sozinho**.

---

## YouTube (YouTube Data API v3)

1. Acesse https://console.cloud.google.com, crie um projeto e ative a **YouTube Data API v3**
   (*APIs e serviços › Biblioteca*).
2. Em *APIs e serviços › Credenciais*, clique em **Criar credenciais › Chave de API**.
   Recomendado: restrinja a chave à YouTube Data API v3.
3. No ODIN, em **Configurações › YouTube**, escolha *Meu canal*, cole a chave e informe o
   canal pelo **@handle** (ex.: `@seucanal`) ou pelo **ID** (`UC...`).

**Cota:** cada leitura gasta 3 unidades da cota gratuita de 10.000 por dia. Com leituras a
cada 5 minutos, o gasto é de cerca de 860 unidades por dia.

Vídeos de até 3 minutos são tratados como **Shorts**.

---

## Alertas

Um alerta é gerado quando, entre duas leituras, uma métrica sobe mais que o gatilho
configurado para aquela rede (valor absoluto) ou mais que o percentual geral. Aumentos três
vezes maiores que o gatilho aparecem como **🔥 Pico de engajamento**. Os gatilhos são
separados por rede porque os volumes são diferentes: 500 visualizações a mais é pouco no
TikTok e muito num vídeo longo do YouTube.

## Recomendações

O painel **Ações recomendadas** analisa os seus próprios dados:

- **Por rede:** publicação em alta agora e o que fazer com ela, comentários novos para
  responder, frequência ideal, melhor formato (Reels/carrossel, duração dos vídeos do
  TikTok, Shorts ou vídeos longos), melhor horário e dia, chamadas para ação, hashtags,
  compartilhamentos e salvamentos, alcance em relação ao público e conteúdos para reaproveitar.
- **Entre redes:** qual rede tem a audiência mais engajada e qual conteúdo campeão
  recente vale adaptar para as outras (por exemplo, um TikTok que performou muito bem
  virando Reels e Short).

Clique em uma publicação para ver a evolução de cada métrica e as ações sugeridas para ela.

## Limites e observações

- **Tempo real:** nenhuma dessas APIs envia visualizações e curtidas em tempo real, por isso
  o ODIN consulta periodicamente. Os números podem ter alguns minutos de atraso.
- Navegadores reduzem a frequência de timers em abas em segundo plano. Se possível, deixe o
  ODIN numa janela própria.
- Tokens, chaves e histórico ficam no `localStorage` deste navegador. Não use o ODIN em
  computadores compartilhados.
- Para monitoramento 24 h com a aba fechada, o próximo passo é mover as consultas para um
  servidor (por exemplo, em Java/Spring ou Node) que envie os alertas por e-mail, Telegram ou push.

## Estrutura

```
├── index.html              interface
├── css/odin.css            estilos
├── js/util.js              utilidades e definição das redes
├── js/sources/instagram.js cliente da Instagram Graph API
├── js/sources/tiktok.js    cliente da TikTok Display API (via servidor local)
├── js/sources/youtube.js   cliente da YouTube Data API v3
├── js/demo.js              dados simulados (modo demonstração)
├── js/monitor.js           histórico, variações e alertas
├── js/advisor.js           motor de recomendações
├── js/app.js               telas, ciclos de monitoramento e notificações
└── servidor.ps1            servidor local + ponte para a API do TikTok
```
