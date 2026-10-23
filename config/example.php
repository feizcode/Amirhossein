<?php
return [
    'environment' => 'production',
    'database' => ['dsn' => 'mysql:host=localhost;dbname=feizaiir_amir;charset=utf8mb4', 'user' => 'feizaiir_amir', 'password' => 'SET_DATABASE_PASSWORD'],
    'password_hash' => 'SET_FAMILY_PASSWORD_HASH',
    'app_key' => 'SET_RANDOM_64_HEX_CHARACTERS',
    'secure_cookies' => true,
    'session_days' => 7,
    // Opt-in practice pilot: durable records and reviews.
    'practice_prototype_enabled' => false,
    'practice_allow_prototype_content' => false, // Internal pilot only; release requires approved_by/approved_at.
    // PRIVATE SERVER CONFIG ONLY. Never expose this block to templates/browser JSON.
    // Pass this nested array to new Kashf\AiCoach($pdo, $config['ai_coach']).
    'ai_coach' => [
        'enabled' => false,
        // Explicit GapGPT beta exception: defer human review and provider-terms review.
        // This is risk acknowledgment, NOT verification; keep approval metadata truthful.
        'beta_enabled' => false,
        'beta_acknowledged_at' => null, // Exact past/current UTC timestamp: YYYY-MM-DDTHH:MM:SSZ.
        'provider' => 'gapgpt',
        'free_only' => false,
        // Independently resolve provider terms/age eligibility for the ten-year-old learner.
        // A working API response is NOT terms approval or proof of underlying model identity.
        'provider_terms_verified' => false,
        'model_verified' => false,
        'model' => 'gpt-6-luna',
        'endpoint' => 'https://api.gapgpt.app/v1/chat/completions',
        'api_key' => getenv('GAPGPT_API_KEY') ?: '', // Never commit credentials.
        // Verified Luna rates, pinned by AiCoach; currency must match the spend limit.
        'input_price_per_million' => 0.10,
        'output_price_per_million' => 0.50,
        'daily_request_limit' => 10,
        'daily_spend_limit' => 0.01,
        'max_input_tokens' => 8192, // Conservative byte-based upper bound incl. framing.
        'max_output_tokens' => 512, // GapGPT: max_tokens=512, no reasoning override; cURL required.
        'timeout_seconds' => 10,
        // Normal GapGPT requires review_status=approved and nonempty approved_by/approved_at.
        // Explicit beta additionally accepts internal prototype_checked bank records only;
        // provider_terms_verified may remain false. Other model/budget/quality gates remain.
        // Surface quality guards are not semantic correctness or hint-secrecy guarantees.

        // Optional existing OpenRouter FREE ONLY mode: replace provider, endpoint, key,
        // free_only=true; set both prices and daily_spend_limit to 0. One native request,
        // no local retries/paid fallback. Set models_verified=true only after catalog review.
        // endpoint: https://openrouter.ai/api/v1/chat/completions
        'models' => [], // 1–3 distinct explicit vendor/model:free IDs; no routers/aliases.
        'models_verified' => false,
        'free_models_verified_at' => null, // UTC ISO-8601 or Unix seconds; expires after 7 days.
        'free_model_prices' => [], // Exact ID => complete zero pricing map (prompt/completion required).
        // Generic paid single-model support is unchanged: independently verified model,
        // safe HTTPS endpoint and positive configured prices; low reasoning contract.
    ],
];
