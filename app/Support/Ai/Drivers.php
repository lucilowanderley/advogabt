<?php

namespace App\Support\Ai;

/**
 * Catálogo de provedores de IA suportados.
 *
 * Cada provedor usa um dos "protocolos" implementados em LlmClient:
 *  - anthropic: API Messages da Anthropic (Claude)
 *  - openai:    API Chat Completions (OpenAI e todos os serviços compatíveis)
 *  - azure:     Azure OpenAI (Chat Completions com cabeçalho api-key e api-version)
 *  - gemini:    API generateContent do Google Gemini
 *  - ollama:    API /api/chat do Ollama (modelos locais)
 *
 * Para incluir um novo serviço compatível com a OpenAI basta acrescentar uma entrada aqui.
 * Os modelos listados são apenas sugestões: o escritório pode digitar qualquer modelo contratado.
 */
class Drivers
{
    public const ALL = [
        'anthropic' => [
            'pricing' => 'comercial',
            'pricing_note' => 'Pago por uso (tokens), cobrado pela Anthropic na conta do escritório.',
            'label' => 'Anthropic Claude (recomendado)',
            'protocol' => 'anthropic',
            'base_url' => 'https://api.anthropic.com',
            'needs_key' => true,
            'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'],
            'default_model' => 'claude-opus-5-5',
            'keys_url' => 'https://console.anthropic.com/settings/keys',
            'hint' => 'Claude Opus 5.5 é o modelo mais indicado para peças e análises jurídicas. Sonnet e Haiku custam menos.',
        ],
        'openai' => [
            'pricing' => 'comercial',
            'pricing_note' => 'Pago por uso (tokens), cobrado pela OpenAI.',
            'label' => 'OpenAI (ChatGPT)',
            'protocol' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'needs_key' => true,
            'models' => ['gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4o'],
            'default_model' => 'gpt-5',
            'keys_url' => 'https://platform.openai.com/api-keys',
            'hint' => 'Use o nome exato do modelo contratado na sua conta OpenAI.',
        ],
        'azure_openai' => [
            'pricing' => 'comercial',
            'pricing_note' => 'Pago por uso na assinatura Azure do escritório.',
            'label' => 'Azure OpenAI',
            'protocol' => 'azure',
            'base_url' => 'https://SEU-RECURSO.openai.azure.com/openai/deployments/SEU-DEPLOYMENT',
            'needs_key' => true,
            'models' => [],
            'default_model' => '',
            'keys_url' => 'https://portal.azure.com',
            'hint' => 'Informe a URL do deployment e, em "Modelo", o nome do deployment. Versão da API em opções avançadas.',
        ],
        'gemini' => [
            'pricing' => 'freemium',
            'pricing_note' => 'Tem plano gratuito com limites de uso; no plano gratuito o Google pode usar os dados para melhorar os produtos (atenção à LGPD). Plano pago sem esse uso.',
            'label' => 'Google Gemini',
            'protocol' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'needs_key' => true,
            'models' => ['gemini-2.5-pro', 'gemini-2.5-flash'],
            'default_model' => 'gemini-2.5-pro',
            'keys_url' => 'https://aistudio.google.com/apikey',
            'hint' => 'Chave do Google AI Studio.',
        ],
        'mistral' => [
            'pricing' => 'freemium',
            'pricing_note' => 'Tem plano gratuito de testes com limites; uso profissional é pago por tokens.',
            'label' => 'Mistral AI',
            'protocol' => 'openai',
            'base_url' => 'https://api.mistral.ai/v1',
            'needs_key' => true,
            'models' => ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest'],
            'default_model' => 'mistral-large-latest',
            'keys_url' => 'https://console.mistral.ai/api-keys',
            'hint' => null,
        ],
        'deepseek' => [
            'pricing' => 'comercial',
            'pricing_note' => 'Pago por uso (tokens), com preços baixos.',
            'label' => 'DeepSeek',
            'protocol' => 'openai',
            'base_url' => 'https://api.deepseek.com/v1',
            'needs_key' => true,
            'models' => ['deepseek-chat', 'deepseek-reasoner'],
            'default_model' => 'deepseek-chat',
            'keys_url' => 'https://platform.deepseek.com/api_keys',
            'hint' => null,
        ],
        'groq' => [
            'pricing' => 'freemium',
            'pricing_note' => 'Tem plano gratuito com limites de requisições; plano pago para uso maior.',
            'label' => 'Groq',
            'protocol' => 'openai',
            'base_url' => 'https://api.groq.com/openai/v1',
            'needs_key' => true,
            'models' => ['llama-3.3-70b-versatile'],
            'default_model' => 'llama-3.3-70b-versatile',
            'keys_url' => 'https://console.groq.com/keys',
            'hint' => null,
        ],
        'openrouter' => [
            'pricing' => 'comercial',
            'pricing_note' => 'Pago por uso; modelos com sufixo :free são gratuitos (com limites).',
            'label' => 'OpenRouter (vários modelos)',
            'protocol' => 'openai',
            'base_url' => 'https://openrouter.ai/api/v1',
            'needs_key' => true,
            'models' => ['anthropic/claude-opus-5.5', 'openai/gpt-5', 'google/gemini-2.5-pro'],
            'default_model' => 'anthropic/claude-opus-5.5',
            'keys_url' => 'https://openrouter.ai/keys',
            'hint' => 'Uma única chave dá acesso a modelos de vários fabricantes. Confira o nome exato do modelo no site.',
        ],
        'ollama' => [
            'pricing' => 'gratis',
            'pricing_note' => 'Gratuito: modelos abertos rodando no próprio computador/servidor. O custo é só o hardware.',
            'label' => 'Ollama (modelo local, sem nuvem)',
            'protocol' => 'ollama',
            'base_url' => 'http://localhost:11434',
            'needs_key' => false,
            'models' => ['llama3.1', 'qwen2.5', 'mistral', 'gemma2'],
            'default_model' => 'llama3.1',
            'keys_url' => 'https://ollama.com/download',
            'hint' => 'Os dados não saem do computador/servidor do escritório. Exige o Ollama instalado e o modelo baixado (ollama pull).',
        ],
        'openai_compatible' => [
            'pricing' => 'depende',
            'pricing_note' => 'Depende do serviço: LM Studio/vLLM locais são gratuitos; serviços na nuvem costumam ser pagos.',
            'label' => 'Outro compatível com OpenAI (LM Studio, vLLM, Together, Perplexity...)',
            'protocol' => 'openai',
            'base_url' => '',
            'needs_key' => false,
            'models' => [],
            'default_model' => '',
            'keys_url' => null,
            'hint' => 'Qualquer serviço que implemente /chat/completions. Informe a URL base terminando em /v1.',
        ],
    ];

    /** Tipo de cobrança: rótulo e cor do selo exibido na interface. */
    public const PRICING = [
        'gratis' => ['label' => 'Grátis', 'color' => 'green'],
        'freemium' => ['label' => 'Grátis com limites', 'color' => 'blue'],
        'comercial' => ['label' => 'Comercial (pago)', 'color' => 'orange'],
        'depende' => ['label' => 'Grátis ou pago', 'color' => ''],
    ];

    /** Cobrança de um provedor/modelo (no OpenRouter, modelos ":free" são gratuitos). */
    public static function pricing(string $driver, ?string $model = null): string
    {
        if ($driver === 'openrouter' && $model && str_ends_with($model, ':free')) {
            return 'freemium';
        }

        return self::get($driver)['pricing'];
    }

    public static function pricingLabel(string $key): string
    {
        return self::PRICING[$key]['label'] ?? $key;
    }

    public static function get(string $driver): array
    {
        return self::ALL[$driver] ?? self::ALL['openai_compatible'];
    }

    public static function labels(): array
    {
        return array_map(fn ($d) => $d['label'], self::ALL);
    }

    public static function protocol(string $driver): string
    {
        return self::get($driver)['protocol'];
    }
}
