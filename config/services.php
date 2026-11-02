<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Assistente IA: provedores, chaves e modelos são cadastrados na tela Assistente IA > Provedores.
    'ai' => [
        'timeout' => (int) env('AI_TIMEOUT', 180), // segundos aguardando a resposta do provedor
        'history' => (int) env('AI_HISTORY_MESSAGES', 20), // mensagens anteriores enviadas como contexto
        'ca_bundle' => env('AI_CA_BUNDLE'), // opcional: caminho de um cacert.pem para HTTPS
    ],

    // Integrações: API REST e webhooks (tela Integrações).
    'integrations' => [
        'deadline_days' => (int) env('INTEGRATIONS_DEADLINE_DAYS', 3), // antecedência do evento prazo.vencendo
        'webhook_timeout' => (int) env('INTEGRATIONS_WEBHOOK_TIMEOUT', 15), // segundos aguardando o destino
        'api_rate_limit' => (int) env('INTEGRATIONS_API_RATE_LIMIT', 120), // chamadas por minuto por token
        'web_routine' => (bool) env('INTEGRATIONS_WEB_ROUTINE', true), // roda a rotina pelo uso do sistema quando não há agendador
    ],

];
