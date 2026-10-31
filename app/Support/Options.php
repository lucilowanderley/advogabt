<?php

namespace App\Support;

/**
 * Listas de opções (rótulos em pt-BR) usadas nos formulários, filtros e validações.
 */
class Options
{
    public const USER_ROLES = [
        'admin' => 'Administrador',
        'advogado' => 'Advogado(a)',
        'estagiario' => 'Estagiário(a)',
        'financeiro' => 'Financeiro',
        'secretaria' => 'Secretaria',
    ];

    public const CONTACT_CATEGORIES = [
        'cliente' => 'Cliente',
        'parte_contraria' => 'Parte contrária',
        'testemunha' => 'Testemunha',
        'fornecedor' => 'Fornecedor',
        'outro' => 'Outro',
    ];

    public const CRM_STAGES = [
        'lead' => 'Lead',
        'contato' => 'Em contato',
        'proposta' => 'Proposta enviada',
        'cliente' => 'Cliente ativo',
        'perdido' => 'Perdido',
    ];

    public const CONTACT_ORIGINS = [
        'indicacao' => 'Indicação',
        'site' => 'Site',
        'redes_sociais' => 'Redes sociais',
        'google' => 'Google',
        'parceiro' => 'Parceiro',
        'outro' => 'Outro',
    ];

    public const MARITAL_STATUS = [
        'solteiro(a)' => 'Solteiro(a)',
        'casado(a)' => 'Casado(a)',
        'divorciado(a)' => 'Divorciado(a)',
        'viúvo(a)' => 'Viúvo(a)',
        'união estável' => 'União estável',
    ];

    public const PARTNER_TYPES = [
        'correspondente' => 'Advogado correspondente',
        'indicador' => 'Indicador',
        'perito' => 'Perito / Assistente técnico',
        'escritorio' => 'Escritório parceiro',
        'outro' => 'Outro',
    ];

    public const CASE_AREAS = [
        'civel' => 'Cível',
        'trabalhista' => 'Trabalhista',
        'previdenciario' => 'Previdenciário',
        'familia' => 'Família e Sucessões',
        'consumidor' => 'Consumidor',
        'tributario' => 'Tributário',
        'criminal' => 'Criminal',
        'empresarial' => 'Empresarial',
        'administrativo' => 'Administrativo',
        'outro' => 'Outro',
    ];

    public const CASE_PHASES = [
        'inicial' => 'Pré-processual / Inicial',
        'conhecimento' => 'Conhecimento',
        'recursal' => 'Recursal',
        'execucao' => 'Execução / Cumprimento',
        'encerrado' => 'Encerrado',
    ];

    public const CASE_STATUS = [
        'ativo' => 'Ativo',
        'suspenso' => 'Suspenso',
        'arquivado' => 'Arquivado',
        'encerrado' => 'Encerrado',
    ];

    public const CLIENT_ROLES = [
        'autor' => 'Autor / Requerente',
        'reu' => 'Réu / Requerido',
        'terceiro' => 'Terceiro interessado',
    ];

    public const TASK_STATUS = [
        'pendente' => 'Pendente',
        'fazendo' => 'Fazendo',
        'concluida' => 'Concluída',
    ];

    public const TASK_PRIORITIES = [
        'baixa' => 'Baixa',
        'normal' => 'Normal',
        'alta' => 'Alta',
        'urgente' => 'Urgente',
    ];

    public const EVENT_TYPES = [
        'audiencia' => 'Audiência',
        'reuniao' => 'Reunião',
        'prazo' => 'Prazo',
        'pericia' => 'Perícia',
        'despacho' => 'Despacho',
        'outro' => 'Outro',
    ];

    public const FINANCE_TYPES = [
        'receita' => 'Receita',
        'despesa' => 'Despesa',
    ];

    public const FINANCE_CATEGORIES = [
        'honorarios_contratuais' => 'Honorários contratuais',
        'honorarios_exito' => 'Honorários de êxito',
        'honorarios_sucumbencia' => 'Honorários de sucumbência',
        'consultoria' => 'Consultoria',
        'reembolso' => 'Reembolso de despesas',
        'custas' => 'Custas processuais',
        'repasse_parceiro' => 'Repasse a parceiro',
        'aluguel' => 'Aluguel',
        'salarios' => 'Salários / Pró-labore',
        'impostos' => 'Impostos',
        'software' => 'Software / Assinaturas',
        'marketing' => 'Marketing',
        'outros' => 'Outros',
    ];

    public const PAYMENT_METHODS = [
        'pix' => 'PIX',
        'boleto' => 'Boleto',
        'transferencia' => 'Transferência',
        'cartao' => 'Cartão',
        'dinheiro' => 'Dinheiro',
        'alvara' => 'Alvará judicial',
    ];

    public const TEMPLATE_CATEGORIES = [
        'procuracao' => 'Procuração',
        'contrato' => 'Contrato de honorários',
        'peticao' => 'Petição',
        'declaracao' => 'Declaração',
        'notificacao' => 'Notificação',
        'outro' => 'Outro',
    ];

    public const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public static function label(array $options, ?string $key): string
    {
        return $key === null ? '—' : ($options[$key] ?? $key);
    }

    /** Lista de chaves para regra "in:" da validação. */
    public static function keys(array $options): string
    {
        return implode(',', array_keys($options));
    }
}
