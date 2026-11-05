# WP Events Certificate — mapeamento e plano

Plugin WordPress para emitir certificados de participação em eventos (WordCamps,
meetups, eventos do GatherPress). Os participantes vêm do CampTix, do GatherPress,
de um CSV (Meetup.com ou qualquer planilha) ou são incluídos à mão, um a um ou
colando várias linhas.

---

## 1. Mapeamento das referências

### 1.1 wpbrasil/gerador-de-certificados (PHP + GD, descontinuado)

- Tabela MySQL própria `attendee` (name, email, type, data, file). Era alimentada
  por uma planilha.
- Tinha **três tipos de certificado**: participante (com horas), palestrante (com o
  título da palestra) e organizador (com horas). Cada tipo tinha um fundo
  `img/bg-<tipo>.png`.
- A pessoa digitava o e-mail e via a lista dos certificados dela.
- O certificado era um PNG gerado com GD (`imagettftext`), com o texto centralizado
  e quebra de linha calculada à mão. A posição ficava em constantes no config.
- **O que levamos:** o papel (tipo) muda o texto; o campo "detalhes" (por exemplo, a
  palestra); a busca por e-mail que lista vários certificados.
- **O que não levamos:** o SQL interpolado (`'{$email}'`, sujeito a injeção), o PNG
  em vez de PDF e as posições em código.

### 1.2 wpbrasil/camp-certificates (plugin WP, esboço de 2015)

- Tinha dois CPTs: `cc_events` (país, cidade, data, fonte, tamanho da fonte e
  imagem de fundo por URL) e `cc_attendees` (e-mail e evento como texto livre).
- Só tinha os meta boxes; nunca chegou a gerar o certificado.
- **O que levamos:** o evento como CPT no admin e os metadados de design por
  evento.
- **O que não levamos:** um participante por post (é pesado para mais de 1.000
  pessoas por WordCamp) e o vínculo com o evento por texto.

### 1.3 wpbrasil/certificates-for-wordcamp (Node/Express, v1.4.1)

- `config.yml` com os dados do evento (nome, URL, data, duração), os textos e as
  mensagens de erro.
- CSV com as colunas `type,first_name,last_name,email,attendance`. Só recebe o
  certificado quem tem `attendance = Yes`.
- Página com e-mail e CSRF que gera um PDF A4 paisagem a partir de HTML
  (`textLine1` + nome + `textLine2` com `%event_name% %event_date%
  %attendee_type% %event_duration%`).
