<?php

use App\Support\Ai\CertificateStore;
use App\Support\Integrations\Routine;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Assistente IA: importa as autoridades de certificado do Windows (antivírus/proxy que inspecionam HTTPS).
Artisan::command('ai:certificados', function () {
    if (! CertificateStore::isWindows()) {
        $this->warn('Este comando é para servidores Windows (Laragon). Em Linux os certificados do sistema já são usados.');

        return 0;
    }
    $count = CertificateStore::buildFromWindows();
    $this->info("{$count} certificados do Windows importados em ".CertificateStore::path());

    return 0;
})->purpose('Importa os certificados raiz do Windows para as conexões HTTPS do Assistente IA');

// Integrações: avisa prazos vencendo (webhook prazo.vencendo) e reenvia webhooks que falharam.
Artisan::command('integracoes:rotina', function () {
    $result = Routine::run();
    $this->info("Prazos avisados: {$result['deadlines']} · Webhooks reenviados: {$result['retried']}");

    return 0;
})->purpose('Envia os avisos de prazo vencendo e reenvia os webhooks que falharam');

Schedule::command('integracoes:rotina')->everyFifteenMinutes()->withoutOverlapping();
