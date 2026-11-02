<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappChannel;
use App\Support\Whatsapp\WhatsappBot;
use App\Support\Whatsapp\WhatsappClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Recebe os avisos de mensagens do WhatsApp (Evolution API ou Meta) na URL
 * secreta do canal. Responde na hora e trata as mensagens depois da resposta.
 */
class WhatsappWebhookController extends Controller
{
    /** Verificação do webhook pela Meta (hub.challenge). */
    public function verify(Request $request, string $token): Response
    {
        $channel = $this->channel($token);
        if ($request->query('hub_mode') === 'subscribe' && hash_equals((string) $channel->verify_token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
        }

        return response('Token de verificação inválido.', 403);
    }

    public function receive(Request $request, string $token, WhatsappClient $client, WhatsappBot $bot): JsonResponse
    {
        $channel = $this->channel($token);
        if (! $client->validSignature($channel, $request)) {
            return response()->json(['message' => 'Assinatura inválida.'], 403);
        }

        $messages = $client->parse($channel, $request->json()->all());
        if ($messages) {
            \Illuminate\Support\defer(function () use ($channel, $messages, $bot) {
                @set_time_limit(180);
                foreach ($messages as $message) {
                    try {
                        $bot->handle($channel, $message);
                    } catch (\Throwable $e) {
                        Log::error('Mensagem do WhatsApp não processada: '.$e->getMessage());
                    }
                }
            });
        }

        return response()->json(['ok' => true, 'received' => count($messages)]);
    }

    private function channel(string $token): WhatsappChannel
    {
        $channel = WhatsappChannel::where('webhook_token', $token)->first();
        abort_unless($channel && $channel->active, 404);

        return $channel;
    }
}
