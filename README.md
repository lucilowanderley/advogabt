# ADVOGABT

Sistema de gestão para escritórios de advocacia, feito em **PHP 8.3+ / Laravel 13 / MySQL**.

## Módulos

| Módulo | O que faz |
| --- | --- |
| **Meu painel** | Prazos processuais dos próximos dias, tarefas em aberto, agenda do dia, resumo financeiro do mês, fluxo de caixa de 6 meses e ranking de pontos da equipe. |
| **Tarefas** | Quadro (kanban) com as colunas *Todas as tarefas, Hoje, Próximos dias, Fazendo e Concluídas hoje*, com arrastar e soltar. Visão em lista, responsáveis, prioridade, prazos processuais, comentários e pontuação por tarefa concluída. |
| **Agenda** | Calendário mensal com audiências, reuniões, perícias e prazos, além das tarefas com vencimento. Filtro por responsável e tipo. |
| **Contatos e CRM** | Cadastro de clientes (PF/PJ), partes contrárias e testemunhas, com busca de endereço pelo CEP e link direto para WhatsApp. Funil de clientes (lead → em contato → proposta → cliente / perdido) com arrastar e soltar. |
| **Processos** | Número CNJ, partes, área, fase, tribunal/vara/comarca, valor da causa, honorários, responsável e parceiro. Linha do tempo de andamentos, tarefas, audiências e financeiro do processo. |
| **Parceiros** | Correspondentes, indicadores e peritos, com percentual de comissão, processos vinculados e repasses pagos/em aberto. |
| **Financeiro** | Contas a receber e a pagar por mês, parcelamento automático, baixa/estorno com um clique, inadimplência e receitas por categoria. |
| **Modelos** | Procurações, contratos, declarações e petições com variáveis (`{{cliente.nome}}`, `{{processo.numero}}`, `{{advogado.oab}}`...) preenchidas automaticamente, prontas para imprimir ou salvar em PDF. |
| **Assistente IA** | Conversas com a IA contratada pelo escritório, com os dados do processo ou do contato no contexto (andamentos, prazos, compromissos, qualificação). Atalhos para resumo, próximos passos, riscos, mensagem ao cliente, minutas e prazos. Respostas podem ser copiadas ou salvas como modelo. Funciona com Claude (Anthropic, recomendado), OpenAI, Azure OpenAI, Gemini, Mistral, DeepSeek, Groq, OpenRouter, Ollama (local) e qualquer serviço compatível com a OpenAI; cada provedor mostra se é grátis ou comercial. Chaves criptografadas, provedor padrão, teste de conexão e consumo de tokens no mês (Assistente IA › Provedores, só administradores). |
| **WhatsApp** | Robô de autoatendimento ligado ao WhatsApp do escritório pela Evolution API (gratuita, QR Code) ou pela API oficial da Meta. Reconhece o cliente pelo número cadastrado e informa o andamento dos processos com o Assistente IA, em linguagem simples. Também registra pedidos de agendamento, recebe documentos e passa a conversa para a equipe, criando tarefas. A caixa de entrada permite à equipe assumir e responder. Opcional: avisar o cliente de cada novo andamento. Números desconhecidos nunca recebem dados de processos. |
| **Integrações** | API REST v1 com tokens (somente leitura ou leitura e escrita) e webhooks assinados (HMAC SHA-256) para n8n, Make, Zapier e sistemas próprios. Eventos: novo processo, processo alterado, novo andamento, prazo vencendo, tarefa criada/concluída, compromisso, contato, etapa do CRM, lançamento e pagamento recebido. Histórico de envios com reenvio e repetição automática. Documentação em Integrações › Documentação da API. Tribunais (Datajud/DJEN), assinatura digital, cobrança/NFS-e e agenda/e-mail aparecem como "breve". |
| **Arquivos na nuvem** | Pasta de cada cliente e de cada processo no Google Drive ou no OneDrive do escritório (app OAuth próprio do escritório, configurado em Integrações › Arquivos na nuvem). As pastas são criadas automaticamente (com subpastas padrão por processo) ou vinculadas a pastas já existentes. Na ficha do processo e do cliente a equipe vê, envia e manda para a lixeira os arquivos. Documentos recebidos pelo WhatsApp são copiados para a pasta do cliente. |
| **Usuários** | Perfis (administrador, advogado, estagiário, financeiro, secretaria), ativação e cor do avatar. |

