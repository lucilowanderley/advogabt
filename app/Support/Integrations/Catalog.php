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
            'status' => 'proxima',
            'description' => 'Bot que responde ao cliente sobre o andamento do processo, agenda atendimentos e recebe documentos, usando o Assistente IA.',
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
            'status' => 'breve',
            'description' => 'Envio de contratos e procurações gerados pelos Modelos para assinatura eletrônica.',
            'tags' => ['ClickSign', 'D4Sign', 'ICP-Brasil'],
        ],
        'financeiro' => [
            'title' => 'Cobrança e notas fiscais',
            'icon' => 'dollar',
            'group' => 'Financeiro',
            'status' => 'breve',
            'description' => 'Boleto e Pix para os honorários, baixa automática do pagamento e emissão de NFS-e.',
            'tags' => ['Asaas', 'Banco Inter', 'NFS-e'],
        ],
        'agenda' => [
            'title' => 'Agenda e e-mail',
            'icon' => 'calendar',
            'group' => 'Produtividade',
            'status' => 'breve',
            'description' => 'Sincroniza audiências e prazos com a agenda da equipe e envia e-mails automáticos.',
            'tags' => ['Google Agenda', 'Outlook', 'Gmail'],
        ],
        'arquivos' => [
            'title' => 'Arquivos na nuvem',
            'icon' => 'cloud',
            'group' => 'Documentos',
            'status' => 'breve',
            'description' => 'Pasta de cada processo e cliente vinculada ao armazenamento do escritório.',
            'tags' => ['Google Drive', 'OneDrive'],
        ],
    ];
}
