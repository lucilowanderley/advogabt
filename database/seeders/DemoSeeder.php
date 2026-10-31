<?php

namespace Database\Seeders;

use App\Models\CaseMovement;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\LegalCase;
use App\Models\Partner;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Dados fictícios para conhecer o sistema. Não é executado em produção. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Contact::exists()) {
            return; // já possui dados
        }

        $admin = User::where('role', 'admin')->first();
        $ana = User::firstOrCreate(['email' => 'ana@advogabt.com.br'], ['name' => 'Ana Ribeiro', 'password' => 'advogabt', 'role' => 'advogado', 'oab' => 'SP 123.456', 'color' => '#4c8dff']);
        $bruno = User::firstOrCreate(['email' => 'bruno@advogabt.com.br'], ['name' => 'Bruno Carvalho', 'password' => 'advogabt', 'role' => 'advogado', 'oab' => 'SP 234.567', 'color' => '#34c38f']);
        $carla = User::firstOrCreate(['email' => 'carla@advogabt.com.br'], ['name' => 'Carla Mendes', 'password' => 'advogabt', 'role' => 'estagiario', 'color' => '#9b7bff']);
        $team = [$admin, $ana, $bruno, $carla];

        $p1 = Partner::create(['name' => 'Dr. Marcos Lima', 'type' => 'correspondente', 'oab' => 'RJ 98.765', 'phone' => '(21) 99876-5432', 'email' => 'marcos@exemplo.com', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'commission_percent' => 30, 'active' => true]);
        $p2 = Partner::create(['name' => 'Imobiliária Horizonte', 'type' => 'indicador', 'phone' => '(11) 3333-4444', 'city' => 'São Paulo', 'state' => 'SP', 'commission_percent' => 10, 'active' => true]);
        Partner::create(['name' => 'Eng. Paula Souza', 'type' => 'perito', 'phone' => '(11) 98888-1111', 'city' => 'Campinas', 'state' => 'SP', 'commission_percent' => 0, 'active' => true]);

        $contacts = collect([
            ['name' => 'Maria Aparecida Santos', 'document' => '123.456.789-09', 'phone' => '(11) 98765-4321', 'whatsapp' => '(11) 98765-4321', 'email' => 'maria.santos@exemplo.com', 'profession' => 'Professora', 'marital_status' => 'casado(a)', 'city' => 'São Paulo', 'state' => 'SP', 'address' => 'Rua das Flores, 120, Centro', 'zip_code' => '01001-000', 'origin' => 'indicacao'],
            ['name' => 'José Roberto Almeida', 'document' => '987.654.321-00', 'phone' => '(11) 97777-1234', 'email' => 'jose.almeida@exemplo.com', 'profession' => 'Motorista', 'marital_status' => 'solteiro(a)', 'city' => 'Guarulhos', 'state' => 'SP', 'origin' => 'google'],
            ['name' => 'Construtora Alfa Ltda.', 'person_type' => 'PJ', 'document' => '12.345.678/0001-90', 'phone' => '(11) 3222-1000', 'email' => 'juridico@alfa.com.br', 'city' => 'São Paulo', 'state' => 'SP', 'origin' => 'parceiro'],
            ['name' => 'Fernanda Oliveira', 'document' => '321.654.987-11', 'phone' => '(11) 96666-2222', 'whatsapp' => '(11) 96666-2222', 'profession' => 'Enfermeira', 'city' => 'Osasco', 'state' => 'SP', 'origin' => 'redes_sociais'],
            ['name' => 'Ricardo Gomes', 'document' => '456.789.123-22', 'phone' => '(11) 95555-3333', 'profession' => 'Comerciante', 'city' => 'São Paulo', 'state' => 'SP', 'origin' => 'indicacao'],
            ['name' => 'Lucas Pereira', 'phone' => '(11) 94444-5555', 'whatsapp' => '(11) 94444-5555', 'crm_stage' => 'lead', 'origin' => 'site'],
            ['name' => 'Patrícia Nunes', 'phone' => '(11) 93333-6666', 'crm_stage' => 'contato', 'origin' => 'google'],
            ['name' => 'Mercado Bom Preço ME', 'person_type' => 'PJ', 'phone' => '(11) 3111-7777', 'crm_stage' => 'proposta', 'origin' => 'indicacao'],
            ['name' => 'Gustavo Rocha', 'phone' => '(11) 92222-8888', 'crm_stage' => 'perdido', 'origin' => 'site'],
            ['name' => 'Banco Exemplo S.A.', 'person_type' => 'PJ', 'category' => 'parte_contraria', 'crm_stage' => 'cliente'],
        ])->map(fn ($c) => Contact::create($c + ['nationality' => 'brasileiro(a)', 'user_id' => $ana->id]));

        [$maria, $jose, $alfa, $fernanda, $ricardo] = $contacts->all();

        $cases = [
            LegalCase::create(['number' => '1001234-56.2026.8.26.0100', 'title' => 'Revisional de contrato bancário', 'contact_id' => $maria->id, 'opposing_party' => 'Banco Exemplo S.A.', 'area' => 'consumidor', 'action_type' => 'Ação revisional', 'court' => 'TJSP', 'court_division' => '5ª Vara Cível', 'district' => 'São Paulo', 'phase' => 'conhecimento', 'claim_value' => 48500, 'fee_percent' => 20, 'distributed_at' => now()->subMonths(4), 'responsible_id' => $ana->id]),
            LegalCase::create(['number' => '1000987-11.2026.5.02.0001', 'title' => 'Reclamação trabalhista – horas extras', 'contact_id' => $jose->id, 'opposing_party' => 'Transportes Rápidos Ltda.', 'area' => 'trabalhista', 'action_type' => 'Reclamação trabalhista', 'court' => 'TRT-2', 'court_division' => '1ª Vara do Trabalho', 'district' => 'Guarulhos', 'phase' => 'conhecimento', 'claim_value' => 72000, 'fee_percent' => 30, 'distributed_at' => now()->subMonths(2), 'responsible_id' => $bruno->id]),
            LegalCase::create(['number' => '1005555-22.2025.8.26.0100', 'title' => 'Cobrança de serviços de obra', 'contact_id' => $alfa->id, 'opposing_party' => 'Condomínio Residencial Sol', 'area' => 'civel', 'action_type' => 'Ação de cobrança', 'court' => 'TJSP', 'court_division' => '12ª Vara Cível', 'district' => 'São Paulo', 'phase' => 'execucao', 'claim_value' => 230000, 'fee_percent' => 15, 'distributed_at' => now()->subYear(), 'responsible_id' => $admin->id, 'partner_id' => $p2->id]),
            LegalCase::create(['number' => '5003333-44.2026.4.03.6100', 'title' => 'Aposentadoria especial', 'contact_id' => $fernanda->id, 'opposing_party' => 'INSS', 'area' => 'previdenciario', 'action_type' => 'Concessão de benefício', 'court' => 'TRF-3', 'court_division' => '3ª Vara Previdenciária', 'district' => 'São Paulo', 'phase' => 'inicial', 'claim_value' => 95000, 'fee_percent' => 30, 'distributed_at' => now()->subWeeks(3), 'responsible_id' => $ana->id]),
            LegalCase::create(['number' => '0802222-33.2026.8.19.0001', 'title' => 'Indenização por danos morais', 'contact_id' => $ricardo->id, 'opposing_party' => 'Companhia Aérea Voe Bem', 'area' => 'consumidor', 'action_type' => 'Indenizatória', 'court' => 'TJRJ', 'court_division' => '2º Juizado Especial Cível', 'district' => 'Rio de Janeiro', 'phase' => 'recursal', 'claim_value' => 20000, 'fee_percent' => 25, 'distributed_at' => now()->subMonths(8), 'responsible_id' => $bruno->id, 'partner_id' => $p1->id]),
            LegalCase::create(['title' => 'Inventário extrajudicial – Espólio de A. Santos', 'contact_id' => $maria->id, 'area' => 'familia', 'action_type' => 'Inventário', 'phase' => 'encerrado', 'status' => 'encerrado', 'responsible_id' => $ana->id]),
        ];

        $moves = [
            [0, 120, 'Distribuída a ação. Aguardando citação do réu.'],
            [0, 60, 'Juntada contestação pelo réu.'],
            [0, 10, 'Intimação para apresentar réplica no prazo de 15 dias.'],
            [1, 50, 'Audiência inicial designada.'],
            [1, 5, 'Despacho: designada audiência de instrução.'],
            [2, 200, 'Sentença de procedência publicada.'],
            [2, 30, 'Iniciado cumprimento de sentença.'],
            [4, 40, 'Interposto recurso inominado pela parte ré.'],
        ];
        foreach ($moves as [$i, $days, $desc]) {
            CaseMovement::create(['legal_case_id' => $cases[$i]->id, 'user_id' => $cases[$i]->responsible_id, 'date' => now()->subDays($days), 'description' => $desc]);
        }

        $tasks = [
            ['Apresentar réplica', 0, $cases[0], [$ana], 'pendente', 'alta', true, 'Equipe Cível', 25],
            ['Revisar cálculos da revisional', 3, $cases[0], [$ana, $carla], 'pendente', 'normal', false, 'Equipe Cível', 10],
            ['Preparar rol de testemunhas', 2, $cases[1], [$bruno], 'fazendo', 'alta', true, 'Equipe Trabalhista', 20],
            ['Reunião com cliente – horas extras', 0, $cases[1], [$bruno, $carla], 'pendente', 'normal', false, 'Equipe Trabalhista', 10],
            ['Protocolar petição de penhora online', -1, $cases[2], [$admin], 'pendente', 'urgente', true, 'Equipe Cível', 30],
            ['Juntar PPP e laudos técnicos', 5, $cases[3], [$ana, $carla], 'pendente', 'normal', false, 'Equipe Previdenciária', 15],
            ['Contrarrazões ao recurso inominado', 6, $cases[4], [$bruno], 'pendente', 'alta', true, 'Equipe Cível', 25],
            ['Enviar proposta de honorários', 1, null, [$admin], 'pendente', 'normal', false, 'Comercial', 5],
            ['Atualizar planilha de prazos', null, null, [$carla], 'pendente', 'baixa', false, 'Administrativo', 5],
            ['Digitalizar documentos do arquivo', 12, null, [$carla], 'pendente', 'baixa', false, 'Administrativo', 5],
            ['Análise de contrato da Construtora Alfa', 0, $cases[2], [$admin, $ana], 'fazendo', 'normal', false, 'Equipe Cível', 15],
            ['Protocolo de petição inicial', 0, $cases[3], [$ana], 'concluida', 'normal', false, 'Equipe Previdenciária', 20],
            ['Atendimento novo cliente', 0, null, [$admin], 'concluida', 'normal', false, 'Comercial', 10],
            ['Cálculo de liquidação', -3, $cases[2], [$admin], 'concluida', 'alta', false, 'Equipe Cível', 20],
        ];
        foreach ($tasks as [$title, $days, $case, $users, $status, $priority, $deadline, $group, $points]) {
            $task = Task::create([
                'title' => $title,
                'description' => 'Tarefa de demonstração.',
                'status' => $status,
                'priority' => $priority,
                'is_deadline' => $deadline,
                'group' => $group,
                'points' => $points,
                'due_at' => $days === null ? null : now()->addDays($days)->setTime(18, 0),
                'completed_at' => $status === 'concluida' ? now()->addDays(min(0, (int) $days))->setTime(15, 0) : null,
                'legal_case_id' => $case?->id,
                'contact_id' => $case?->contact_id,
                'created_by' => $admin->id,
            ]);
            $task->users()->sync(collect($users)->pluck('id'));
        }

        $events = [
            ['Audiência de instrução – José Roberto', 'audiencia', 4, '14:00', $cases[1], $bruno, 'Fórum Trabalhista de Guarulhos, sala 3'],
            ['Reunião com Maria Santos', 'reuniao', 0, '10:30', $cases[0], $ana, 'Escritório'],
            ['Perícia técnica – aposentadoria especial', 'pericia', 9, '09:00', $cases[3], $ana, 'Hospital Municipal'],
            ['Prazo: contrarrazões', 'prazo', 6, '23:59', $cases[4], $bruno, null],
            ['Reunião de equipe semanal', 'reuniao', 1, '08:30', null, $admin, 'Sala de reuniões'],
            ['Audiência de conciliação – Voe Bem', 'audiencia', 15, '15:30', $cases[4], $bruno, 'Videoconferência'],
            ['Café com parceiro – Imobiliária Horizonte', 'reuniao', -5, '16:00', null, $admin, 'Centro'],
        ];
        foreach ($events as [$title, $type, $days, $time, $case, $user, $location]) {
            $start = now()->addDays($days)->setTimeFromTimeString($time);
            Event::create([
                'title' => $title, 'type' => $type, 'starts_at' => $start, 'ends_at' => $type === 'prazo' ? null : $start->copy()->addHour(),
                'location' => $location, 'legal_case_id' => $case?->id, 'contact_id' => $case?->contact_id, 'user_id' => $user->id,
            ]);
        }

        // Financeiro: últimos 6 meses + próximos 2
        for ($m = -5; $m <= 2; $m++) {
            $month = now()->startOfMonth()->addMonths($m);
            $past = $m < 0;
            $entries = [
                ['receita', 'Honorários mensais – Construtora Alfa', 'honorarios_contratuais', 4500, 5, $alfa, $cases[2]],
                ['receita', 'Honorários – Revisional Maria Santos', 'honorarios_contratuais', 1200, 10, $maria, $cases[0]],
                ['receita', 'Consultoria avulsa', 'consultoria', 800 + (($m + 6) * 150), 18, $ricardo, null],
                ['despesa', 'Aluguel do escritório', 'aluguel', 3200, 5, null, null],
                ['despesa', 'Software e assinaturas', 'software', 450, 10, null, null],
                ['despesa', 'Custas e diligências', 'custas', 300 + (($m + 6) * 40), 20, null, null],
            ];
            foreach ($entries as [$type, $desc, $cat, $amount, $day, $contact, $case]) {
                $due = $month->copy()->day($day);
                $paid = $past || ($m === 0 && $due->lt(today()) && $desc !== 'Honorários – Revisional Maria Santos');
                FinancialEntry::create([
                    'type' => $type, 'description' => $desc, 'category' => $cat, 'amount' => $amount, 'due_date' => $due,
                    'paid_at' => $paid ? $due : null, 'payment_method' => $type === 'receita' ? 'pix' : 'boleto',
                    'contact_id' => $contact?->id, 'legal_case_id' => $case?->id, 'user_id' => $admin->id,
                ]);
            }
        }
        FinancialEntry::create(['type' => 'receita', 'description' => 'Honorários de êxito – Indenização Voe Bem', 'category' => 'honorarios_exito', 'amount' => 5000, 'due_date' => now()->subMonths(2)->startOfMonth()->addDays(14), 'paid_at' => now()->subMonths(2)->startOfMonth()->addDays(16), 'payment_method' => 'alvara', 'contact_id' => $ricardo->id, 'legal_case_id' => $cases[4]->id, 'user_id' => $admin->id]);
        FinancialEntry::create(['type' => 'despesa', 'description' => 'Repasse ao correspondente – Voe Bem', 'category' => 'repasse_parceiro', 'amount' => 1500, 'due_date' => now()->subMonths(2)->startOfMonth()->addDays(20), 'paid_at' => now()->subMonths(2)->startOfMonth()->addDays(20), 'payment_method' => 'pix', 'partner_id' => $p1->id, 'legal_case_id' => $cases[4]->id, 'user_id' => $admin->id]);
        FinancialEntry::create(['type' => 'despesa', 'description' => 'Comissão de indicação – Construtora Alfa', 'category' => 'repasse_parceiro', 'amount' => 2300, 'due_date' => now()->addDays(8), 'payment_method' => 'pix', 'partner_id' => $p2->id, 'legal_case_id' => $cases[2]->id, 'user_id' => $admin->id]);
    }
}
