<?php

namespace App\Support\Integrations;

/** Integrações do ADVOGABT: as já disponíveis e as previstas (cards "breve"). */
class Catalog
{
    public const STATUS = [
        'disponivel' => ['label' => 'Disponível', 'badge' => 'green'],
        'proxima' => ['label' => 'Próxima etapa', 'badge' => 'gold'],
        'breve' => ['label' => 'Breve', 'badge' => ''],
    ];

    public const ITEMS = [
        'api' => [
            'title' => 'API REST',
            'icon' => 'key',
            'group' => 'Automação',
            'status' => 'disponivel',
            'route' => 'integrations.tokens.index',
            'description' => 'Tokens de acesso para outros sistemas consultarem e cadastrarem processos, contatos, tarefas, agenda e financeiro.',
            'tags' => ['n8n', 'Make', 'Zapier', 'Power Automate'],
        ],
        'webhooks' => [
            'title' => 'Webhooks',
            'icon' => 'webhook',
            'group' => 'Automação',
            'status' => 'disponivel',
            'route' => 'integrations.webhooks.index',
            'description' => 'Avisa automaticamente uma URL quando surge um processo, um prazo está vencendo, um pagamento é recebido e mais.',
            'tags' => ['n8n', 'Make', 'Zapier'],
        ],
        'whatsapp' => [
            'title' => 'WhatsApp e autoatendimento',
            'icon' => 'whatsapp',
            'group' => 'Atendimento',
            'status' => 'disponivel',
            'route' => 'whatsapp.index',
            'everyone' => true,
            'description' => 'Robô que responde ao cliente sobre o andamento do processo, registra pedidos de agendamento e recebe documentos, usando o Assistente IA. A equipe assume a conversa quando precisar.',
            'tags' => ['API oficial (Meta)', 'Evolution API'],
        ],
        'tribunais' => [
            'title' => 'Tribunais',
            'icon' => 'gavel',
            'group' => 'Processos',
            'status' => 'breve',
            'description' => 'Consulta automática de andamentos e captura de intimações e publicações, alimentando os prazos.',
            'tags' => ['Datajud (CNJ)', 'DJEN'],
        ],
        'assinatura' => [
            'title' => 'Assinatura digital',
            'icon' => 'pen',
            'group' => 'Documentos',
            'status' => 'disponivel',
            'route' => 'signatures.index',
            'admin_route' => 'signatures.settings',
            'everyone' => true,
            'description' => 'Envio de contratos e procurações gerados pelos Modelos para assinatura eletrônica, com acompanhamento de quem já assinou e o PDF assinado guardado na ficha (e na pasta da nuvem).',
            'tags' => ['ClickSign', 'D4Sign', 'ICP-Brasil'],
        ],
        'financeiro' => [
            'title' => 'Cobrança e notas fiscais',
            'icon' => 'dollar',
            'group' => 'Financeiro',
            'status' => 'disponivel',
            'route' => 'billing.index',
            'admin_route' => 'billing.settings',
            'everyone' => true,
            'description' => 'Boleto e Pix para os honorários direto do Financeiro, baixa automática quando o cliente paga e emissão da NFS-e, com o link de pagamento pronto para mandar pelo WhatsApp.',
            'tags' => ['Asaas', 'Banco Inter', 'NFS-e'],
        ],
        'agenda' => [
            'title' => 'Agenda e e-mail',
            'icon' => 'calendar',
            'group' => 'Produtividade',
            'status' => 'disponivel',
            'route' => 'agenda-email.index',
            'everyone' => true,
            'description' => 'Audiências e prazos no Google Agenda, Outlook ou iPhone de cada advogado, por link de assinatura e por convite. E-mails automáticos de lembrete e um resumo diário dos prazos.',
            'tags' => ['Google Agenda', 'Outlook', 'Gmail', 'SMTP'],
        ],
        'arquivos' => [
            'title' => 'Arquivos na nuvem',
            'icon' => 'cloud',
            'group' => 'Documentos',
            'status' => 'disponivel',
            'route' => 'cloud.settings',
            'description' => 'Pasta de cada processo e cliente no Google Drive ou OneDrive do escritório, criada automaticamente, com envio e consulta dos arquivos pela ficha. Documentos recebidos pelo WhatsApp vão para a pasta do cliente.',
            'tags' => ['Google Drive', 'OneDrive'],
        ],
    ];
}