Interface em português, tema escuro (padrão) e claro, responsiva para celular. Não precisa de Node/NPM: o CSS e o JavaScript ficam prontos em `public/`.

Próximas integrações previstas: tribunais (Datajud/DJEN), assinatura digital, cobrança, agenda e arquivos.

### WhatsApp

Cadastre o número em **WhatsApp › Canais e robô** e cole a URL de recebimento exibida ali no painel da Evolution API (evento `MESSAGES_UPSERT`) ou no webhook do app da Meta. Essa URL precisa ser acessível pela internet: num Laragon local, use um túnel (Cloudflare Tunnel, ngrok) ou publique o sistema.

### Arquivos na nuvem

Em **Integrações › Arquivos na nuvem**, escolha Google Drive ou OneDrive. Na **conexão rápida (rclone)**, recomendada, não é preciso criar app: clique em "Instalar rclone", rode no PowerShell o comando `rclone authorize` mostrado na tela, entre na conta e cole o código gerado (o rclone fica instalado porque é ele que renova o acesso). Na opção **app próprio**, crie o app no Google Cloud Console ou no Microsoft Entra seguindo o passo a passo da tela, cole o ID e a chave secreta e clique em **Conectar**. No app próprio, Google e Microsoft só aceitam endereço `http` em `localhost`, por isso no Laragon a URL de retorno sugerida é `http://localhost/<pasta-do-projeto>/public/integracoes/arquivos/retorno`. Para criar as pastas de clientes e processos que já existiam, use "Criar pastas que faltam" ou `php artisan arquivos:pastas`.

### Agendador (prazos vencendo e reenvio de webhooks)

A rotina das integrações roda a cada 15 minutos enquanto o sistema está em uso. Para que rode também com o sistema fechado, agende no Windows (Agendador de Tarefas, a cada minuto) `php artisan schedule:run`, ou deixe `php artisan schedule:work` aberto. Manualmente: `php artisan integracoes:rotina`.

## Instalação (Laragon / Windows)

1. Coloque o projeto em `C:\laragon\www\advogabt` e confira se o PHP do Laragon é 8.3 ou superior (Menu → PHP → Version).
2. Crie o banco `advogabt` no MySQL (Laragon → Database, ou HeidiSQL).
3. No terminal do Laragon, dentro da pasta do projeto:

```bash
composer install
copy .env.example .env      # no Linux/macOS: cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

4. Acesse `http://advogabt.test` (o Laragon cria o domínio automaticamente) ou rode `php artisan serve` e abra `http://127.0.0.1:8000`.

O `.env.example` já vem configurado para MySQL local (`root` sem senha, banco `advogabt`) e fuso `America/Sao_Paulo`.

### Acesso inicial

| E-mail | Senha |
| --- | --- |
| `admin@advogabt.com.br` | `advogabt` |

Troque a senha em **Meu perfil** logo no primeiro acesso. Para criar o administrador com outros dados, defina `ADMIN_NAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD` no `.env` antes do `migrate --seed`.

Fora de produção (`APP_ENV` diferente de `production`), o seed também cria **dados de demonstração** (equipe, clientes, processos, tarefas, agenda e financeiro). Para começar com o banco limpo em produção, use `APP_ENV=production`; para apagar tudo e recriar: `php artisan migrate:fresh --seed`.

## Testes

```bash
php artisan test
```

## Estrutura

- `app/Http/Controllers` — um controller por módulo
- `app/Models` — `Contact`, `LegalCase` (processo), `CaseMovement` (andamento), `Task`, `Event`, `FinancialEntry`, `Partner`, `DocumentTemplate`
- `app/Support/Options.php` — listas de opções (áreas, fases, categorias...) com rótulos em português
- `database/migrations` e `database/seeders`
- `resources/views` — telas em Blade
- `public/css/app.css` e `public/js/app.js` — estilo e interações (sem build)