- **O que levamos:** os placeholders, o texto padrão em pt-BR ("Declaramos para os
  devidos fins que…"), o filtro por presença, o PDF em A4 paisagem e as mensagens
  de erro claras.

### 1.4 Sensei Certificates (woocommerce/sensei-certificates)

| Tema | Como o Sensei faz | O que faremos |
|---|---|---|
| Modelo | CPT `certificate` por aluno+curso; CPT `certificate_template` | Evento (CPT) com o design embutido; participantes em tabela própria |
| Token/URL | `substr(md5(course.user), -8)`: determinístico e enumerável | Código aleatório (CSPRNG) de 12 caracteres sem ambíguos, ex. `K7M2-9QXA-P3TB` |
| Editor | `imgareaselect` sobre a imagem; coordenadas em px da imagem; campos heading/message/course/completion/place; estilo empacotado em `"BCO"` | Prévia HTML com caixas arrastáveis; posições em **% da página**; alinhamento, negrito e cor em campos separados |
| PDF | tFPDF com página em **pt = px da imagem** (um A4 de 300 dpi vira uma página de cerca de 1,2 m) | tFPDF com página **A4 fixa em mm** e a imagem esticada; o texto em pt fica igual em qualquer resolução |
| Fontes | Helvetica/Courier/Times + DejaVu Condensed só quando há UTF-8 (perde negrito) | Sempre fontes Unicode (DejaVu Sans/Serif, regular e negrito) e um filtro para TTF próprio |
| Cache de fontes | tFPDF grava `.mtx.php`/`.cw.dat` e faz `mkdir` dentro do plugin | Subclasse que **não grava nada**: as métricas ficam em memória durante a requisição |
| Acesso | Público por opt-in, dono ou admin | Quem tem o código vê o PDF e a verificação; a busca por e-mail lista ou envia os links |
| Verificação | Não existe | Página `?evcert_certificate=CODIGO`, mais o código impresso no PDF |
| Placeholders | `{{learner}}` etc. com `str_replace` e um filtro | `{name}`, `{event}`, `{date}`, `{hours}`, `{role}`, `{details}`, `{location}`, `{code}`, `{verify_url}`, com filtro |

Bug do Sensei que não vamos repetir: em `pdf-certificate.php:394` o array de
posição é passado como `ln` do `Cell`.

### 1.5 Fontes de participantes

**CampTix (sites WordCamp)**
- Os participantes são posts `tix_attendee` com status `publish` (pagos). Os dados
  ficam nos metas `tix_first_name`, `tix_last_name`, `tix_email` e `tix_ticket_id`.
- A presença vem do addon *track-attendance*, no meta `tix_attended` (true ou
  ausente).
- O export CSV tem estas colunas: `Attendee ID, Ticket Type, First Name, Last Name,
  E-mail Address, Purchase date, …, Attended` (esta última só com o addon).
- **Os cabeçalhos saem traduzidos** no idioma do site (pt-BR em WordCamps
  brasileiras). Por isso o importador precisa de sinônimos em pt/en e de um
  mapeamento manual de colunas como alternativa.

**GatherPress**
- O evento é o CPT `gatherpress_event`. As datas vêm de
  `GatherPress\Core\Event\Event::get_datetime_start()`.
- O RSVP é um comentário `comment_type = gatherpress_rsvp` no post do evento. O
  status fica na taxonomia `_gatherpress_rsvp_status`, termo `attending`.
- A presença é a flag de check-in: taxonomia `_gatherpress_rsvp_flag`, termo
  `checked-in`.
- O nome e o e-mail vêm do `user_id`, quando há usuário, ou de `comment_author` e
  `comment_author_email`. As respostas anônimas (`gatherpress_rsvp_anonymous`)
  continuam elegíveis, mas o nome sai do usuário.
- A leitura é feita direto (comments + tax_query), sem depender das classes
  internas, que mudam entre versões. As classes só são usadas para a data, quando
  existem.

**Meetup.com**
- Não há API gratuita útil. O organizador baixa o CSV de participantes do evento.
- Colunas esperadas: `Name, User ID, Title, Event Host, RSVP, Guests, RSVPed on,
  Joined Group on, URL of Member Profile` e, às vezes, `Attendance`.
  **A confirmar com um export real**.
- **Normalmente não vem e-mail.** Por isso a busca por e-mail não serve para quem
  veio do Meetup. O plugin exporta um CSV com o link de cada certificado, para o
  organizador mandar pelo próprio Meetup (mensagem ou comentário).

**Manual**
- Um formulário para uma pessoa.
- Uma caixa "colar várias linhas" que aceita o texto copiado de uma planilha (TAB),
  `;` ou `,`, com ou sem cabeçalho. A ordem padrão sem cabeçalho é
  `nome, e-mail, papel, detalhes`.

---


### 1.6 Referência de autenticidade: documentos gov.br/INSS

O PDF de exemplo é um extrato do INSS. Tem dados pessoais reais (CPF, NIT, nome
da mãe) e não deve ficar na pasta do projeto nem entrar no git. Dele só
aproveitamos o padrão do rodapé:

- Uma caixa no pé da página com um **QR code** à esquerda e o texto à direita:
  "Você pode conferir a autenticidade do documento em `<URL>` com o código
  `<CÓDIGO>`".
- O código tem 22 caracteres, em maiúsculas e números, e começa pela data de
  emissão (`261029…` = 29/10/26). Assim ele pode ser digitado à mão.
- Os dois caminhos são o QR (abre direto) e o digitado (URL curta + campo de
  código).
- O documento é **congelado** na emissão: a página de autenticidade mostra o que foi
  emitido, e não o estado atual do cadastro.

O que muda para estrangeiros:
- Texto bilíngue no PDF quando o site não está em inglês, por exemplo "Verifique a
  autenticidade em … / Verify authenticity at …".
- Página de verificação com seletor de idioma (`?lang=en`).
- Datas sem ambiguidade (4 Nov 2026, e não 04/11).
- Slug em inglês e código sem caracteres ambíguos.

### 1.7 Outros documentos públicos com autenticação (baixados em `references/gov-pdfs/`)

| Documento | Como autentica | O que aproveitar |
|---|---|---|
| SEI (Goiás, Bahia) | **dois códigos**: o *verificador* (que documento é) e o *CRC* (confere que o conteúdo é aquela versão), mais o QR, a URL e um carimbo "Documento assinado eletronicamente por **Fulano**, **Cargo**, em 23/07/2026, às 09:47, conforme horário oficial de Brasília" | o código + uma **impressão digital curta** do conteúdo (8 hex do SHA-256); o carimbo "emitido eletronicamente por <organização> em <data/hora + fuso>" |
| Receita Federal (atestado) | uma **página de autenticação** separada no fim: título, texto legal, QR, URL, código `AP25.0722.07144.1608` e o **hash SHA-256** em base64 | página 2 opcional "Authentication page / Página de autenticação", bilíngue; a arte da página 1 fica limpa |
| TCU (instrução normativa) | uma linha no rodapé de **todas** as páginas: "Para verificar as assinaturas, acesse www.tcu.gov.br/autenticidade, informando o código 64210132" | a versão mínima: uma linha só, sem QR |
| JUCESP (certidão) | "código de controle" + data/hora de expedição + "a autenticidade… pode ser verificada no endereço…" | a data **e hora** de expedição junto do código |

- Nenhum dos PDFs tem assinatura digital PAdES embutida (conferido com `pdfsig`).
  Todos confiam na consulta online. Para nós vale o mesmo: a página de verificação
  é a fonte da verdade.
- Uma assinatura PAdES com o certificado do site fica como ideia futura e opcional.

### 1.8 Referência visual: certificado *WordPress Campus Connect* (Figma)

O arquivo é `hDFYLIsJ9eibawK8D57TE5` (cópia da Community), componente
`certificate` `2086:213`. Os assets e o PNG ficam em `references/figma/`.

**Página:** **842×595 pt** (A4 paisagem). O fundo é branco, e as cores são
Blueberry `#3858E9`, Charcoal 0 `#1A1919` e Charcoal 4 `#656A71`.

**Camadas** (de baixo para cima):

| Camada | O que é | O que exige do editor |
|---|---|---|
| `wordpress` | o logo W gigante (842×842) como marca-d'água, `#3858E9` com opacidade 0,15–0,2, **vazando** para fora da página | imagem SVG com opacidade, posição negativa e **recorte na borda da página** |
| `details` | moldura ornamental com cantos recortados, em linhas finas azuis (opacidade 0,35), SVG girado 90° | imagem SVG **girada** |
| `logo` | faixa azul 128×190 que começa **acima** da página (y = −26), com **só os cantos de baixo** arredondados (r 118) e o logo branco (SVG) dentro | retângulo com **raio por canto**, sangria e um **grupo** (forma + imagem) |
| `name-logo` | o texto "WordPress / Campus Connect" já em curvas (SVG) | imagem SVG |
| título | "CERTIFICATE OF PARTICIPATION", Lora SemiBold 16 pt, maiúsculas, Charcoal 4 | maiúsculas por estilo |
| nome | `{name}` em Lora Medium 41,8 pt, centralizado em 473 pt de largura | **encolher para caber** |
| texto | Inter Regular 13 pt, entrelinha 1,4, letras −0,26, com **trechos em negrito** ("**Belo Horizonte, Brazil,**") | **rich text inline**: negrito e itálico dentro do parágrafo, inclusive em placeholders (`**{location}**`) |
| data | `{issued_date}` em Lora 12 + linha de 200 pt (opacidade 0,5) + rótulo "DATE OF EMISSION" em Inter SemiBold 8 | grupo *campo com rótulo* |
| assinatura | imagem da assinatura (SVG, alternável) + linha + NOME em Inter SemiBold 8 + "Event Coordinator" em Inter Medium 8 a 50% | grupo *assinatura* com imagem opcional |

**Variáveis do componente no Figma** = os nossos placeholders: *Participant name*
→ `{name}`, *Date of emission* → `{issued_date}`, *Event Coordinator name* →
`{signer_name}` e *Show signature example* → mostrar a imagem da assinatura.

**Não há QR no design.** No modelo "Campus Connect", o elemento de autenticidade
entra pequeno no centro de baixo, entre a data e a assinatura, dentro da moldura.
Outra opção é a página de autenticação no fim.

**Fontes:** Lora (Regular, Medium, SemiBold) e Inter (Regular, Medium,
SemiBold), as duas OFL. O PDF precisa de **instâncias estáticas** de cada peso; as
versões *variable* não servem.

**Os SVGs** usam só `<svg> <g> <path>` (sem `<text>`, filtros, máscaras nem
clipPath). Eles são as fixtures do spike do motor de PDF.

---

## 2. Conceitos centrais

### 2.1 Onde mora o "evento" (contexto)

O plugin não impõe um CPT de evento. O certificado sempre pertence a um
**contexto**, que pode ser de três tipos:

| Contexto | Quando | Quem é o evento | Participantes vêm de |
|---|---|---|---|
| `site` | WordCamp (cada subsite é um evento) ou qualquer site de um evento só | o próprio site; dados em *Configurações → Certificados* | CampTix (`tix_attendee`), CSV ou manual |
| `gatherpress` | site com GatherPress (vários eventos) | o post `gatherpress_event` (precisa do **ID do evento**) | RSVPs `attending` (+ check-in), CSV ou manual |
| `standalone` | grupo de meetup com vários eventos sem GatherPress | CPT `evcert_event`, opcional e só ativado quando usado | CSV do Meetup ou manual |

- **Design:** ver 2.5. No WordCamp/CampTix o design é o do site (um por subsite).
  No GatherPress há um global e cada evento pode ajustá-lo.
- **GatherPress:** um painel "Certificados" na barra lateral do editor do
  `gatherpress_event`, com carga horária, exigir check-in e um link para emitir e
  ver os certificados. A data e o local vêm do próprio evento.
- **WordCamp:** em multisite, cada subsite tem a sua tabela (`$wpdb->prefix`) e a
  sua URL de autenticidade (`2026.saopaulo.wordcamp.org/certificate/…`). Não há nada
  em nível de rede.

### 2.1.1 Site comunitário com vários grupos (ex.: wp-brasil.org)

Um cenário intermediário: o plugin roda no **site da comunidade** (WP Brasil), e os
organizadores dos meetups de cada cidade recebem acesso **só ao próprio grupo**.
Isso vale até a migração para o **events.wordpress.org** (grupos em GatherPress).

**O wp-brasil.org não tem GatherPress nem CampTix.** Por isso o plugin tem um
**modo próprio de grupos e eventos** (o contexto `standalone` da 2.1, agora
completo), que se ativa quando nenhum dos dois existe. Se o GatherPress for
instalado depois, os grupos podem passar a apontar para eventos dele.

**Grupos e eventos próprios**
- **Grupos:** CPT `evcert_group` (por exemplo "WordPress Meetup Curitiba"), não
  público, com slug, logo, cidade/país e um campo **"URL do grupo no
  events.wordpress.org"** (vazio até a migração, 2.1.1 abaixo).
- **Eventos:** CPT `evcert_event`, filho de um grupo. Cada evento tem título, data
  e hora (início/fim, com fuso), local (ou "online"), carga horária, link da página
  no Meetup.com (opcional) e "exigir presença".
  - É um cadastro mínimo, não um sistema de eventos: nada de RSVP nem página
    pública de evento. As inscrições continuam no Meetup.com ou onde já estão.
- **Meta do grupo:** nome do emissor ("Meetup WordPress Curitiba"), logo,
  organizadores (IDs de usuário), signatários padrão, design do grupo e texto de
  compartilhamento (hashtags da cidade).
- Num site de um evento só (uma WordCamp), a taxonomia nem aparece: o grupo é
  implícito.

**Permissões**
- Um papel novo, **"Organizador de certificados"**, com só as capabilities do
  plugin + `upload_files` (para logo e assinatura) + `read`.
  - Ele não edita posts, páginas nem nada do site.
  - O admin do site pode dar esse acesso a um usuário existente sem mudar o papel
    dele (capability por usuário).
- **Escopo por grupo:** um `map_meta_cap` permite editar um evento, design ou
  certificado só se o usuário estiver nos organizadores do grupo daquele item.
  Admins veem tudo.
- As listas (eventos, certificados, designs) já vêm filtradas pelos grupos do
  usuário.
- **Mídia:** o organizador vê só os próprios uploads na Biblioteca
  (`ajax_query_attachments_args` filtrado por autor, para quem não pode
  `edit_others_posts`).

**Design com herança em três níveis**

> design do site (WP Brasil) → design do grupo (logo e cores da cidade) → ajuste do evento

A mesma lógica de overrides por campo (2.5) se aplica, nível a nível.

**Emissão e verificação**
- O carimbo diz quem emitiu: "Emitido por **Meetup WordPress Curitiba** via
  wp-brasil.org em 2026-11-04 19:30 UTC−03:00".
- Todos os códigos ficam em `wp-brasil.org/certificate/…`, num registro único para
  todas as cidades.

**Migração de um grupo para o events.wordpress.org (com redirecionamento)**

O link impresso nunca quebra. Ele passa a **redirecionar** para o novo endereço
quando o grupo migra.

1. **Exportar grupo** (no wp-brasil.org): um JSON/ZIP com os designs (com imagens),
   os eventos e o **registro de certificados** do grupo.
2. **Importar** no site novo (o do grupo no events.wordpress.org, com o plugin): os
   certificados entram com **o mesmo código** e os mesmos dados congelados.
3. **Marcar o grupo como migrado** no wp-brasil.org, informando a URL nova (por
   exemplo `events.wordpress.org/curitiba/`).
   - Antes de ativar, o plugin **confere** se a URL nova responde por alguns
     códigos do grupo. É uma requisição feita pelo admin, para um destino que ele
     informou, e entra na seção *External services* do readme.
4. Daí em diante, `wp-brasil.org/certificate/CODIGO` de um certificado desse grupo
   responde **301** para `events.wordpress.org/curitiba/certificate/CODIGO/` (a base
   nova + o mesmo código). O mesmo vale para `/pdf/` e `/image/`.
5. O wp-brasil.org **mantém a entrada** (código → grupo, dados e status) como
   reserva:
   - se o site novo sair do ar, o admin desliga o redirecionamento e a verificação
     volta a responder localmente;
   - revogações feitas no site novo não chegam ao antigo. Por isso, enquanto o
     redirecionamento estiver ativo, ele é a **fonte da verdade**, e a cópia local
     fica marcada como "migrado em <data>".

- **Isso depende de o plugin rodar no events.wordpress.org.** Ele é mantido pelo
  time Meta, que é quem instala plugins lá (ver a seção 3, "Por que falar com o time
  Meta"). Sem isso, o grupo continua emitindo no wp-brasil.org, sem migração.
- A estrutura dos grupos no events.wordpress.org (um site por grupo? uma URL por
  grupo?) é **a confirmar**. Ela define o formato da "base nova".

### 2.2 Certificado = registro emitido e congelado

A tabela `{prefix}evcert_certificates` funciona como um cartório, com uma linha por
certificado emitido:

```
id             BIGINT PK
code           VARCHAR(20) UNIQUE   -- ex. 2611-04K7-M29Q-XAP3 (data de emissão + aleatório)
context        VARCHAR(20)          -- site | gatherpress | standalone
event_id       BIGINT               -- 0 em "site"; ID do gatherpress_event / evcert_event
name           VARCHAR(190)         -- congelados na emissão ↓
email          VARCHAR(190)         -- pode ser vazio (Meetup)
wporg_username VARCHAR(60)          -- identidade padrão (seção 3)
doc_type       VARCHAR(40) NULL     -- id do tipo configurado (2.2.2)
doc_label      VARCHAR(60) NULL     -- nome do documento quando o tipo é "Outro"
doc_hash       VARCHAR(255) NULL    -- v1:<pepper_id>:password_hash(HMAC(pepper, número)); o número real nunca é gravado
doc_check_fail SMALLINT DEFAULT 0   -- tentativas erradas na conferência
doc_masked     VARCHAR(40) NULL     -- •••.•••.789-•• (≤ 60% visível)
self_service   VARCHAR(20) NULL     -- pending | confirmed | correction_requested
role           VARCHAR(60)          -- id da função (os rótulos pt/en vêm da configuração, 2.3.4)
locale         VARCHAR(12)          -- idioma do emissor na emissão (ex.: pt_BR)
details        TEXT
event_title    VARCHAR(255)
event_start    DATETIME             -- dados estruturados, nunca texto formatado (2.3.4)
event_end      DATETIME
event_tz       VARCHAR(40)
hours          DECIMAL(5,2)         -- número; a unidade é traduzida na exibição
data_json      LONGTEXT             -- demais dados estruturados (local, detalhes; textos livres com a versão em inglês quando houver)
fingerprint    CHAR(64)             -- SHA-256 dos dados congelados
renderer_version VARCHAR(10)        -- versão do desenhista de PDF na emissão
design_hash    CHAR(64)             -- snapshot do design usado (2.5)
source         VARCHAR(20)          -- camptix | gatherpress | csv | manual
source_ref     VARCHAR(100)         -- ID do attendee / RSVP / user Meetup
status         VARCHAR(20)          -- draft | issued | revoked
replaced_by    BIGINT NULL          -- certificado que substituiu este
revoked_reason VARCHAR(255)
issued_at      DATETIME (UTC)
emailed_at     DATETIME NULL
email_status   VARCHAR(10) NULL     -- queued | sent | failed
email_attempts TINYINT DEFAULT 0
KEY(context,event_id), KEY(email), KEY(wporg_username), UNIQUE(context,event_id,source,source_ref)
```

**Princípio: guardamos receitas, não arquivos.**
- Nenhum PDF, PNG ou ZIP fica salvo. Cada certificado é uma **entrada** com tudo o
  que é preciso para gerá-lo de novo, idêntico, a qualquer momento:
  - os **dados congelados** (nome, evento, datas, papel, carga horária…);
  - o **`design_hash`**, que aponta para o snapshot do design: um JSON de alguns
    KB, guardado **uma vez** e compartilhado por todos os certificados com aquela
    arte (2.5);
  - o **código**, a **impressão digital** e o **`renderer_version`** (a versão do
    desenhista de PDF que o emitiu, para rastrear diferenças se o motor mudar).
- **Custo por certificado:** uma linha de banco (cerca de 1 KB). Mil participantes
  dão cerca de 1 MB mais um snapshot de design, contra 100–300 MB de PDFs
  guardados.
- O que já existe e é **referenciado**, não copiado: as imagens da arte (anexos da
  Biblioteca de Mídia, com o SHA-256 guardado no snapshot para detectar troca) e as
  fontes (arquivos do plugin).
- **A impressão digital e o hash da página de autenticação são dos dados**, não do
  arquivo PDF. Os bytes do PDF mudam a cada geração (data de criação, ID do
  documento), mas o conteúdo não. Assim, conferir o certificado é comparar o que
  está impresso com o que a página de verificação mostra.
- **Desempenho:** gerar um PDF leva de dezenas a centenas de milissegundos (medir
  no spike). O download manda `ETag`/`Last-Modified`, e o navegador ou o CDN
  guardam a cópia, nunca o servidor. **Exceção:** o PDF com o documento completo
  (digitado pelo dono na hora) sai como `private, no-store` (2.2.2). Downloads em massa (ZIP, envio de e-mails)
  rodam em lotes.
- Os e-mails enviam o **link** do certificado, não o PDF anexado. Assim não há
  arquivo gerado nem caixas de e-mail pesadas, e o link sempre traz o status atual
  (revogado aparece como revogado).

**Ciclo de vida** (status `draft → issued → revoked`)

1. **Rascunho** (`draft`): a pessoa entrou por importação, colagem, cadastro manual,
   CampTix ou GatherPress. Nessa fase **tudo é editável à vontade**: nome, papel,
   detalhes e presença. Ainda não há código nem PDF público. É a fase em que o
   organizador revisa a lista, com ações em lote que ajudam:
   - "Corrigir maiúsculas", que transforma `JOAO DA SILVA` e `joao da silva` em
     `Joao da Silva`, com exceções para *da, de, do, dos, e, van, von, 't*;
   - "Marcar presentes" / "Marcar ausentes";
   - um filtro "nomes suspeitos": só minúsculas/maiúsculas, um nome só, e-mail no
     lugar do nome.
2. **Emitir** (`issued`): o organizador clica em "Emitir certificados" (todos ou
   os selecionados, só os presentes quando "exigir presença" está ligado). A ação
   congela os dados, gera o código e grava o snapshot do design. Opcionalmente,
   envia os e-mails. O PDF é sempre redesenhado a partir desses dados, então não é
   preciso guardar arquivos.
3. **Depois de emitido, o conteúdo não muda.** Se alguém pedir a correção do nome
   (um acento faltando, o nome social, um sobrenome trocado), a ação "Corrigir e
   reemitir" faz o seguinte:
   - o certificado antigo é **revogado** com o motivo "substituído por <código
     novo>";
   - um novo é emitido com os dados corrigidos;
   - a página de verificação do código antigo mostra "Substituído", com um link
     para o novo.

   Isso protege quem já conferiu o documento: um código nunca passa a significar
   outra coisa.
4. **Revogar** (sem reemitir): para emissões por engano, como alguém que não
   compareceu.

- **Por que existe a correção:** inscrições chegam com nomes digitados de qualquer
  jeito (tudo minúsculo, apelido, sem acento), e as pessoas pedem o nome correto ou
  o nome social no certificado. A fase de rascunho resolve quase tudo antes da
  emissão. O "corrigir e reemitir" fica para o que aparecer depois.
- **Ressincronizar** o CampTix ou o GatherPress atualiza os rascunhos (pelo ID de
  origem, nunca pelo nome, ver 2.2.3) e cria os que faltam. Os emitidos não mudam.
- **Status:** `draft | issued | revoked`. O `revoked_reason` e o
  `replaced_by` (id do novo) entram no schema.

### 2.2.1 Emitir e enviar em lote (meetups e WordCamps)

O comum depois de um meetup é emitir para todos os presentes de uma vez e mandar
por e-mail.

**Emitir em lote**
- "Emitir certificados" atua sobre todos os rascunhos presentes, os selecionados ou
  o filtro atual.
- Ela roda em **lotes de ~100 por requisição**, com uma barra de progresso. Se o
  navegador fechar, os já emitidos ficam emitidos e o resto continua de onde
  parou.
- **Revisão antes de emitir:** um resumo ("42 presentes, 3 nomes suspeitos, 5 sem
  e-mail") e uma **prévia do certificado** de 3 pessoas reais (nome mais longo,
  nome com acento e um sorteado).

**Enviar por e-mail em lote**
- É uma **fila própria** no banco (coluna `email_status`: `queued | sent | failed`
  + `email_attempts` + `emailed_at`), processada por WP-Cron em lotes pequenos (20
  por minuto, filtrável) para respeitar o limite de envio da hospedagem.
- Há também o botão "Processar agora" para quando o WP-Cron não roda.
- Telas: progresso ("120 de 340 enviados"), lista de falhas com "Tentar de novo",
  e reenvio individual.
- **Conteúdo do e-mail:**
  - assunto e texto editáveis por evento, com placeholders;
  - o link de verificação/download, "Add to LinkedIn" e os botões de
    compartilhar;
  - **opção "anexar o PDF"**: o PDF é gerado na hora do envio, só em memória, e
    **não fica salvo**. A opção vem desligada por padrão, porque o link é mais leve
    e sempre mostra o status atual.
- Usa `wp_mail()`, então funciona com o plugin de SMTP que o site já tiver. O
  remetente e o reply-to são configuráveis (por exemplo, o e-mail do grupo).
- **Quem não tem e-mail** (CSV do Meetup) fica de fora da fila e aparece em "Sem
  e-mail: exportar links", que gera um CSV de nome + link para mandar pelo Meetup.
- **Site não público ou Playground:** o envio é **desligado**, porque os links não
  funcionariam e o Playground não tem e-mail. A saída é o **ZIP** de PDFs (2.4).

### 2.2.2 Documento da pessoa (CPF, passaporte, qualquer um), opcional

- **No Brasil não há lei federal** exigindo CPF em certificado de curso livre ou
  evento. Quem exige são as **instituições que aceitam o certificado**, como os
  regulamentos de atividades complementares das universidades.
  - Algumas aceitam nome completo **ou** RG **ou** CPF (IFB).
  - Outras pedem o CNPJ/CPF de **quem emitiu** quando o emissor é externo (UFPE).
  - Ele também resolve **homônimos** (2.2.3).
- **É desligado por padrão**: o organizador ativa por evento ou grupo e escolhe
  os **tipos aceitos**.

**Padrão: "Outro", aberto e personalizável**

O tipo **padrão é sempre "Outro"**: um documento **aberto**, que existe em todo site
e não pode ser excluído. Ele é personalizado em camadas, e a primeira camada que
definir um campo vence:

| Quem | O que pode definir no "Outro" | Exemplo |
|---|---|---|
| **Emissor** (organizador do evento ou grupo) | o nome do documento, o formato de digitação, o validador (da lista do código), a máscara pública (≤ 60%) e a ajuda. Ou deixar tudo em aberto | num meetup no Brasil: nome "CPF", formato `000.000.000-00`, validador CPF, máscara `•••.•••.###-••` |
| **Participante** (ou palestrante, voluntário…) | o que o emissor **deixou em aberto**: tipicamente o **nome do documento** e o número em formato livre | "DNI", "Cédula de identidade", "Passport" |
| **Admin do site** | os padrões do "Outro" no site e os **modelos prontos** (abaixo), para o emissor não começar do zero | — |

- Sem nenhuma personalização, a pessoa escreve o nome do documento e o número
  livre, e a máscara pública é automática (≤ 60%). Funciona em qualquer país, sem
  configuração.
- O emissor configura o "Outro" na tela do evento ou do grupo, com uma prévia ao
  vivo da máscara. A regra dos 60% e a lista fechada de validadores valem igual:
  o servidor recusa o que passar disso.
- A forma mascarada e o nome do documento usados ficam gravados **com cada
  documento**, porque o número não existe para recalcular. Mudar a configuração
  depois só afeta os novos.

**Modelos prontos cadastrados no painel** (atalhos para o emissor)

O admin do site cadastra, edita e ativa/desativa os tipos de documento numa tela
própria (*Certificados → Documentos*, DataViews + DataForm, abilities
`evcert/get-document-types` e `evcert/save-document-type`). A capability é
`evcert_manage_document_types`, só para admins. Os organizadores **escolhem**,
entre os tipos ativos, quais o seu evento ou grupo aceita, mas não criam tipos.

**Campos de um tipo**

| Campo | Exemplo (CPF) | Regras |
|---|---|---|
| Nome | "CPF" | texto do site |
| Nome em inglês (opcional) | "Brazilian Taxpayer ID (CPF)" | usado na página de verificação em inglês, para estrangeiros |
| País (opcional) | Brasil | serve para sugerir o tipo pelo país do grupo ou evento |
| **Formato de digitação** | `000.000.000-00` | só a sintaxe de máscara: `0` dígito, `A` letra, `*` qualquer, e os outros caracteres viram separadores. **Não há regex livre** (evita ReDoS e erro de configuração). Também pode ser "livre", com tamanho mínimo e máximo |
| **Validador** | "CPF (dígitos verificadores)" | escolhido de uma **lista no código**: nenhum, CPF, CNPJ, módulo 11 genérico… Novos validadores entram pelo filtro `evcert_document_validators` ou por PR |
| **Máscara pública** | `•••.•••.###-••` | `#` mostra, `•` esconde. **Recusada ao salvar se mostrar mais de 60%** dos alfanuméricos. Também pode ser "automática". Uma prévia ao vivo usa um número fictício válido |
| Texto de ajuda | "Digite só os números…" | aparece no formulário de autoatendimento |
| Ativo | sim/não | um tipo inativo some dos formulários. Os documentos já gravados continuam |
| Sugerido por padrão | sim | qual vem selecionado quando o evento não define |

**Modelos iniciais** (criados na ativação conforme o idioma do site, e editáveis
depois). Eles são **atalhos**: o emissor escolhe um modelo e o "Outro" do evento
já vem preenchido. O padrão continua sendo o "Outro" aberto.
- site em **pt-BR**: os modelos CPF (validador de CPF, máscara
  `•••.•••.###-••`), RG (livre, máscara automática) e Passaporte;
- outros idiomas: Passaporte;
- os nomes e os textos de ajuda iniciais são strings em inglês traduzidas
  normalmente. Depois de criados, viram dados do site e são editados no painel.
- **"Outro"** é especial: a pessoa escreve o nome do documento ("DNI",
  "Cédula…") e usa formato livre com máscara automática.

**Mudar um tipo depois de haver documentos:**
- **o formato, o validador e a máscara valem só para os documentos novos.** A
  forma mascarada de cada documento é calculada na gravação e não pode ser
  recalculada, porque o número real não existe (é tratado como senha, abaixo);
- a tela avisa quantos documentos usam aquele tipo antes de salvar;
- **excluir** um tipo em uso não é permitido, só desativar.

**O que aparece para a pessoa:** o **"Outro" do evento**, com o que o emissor
definiu e o resto em aberto. Se o emissor marcou mais de um modelo como aceito
(por exemplo, CPF **ou** Passaporte, num evento com estrangeiros), aparece um
seletor com eles, e o "Outro" aberto fica no fim da lista.

**Política:** o painel avisa ao cadastrar um nome parecido com identificadores
que facilitam fraude e não costumam ir em certificados (SSN dos EUA, por
exemplo). É só um aviso, porque a decisão é do admin, e a regra dos 60% continua
valendo.

**Regra dos 60% (garantida pelo servidor, sem exceção)**
- Nas telas públicas (verificação, prévia das redes, imagem, página "Meus
  certificados" vista por outra pessoa), **no máximo 60% dos caracteres
  alfanuméricos** do número aparecem. Os separadores (`.` `-` `/` espaço) **não
  contam** e aparecem como estão.
- Uma **máscara pública configurada** que mostre mais que 60% é **recusada ao
  salvar**. Exemplo: o CPF tem 11 dígitos, então no máximo 6 podem aparecer
  (`•••.###.###-••` = 54%, o teto). O padrão `•••.•••.###-••` mostra 3 (27%), por
  segurança (ver "contra a adivinhação" abaixo).
- **Máscara automática** (tipos sem máscara, como "Outro"): mostra no máximo 60%,
  arredondado **para baixo**, sempre do **meio**, escondendo começo e fim, como
  o gov.br.
  - `AB1234567` (9) → 5 visíveis → `••12345••`.
  - Documentos muito curtos (≤ 4 caracteres) aparecem totalmente mascarados.
- **O número completo nunca vai ao navegador em página pública.** A máscara é
  aplicada no servidor, e o HTML, o JSON, as tags OG e a imagem só recebem o
  mascarado.
- **Conferência sem revelar:** quem confere digita o número completo, e a página
  responde **confere / não confere** (`password_verify`, abaixo), com limite de
  tentativas.

**No PDF**
- `{document}` sai **mascarado** (tipo + máscara pública) em todo PDF gerado
  normalmente, porque o plugin **não tem** o número (ver abaixo).
- **Documento completo no PDF, sob demanda:** o dono, na hora de baixar, clica em
  "Incluir meu documento completo" e **digita o número**.
  - O servidor confere o número contra o hash. Se conferir, gera **aquele** PDF em
    memória com o número completo e o entrega (`private, no-store`).
  - O número é descartado ao fim da requisição. Nada é gravado.

**Armazenamento:** só o tipo, o nome do documento (no caso de "Outro"), a **forma
mascarada** e um **hash no estilo senha**. **O número real não existe no banco**
(ver logo abaixo).
- Por consequência: não há CSV com documentos, compartilhamento, LinkedIn nem
  busca por documento. Não há o que exportar.
- As ferramentas de privacidade do WP: o exportador entrega o tipo e a forma
  mascarada; o apagador remove o hash e a máscara.

#### Gravação segura: o documento é tratado como **senha**

**Decisão:** o plugin **nunca guarda o número real**, nem cifrado. Guarda só o que
permite **conferir** se um número digitado é o mesmo, como o WordPress faz com
senhas. Se o banco vazar, não há número para vazar. Também não existe "revelar"
no admin.

**O que é gravado, na entrada** (autoatendimento, importação de CSV, cadastro
manual):
1. **Normalizar:** só os alfanuméricos, em maiúsculas (`123.456.789-09` →
   `12345678909`).
2. **Validar** pelo tipo (formato e dígitos verificadores do CPF) e recusar um
   número inválido.
3. **Calcular a forma mascarada** pela máscara pública do tipo (≤ 60%, regra
   acima) e guardá-la, por exemplo `•••.•••.789-••`.
4. **Calcular o hash:**

   ```
   pepper  = HMAC-SHA256( EVCERT_DOC_PEPPER , normalizado )        // chave fora do banco
   hash    = password_hash( pepper , PASSWORD_ARGON2ID )            // bcrypt se não houver Argon2
   gravado = "v1:<pepper_id>:" . hash
   ```

   - **Argon2id/bcrypt com sal próprio:** cada tentativa custa caro (é lento de
     propósito), e cada registro precisa ser atacado separado.
   - **Pepper:** um HMAC com uma chave que fica **no `wp-config.php`**
     (`EVCERT_DOC_PEPPER`). Quem só tem o dump do banco **não consegue nem começar**
     a adivinhar.
   - Isso é necessário porque o espaço de um CPF é pequeno (cerca de 10⁹). Um hash
     de senha sozinho seria quebrável com tempo, e o pepper fecha essa porta.
5. **Descartar o número:** ele não fica em variável persistente, log, sessão,
   transient, `data_json` nem e-mail. Faz-se o melhor possível com `sodium_memzero`
   nas variáveis.

**Conferir** (página pública ou "documento completo no PDF"): normalizar o que foi
digitado, aplicar o mesmo pepper e checar com `password_verify`, que compara em
tempo constante.
- Um `password_needs_rehash()` ou um `pepper_id` antigo, depois de uma conferência
  bem-sucedida, regrava com o algoritmo e o pepper atuais. É a mesma rotação "na
  próxima vez que a pessoa logar" das senhas.

**Pepper (chave)**
- **Gerado pelo plugin** (`wp evcert pepper generate`, ou um botão que mostra a
  linha para colar no `wp-config.php`). O plugin **nunca** o grava no banco.
- **Sem a constante, o campo de documento fica desligado.** Não há alternativa
  derivada dos salts, porque plugins de segurança rotacionam os salts.
- **Rotação:** `EVCERT_DOC_PEPPER` (novo) + `EVCERT_DOC_PEPPERS_OLD` (lista com
  ids). Os hashes antigos continuam conferindo e migram na próxima conferência.
- **Perder o pepper** significa que as conferências daquele período deixam de
  funcionar. **O certificado e a forma mascarada continuam valendo**, então nada
  se perde além do "confere".
- **Multisite:** um pepper por rede, com o `site_id` dentro do HMAC.

**O que a pessoa e o organizador veem**
- **A pessoa**, no autoatendimento, vê o tipo + a forma mascarada ("CPF
  •••.•••.789-•• cadastrado"). Para trocar, digita de novo, como uma senha. Não
  existe "mostrar o que eu salvei".
- **O organizador** vê o mesmo mascarado. Não há "revelar" nem export.
  - Se precisar conferir (por exemplo, uma instituição ligou), usa o mesmo
    "confere?" da página pública, com os mesmos limites.

**Contra a adivinhação**
- **Online** (o "confere?" público e o "documento completo no PDF"):
  - 5 tentativas erradas por certificado a cada 24 h;
  - 20 erradas no total → a conferência daquele certificado se bloqueia até o
    organizador liberar;
  - limite por IP.
  - A conferência pública é **opcional** e vem **desligada** por padrão.
- **Offline**, se o banco vazar: sem o pepper é impossível. Com o pepper também
  vazado (o servidor inteiro comprometido), o Argon2id ainda custa caro por
  tentativa e por registro.
  - A máscara pública reduz o espaço de busca. Por isso a **máscara padrão do
    CPF mostra só 3 dígitos** (`•••.•••.789-••`): sobram cerca de 10⁶
    combinações. Mostrar 6 deixaria só 10³.
  - O organizador pode subir até o teto de 60%, com um aviso dessa troca.

**Transporte e tela**
- O formulário só aceita o envio por **HTTPS** (`is_ssl()`), exceto em site não
  público. Ele tem `autocomplete="off"` e nunca devolve o número, nem quando a
  validação falha.
- O número nunca aparece em mensagens de erro, `debug.log` ou respostas das
  abilities. A impressão digital do certificado usa a **forma mascarada**, nunca
  o número.

**Auditoria:** a tabela `{prefix}evcert_audit` registra conferências (acerto/erro,
IP truncado), bloqueios e desbloqueios, trocas e exclusões de documento. Nunca
guarda o número.

**LGPD**
- O formulário avisa: "seu documento é guardado como uma senha: nem a
  organização consegue lê-lo; ele aparece mascarado no certificado e na página
  pública".
- Há um texto sugerido para a política de privacidade
  (`wp_add_privacy_policy_content`).

**Testes obrigatórios**
- depois de gravar, o número de teste **não aparece em nenhuma tabela** (busca
  em todas), nem em `debug.log`, transients ou e-mails;
- `password_verify` confere o número certo e recusa o errado; o hash sem o pepper
  não confere;
- a rotação: um hash com o pepper antigo confere e é regravado com o novo;
- a máscara nunca passa de 60% (teste de propriedade com milhares de formatos);
- as páginas públicas (HTML, JSON, cabeçalhos, tags OG, imagem) não contêm o
  número completo;
- a conferência se bloqueia na 21ª tentativa errada;
- sem a constante do pepper, o campo fica desligado e nada é gravado;
- o "documento completo no PDF" com o número errado não gera PDF; com o certo,
  gera com `no-store` e não deixa rastro no banco.

**Emissor:** o campo opcional "documento do emissor" (CNPJ/CPF do organizador ou
da associação) vai para o carimbo de emissão, para as instituições que exigem a
identificação de quem emitiu.

### 2.2.3 Homônimos: duas pessoas com o mesmo nome

Duas "Maria Silva" no mesmo evento são comuns. O plugin **nunca usa o nome como
identidade**.

- **Importar e sincronizar:** a deduplicação é por `source + source_ref`, depois
  `wporg_username` e depois `email`. O nome **não é chave**. Duas linhas com o
  mesmo nome e identificadores diferentes (ou sem nenhum) viram **duas pessoas**
  e recebem a marca "possível homônimo ou duplicado", para o organizador revisar
  no rascunho (juntar ou manter as duas). O plugin nunca junta sozinho.
- **No certificado:** cada pessoa tem o seu **código**, que é o que identifica o
  documento. Para quem confere, o que diferencia homônimos é:
  - o **usuário do WordPress.org**, com o link para o perfil (padrão quando
    existe);
  - o **documento mascarado** (`***.456.789-**`), com a conferência "digite o
    CPF" (2.2.2). É por isso que tantos certificados brasileiros trazem o CPF;
  - o e-mail **nunca** aparece.
- **Na busca:** pelo usuário do WordPress.org não há ambiguidade, porque ele é
  único. Pelo e-mail também não, porque cada e-mail recebe só os próprios links.
  **Não existe busca por nome**, justamente por causa dos homônimos e da
  privacidade.
- **"Meus certificados"** (logado) usa o `user_login`, nunca o nome.

### 2.2.4 Autoatendimento: a própria pessoa completa os dados

**Funções** (papéis): a lista é configurável por site/grupo, e cada função pode ter
o seu texto no certificado (via "mostrar só para os papéis…", 2.6). Vêm por
padrão **Participante, Voluntário(a), Palestrante, Painelista, Mediador(a) e
Organizador(a)**. Todas usam o mesmo autoatendimento.

**O que a própria pessoa pode preencher ou corrigir**, enquanto o certificado é
**rascunho** (antes da emissão):

| Campo | A pessoa edita? | Observação |
|---|---|---|
| Nome como deve sair no certificado (inclusive **nome social**) | ✅ | grandes diferenças em relação ao nome da inscrição ficam marcadas para o organizador revisar |
| Documento: tipo, nome do documento ("Outro") e número | ✅ | com o formato e a validação do tipo (2.2.2) |
| Usuário do WordPress.org | ✅ (só fora de sites com login do WordPress.org) | nos sites com login, vem da conta |
| Idioma preferido da página de verificação e do e-mail | ✅ | |
| Função, carga horária, presença e detalhes (ex.: título da palestra) | ❌ | só o organizador. A pessoa pode **pedir** correção com um comentário |

**Como a pessoa chega ao formulário "Confirme os dados do seu certificado"**
- **Sites com login do WordPress.org:** "Meus certificados" → "Completar dados"
  (logado, sem link especial).
- **Os outros sites:** um **link pessoal assinado** (HMAC com expiração), enviado
  pela fila de e-mails (2.2.1) no envio "Confirme seus dados até <data>". Ele
  também sai no CSV de links, para quem não tem e-mail (Meetup).
- **Playground ou site não público:** não há autoatendimento. O organizador
  preenche.

**Prazo:** o organizador define "dados abertos até <data>" por evento. Durante o
prazo, um painel mostra quem confirmou, quem falta e quem pediu correção. Depois
do prazo, ele emite em lote (2.2.1), e nada impede emitir antes para quem já
confirmou.

**Depois de emitido**, a pessoa só pode **pedir correção**: o organizador aprova,
e é "corrigir e reemitir", com código novo (2.2).

**Segurança**
- Nonce, limite por IP e por link, e o link assinado vale para **um** certificado.
- Os dados enviados são sanitizados e validados no servidor.
- O formulário mostra o documento já salvo **mascarado** (regra dos 60%), nunca
  completo: para trocar, a pessoa digita de novo.

### 2.3 Autenticidade (estilo gov.br, funcionando para estrangeiros)

**URL**
- O slug padrão é `certificate`, em inglês, e serve para tudo: a página do
  certificado com a autenticidade (`/certificate/<código>/`), o formulário para
  digitar o código (`/certificate/`), o PDF (`…/<código>/pdf/`) e a imagem
  (`…/<código>/image/`). Ele pode ser trocado na aba de configurações dos
  certificados ("Endereço do certificado"; no CampTix, Ingressos → Configuração →
  Certificados). Foi escolhido em vez de `verify`, que é genérico, colide com
  outros plugins e não diz o que se verifica.
- Exemplos: `https://site/certificate/` (formulário para digitar o código) e
  `https://site/certificate/2611-04K7-M29Q-XAP3/` (resultado).
- Usa uma rewrite rule própria, com flush só quando o slug muda.
- Com links permanentes "simples", cai para `?evcert_certificate=CODIGO`, que funciona
  em qualquer configuração.
- Um aviso aparece se já existir uma página ou post com o mesmo slug.

**Código**
- Formato `AAMM-DDxx-xxxx-xxxx`: começa pela data de emissão, como no gov.br, e o
  resto é aleatório (CSPRNG), num alfabeto Crockford base32 sem I/L/O/U.
- São cerca de 50 bits aleatórios, mais o limite de tentativas por IP.
- A busca ignora maiúsculas, hífens e espaços. Na leitura, O vira 0 e I/L viram 1.

**No PDF**, uma caixa no rodapé, posicionável como os outros blocos:
- o QR code com a URL completa (desenhado em **vetor** com `Rect()` do tFPDF, nítido
  e sem imagem temporária);
- o texto "Verifique a autenticidade em `site/certificate` com o código `XXXX`" no
  idioma do site, com a linha em inglês embaixo quando o site não está em `en_*`
  (pode ser desligado);
- a data de emissão em ISO (`2026-11-04`).

**Página de verificação**
- Usa o tema do site, com `noindex`.
- Mostra:
  - ✅ **Valid** ou ❌ **Revoked** (com o motivo e o link do substituto) ou
    **Not found**;
  - nome, evento, datas, papel, carga horária, emitido em, código e fingerprint
    curto;
  - o botão "Baixar PDF" (redesenhado a partir dos dados congelados).
- O seletor de idioma (visível no topo, com os idiomas definidos no site, 2.3.4) troca o idioma de toda a página
  (`switch_to_locale`), e o plugin já vem com a tradução em inglês.
- O formulário de código usa nonce, honeypot e um limite por IP (transient).
- Filtros: `evcert_certificate_slug`, `evcert_verify_fields`, `evcert_code_format`.

**Privacidade**
- A página mostra o nome (precisa mostrar para autenticar) e **nunca o e-mail**.
- O PDF só é aberto por quem tem o código. A busca por e-mail (shortcode) lista os
  links ou envia por e-mail, conforme a configuração.

**Lições do 1.7 aplicadas**
- **Código + impressão digital:** o código identifica o certificado, e a impressão
  digital (8 primeiros hex do SHA-256 dos dados + `design_hash`) confirma o
  conteúdo, como o CRC do SEI. Os dois são impressos, e a verificação mostra os dois.
- **Carimbo de emissão:** "Emitido eletronicamente por <organização> em
  2026-11-04 14:32 UTC−03:00". O nome da organização vem das configurações, por
  exemplo "WordCamp São Paulo 2026 — Comunidade WordPress Brasil".
- **Onde a autenticação fica no PDF:** é uma escolha do design ("Autenticação
  no PDF"), com três modos:

  | Modo | Frente | Verso (página 2) |
  |---|---|---|
  | **Mesma página** | o elemento *Autenticidade* (QR + código + URL), posicionado na arte | não existe |
  | **Verso** (padrão sugerido) | só a **linha mínima** (estilo TCU: "Verify at site/certificate · code XXXX"), para a frente impressa sozinha continuar verificável; pode ser desligada | a **página de autenticação** (estilo Receita), bilíngue: QR grande, URL, código, impressão digital, hash SHA-256 completo e o carimbo de emissão |
  | **Os dois** | o elemento na arte | a página de autenticação |

  - O verso tem o mesmo tamanho e a mesma orientação da frente, para imprimir
    frente e verso.
  - O verso também é uma **página do editor** (aba *Frente | Verso*, 2.6). Ele já
    vem com o modelo da página de autenticação e pode ganhar o logo, a
    programação do evento, a carga horária detalhada ou o texto legal.
  - **"Deixar o participante escolher ao baixar":** quando ligado, a página de
    verificação oferece dois botões, "PDF frente e verso" e "PDF página única".
    No PDF de página única, a autenticação entra na frente: o elemento
    *Autenticidade*, se existir no design; senão, a linha mínima.
  - No Playground o verso e a linha mínima não existem (2.4).

### 2.3.1 Compartilhar e levar o certificado

A página de verificação é a página pública do certificado: é ela que se
compartilha, nunca o PDF.

**LinkedIn**
- Botão **"Add to LinkedIn profile"**. Ele abre a URL pública de *add
  certification* já preenchida: nome do certificado, organização (com o ID da
  empresa no LinkedIn, opcional nas configurações, para o logo aparecer), mês e
  ano, `certId` = código e `certUrl` = URL de verificação.
- O mesmo botão vai no e-mail de entrega do certificado.
- Botão **"Compartilhar no LinkedIn"**, para um post com o link.

**Redes sociais**
- Botões: LinkedIn, X, Facebook, Bluesky, Mastodon (pergunta a instância e guarda
  no navegador), Threads, WhatsApp, Telegram, e-mail e "Copiar link".
- Os links de compartilhamento são URLs simples, sem SDKs nem scripts de
  terceiros. O plugin não carrega nada de fora (seção *External services* do
  readme).
- No celular, o botão **Compartilhar** usa a Web Share API (`navigator.share`) com
  o texto, o link e, quando houver suporte, **a imagem do certificado como
  arquivo**. Isso cobre Instagram e Stories, que não aceitam link.
- O texto padrão é editável nas configurações do evento: "Participei do {event}!
  🎉 {verify_url} #WordCamp #WCSP", com placeholders e hashtags.

**Imagem para redes (PNG/JPG)**
- **"Baixar imagem"**: o navegador desenha o SVG do certificado num `<canvas>` e
  gera um PNG de 1600 px. Funciona em qualquer servidor, porque não depende de
  Imagick.
- **Prévia do link (`og:image`)** na página de verificação, com `og:title`
  "{name} — {event}" e `og:description`:
  - `og:image` aponta para `/certificate/CODIGO/image/`, que **gera o PNG sob
    demanda** quando o servidor tem Imagick com SVG. Ele responde com
    `Cache-Control: public, max-age=…` e `ETag` = impressão digital, e não grava
    nada em disco: quem guarda a cópia são as redes, o navegador e um CDN, se
    houver;
  - sem Imagick, `og:image` usa uma imagem **que já existe**: o logo do grupo ou
    do evento, ou o ícone do site. Não se gera nenhum arquivo.
- As tags Open Graph aparecem mesmo com `noindex`: as redes leem a prévia e os
  buscadores não indexam.

**Privacidade**
- Compartilhar é escolha do participante. A página já é acessível a quem tem o
  código.
- Uma opção do organizador desliga os botões e as tags OG (por exemplo, num evento
  fechado).

**Ideia futura**
- Exportar como **Open Badges 3.0 / W3C Verifiable Credential** (JSON assinado),
  o padrão internacional de credenciais.

### 2.3.3 Quem pode o quê: público × logado

O princípio: **conferir e compartilhar são públicos; obter o documento é do dono.**

**Em sites com login do WordPress.org** (wordcamp.org com CampTix,
events.wordpress.org com GatherPress e qualquer site que use as contas do
WordPress.org): o usuário logado **é** a conta do WordPress.org, então dá para
saber com segurança quem é o dono.

| Ação | Quem pode |
|---|---|
| Página de verificação `/certificate/CODIGO/` (status, nome, evento, datas, carga horária, código, impressão digital, link do perfil) | **público** |
| Visualizar a arte do certificado na página e na prévia das redes (`/image/`) | **público**: é o que se compartilha |
| Botões de compartilhar e "Add to LinkedIn" | **público** |
| **Baixar o PDF / "segunda via"** (`/pdf/`) | **só o dono logado** (`user_login` = `wporg_username`) e os organizadores. Os outros veem "Entre com a sua conta do WordPress.org para baixar" |
| **"Meus certificados"**: lista dos meus certificados no site, com download | **logado**, só os próprios |
| Pedir correção do nome (abre um pedido para o organizador, 2.2) | **dono logado** |
| Emitir, revogar e reemitir | organizadores |

- A "segunda via" é o mesmo PDF gerado de novo a partir da entrada (2.2), sem
  novo código. Nada se emite de novo, a menos que haja uma correção.
- O bloco **"Meus certificados"** vai na página do evento ou numa página
  "Certificados". Para o visitante deslogado, ele mostra o botão "Entrar com
  WordPress.org" (o login do próprio site) e o campo de busca.
- No wordcamp.org cada edição é um site da rede. Uma lista "todos os meus
  certificados de todas as WordCamps" precisaria de uma consulta na rede ou de uma
  integração com o profiles.wordpress.org: é uma ideia futura, a conversar com o
  time Meta.

**Em sites sem login do WordPress.org** (wp-brasil.org, sites de grupos,
Playground), onde os participantes não têm conta no site:

| Ação | Quem pode |
|---|---|
| Verificação, arte, compartilhar | **público** (igual) |
| Baixar o PDF | quem tem o **link com o código**, que chega por e-mail, pela lista por usuário ou pelo CSV de links. É configurável: o organizador pode exigir a confirmação por e-mail antes do download |
| Busca por usuário do WordPress.org | **não lista**: envia os links para o e-mail cadastrado (2.7) |
| Busca por e-mail | envia os links para o endereço |

- **Configuração** "As contas deste site são contas do WordPress.org": ligada,
  aplica a primeira tabela. Ela fica ligada sozinha quando o plugin detecta o
  ambiente do wordcamp.org ou do events.wordpress.org (constantes ou filtro, a
  confirmar com o time Meta) e pode ser forçada pelo filtro
  `evcert_site_uses_wporg_accounts`.
- Um "Entrar com WordPress.org" para sites de fora **não existe** como serviço
  aberto. Por isso o wp-brasil.org fica no modelo "link com código" até o grupo
  migrar para o events.wordpress.org.

### 2.3.4 Idiomas: emitido no idioma do emissor, verificável nos idiomas do site

**Princípio:** o certificado sai no **idioma de quem emite** (pt-BR numa WordCamp
brasileira), e **qualquer pessoa** consegue conferir em **qualquer um dos idiomas
definidos no site**, com a troca **visível**. `Português | English` era só um
exemplo: a lista é configurável, e **todo texto personalizado** precisa existir em
cada idioma para a tradução ser completa.

**1. Idiomas do certificado (configuração do site)**
- Fica em *Configurações → Certificados → Idiomas*: uma lista ordenada de locales,
  por exemplo `pt_BR, en_US, es_ES`.
  - **Padrão:** o idioma do site + `en_US`. O inglês vem sugerido porque é a
    língua comum para estrangeiros, mas pode ser removido.
- **O primeiro da lista é o idioma de emissão** (o do emissor). Um grupo ou evento
  pode escolher outro da lista (um evento em espanhol num site brasileiro).
- **Sem integração com Polylang ou WPML:** os sites-alvo (wordcamp.org,
  events.wordpress.org, wp-brasil.org) não os usam, e a lista própria resolve.
  Fica fora do escopo. Se um dia alguém pedir, entra em `compat/` sem mudar o
  resto.
- **Os textos do próprio plugin** (rótulos da verificação, status, botões, Dock)
  vêm das **traduções do WordPress** (language packs, ou
  `wp-content/languages/plugins`) de cada locale. Se um locale não tiver
  tradução instalada, esses rótulos caem para o inglês, e o painel avisa ("es_ES
  sem tradução do plugin instalada").

**2. Guardar dados, não frases**
- O registro congela **valores estruturados**: as datas em ISO + o fuso, a carga
  horária como número, a função como **id** (`speaker`), o documento como tipo +
  máscara e o `locale` de emissão.
- A mesma entrada é exibida em qualquer idioma: "8 horas" / "8 hours" / "8
  horas", "10 de dezembro de 2026" / "10 Dec 2026" / "10 dic 2026".
- A impressão digital é calculada **sobre esses dados**, nunca sobre o texto
  exibido. Todas as versões de idioma são o **mesmo certificado**, com o mesmo
  código.

**3. Todo texto personalizado é traduzível por idioma**
- Tudo o que o admin, o emissor ou o organizador **escrevem** é guardado como um
  mapa `{ locale: texto }`, com um campo por idioma da lista:

  | Texto personalizado | Onde se edita |
  |---|---|
  | Textos da arte (título, texto principal, rótulos "Data de emissão", cargo do signatário…) | editor de arte, com o seletor de idioma no Dock |
  | Nomes das funções (Participante, Palestrante…) | configurações. As padrão já vêm traduzidas pelas strings do plugin em cada locale instalado |
  | Nome do documento ("CPF" → "Brazilian Taxpayer ID (CPF)" → "CPF (Brasil)") e texto de ajuda | tipo de documento / "Outro" do evento |
  | Título do evento, local e nome do emissor/grupo | evento / grupo |
  | Assunto e texto dos e-mails, texto de compartilhar | configurações / evento |
  | Textos da página de autenticação (verso) além dos padrão | editor de arte, prancheta do verso |

- **Exceções que nunca se traduzem:** o nome da pessoa, o que **o participante**
  escreveu (por exemplo, o nome do documento "DNI"), o título da palestra, se o
  organizador não traduzir, e o código.
  - Na página, eles aparecem como foram escritos, com o **rótulo** traduzido:
    "Document: DNI".
- **Os placeholders** (`{dates}`, `{hours}`, `{role}`, `{document}`,
  `{issued_date}`) se formatam sozinhos no idioma de exibição.
- **Completude:**
  - o editor e as telas mostram, por idioma, o que falta ("es_ES: 3 textos sem
    tradução"), com a prévia alternando entre os idiomas;
  - um texto **sem tradução** num idioma **cai para o idioma de emissão**, nunca
    some e nunca fica vazio. Na prévia do editor, ele aparece marcado.

**4. Página de verificação com a troca visível**
- **Seletor fixo no topo**, ao lado do selo de status, com **os idiomas definidos
  no site**, cada um no próprio idioma ("Português", "English", "Español"). Com
  mais de três, vira um menu compacto, sempre visível.
  - O idioma escolhido vai na URL (`?lang=es`), para o link poder ser mandado já no
    idioma certo, e fica lembrado num cookie.
- **Sugestão sem redirecionar:** se o `Accept-Language` do navegador combinar com
  um dos idiomas do site diferente do atual, aparece uma faixa ("View this
  certificate in English?" / "¿Ver en español?"). O link compartilhado nunca muda
  sozinho.
- **No idioma escolhido, a página mostra:** os rótulos e o status (tradução do
  plugin), as datas e a carga horária formatadas, e os textos personalizados
  daquele idioma (ou do idioma de emissão, quando faltam).
- **A prancheta Mobile e a imagem** seguem o idioma escolhido.

**5. PDF em cada idioma**
- **O PDF padrão sai no idioma de emissão**, como foi desenhado.
- **O verso / página de autenticação** sai no idioma de emissão + o idioma
  escolhido na verificação (ou o inglês, por padrão), lado a lado.
- **"Baixar PDF em <idioma>":** aparece para cada idioma da lista em que **todos
  os textos visíveis da arte** estiverem traduzidos. São a mesma arte, o mesmo
  código e os mesmos dados.
  - Num idioma incompleto, o botão não aparece. Um PDF com metade dos textos em
    outra língua não seria um documento apresentável.

### 2.3.2 Links (URLs) por tipo de site

O código é **único no site inteiro**, e não por evento. Por isso a URL de
verificação **não precisa do evento**: a página mostra a qual evento o
certificado pertence. Quanto mais curta a URL, mais fácil de digitar e menor o QR.

| | WordCamp (CampTix) | Meetup com GatherPress | Meetup sem GatherPress (standalone) |
|---|---|---|---|
| Site | um subsite por edição: `saopaulo.wordcamp.org/2026/` | um site do grupo com vários eventos: `wpsp.org` | idem |
| **Verificação** (no QR) | `saopaulo.wordcamp.org/2026/certificate/2611-04K7-M29Q-XAP3/` | `wpsp.org/certificate/2611-04K7-M29Q-XAP3/` | idem |
| Verificação sem código (digitar) | `…/2026/certificate/` | `wpsp.org/certificate/` | idem |
| PDF | `…/certificate/CODIGO/pdf/` (`?sides=front` = só a frente) | idem | idem |
| Imagem para redes | `…/certificate/CODIGO/image/` | idem | idem |
| **"Pegue seu certificado"** (busca por e-mail) | uma página do site, por exemplo `…/2026/certificates/`, com o bloco/shortcode | o bloco **dentro da página do evento** (`wpsp.org/event/meetup-novembro/`, que já sabe o evento), ou uma página geral `wpsp.org/certificates/` com um seletor de evento | página geral, com `event="ID"` ou o seletor |
| Fallback com links "simples" | `?evcert_certificate=CODIGO` | idem | idem |

- `certificate` é o padrão em inglês e pode ser trocado em *Links permanentes*.
  `/pdf/` e `/image/` são sufixos fixos.
- **No WordCamp.org há um porém real:** os organizadores **não instalam
  plugins**, pois a rede wordcamp.org é mantida pelo time Meta, que roda o próprio
  fork do CampTix. Para valer em wordcamp.org, o plugin precisa ser **adotado pelo
  Meta**. Isso pede código no padrão deles (WPCS, sem dependências externas e
  sem requisições de fora) e uma conversa com o time cedo.
  - Até lá, o caminho para uma WordCamp é um site WordPress próprio, que importa o
    CSV exportado do CampTix.

### 2.4 Site não público (Playground, localhost, intranet): autenticação indisponível

A autenticação só faz sentido quando a URL impressa pode ser aberta por qualquer
pessoa. Isso falha no Playground (o site é temporário), no localhost, no Studio e
em domínios `.local` ou `.test`. Nesses casos **não se imprime um QR falso**.

**Detecção:** `Environment::is_public()`, com os motivos devolvidos para a tela.
Não é público quando:
- é Playground (`SERVER_SOFTWARE === 'PHP.wasm'` ou `php_uname('s')` começando
  com `Emscripten`, testado no Playground CLI);
- o host de `home_url()` é `localhost`, `*.localhost`, `*.local`, `*.test`,
  `*.example`, `*.invalid`, um IP de loopback ou privado (10/8, 172.16/12,
  192.168/16, ::1, fc00::/7), ou um host sem ponto;
- `wp_get_environment_type()` é `local` (o `development` e o `staging` só
  geram um aviso, porque podem ser públicos).

Há duas saídas manuais:
- a configuração "Forçar autenticação neste site" (para um staging público), com
  um aviso;
- o filtro `evcert_is_public_site`.

O plugin não faz nenhuma requisição externa para testar se o site é público. Isso
seria enviar dados a terceiros.

**O que muda quando o site não é público:**
- no PDF e no editor, o elemento *Autenticidade* vira um **marcador do mesmo
  tamanho**: um **ícone genérico de QR** (desenho fixo, não escaneável, com o
  canto marcado "indisponível") e textos no lugar do código e da URL, por exemplo
  "Autenticação indisponível: site não público (localhost) / Authentication
  unavailable on non-public sites";
  - a linha mínima e o verso também mostram esses marcadores;
  - não há marca d'água;
  - assim o organizador vê o layout final exato e entende por que o QR não está
    lá;
- os **códigos continuam sendo gerados** na emissão. Se o banco migrar para o
  domínio público, os mesmos certificados passam a sair com o QR e a URL reais,
  porque o PDF é redesenhado no download;
- `/certificate/CODIGO` responde com uma página "verificação indisponível neste
  ambiente", sem dados do certificado;
- a tela de configurações mostra o motivo detectado ("host .local", "Playground"…)
  e a opção de forçar.

**O Playground como ferramenta real para meetups** (sem site, sem autenticação)

Um organizador sem site abre um link do Playground com o plugin, importa o CSV do
Meetup, ajusta a arte e baixa os PDFs. Para isso ficar útil:
- **"Baixar todos (ZIP)":** um PDF por pessoa (`Nome-Sobrenome.pdf`), gerado
  **na hora**, em lotes (para não estourar o tempo e a memória do PHP.wasm), num
  arquivo temporário que é apagado ao fim do download. Também é útil fora do
  Playground (para imprimir).
- **Exportar/importar o design** em JSON, com as imagens embutidas (base64). O
  Playground é temporário: o organizador guarda o arquivo e reaproveita no
  próximo meetup, ou leva para o site da comunidade quando ganhar acesso.
- **Salvar no navegador:** lembrar que o Playground pode persistir o site no
  navegador (OPFS, "Save" no Dock do Playground), com um aviso no nosso painel.
- Os PDFs saem com o marcador de autenticação (2.4). O aviso deixa claro que, para
  certificados autenticáveis, é preciso usar um site público (por exemplo, pedir
  acesso no wp-brasil.org).

**Blueprint de demonstração** (`playground/blueprint.json`): instala o plugin,
importa o `sample.csv` e abre a tela de certificados com a arte base.

### 2.5 Design: global + ajustes por evento (herança por campo)

**Onde o design mora**

| Contexto | Design |
|---|---|
| `site` (WordCamp/CampTix) | o **design do site**, um por subsite, em *Configurações → Certificados → Design* |
| `gatherpress` | o **design global** (o mesmo da tela acima) + **ajustes por evento** |
| `standalone` | igual ao GatherPress |

Um design é composto por:
- o **fundo** (attachment);
- a **página** (A4 paisagem/retrato);
- a **fonte** e a **cor padrão**;
- os **blocos** (`heading`, `intro`, `name`, `body` e variantes por papel,
  `footer`, `authenticity`), cada um com texto, posição, tamanho, negrito,
  alinhamento e cor.

**Ajuste por evento = só a diferença (override), não uma cópia**
- O evento guarda no meta `_evcert_design_overrides` apenas o que mudou, por
  exemplo `{ "background": 812, "blocks": { "heading": { "color": "#c00" } } }`.
- O design efetivo é o global mesclado com os overrides, campo a campo.
- Assim, ao corrigir a arte global (um erro de digitação no texto, uma logo nova),
  a correção chega a todos os eventos, exceto nos campos que o evento alterou de
  propósito.
- Na interface, todo campo alterado no evento aparece marcado com "alterado neste
  evento ↺" e tem o botão "voltar ao global". Há também "voltar tudo ao global".
- O nome, as datas e o local do evento entram sempre por placeholder (`{event}`,
  `{dates}`, `{location}`, vindos do `gatherpress_event`). Então o global serve para
  todos sem ajuste, e o override fica para mudanças de arte (fundo, cor, posição,
  um texto específico).
- O editor visual é o mesmo nas duas telas. No evento, ele abre com o design
  efetivo e grava só a diferença.

**O documento emitido não muda depois (autenticidade)**
- Na emissão, o design efetivo resolvido é gravado como um **snapshot imutável**
  na tabela `{prefix}evcert_designs` (`hash` SHA-256 do JSON, `json`, `created_at`),
  sem duplicatas. Mil certificados com o mesmo design geram um snapshot só.
- O certificado guarda o `design_hash`. O "Baixar PDF" da verificação redesenha com
  o snapshot e os dados congelados, então o PDF é idêntico ao emitido, mesmo que a
  arte tenha mudado depois.
- **Exceção:** se o fundo for apagado da Biblioteca de Mídia, o PDF sai sem fundo.
  O snapshot guarda o SHA-256 do arquivo e a tela de certificados avisa quando um
  fundo usado foi removido.
- Para reemitir com a arte nova, há uma ação em lote "Reemitir com o design atual",
  que revoga os certificados e emite outros com códigos novos. É opcional e nunca
  automática.

### 2.6 Editor de arte: estruturado, em tela cheia com Dock

**Objetivo:** montar a arte com a liberdade de um Canva/Figma simples, mas **sem
virar uma tela em branco**. O modelo mental é o do componente do Figma do Campus
Connect (1.8): uma estrutura pronta com **campos**, que o organizador ajusta.
Ele pode trocar a arte de fundo à vontade, mas nunca consegue gerar um certificado
sem nome ou sem autenticação.

Critério de aceite da etapa 9: reproduzir o certificado do Campus Connect com os
logos trocados por genéricos.

#### Estrutura: slots fixos + camada de arte livre

| Camada | O que tem | Liberdade |
|---|---|---|
| **Conteúdo (slots)** | *Título*, *Nome* (obrigatório), *Texto*, *Data*, *Assinaturas* (1–3), *Autenticidade* (obrigatório quando é na mesma página) e *Campos extras* (carga horária, papel…, opcionais) | mover, redimensionar e estilizar. Os opcionais podem ser ocultados, e os obrigatórios não podem ser removidos. Cada slot sabe o que é (o nome usa "encolher para caber"; a autenticidade troca para o marcador fora de site público) |
| **Arte (decoração)** | imagens PNG/JPG/SVG, retângulos (raio por canto), linhas e textos livres | livre: adicionar, remover, ordenar, opacidade, rotação em passos de 90° e sangria |
| **Página** | tamanho (A4/Letter), orientação, cor de fundo e Frente/Verso | é o painel quando nada está selecionado |

**Dois modos no topo** (como "Editar"/"Design" em ferramentas de cartaz):
- **Conteúdo** (padrão): só os textos e os campos, num painel parecido com as
  propriedades do componente no Figma. É onde ficam o título, o texto (com
  placeholders e negrito), os signatários (nome, cargo, imagem da assinatura) e a
  data. Nada se move. A maioria dos organizadores fica só aqui.
- **Arte:** mostra as alças e as camadas, permite arrastar, e libera o inseridor
  de decorações.

**Ponto de partida: um único modelo "Base", genérico** (sem logos de marcas)
- A estrutura é a do Campus Connect: faixa no topo com um logo placeholder
  ("Seu logo"), título, nome, texto, data, assinatura e autenticidade, mais uma
  moldura simples feita de retângulos e linhas.
- As cores e as fontes (Lora + Inter) seguem o Figma. Tudo é trocável.
- Não há galeria de modelos. "Restaurar o modelo base" fica disponível.

#### Interface: tela cheia responsiva com um *Dock* flutuante (como o novo Playground)

Esta é a referência dada pelo dono do projeto: o Dock do novo Playground (jul/2026).
É uma barra flutuante na parte de baixo, que pode ser **compacta**, ficar
**mínima** (um botão só) ou se **expandir** numa barra fixa de largura total, e que
no celular é usável com um polegar.

**A página inteira é o editor:**
- o canvas ocupa a tela e se ajusta ao dispositivo: no desktop fica em tamanho
  "ajustar à tela"; no celular, cabe na largura e admite pinça para zoom;
- nada de barra superior nem painéis laterais fixos do Gutenberg;
- o **Dock** traz os itens: *Conteúdo* · *Arte* (alterna o modo) · *Inserir* ·
  *Camadas* · *Frente/Verso* · *Prévia PDF* · *Desfazer/Refazer* · *Salvar* ·
  *Mais* (página, autenticação no PDF, restaurar a base, sair);
- os painéis (Conteúdo, propriedades do elemento, Camadas) abrem como **cartões
  flutuantes** acima do Dock no desktop e como uma **folha de baixo** (*bottom
  sheet*) no celular;
- ao selecionar um elemento, aparece uma **mini-barra flutuante** junto dele
  (duplicar, ordem, ocultar, excluir), como a barra de bloco, mas presa ao
  elemento;
- os componentes são do `@wordpress/components` (botões, popovers, controles de
  cor e fonte), então o visual fica "de WordPress" sem depender do editor de
  blocos.

**Consequência técnica: sai o "bloco travado no editor de blocos" (decisão
anterior) e entra uma tela própria em tela cheia.** O armazenamento continua igual:
- o design segue como CPT `evcert_design`. Salvar pela REST API (`/wp/v2/evcert_design`)
  mantém **revisões** (o JSON no `post_content`), permissões e bloqueio de edição
  simultânea do core;
- **desfazer/refazer** passa a ser nosso: um histórico em memória, com um passo por
  ação (o arraste conta um só);
- o autosave é nosso: um rascunho local no `localStorage` e "alterações não salvas"
  no Dock;
- os riscos de conflito de atalhos e seleção com o editor de blocos (tabela
  anterior) **deixam de existir**, porque a tela é só nossa;
- a tela abre em `wp-admin/admin.php?page=evcert-design&design=<id>` sem o menu do
  admin (tela cheia, como o Site Editor), com um botão "Sair" no Dock.

**A mesma linguagem na página pública do certificado** (verificação, 2.3/2.3.1):
- o certificado ocupa a tela, responsivo;
- o Dock traz *Baixar PDF* (com escolha de frente e verso, se liberada) ·
  *Compartilhar* · *LinkedIn* · *Detalhes da autenticação* (cartão com status,
  código, impressão digital e carimbo) · *Idioma*;
- o status (✅ válido / ❌ revogado / substituído) fica sempre visível num selo
  acima do certificado;
- sem JS: a página mostra o certificado e uma lista simples de links e dados.

#### Desenhar no próprio canvas (sem depender de SVG para tudo)

**Referências de interação:**
- **Google Slides e Google Desenhos:** a principal, pela simplicidade para quem
  vem do editor do WordPress;
- Figma, Illustrator, Inkscape e Canva;
- os apps abertos da **storytold** (github.com/storytold): *VectorCraft* (pen,
  seleção direta, barra de tarefas contextual), *FigCraft* (painéis, atalhos e
  "todo comando é uma ação": UI, CLI e MCP) e *DesignCraft* (diagramação, com
  *"quebras de linha idênticas na tela e no PDF"*).
  - São apps nativos em Rust, MIT/Apache-2.0. Servem de **inspiração de UX**, e
    não de código.

**Ferramentas de desenho** (no Dock: *Inserir* → ferramenta; desenha clicando e
arrastando, como no Google Desenhos):

| Ferramenta | Como desenha | Parâmetros |
|---|---|---|
| **Linha** | arrastar do ponto A ao B. **Shift** trava em 0°/45°/90° | cor, espessura, tracejado (contínuo/tracejado/pontilhado), pontas (reta/redonda/seta), opacidade |
| **Retângulo** | arrastar. **Shift** = quadrado | preenchimento, contorno, **raio por canto** |
| **Elipse** | arrastar. **Shift** = círculo | preenchimento, contorno |
| **Caneta (pen)** | cliques criam pontos retos; **clicar e arrastar** cria uma curva Bézier; fecha clicando no primeiro ponto | contorno, preenchimento (quando fechada) |
| **Polígono/estrela** (depois do MVP) | arrastar + número de lados/pontas | — |
| **Texto** e **Imagem** | como antes (2.6) | — |

- **Edição de pontos** (seleção direta, como no VectorCraft): duplo clique numa
  linha ou caminho mostra os pontos e as alças Bézier para ajustar. **Esc** sai.
- **Barra contextual** junto da seleção (2.6, mini-barra): para uma forma,
  preenchimento, contorno, espessura e tracejado em um toque, como no Google
  Slides.
- **Armazenamento:** linhas, formas e caminhos são **elementos JSON** com os
  pontos em pt, e o caminho com os dados no formato `d` do SVG. Eles geram o SVG
  no editor e são desenhados em vetor no PDF. Uma moldura como a do Campus
  Connect pode ser **desenhada** em vez de enviada como arquivo.
- **Imagens SVG continuam aceitas** para logos e artes prontas, sanitizadas
  (2.6).

**Guias e áreas reservadas** (sempre visíveis no editor, nunca no PDF):
- **Guias inteligentes** durante o arraste, com *snap*:
  - **centro da página** (horizontal e vertical), em destaque;
  - centros e bordas dos outros elementos;
  - **espaçamento igual** entre três ou mais elementos (como no Figma e no Google
    Slides), com a medida mostrada.
- **Margem de segurança** (padrão 10 mm, configurável), uma faixa tracejada nas
  extremidades:
  - textos e o QR **não deveriam** passar dela, porque impressoras e cortes
    comem as bordas;
  - um aviso aparece quando um elemento de conteúdo invade a margem.
- **Sangria** (padrão 3 mm, para gráfica): fundos e decorações podem ir até ela.
  O PDF "para gráfica" (opção) a inclui com marcas de corte. O PDF normal corta
  na página.
- **Áreas reservadas** dos slots obrigatórios (nome e autenticidade) ficam
  destacadas quando uma decoração passa por cima delas.
- **Réguas** em mm ou pt, com **guias manuais** que se arrastam da régua (como no
  Google Slides), mostrar ou ocultar guias (`Ctrl+;`), e uma grade opcional.

**Quebra de linha igual na tela e no PDF** (a lição do DesignCraft): um único
algoritmo de quebra, implementado em JS (editor) e em PHP (PDF), usando as
**métricas dos mesmos arquivos TTF** (`opentype.js` no navegador e a
`php-font-lib` no servidor). Ele calcula as linhas, e os dois lados desenham as
linhas já quebradas. Isso também vale para o "encolher para caber" com nomes
reais. Teste: a mesma lista de textos dá as mesmas quebras nos dois lados.

#### Pranchetas: Documento (PDF), Mobile e Social

O certificado aparece em lugares com formatos muito diferentes. A arte tem
**pranchetas** (artboards), em abas no Dock:

| Prancheta | Tamanho | Onde aparece |
|---|---|---|
| **Documento** | A4/Letter, paisagem ou retrato (842×595 pt) | o **PDF** e a página pública em telas largas |
| **Mobile** | retrato 9:16 (1080×1920 px) | a página pública **no celular**, a imagem para **Stories** e o compartilhamento no celular (Web Share) |
| **Social** | 1,91:1 (1200×630 px) | a prévia do link (`og:image`) e "Baixar imagem" no desktop |

- **Os mesmos elementos, posições diferentes.** Mobile e Social **herdam** o
  conteúdo do Documento: os slots, os textos e as imagens. Cada prancheta guarda
  só os **overrides** de posição, tamanho, fonte e visibilidade. É o mesmo
  mecanismo de herança da 2.5.
  - Mudar um texto no Documento muda em todas.
  - Esconder a moldura no Mobile só afeta o Mobile.
- **Layout automático inicial:** ao criar o design, o Mobile e o Social nascem com
  um arranjo automático, com os slots **empilhados** na ordem de leitura (logo →
  título → nome → texto → data/assinatura → QR). Depois o organizador ajusta à
  mão.
  - O botão "Refazer o layout automático" recoloca tudo.
- **O editor também é responsivo:** no celular, o organizador edita a prancheta
  Mobile com o polegar (Dock embaixo, folhas que sobem). As outras pranchetas
  ficam disponíveis, com zoom.
- **Na página pública (tela cheia):**
  - larguras grandes mostram a prancheta **Documento**;
  - estreitas (≤ 600 px, ou retrato) mostram a **Mobile**;
  - o SVG se ajusta à tela, e o Dock fica por cima;
  - o botão "Baixar PDF" **sempre** entrega o Documento.
- **Autenticidade em todas:** o slot de autenticidade (QR + código) é obrigatório
  em Documento e Mobile. No Social ele é opcional, porque a prévia não é
  escaneável e o link já é a verificação.
- **Sem arquivos guardados:** as imagens das pranchetas Mobile e Social também
  são geradas sob demanda (2.2), com Imagick ou no navegador.

#### Escopo do primeiro lançamento (MVP)

- **Entra:**
  - slots e decoração com imagem, retângulo, linha e texto livre;
  - rotação em passos de 90°, opacidade, raio por canto e sangria;
  - snap no centro e nas bordas da página e dos elementos;
  - Frente/Verso;
  - "Prévia exata (PDF)".
- **Depois:** elipse, rotação livre, grupos genéricos, réguas, grade, "Baixar SVG"
  e fontes enviadas pelo admin.

#### Parâmetros dos elementos (JSON)

- **Unidades:** as internas são **pt**, como no Figma (842×595). O inspector mostra
  mm ou pt.
- **Todo elemento** tem `id, slot|null, x, y, w, h, rotation (0/90/180/270),
  opacity, locked, hidden, roles[]`. O `roles` é a visibilidade "só para os
  papéis…", por exemplo "apresentou a palestra {details}" só para Palestrante.
- **Texto e slots de texto:**
  - rich text com negrito e itálico inline, inclusive nos placeholders inseridos
    por um menu;
  - fonte, tamanho, peso, cor, alinhamento, entrelinha, espaçamento entre letras e
    MAIÚSCULAS;
  - "encolher para caber" (padrão no slot *Nome*).
- **Imagem:** attachment PNG, JPG ou SVG, ajuste (contain/cover) e opacidade.
  - O SVG é sanitizado no upload: o plugin libera o tipo só para este uso e remove
    `<script>`, `on*`, `href` externos e `foreignObject`.
- **Retângulo:** preenchimento, borda e raio por canto.
- **Linha:** cor, espessura e tracejado.
- **Assinaturas:** cada signatário tem imagem da assinatura (opcional), nome e
  cargo, com uma linha acima do nome, como no Figma.
- **Autenticidade:** QR, código e URL, em layout compacto, vertical ou horizontal.
  Fora de site público vira o marcador (2.4).

#### Uma fonte de verdade: parâmetros → SVG → PDF

- O mesmo JSON gera o SVG no editor (React) e o PDF no servidor (desenho em vetor).
- **Quebra de linha:** o navegador e o PDF podem quebrar em lugares diferentes.
  - **Mitigação:** as mesmas fontes TTF nos dois lados e o botão "Prévia exata
    (PDF)", que renderiza no servidor e sobrepõe a imagem.
  - **Teste visual automatizado** (o SVG contra o PDF rasterizado, com uma
    tolerância).
- **Frente/Verso:** o verso só existe nos modos "Verso" e "Os dois" (2.3) e
  começa com o modelo da página de autenticação.

#### Fontes

- **Lora** e **Inter** (OFL), em TTF estáticos 400, 500 e 600 com itálico.
- Mais fontes podem entrar depois.
- As fontes da Font Library do WP entram quando forem TTF/OTF (o PDF não lê WOFF2).

### 2.7 LGPD / GDPR: o que é tratado e por quê

*(Isto é análise de projeto, não parecer jurídico: vale uma revisão de alguém da
área antes do 1.0.)*

**Há dados sensíveis?**
- **Em regra, não.** Nome, e-mail, usuário do WordPress.org, evento, função, datas
  e carga horária são **dados pessoais comuns**, e não os **sensíveis** do art. 5º,
  II da LGPD (origem racial ou étnica, convicção religiosa, opinião política,
  filiação sindical, saúde, vida sexual, genética e biometria).
- **Mas o evento pode revelar um dado sensível de forma indireta:** um meetup
  "WordPress para pessoas LGBTQIA+", um grupo ligado a uma religião, um evento de
  acessibilidade ou saúde. Estar na lista desse evento pode indicar orientação
  sexual, crença ou condição de saúde.
  - Por isso existe a marca **"evento sensível"** (abaixo).
- **O documento (CPF etc.)** é um dado comum, mas de alto risco para fraude. Por
  isso é tratado como senha (2.2.2).

**O risco real da busca que lista por usuário**
- Listar na tela "todos os certificados do usuário X" para **qualquer um que
  digite o usuário** transforma o site num **histórico público de presença**:
  onde a pessoa esteve, quando e em que cidade. Isso permite montar um perfil, e
  em casos extremos rastrear alguém.
- Mesmo que o usuário do WordPress.org seja público, **a decisão de tornar a
  presença pública é da pessoa**, e não do site. Isso fere os princípios de
  **finalidade, necessidade e adequação** (art. 6º): para alguém pegar o próprio
  certificado, não é preciso mostrar a lista a terceiros.

**Recomendação aplicada no plano**

| Situação | Comportamento |
|---|---|
| Pegar o próprio certificado (sites com login do WordPress.org) | **"Meus certificados" logado**: só a própria pessoa vê a lista |
| Pegar o próprio certificado (sites sem login) | busca por **usuário ou e-mail → os links vão para o e-mail cadastrado**. **Nada é listado na tela.** A resposta é sempre igual ("se houver, enviamos"), para não revelar quem participou |
| Conferir um certificado | **só com o código** (`/certificate/CODIGO`). Quem tem o código recebeu da própria pessoa. Não existe busca pública por nome, usuário ou documento |
| Mostrar "meus certificados" publicamente | **opt-in da pessoa**, desligado por padrão: uma página pública com os certificados que ela escolher, como num perfil |
| Link para o perfil do WordPress.org na verificação | **opt-in da pessoa**, desligado por padrão |
| Compartilhar nas redes | decisão da pessoa, um certificado por vez |

**Marca "evento sensível"** (por evento ou grupo; o organizador marca):
- desliga a prévia nas redes (`og:image`/OG), os botões de compartilhar e o "Add
  to LinkedIn" por padrão. A pessoa ainda pode ligá-los para o próprio
  certificado;
- a página de verificação mostra só o necessário (nome, status, código, datas e
  carga horária), com o título do evento opcional, e o organizador pode usar um
  título neutro no certificado;
- não aparece em nenhuma lista pública, nem com o opt-in.

**Bases legais (art. 7º)**
- **Emitir e verificar o certificado:** **legítimo interesse** do organizador e do
  titular. A pessoa participou e espera o certificado. Isso fica documentado no
  texto de privacidade sugerido.
- **Documento, perfil na verificação, lista pública, compartilhar:**
  **consentimento**, dado no autoatendimento, específico e revogável.
- **E-mail:** usado só para entregar os links e confirmar dados (finalidade
  declarada), nunca para marketing.

**Direitos do titular (art. 18), com as ferramentas de privacidade do WordPress**
- **Acesso/portabilidade:** o exportador entrega os certificados da pessoa (dados
  + links) e o documento **mascarado** (o número não existe para entregar).
- **Correção:** o autoatendimento no rascunho; depois, o pedido de correção (2.2).
- **Eliminação:** apagar o certificado **quebraria a verificação** de quem já
  recebeu o documento. Por isso a eliminação **anonimiza**: nome, e-mail,
  usuário, documento e dados livres são apagados.
  - A entrada fica só com o código e o status **"removido a pedido do titular"**.
  - Quem conferir depois vê esse status, e não um "não encontrado", o que evita
    que pareça uma falsificação.
- **Revogar o consentimento** do documento ou do perfil: apaga o hash e a máscara,
  ou desliga o link, na hora.

**Outros pontos**
- **Minimização:** o e-mail nunca aparece em tela pública, OG, imagem ou PDF. A
  página pública mostra só o necessário para autenticar.
- **Retenção:** configurável. Rascunhos não emitidos são apagados N dias depois do
  evento (padrão 180). Os certificados emitidos ficam enquanto o site existir,
  porque o objetivo é poderem ser verificados.
- **Texto de privacidade sugerido** (`wp_add_privacy_policy_content`): quem é o
  controlador (o organizador ou a comunidade), as finalidades, as bases, a
  retenção, os direitos e o contato.
- **Logs e auditoria:** o IP fica truncado e com retenção curta.
- **Operador × controlador:** no wp-brasil.org, o controlador de cada grupo é a
  organização do grupo, e o site atua como operador. O texto de privacidade e os
  termos de acesso dos organizadores precisam dizer isso.

---

## 3. Outras decisões de arquitetura

- **Slug/text domain:** **`events-certificate`** (nome exibido "Events
  Certificate"). Ele foi escolhido sem "wp", porque o Plugin Check avisa sobre
  "wp" no slug e "wordpress" é proibido em qualquer posição (conferido em
  `Trademarks_Check`, PCP 2.1.0). Isso também deixa claro que o plugin **não é só
  para eventos WordPress nem depende do time Meta**: ele serve a qualquer evento,
  e a comunidade WordPress (CampTix, GatherPress, usuário do WordPress.org) é uma
  integração de primeira classe, mas opcional.
- **Prefixo:** `evcert_`, com namespace `EventsCertificate\`. Ninguém no diretório usa
  "evcert" (busca na API do WordPress.org: 0 resultados). O handbook pede ao menos 4–5 letras; o limite prático é o nome de CPT (máx. 20 caracteres): `evcert_design` tem 13.
- **Por que falar com o time Meta** (sem pressa, mas antes do 1.0):
  - **o wordcamp.org e o events.wordpress.org são sites do próprio WordPress.org**,
    mantidos pelo time Meta. Organizadores não instalam plugins neles: o que roda lá
    é o que o Meta coloca;
  - **sem isso, o plugin funciona em qualquer outro site** (o do grupo, o
    wp-brasil.org, o Playground), mas **não dentro** do wordcamp.org nem do
    events.wordpress.org. Isso significa: nem a sincronização direta com o CampTix
    de lá, nem a migração com redirecionamento (2.1.1);
  - **conversar cedo** serve para saber se há interesse, o que eles exigiriam
    (padrões de código, revisão de segurança, sem dependências externas) e se já
    existe algo parecido em andamento. Assim o plugin nasce compatível e não
    precisa ser refeito;
  - o canal é o Slack do Making WordPress (#meta, #meta-wordcamp,
    #community-events) e o make.wordpress.org/meta.
- **Requisitos:** **WP 7.0+** (o `@wordpress/boot` do core para as telas, como o
  Eizport) e PHP 7.4+. Sem `Requires Plugins`: CampTix e GatherPress são
  detectados.
- **PHP:** o QR code pede uma decisão.
  - `chillerlan/php-qrcode` 4.4 (MIT) roda no PHP 7.4+. A v6 pede PHP 8.2+.
  - Uso só a matriz do QR e desenho no PDF.
  - Dependências do Composer prefixadas com Strauss (`EventsCertificate\Vendor\`)
    para não conflitar com outros plugins.
  - **Recomendação:** PHP 7.4+ com a 4.4, que cobre hospedagens antigas.
- **Motor de PDF: dompdf** (decidido no spike da etapa 0, em
  `docs/spikes/pdf-engine/RESULTS.md`), com **chillerlan/php-qrcode 4.4** para o
  QR em SVG. Os dois vão empacotados com as licenças e com os namespaces
  prefixados (Strauss). As fontes próprias do dompdf (7,5 MB) ficam de fora,
  porque o plugin traz as suas.
  - **Resultado:** 5,7% de pixels diferentes do Figma, contra 85,6% do TCPDF, que
    errou os SVGs e está **descontinuado**. O tc-lib-pdf pede PHP 8.2, e o tFPDF
    não desenha SVG.
  - **PDF de cerca de 73 KB**, gerado em 0,2–0,4 s e com 20–24 MB de memória.
    Funciona no **Playground** (1 s a frio).
  - **Um formato, três saídas:** os elementos (JSON versionado) geram **um HTML
    posicionado com SVG embutido**, que serve:
    1. à **página web responsiva** (escalada num contêiner);
    2. ao **PDF leve** (dompdf lê o mesmo HTML);
    3. aos **bitmaps de compartilhamento**, desenhados pelo navegador num
       `<canvas>` a partir do mesmo SVG. No servidor, só quando houver Imagick.
  - **A tratar:** achatar a opacidade aninhada dos SVGs no upload, quebra de linha
    própria (2.6), texto posicionado pela linha de base e "encolher para caber"
    sem quebra.
- **Layout:** são os elementos do editor (2.6), em mm, numa página de tamanho fixo.
  O texto por papel é feito com a visibilidade "só para os papéis…" de cada
  elemento.
- **Placeholders:** `{name} {event} {dates} {hours} {role} {details} {location}
  {code} {verify_url} {issued_date}`, extensíveis por filtro.
- **Identidade padrão: o usuário do WordPress.org.**
  - **Por quê:**
    - é a identidade da comunidade, igual em todo o mundo;
    - o próprio wordcamp.org usa: o CampTix com o addon *require-login* guarda
      `tix_username` em cada participante (conferido no código do CampTix,
      `addons/require-login.php`, que também marca `UNCONFIRMED_USERNAME` para
      quem ainda não confirmou);
    - no events.wordpress.org, o login é a conta do WordPress.org.
  - **Coluna nova:** `wporg_username` no registro. Ela vem de:
    - CampTix: `tix_username`, ignorando o valor "não confirmado";
    - GatherPress: o `user_login` do usuário do RSVP, quando o site usa contas do
      WordPress.org (opção nas configurações; num site comum o login não é o do
      WordPress.org);
    - CSV/colar: colunas "WordPress.org username", "usuário wordpress.org" ou
      "wporg", aceitando também a URL `profiles.wordpress.org/fulano/` e "@fulano";
    - cadastro manual: o mesmo campo.
  - **Na página de verificação:** o link para `profiles.wordpress.org/<usuário>/`
    junto do nome, **só se a pessoa permitir** no autoatendimento ("mostrar meu
    perfil do WordPress.org na verificação"; desligado por padrão, 2.7). Ajuda
    contra homônimos e estrangeiros a conferirem quem é.
  - **E-mail é a alternativa**, para quem veio do Meetup.com sem conta no
    WordPress.org.
- **Busca pública ("Pegue seu certificado"):** um único campo, "Seu usuário do
  WordPress.org ou e-mail".
  - **Usuário:** **nunca lista na tela** (decisão LGPD, 2.7). Ele **envia os
    links para o e-mail cadastrado** naquele certificado e responde sempre "se
    houver certificados para este usuário, enviamos para o e-mail cadastrado".
    Sem e-mail cadastrado, a pessoa fala com o organizador, que tem o CSV de
    links.
    - **Nos sites com login do WordPress.org**, o caminho é "Meus certificados"
      logado (2.3.3), e a busca nem é necessária.
  - **E-mail** (detectado pelo `@`): **envia os links** para o endereço e responde
    "se estiver cadastrado, enviamos". Não lista na tela, porque o e-mail não é
    público e listar revelaria a participação.
  - Há um limite por IP, honeypot e nonce nos dois casos.
- **Shortcode/bloco:** `[evcert_lookup]`, com `event="ID"` ou `group="slug"`
  opcionais.
  - Funciona no contexto `site` sem atributos, no GatherPress com
    `event="123"` e no site comunitário com `group="curitiba"`.
  - Também há um bloco dinâmico equivalente que, dentro de um `gatherpress_event`,
    usa o evento atual sem precisar do ID.

---

### 3.1 Infraestrutura copiada do Eizport

O Eizport (`~/workspace/wp-export-import-z/wp-content/plugins/eizport`) é a
referência de "como fazer certo". **Copiamos a infraestrutura, não as
funcionalidades.**

- **Código:**
  - funções com namespace (`EventsCertificate\Certificates`, `EventsCertificate\Verify`…), um arquivo
    por área, cada um com `bootstrap()` que só registra hooks, e `require_once`
    no arquivo principal;
  - `defined( 'ABSPATH' ) || exit;` no topo de todos os arquivos;
  - `const VERSION` igual ao header e ao `Stable tag` (o CI confere).
- **Admin:** @wordpress/build (`package.json` → `wpPlugin.pages`, `routes/*`),
  admin-ui, DataViews e DataForm.
  - `bin/postbuild.mjs` ajusta o PHP gerado para passar no Plugin Check: guarda
    de ABSPATH, sem `<style>` inline e o asset do boot do site.
  - As páginas só se registram quando `build/` existe.
- **Abilities API** como a única API do admin. Elas aparecem no
  `wp_abilities_api_init`, com o nome `evcert/<verbo-substantivo>`:
  - `get-certificates`, `issue-certificates`, `revoke-certificate`,
    `reissue-certificate`, `update-drafts`;
  - `import-rows`, `preview-import`, `sync-camptix`, `sync-gatherpress`;
  - `queue-emails`, `get-email-queue`, `process-email-queue`;
  - `get-designs`, `save-design`, `get-effective-design`, `render-preview`;
  - `get-groups`, `save-group`, `get-events`, `save-event`, `export-group`,
    `import-group`;
  - `get-settings`, `update-settings`.

  Cada uma tem `input_schema`/`output_schema` estritos
  (`additionalProperties: false`), anotações `readonly`/`destructive`/
  `idempotent` e permissões em funções nomeadas (`can_manage`, `can_manage_group(
  $group )`). **Bônus:** o plugin já nasce operável por agentes e pelo WP-CLI.
  - O cliente usa `src/abilities.ts` com `runAbility()`, que espera o
    `@wordpress/core-abilities` (`initialize()` no core 7.1 e `ready` no
    Gutenberg).
  - **Público fica fora das abilities:** a verificação, o PDF e a imagem são
    rotas de rewrite. A busca "Pegue seu certificado" é uma rota REST pública
    com nonce, honeypot e limite por IP (o modelo de formulário público das
    lições).
- **Settings:** uma opção `evcert_settings` com `defaults()`, `*_choices()`,
  `sanitize()` (lista branca, chaves desconhecidas descartadas), `get()` sempre
  sanitizado e `update()` só das chaves alteradas. Um teste salva e relê cada
  chave.
- **Integrações em `includes/compat/`:** um namespace por plugin, registrado na
  ação `evcert_register_compat` (`plugins_loaded` 20), com `class_exists`, usando
  só os filtros `evcert_*`. Assim, o CampTix ou o GatherPress podem trocar a nossa
  integração pela deles.
- **Formatos versionados:** `schemas/1.0/design.json` e `group-export.json`
  (JSON Schema congelado por versão, copiado no release). Isso serve para
  exportar/importar arte e grupos e para a migração entre sites.
- **Qualidade:**
  - `composer check` = parallel-lint + PHPCS (WordPress + PHPCompatibilityWP,
    `testVersion 7.4-`, `minimum_wp_version 7.0`, prefixos `evcert`/`EventsCertificate`,
    text domain) + PHPUnit + `check-pot`;
  - `tsc --noEmit` e `lint:js`;
  - `bin/plugin-check.sh` lendo o `.distignore`.
- **Testes:**
  - PHPUnit no site real (o `wp-load.php`, sem o test suite do WP), com o
    locale en_US e as settings padrão via `pre_option_*`;
  - nas tabelas próprias, **`START TRANSACTION`/`ROLLBACK`** em cada teste
    (lição do Eizport);
  - uma rodada em multisite (site principal + subsite) no CI.
- **CI (`.github/workflows`):** `tests.yml` (changelog obrigatório, versões
  batendo, PHP, multisite e JS), `release.yml` (tag `X.Y.Z` → zip versionado +
  `events-certificate.zip` + deploy opcional no SVN), `wporg-assets.yml`,
  `wporg-trunk.yml` e **preview no Playground por PR**.
- **Release:**
  - arquivos em `changelog/` por PR (`Significance`/`Type`/frase);
  - `bin/release.php` (WP-CLI): sobe a versão, compila o changelog no readme,
    exige o Upgrade Notice, roda todas as verificações, faz o commit e cria as
    tags `latest`/`stable`;
  - trunk em `X.Y.0-dev`, correções em `release/X.Y`.
- **i18n:**
  - **todo o código e todos os textos em inglês**. O pt-BR é tradução, nunca
    texto fixo no código;
  - durante o desenvolvimento, as traduções pt-BR ficam em
    **`wp-content/languages/plugins/events-certificate-pt_BR.{po,mo,l10n.php}`**
    (+ os `.json` das telas). Desde o WP 6.7, o carregamento just-in-time só lê de
    lá, como no Eizport. Elas são versionadas num repositório de tradução à parte
    (como o `eizport-ptb`) e, depois da aprovação, a fonte passa a ser o
    translate.wordpress.org (language packs);
  - os testes rodam em `en_US` (`switch_to_locale`), para não quebrarem num site de
    desenvolvimento em pt-BR;
  - a tradução respeita a regra de começar com a mesma caixa do original (senão
    o GlotPress marca como fuzzy, lição do Eizport);
  - `bin/make-pot.sh` combina o PHP com o JS do `build/` (o WP-CLI não lê TS);
  - as traduções JS chegam às telas por `wp.i18n.setLocaleData(…,
    "events-certificate")`, porque as telas são script modules;
  - não há `load_plugin_textdomain`.
- **readme.txt:** Description, **External services** (LinkedIn, redes, a
  conferência de URL na migração, Imagick local não conta), Installation, FAQ
  (privacidade, CPF, site não público), Screenshots, **Upgrade Notice** e
  Changelog. O README.md é gerado por Grunt, com o badge do Playground.
- **Playground:**
  - `.wordpress-org/blueprints/blueprint.json` (a prévia do diretório);
  - `releases/latest/download/events-certificate.zip` estável para demos;
  - a prévia por PR.

### 3.2 Fluxo de git e release (o mesmo do Eizport, `.github/CONTRIBUTING.md`)

**Branches**

| Branch | O que entra | Versão no plugin |
|---|---|---|
| `trunk` (padrão do repositório) | toda mudança, por **squash-merge** de PR: um commit por mudança | o próximo `X.Y.0-dev` |
| `feature/<issue>-<nome>`, `fix/<issue>-<nome>` | uma mudança, criada a partir do `trunk` e ligada a uma issue ("Fixes #7") | — |
| `release/X.Y` | só correções, depois do corte | `X.Y.0-rc.N`, `X.Y.0`, `X.Y.1`… |

- O `Stable tag` do readme é sempre a última versão final, inclusive no `trunk`.
- Uma correção entra primeiro no `trunk` e depois no `release/X.Y` com
  `git cherry-pick -x`. Branches de release nunca voltam por merge.

**Antes de cada commit**, a mesma porta do Eizport:

```
composer check && npm run build && npm run readme && bin/plugin-check.sh studio wp
```

O commit só acontece se tudo passar (`if …; then git commit …; fi`, nunca com
`| tail`, que esconde a falha).

- **PCP (Plugin Check)** roda nos arquivos *de release* (exclusões lidas do
  `.distignore`) e precisa terminar em "No errors found". Ele roda no CI de todo
  PR, não só no release.
- Também fazemos o que o Plugin Check não pega e a pré-revisão do diretório
  pega: nenhum `<script>`/`<style>` em PHP (um teste procura essas tags), a seção
  External services completa e `register_setting` com callback nomeado
  literalmente.

**Changelog:** um arquivo em `changelog/<issue>-<nome>` por PR que mexe no que é
distribuído, com `Significance` patch/minor/major, `Type` add/fix/update/dev e uma
frase para o usuário. O CI exige o arquivo. Ninguém edita o Changelog do readme à
mão.

**Release `X.Y.0`:**
1. Cortar `release/X.Y` e mover o `trunk` para `X.(Y+1).0-dev`.
2. Mandar o `release/X.Y` para o **trunk do SVN** (o workflow `wporg-trunk`), para
   as strings serem traduzidas antes. O release estável espera o pt-BR chegar a
   100% no translate.wordpress.org.
3. Opcional: `X.Y.0-rc.N`, só no GitHub.
4. `evcert release X.Y.0` (o `bin/release.php`) a partir do `release/X.Y`: a tag
   dispara o `release.yml` (GitHub Release + deploy no SVN).
5. Cherry-pick do commit de release de volta para o `trunk`, mantendo a versão
   dev.

**Release patch `X.Y.1`:** sai do mesmo `release/X.Y`, só para bugs que quebram
algo para o usuário.

**Ao fim de cada sessão de trabalho:** registrar o que se aprendeu nas skills
`wp-plugin-lessons` / `wp-camaleaun-lessons` (a regra delas).

## 4. Telas de admin (no padrão do Eizport)

As telas são **@wordpress/build pages + routes**, com `@wordpress/admin-ui`
(`Page`), DataViews (listas) e DataForm (formulários). Os dados passam pelas
**abilities** (3.1). Não há `WP_List_Table`, `admin-post.php` nem REST próprio no
admin.

1. **Página `evcert-certificates`** ("Certificados"), com estas rotas:
   - **Onde fica no menu:**
     - **com o CampTix ativo**, vira o submenu **Ingressos → Certificados**
       (`add_submenu_page( 'edit.php?post_type=tix_ticket', … )`, o mesmo pai das
       telas Participantes, Cupons, Ferramentas e Configuração do CampTix,
       conferido no fork do wordcamp.org). Isso fica em `compat/camptix.php`, com
       o filtro `evcert_admin_menu_parent`;
     - **posição: logo depois de "Participantes"**, porque certificados são
       sobre participantes. A ordem fica Ingressos · Adicionar novo ·
       Participantes · **Certificados** · Cupons · Ferramentas · Configuração ·
       Profile Badges. Como os submenus do CampTix vêm de CPTs (`show_in_menu`)
       e de `add_submenu_page`, o índice não é fixo. Por isso a posição é
       calculada em `admin_menu` (prioridade alta): o plugin acha o item
       `edit.php?post_type=tix_attendee` em `$submenu` e se insere logo depois.
       Se não o achar, fica no fim;
     - **com o CampTix, a integração usa os hooks dele** (conferidos no fork do
       wordcamp.org) para cada coisa ficar onde o organizador já procura:

       | Onde, no CampTix | Hook | O que o plugin coloca |
       |---|---|---|
       | **Ingressos → Configuração → aba "Certificados"** | filtro `camptix_setup_sections` (a aba) + ação `camptix_menu_setup_controls` (os campos, na Settings API da página `camptix_options`) | as **configurações** do contexto site: carga horária, exigir presença, idiomas, autenticidade, documento, envio e compartilhamento. Os campos usam o nome `evcert_settings[...]`, e a opção é liberada para o formulário do CampTix pelo filtro `allowed_options` (o `register_setting` do CampTix só cobre a `camptix_options`), com o sanitize do nosso plugin |
       | **Ingressos → Participantes** (lista de `tix_attendee`) | `manage_edit-tix_attendee_columns` + ações em lote | uma coluna **"Certificado"** (rascunho/emitido/revogado, com o link) e a ação em lote **"Emitir certificados"** para os selecionados |
       | **Ingressos → Certificados** (submenu, depois de Participantes) | `add_submenu_page` | a **tela de gestão** (DataViews): rascunhos, emissão em lote, fila de e-mails, importação, correções e o link para o editor de arte. Ela precisa de página própria (@wordpress/build), então não cabe dentro de uma aba de formulário do CampTix |
       | Ingressos → Ferramentas (abas) | `camptix_menu_tools_tabs` + `camptix_menu_tools_{aba}` | **não usado**, para não espalhar: emitir e enviar ficam na tela Certificados |

       - Sem o CampTix, as mesmas configurações viram a aba "Configurações" da
         própria tela Certificados.
     - com o GatherPress, o submenu é o dos eventos dele (a confirmar quando
       chegarmos lá);
     - sem nenhum dos dois, é um menu próprio "Certificados".
   - `/` **Certificados** (DataViews):
     - filtros: grupo, evento, status (rascunho/emitido/revogado), papel,
       presença, "nomes suspeitos" e "sem e-mail";
     - ações por linha: ver, abrir verificação, copiar link, corrigir e reemitir,
       revogar;
     - ações em lote: emitir, marcar presentes/ausentes, corrigir maiúsculas,
       enfileirar e-mail, revogar e excluir rascunhos;
     - inspector lateral com os detalhes e o histórico (substituído
       por/substitui, e-mails).
   - `/add` **Adicionar**: uma pessoa (DataForm) ou várias coladas.
   - `/import` **Importar CSV**: upload → mapeamento de colunas → prévia →
     importar.
   - `/sync` **Sincronizar**: CampTix / GatherPress, só quando existem.
   - `/emails` **Fila de e-mails**: progresso, falhas e "Processar agora".
   - `/groups` e `/events` **Grupos e eventos próprios** (DataViews + DataForm),
     só no modo standalone (2.1.1).
2. **Página `evcert-editor`** no modo **página inteira** do @wordpress/build, sem
   o menu do wp-admin: o editor de arte em tela cheia com Dock (2.6). Ela abre
   com `?design=<id>`.
   - O Eizport removia o modo página inteira e usava só o `-wp-admin`. Aqui o
     modo página inteira é justamente o que queremos. **A confirmar no
     protótipo** (etapa 0b): o @wordpress/build gera esse modo e ele passa no
     Plugin Check depois do `postbuild.mjs`.
3. **Configurações** escondidas (abertas pelo link "Configurações" na linha do
   plugin, `add_plugins_page` + `remove_submenu_page`, como no Eizport), em
   DataForm com cards:
   - *Evento do site* (contexto site): nome, datas, carga horária, local e exigir
     presença;
   - *Autenticidade*: linha em inglês, campos da verificação, "contas do
     WordPress.org", forçar site público e link para o slug;
   - *Envio*: remetente, assunto e texto, lote por minuto e anexar PDF;
   - *Compartilhamento*: botões, texto padrão e ID da organização no LinkedIn;
   - *Privacidade*: documento do participante e modo da busca.
4. **GatherPress:** um painel *Certificados* na barra lateral do
   `gatherpress_event` (um plugin do editor via `registerPlugin`), com o resumo, o
   link para a lista filtrada e "Personalizar a arte deste evento".
5. **O endereço do certificado** (slug, padrão `certificate`) fica nas
   configurações dos certificados, e não em Links permanentes.

**Capabilities:** `evcert_manage` (site), `evcert_manage_group` (escopo por grupo
via `map_meta_cap`) e `evcert_edit_design`. Os admins recebem todas.

---

## 5. Estrutura de arquivos (padrão Eizport: funções com namespace, sem classes obrigatórias)

```
events-certificate/
├── events-certificate.php      # header, namespace EventsCertificate, const VERSION, require_once + bootstrap()
├── uninstall.php                  # loop por site em multisite; não apaga o registro sem confirmação (ver nota)
├── readme.txt                     # + README.md via Grunt; seções do Eizport (External services, Upgrade Notice…)
├── includes/
│   ├── install.php                # dbDelta das tabelas, versão do schema do banco (sem activation hook: checa versão em admin_init)
│   ├── environment.php            # is_public(), is_playground(), uses_wporg_accounts()
│   ├── settings.php               # defaults(), *_choices(), sanitize(), get(), update()
│   ├── certificates.php           # registro: emitir, congelar, revogar, reemitir, buscar
│   ├── code.php                   # código Crockford, impressão digital
│   ├── designs.php                # CPT evcert_design, herança/overrides, snapshots
│   ├── elements.php               # schema dos elementos, placeholders, papéis
│   ├── svg.php                    # JSON → SVG + sanitização de SVG enviado
│   ├── fonts.php
│   ├── pdf.php                    # JSON → PDF (motor do spike) + página de autenticação
│   ├── groups.php                 # CPT evcert_group / evcert_event, capabilities por grupo
│   ├── verify.php                 # rewrite /certificate/, página pública, /pdf/, /image/, robots, OG
│   ├── lookup.php                 # bloco/shortcode "Pegue seu certificado", "Meus certificados"
│   ├── mail.php                   # fila de e-mails (WP-Cron)
│   ├── import.php                 # CSV/colar: parser, encoding, sinônimos, mapeamento
│   ├── migrate.php                # exportar/importar grupo, redirecionamento 301
│   ├── privacy.php                # exporter/eraser do WP (documento, e-mail)
│   ├── abilities.php              # todas as abilities evcert/* (3.1)
│   ├── admin-app.php              # registra as pages do build, traduções JS (setLocaleData)
│   ├── cli.php                    # wp evcert … (só com WP_CLI)
│   └── compat/
│       ├── camptix.php            # via ação evcert_register_compat (plugins_loaded 20)
│       └── gatherpress.php
├── routes/                        # @wordpress/build: certificates, add, import, sync, emails, groups, events, editor
├── src/                           # TS compartilhado: abilities.ts, editor (palco SVG, Dock, histórico), types.ts
├── schemas/1.0/                   # JSON Schema congelado por versão: design.json, group-export.json
├── fonts/                         # Lora + Inter TTF estáticos + OFL
├── lib/                           # motor de PDF (só o necessário) + licenças
├── templates/                     # página de verificação, e-mails
├── languages/events-certificate.pot
├── bin/                           # postbuild.mjs, make-pot.sh, plugin-check.sh, release.php
├── changelog/                     # um arquivo por PR (Significance/Type/frase)
├── tests/php/                     # bootstrap no site real; transações com ROLLBACK para as tabelas
├── .wordpress-org/                # banners, ícones, screenshots (+ -pt_BR), blueprints/blueprint.json
├── .github/workflows/             # tests, release, wporg-assets, wporg-trunk, pr-preview-*
├── .wp-env.json, phpcs.xml.dist, phpunit.xml.dist, tsconfig.json, .distignore, .gitattributes
└── build/                         # gerado, fora do git, dentro do zip
```

- **Nota sobre o uninstall:** apagar o plugin não pode quebrar os links
  impressos sem aviso. O `uninstall.php` só remove as tabelas quando a opção
  "Apagar todos os dados ao desinstalar" estiver marcada. Ela vem desmarcada e
  traz um aviso explicando que os certificados deixam de ser verificáveis.

---

## 6. Etapas (cada uma termina testada)

0. **Spike do motor de PDF (TCPDF × tFPDF):**
   - a mesma arte de teste nas duas, gerada a partir de um JSON de elementos;
   - medir fidelidade do SVG, tamanho, tempo, memória e o funcionamento no
     Playground CLI;
   - **decisão registrada no plano**.
0b. **Protótipo do editor (antes de construir):**
   - um protótipo navegável da tela cheia com Dock, os modos Conteúdo/Arte e o
     modelo Base, no modo página inteira do @wordpress/build;
   - **o dono do projeto valida** se é "como imaginou" antes da etapa 9.
1. **Base:**
   - header, instalador, repositório, código Crockford, `Environment` e
     uninstall;
   - `composer check` (lint, WPCS, PHPUnit).
2. **Renderer (JSON → SVG e JSON → PDF) + QR:**
   - todos os tipos de elemento, placeholders, "encolher para caber" e visibilidade
     por papel;
   - elemento de autenticidade, linha mínima e página de autenticação bilíngue;
   - teste: acentos, conferência com `pdftotext`, QR lido por `zbarimg` com a URL
     correta, fundos PNG/JPG/WebP.
3. **Verificação:**
   - slug padrão e personalizado, fallback `?evcert_certificate=`, os estados
     válido/revogado/não encontrado, `?lang=en`, limite por IP e noindex;
   - curl com links permanentes ligados e desligados, e com troca de slug.
4. **Tela de certificados:** emitir, revogar/corrigir, lote, adicionar e colar,
   exportar.
5. **Importador CSV:** sinônimos em pt/en, mapeamento manual e fixtures (sample.csv,
   CampTix en e pt-BR, Meetup, Excel `;` Windows-1252).
6. **CampTix:** contexto site num multisite Studio com dois subsites, cada um uma
   WordCamp com attendees semeados.
7. **GatherPress:** ID do evento, RSVPs (attending, not_attending, waiting e
   check-in), painel no editor e bloco de busca dentro do evento.
8. **Playground:** blueprint de demonstração; confirmar que não aparecem nem o QR nem
   o código e que `/certificate` dá 404 no CLI e no playground.wordpress.net.
8.5. **Compartilhamento:**
   - LinkedIn (adicionar ao perfil e post), links das redes, Web Share API com
     arquivo, "Baixar imagem" e `og:image` sob demanda (Imagick ou uma imagem existente);
   - teste: o validador de prévia do LinkedIn (Post Inspector) numa URL pública
     temporária, ou checagem das tags com curl;
   - Web Share testado no celular com a emulação do DevTools.
9. **Editor de arte (tela cheia com Dock):**
   - CPT `evcert_design` + tela cheia com Dock (salva via REST, com revisões);
   - seleção, arraste, redimensionar, girar, guias com snap, zoom e réguas;
   - inspector por elemento, camadas, inseridor, modelos iniciais e prévia exata;
   - overrides por evento GatherPress;
   - teste visual: o SVG do editor contra o PDF rasterizado (Playwright +
     comparação de pixels com tolerância).
9.5. **Grupos e permissões (site comunitário):**
   - taxonomia de grupos, papel "Organizador de certificados", `map_meta_cap` por
     grupo, mídia filtrada e herança de design em três níveis;
   - exportar/importar grupo com códigos preservados;
   - teste com dois organizadores de cidades diferentes: um não vê nem edita nada
     do outro, inclusive pela REST API.
9.6. **Playground como ferramenta:** ZIP em lotes, exportar/importar design e o
   blueprint "Gerar certificados do meetup".
10. **Acabamento:**
   - i18n com pt_BR e en (verificação);
   - readme e FAQ;
   - Plugin Check e e2e pelo navegador.

---

## 7. Decisões tomadas e perguntas em aberto

**Decididas**
- **LGPD (2.7):** nenhuma lista pública por usuário, e-mail, nome ou documento.
  Conferir só com o código. Pegar o próprio certificado só logado ("Meus
  certificados") ou com os links enviados para o e-mail cadastrado. O perfil do
  WordPress.org na verificação e a lista pública dos meus certificados são opt-in
  da pessoa. Há a marca "evento sensível". A eliminação anonimiza e mantém o
  código com o status "removido a pedido do titular".
- Página pública em **tela cheia** com Dock. O editor define o **mobile** pela
  prancheta Mobile (e também a Social), herdando do Documento (2.6).
- Repositório: **`camaleaun/events-certificate`** no GitHub, com o default
  branch `trunk`. Ele é criado quando a implementação começar, porque por enquanto
  só há o plano.
- **Documento padrão = "Outro", aberto.** O emissor personaliza por evento ou
  grupo (nome, formato, validador da lista, máscara ≤ 60%), e o participante
  completa o que ficou em aberto (nome do documento e número). O admin cadastra
  modelos prontos como atalho (num site pt-BR: CPF, RG, Passaporte) (2.2.2).
- Quem administra o wp-brasil.org **não bloqueia nada agora**: fica para quando
  formos implantar lá.
- Público: verificação, arte e compartilhar. Baixar o PDF / segunda via exige
  login do dono nos sites com contas do WordPress.org (wordcamp.org,
  events.wordpress.org); nos outros, exige o link com o código (2.3.3).
- PHP 7.4+.
- Sem marca d'água. Fora de site público, o PDF mostra o marcador de QR e textos
  explicando a limitação (2.4).
- Tudo genérico e personalizável, sem logos de marcas.
- Um único modelo "Base", sem galeria.
- Editor em tela cheia, responsivo, com Dock flutuante (2.6). A página pública do
  certificado usa a mesma linguagem.
- Rascunho editável antes da emissão; depois, "corrigir e reemitir" (2.2).
- Prefixo `evcert_` / namespace `EventsCertificate\`.
- Identidade padrão: o **usuário do WordPress.org**. O e-mail é a alternativa
  (Meetup.com).
- Grupos e eventos próprios (`evcert_group`, `evcert_event`) quando não há
  GatherPress nem CampTix. É o caso do wp-brasil.org.
- Migração de grupo com **redirecionamento 301** dos códigos para o novo site,
  mantendo a cópia local como reserva (2.1.1).

- O plugin deve servir também a um **site comunitário com vários grupos**
  (wp-brasil.org), com organizadores por cidade, até a migração para o
  events.wordpress.org (2.1.1). No Playground, ele serve de ferramenta para meetups
  sem site.

**Em aberto**
- Nenhuma no momento. Uma revisão jurídica da seção 2.7 está prevista antes do 1.0.
