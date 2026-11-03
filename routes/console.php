<?php

use App\Models\CloudStorage;
use App\Support\Ai\CertificateStore;
use App\Support\Cloud\CloudFolders;
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

// Integrações: avisa prazos vencendo (webhook prazo.vencendo), reenvia webhooks que falharam e envia os e-mails da agenda.
Artisan::command('integracoes:rotina', function () {
    $result = Routine::run();
    $this->info("Prazos avisados: {$result['deadlines']} · Webhooks reenviados: {$result['retried']} · Lembretes por e-mail: {$result['reminders']} · Resumos diários: {$result['digests']}");

    return 0;
})->purpose('Envia os avisos de prazo vencendo, reenvia os webhooks que falharam e envia lembretes e resumos por e-mail');

// Arquivos na nuvem: cria as pastas dos clientes e processos que ainda não têm.
Artisan::command('arquivos:pastas {--limite=500}', function (CloudFolders $folders) {
    $storage = CloudStorage::connected();
    if (! $storage) {
        $this->error('Conecte o Google Drive ou o OneDrive em Integrações › Arquivos na nuvem.');

        return 1;
    }
    $r = $folders->createMissing($storage, (int) $this->option('limite'));
    $this->info("Pastas criadas/vinculadas: {$r['contatos']} cliente(s), {$r['processos']} processo(s). Faltam: ".$folders->missingCount($storage));
    foreach ($r['erros'] as $erro) {
        $this->warn($erro);
    }

    return $r['erros'] ? 1 : 0;
})->purpose('Cria na nuvem as pastas dos clientes e processos que ainda não têm');

Schedule::command('integracoes:rotina')->everyFifteenMinutes()->withoutOverlapping();
