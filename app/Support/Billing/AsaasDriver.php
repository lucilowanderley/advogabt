<?php

namespace App\Support\Billing;

use App\Models\BillingCharge;
use App\Models\BillingProvider;
use App\Models\Contact;
use App\Models\FiscalInvoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Asaas, API v3. Cobrança: localiza/cadastra o cliente pelo CPF/CNPJ, cria o pagamento
 * (BOLETO, PIX ou UNDEFINED = cliente escolhe) e busca a linha digitável e o QR Code Pix.
 * NFS-e: cria a nota (/invoices) ligada ao pagamento ou ao cliente e pede a emissão imediata.
 */
class AsaasDriver extends Driver
{
    public const HOSTS = [
        'sandbox' => 'https://api-sandbox.asaas.com/v3',
        'producao' => 'https://api.asaas.com/v3',
    ];

    public const BILLING_TYPES = ['boleto_pix' => 'BOLETO', 'pix' => 'PIX', 'livre' => 'UNDEFINED'];

    public function test(BillingProvider $p): string
    {
        $r = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'myAccount/commercialInfo'))));
        $name = $r->json('companyName') ?? $r->json('name');

        return 'Asaas conectado'.($name ? ': '.$name : '').' ('.($p->isSandbox() ? 'ambiente de testes' : 'produção').').';
    }

    public function create(BillingProvider $p, BillingCharge $c, Contact $payer): void
    {
        $customer = $this->customer($p, $payer);
        $c->forceFill(['external_customer_id' => $customer])->save();

        $payload = [
            'customer' => $customer,
            'billingType' => self::BILLING_TYPES[$c->method] ?? 'BOLETO',
            'value' => round((float) $c->amount, 2),
            'dueDate' => $c->due_date->format('Y-m-d'),
            'description' => Str::limit($c->description, 500, ''),
            'externalReference' => 'advogabt-cobranca-'.$c->id,
            'postalService' => false,
        ];
        if ((float) $p->fine_percent > 0) {
            $payload['fine'] = ['value' => (float) $p->fine_percent, 'type' => 'PERCENTAGE'];
        }
        if ((float) $p->interest_percent > 0) {
            $payload['interest'] = ['value' => (float) $p->interest_percent];
        }
        $payment = $this->guard($p, $this->call($p, fn () => $this->api($p)->post($this->url($p, 'payments'), $payload)));
        $id = (string) $payment->json('id');
        if ($id === '') {
            throw new BillingException('O Asaas não devolveu a cobrança criada.');
        }
        $c->forceFill([
            'external_id' => $id,
            'remote_status' => (string) $payment->json('status'),
            'payment_url' => $payment->json('invoiceUrl'),
            'slip_url' => $payment->json('bankSlipUrl'),
        ])->save();

        $c->forceFill($this->codes($p, $c))->save();
    }

    public function status(BillingProvider $p, BillingCharge $c): RemoteCharge
    {
        $r = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'payments/'.$c->external_id))));
        $remote = (string) $r->json('status');
        $status = $r->json('deleted') ? 'cancelado' : match ($remote) {
            'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH', 'DUNNING_RECEIVED' => 'pago',
            'OVERDUE', 'DUNNING_REQUESTED' => 'vencido',
            'REFUNDED', 'REFUND_REQUESTED', 'REFUND_IN_PROGRESS', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL' => 'estornado',
            default => 'pendente',
        };
        $fields = array_filter([
            'payment_url' => $r->json('invoiceUrl'),
            'slip_url' => $r->json('bankSlipUrl'),
        ]);
        // Códigos que não vieram na criação (ex.: Pix liberado depois).
        if ($status === 'pendente' || $status === 'vencido') {
            if (! $c->digitable_line && ! $c->pix_payload) {
                $fields += $this->codes($p, $c);
            }
        }
        $paidWith = match ((string) $r->json('billingType')) {
            'PIX' => 'pix',
            'BOLETO' => 'boleto',
            'CREDIT_CARD', 'DEBIT_CARD' => 'cartao',
            default => $remote === 'RECEIVED_IN_CASH' ? 'outro' : null,
        };

        return new RemoteCharge(
            $status,
            $remote,
            $r->json('clientPaymentDate') ?? $r->json('paymentDate') ?? $r->json('confirmedDate'),
            $status === 'pago' ? (float) $r->json('value') : null,
            $paidWith,
            $fields,
        );
    }

    public function cancel(BillingProvider $p, BillingCharge $c): void
    {
        $this->guard($p, $this->call($p, fn () => $this->api($p)->delete($this->url($p, 'payments/'.$c->external_id))));
    }

    // --- NFS-e -----------------------------------------------------------------

    /** Cria a nota no Asaas e pede a emissão imediata. Grava o id externo na nota. */
    public function issueInvoice(BillingProvider $p, FiscalInvoice $inv, Contact $taker, ?BillingCharge $charge): void
    {
        $payload = [
            'serviceDescription' => Str::limit($inv->service_description, 2000, ''),
            'observations' => Str::limit((string) ($inv->observations ?: 'Nota emitida pelo ADVOGABT.'), 1000, ''),
            'value' => round((float) $inv->amount, 2),
            'deductions' => 0,
            'effectiveDate' => ($inv->effective_date ?? today())->format('Y-m-d'),
            'externalReference' => 'advogabt-nfse-'.$inv->id,
            'taxes' => [
                'retainIss' => (bool) $p->setting('retain_iss'),
                'iss' => (float) ($p->setting('iss') ?? 0),
                'cofins' => 0, 'csll' => 0, 'inss' => 0, 'ir' => 0, 'pis' => 0,
            ],
        ];
        if ($p->setting('service_id')) {
            $payload['municipalServiceId'] = (string) $p->setting('service_id');
        } else {
            $payload['municipalServiceCode'] = (string) $p->setting('service_code');
        }
        $payload['municipalServiceName'] = Str::limit((string) ($p->setting('service_name') ?: 'Serviços advocatícios'), 250, '');

        // Pagamento do próprio Asaas: a nota fica ligada a ele. Senão (Inter, baixa manual), ao cliente.
        if ($charge && $charge->provider === 'asaas' && $charge->external_id) {
            $payload['payment'] = $charge->external_id;
        } else {
            $payload['customer'] = $this->customer($p, $taker);
        }

        $r = $this->guard($p, $this->call($p, fn () => $this->api($p)->post($this->url($p, 'invoices'), $payload)));
        $id = (string) $r->json('id');
        if ($id === '') {
            throw new BillingException('O Asaas não devolveu a nota fiscal criada.');
        }
        $inv->forceFill(['external_id' => $id, 'remote_status' => (string) $r->json('status')])->save();

        // Sem isso o Asaas só emite na data efetiva; pedir já agiliza. Falha aqui não impede a emissão agendada.
        try {
            $this->api($p)->post($this->url($p, "invoices/{$id}/authorize"));
        } catch (\Throwable) {
        }
    }

    /** @return array{status: string, remote: string, number: ?string, code: ?string, pdf: ?string, xml: ?string, message: ?string} */
    public function invoiceStatus(BillingProvider $p, FiscalInvoice $inv): array
    {
        $r = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'invoices/'.$inv->external_id))));
        $remote = (string) $r->json('status');

        return [
            'status' => match ($remote) {
                'AUTHORIZED' => 'emitida',
                'CANCELED' => 'cancelada',
                'ERROR' => 'erro',
                'CANCELLATION_DENIED' => 'emitida',
                default => 'processando', // SCHEDULED, SYNCHRONIZED, PROCESSING_CANCELLATION
            },
            'remote' => $remote,
            'number' => $r->json('number') ? (string) $r->json('number') : null,
            'code' => $r->json('validationCode') ? (string) $r->json('validationCode') : null,
            'pdf' => $r->json('pdfUrl'),
            'xml' => $r->json('xmlUrl'),
            'message' => $r->json('statusDescription'),
        ];
    }

    public function cancelInvoice(BillingProvider $p, FiscalInvoice $inv): void
    {
        $this->guard($p, $this->call($p, fn () => $this->api($p)->post($this->url($p, "invoices/{$inv->external_id}/cancel"))));
    }

    /** Serviços municipais da prefeitura do escritório (lista de serviço da LC 116). @return list<array{id: string, description: string, iss: ?float}> */
    public function municipalServices(BillingProvider $p, string $search): array
    {
        $r = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'invoices/municipalServices'), array_filter(['description' => $search]))));

        return collect((array) $r->json('data'))->map(fn ($s) => [
            'id' => (string) Arr::get($s, 'id'),
            'description' => (string) Arr::get($s, 'description'),
            'iss' => Arr::get($s, 'issTax') !== null ? (float) Arr::get($s, 'issTax') : null,
        ])->filter(fn ($s) => $s['id'] !== '')->values()->all();
    }

    /** Confere se os dados fiscais (prefeitura, certificado/senha) estão preenchidos no Asaas. */
    public function fiscalInfo(BillingProvider $p): string
    {
        $r = $this->call($p, fn () => $this->api($p)->get($this->url($p, 'fiscalInfo/')));
        if ($r->status() === 404) {
            throw new BillingException('Os dados fiscais ainda não foram preenchidos no Asaas (Notas fiscais › Configurações: inscrição municipal, regime e acesso à prefeitura).');
        }
        $r = $this->guard($p, $r);

        return 'Dados fiscais encontrados no Asaas'.($r->json('municipalInscription') ? ' (inscrição municipal '.$r->json('municipalInscription').')' : '').'.';
    }

    // --- Apoio -------------------------------------------------------------------

    /** Id do cliente no Asaas: procura pelo CPF/CNPJ e cadastra se não houver. */
    public function customer(BillingProvider $p, Contact $payer): string
    {
        $doc = self::payerDocument($payer);
        $found = $this->guard($p, $this->call($p, fn () => $this->api($p)->get($this->url($p, 'customers'), ['cpfCnpj' => $doc, 'limit' => 1])));
        $id = (string) $found->json('data.0.id');
        if ($id !== '') {
            return $id;
        }
        $phone = self::digits($payer->whatsapp ?: $payer->phone);
        $created = $this->guard($p, $this->call($p, fn () => $this->api($p)->post($this->url($p, 'customers'), array_filter([
            'name' => Str::limit($payer->name, 100, ''),
            'cpfCnpj' => $doc,
            'email' => $payer->email ?: null,
            'mobilePhone' => strlen($phone) >= 10 ? substr($phone, -11) : null,
            'postalCode' => self::digits($payer->zip_code) ?: null,
            'address' => $payer->address ?: null,
            'externalReference' => 'advogabt-contato-'.$payer->id,
            'notificationDisabled' => ! $p->notify_customer,
        ], fn ($v) => $v !== null))));
        $id = (string) $created->json('id');
        if ($id === '') {
            throw new BillingException('O Asaas não devolveu o cliente cadastrado.');
        }

        return $id;
    }

    /** Linha digitável e Pix copia e cola. Cada consulta é opcional (a conta pode não ter chave Pix). */
    private function codes(BillingProvider $p, BillingCharge $c): array
    {
        $out = [];
        if ($c->method !== 'pix') {
            try {
                $r = $this->api($p)->get($this->url($p, "payments/{$c->external_id}/identificationField"));
                if ($r->successful()) {
                    $out['digitable_line'] = $r->json('identificationField');
                    $out['barcode'] = $r->json('barCode');
                }
            } catch (\Throwable) {
            }
        }
        try {
            $r = $this->api($p)->get($this->url($p, "payments/{$c->external_id}/pixQrCode"));
            if ($r->successful() && $r->json('payload')) {
                $out['pix_payload'] = $r->json('payload');
                $out['pix_qr'] = $r->json('encodedImage');
            }
        } catch (\Throwable) {
        }

        return array_filter($out);
    }

    private function api(BillingProvider $p): PendingRequest
    {
        return $this->http()->asJson()->withHeaders(['access_token' => (string) $p->api_key, 'User-Agent' => 'ADVOGABT']);
    }

    private function url(BillingProvider $p, string $path): string
    {
        return (self::HOSTS[$p->environment] ?? self::HOSTS['sandbox']).'/'.ltrim($path, '/');
    }
}
