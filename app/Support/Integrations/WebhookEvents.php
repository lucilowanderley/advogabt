<?php

namespace App\Support\Integrations;

/** Catálogo dos eventos que podem ser enviados por webhook. */
class WebhookEvents
{
    public const ALL = [
        'processo.criado' => ['label' => 'Novo processo', 'group' => 'Processos', 'description' => 'Um processo foi cadastrado.'],
        'processo.atualizado' => ['label' => 'Processo alterado', 'group' => 'Processos', 'description' => 'Dados do processo mudaram (fase, situação, responsável…). Traz a lista de campos alterados.'],
        'andamento.criado' => ['label' => 'Novo andamento', 'group' => 'Processos', 'description' => 'Um andamento foi registrado em um processo. Bom para avisar o cliente.'],
        'prazo.vencendo' => ['label' => 'Prazo vencendo', 'group' => 'Prazos e tarefas', 'description' => 'Um prazo processual em aberto vence nos próximos dias (enviado uma vez por prazo).'],
        'tarefa.criada' => ['label' => 'Nova tarefa', 'group' => 'Prazos e tarefas', 'description' => 'Uma tarefa ou prazo foi criado.'],
        'tarefa.concluida' => ['label' => 'Tarefa concluída', 'group' => 'Prazos e tarefas', 'description' => 'Uma tarefa foi marcada como concluída.'],
        'compromisso.criado' => ['label' => 'Novo compromisso', 'group' => 'Agenda', 'description' => 'Audiência, reunião, perícia ou outro compromisso agendado.'],
        'contato.criado' => ['label' => 'Novo contato', 'group' => 'Contatos e CRM', 'description' => 'Um cliente, lead ou outro contato foi cadastrado.'],
        'contato.etapa_alterada' => ['label' => 'Etapa do CRM alterada', 'group' => 'Contatos e CRM', 'description' => 'O contato mudou de etapa no funil (ex.: proposta → cliente).'],
        'lancamento.criado' => ['label' => 'Novo lançamento financeiro', 'group' => 'Financeiro', 'description' => 'Uma receita ou despesa foi lançada.'],
        'pagamento.recebido' => ['label' => 'Pagamento recebido', 'group' => 'Financeiro', 'description' => 'Uma receita foi baixada como paga.'],
        'assinatura.concluida' => ['label' => 'Documento assinado', 'group' => 'Assinatura digital', 'description' => 'Todos os signatários assinaram um contrato, procuração ou outro documento enviado para assinatura.'],
        'assinatura.recusada' => ['label' => 'Assinatura recusada', 'group' => 'Assinatura digital', 'description' => 'Um signatário recusou o documento na plataforma de assinatura.'],
        'whatsapp.mensagem_recebida' => ['label' => 'Mensagem no WhatsApp', 'group' => 'WhatsApp', 'description' => 'Um cliente ou interessado mandou mensagem para o WhatsApp do escritório.'],
        'whatsapp.atendimento_humano' => ['label' => 'Pedido de atendente', 'group' => 'WhatsApp', 'description' => 'Alguém pediu para falar com a equipe no WhatsApp.'],
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $event): string
    {
        return self::ALL[$event]['label'] ?? $event;
    }

    /** @return array<string, array<string, array>> eventos agrupados para o formulário */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::ALL as $key => $e) {
            $groups[$e['group']][$key] = $e;
        }

        return $groups;
    }
}
