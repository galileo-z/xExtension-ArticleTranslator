<?php

/**
 * Article Translator Controller.
 */
final class FreshExtension_ArticleTranslator_Controller extends Minz_ActionController
{
  public function translateAction(): void
  {
    ob_start();
    $this->view->_layout(false);
    header('Content-Type: application/json; charset=utf-8');

    $payload = $this->jsonPayload();

    $provider = (string)(FreshRSS_Context::$user_conf->article_translator_provider ?: 'openai');
    $baseUrl = FreshRSS_Context::$user_conf->article_translator_oai_url;
    $apiKey = FreshRSS_Context::$user_conf->article_translator_oai_key;
    $model = FreshRSS_Context::$user_conf->article_translator_oai_model;
    $systemPrompt = FreshRSS_Context::$user_conf->article_translator_prompt;

    if (
      $this->isEmpty($baseUrl)
      || ($this->isEmpty($apiKey) && !$this->allowsEmptyApiKey($provider))
      || $this->isEmpty($model)
      || $this->isEmpty($systemPrompt)
    ) {
      $this->jsonResponse([
        'response' => [
          'data' => _t('ArticleTranslator.error.missing_config'),
          'error' => 'configuration',
        ],
        'status' => 200,
      ]);
      return;
    }

    $entryId = $this->requestValue('id', $payload, '');
    $kind = $this->normalizeKind((string)$this->requestValue('kind', $payload, 'paragraph'));
    $text = trim((string)$this->requestValue('text', $payload, ''));

    if ($text === '' && $kind === 'title' && $entryId !== '') {
      $entryDao = FreshRSS_Factory::createEntryDao();
      $entry = $entryDao->searchById($entryId);
      if ($entry !== null) {
        $text = trim($entry->title());
      }
    }

    if ($text === '') {
      $this->jsonResponse([
        'response' => [
          'data' => _t('ArticleTranslator.error.empty_text'),
          'error' => 'empty_text',
        ],
        'status' => 200,
      ]);
      return;
    }

    $baseUrl = $this->normalizeBaseUrl((string)$baseUrl, (string)$provider);
    $userPrompt = $this->buildUserPrompt($kind, $text);

    $successResponse = [
      'response' => [
        'data' => [
          'oai_url' => $baseUrl . '/chat/completions',
          'oai_key' => $apiKey,
          'model' => $model,
          'messages' => [
            [
              'role' => 'system',
              'content' => $systemPrompt,
            ],
            [
              'role' => 'user',
              'content' => $userPrompt,
            ],
          ],
          'max_tokens' => 2048,
          'temperature' => 0.2,
          'n' => 1,
          'stream' => true,
        ],
        'provider' => $provider === 'lmstudio' ? 'lmstudio' : 'openai',
        'error' => null,
      ],
      'status' => 200,
    ];

    if ($provider === 'ollama') {
      $successResponse = [
        'response' => [
          'data' => [
            'oai_url' => rtrim((string)$baseUrl, '/') . '/api/generate',
            'oai_key' => $apiKey,
            'model' => $model,
            'system' => $systemPrompt,
            'prompt' => $userPrompt,
            'stream' => true,
            'options' => [
              'temperature' => 0.2,
            ],
          ],
          'provider' => 'ollama',
          'error' => null,
        ],
        'status' => 200,
      ];
    }

    if ($provider === 'gemini') {
      $successResponse = [
        'response' => [
          'data' => [
            'oai_url' => rtrim((string)$baseUrl, '/') . '/models/' . $model . ':streamGenerateContent',
            'oai_key' => $apiKey,
            'model' => $model,
            'systemInstruction' => $systemPrompt,
            'prompt' => $userPrompt,
          ],
          'provider' => 'gemini',
          'error' => null,
        ],
        'status' => 200,
      ];
    }

    $this->jsonResponse($successResponse);
  }

  /**
   * @return array<string, mixed>
   */
  private function jsonPayload(): array
  {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
      return [];
    }

    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : [];
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function requestValue(string $key, array $payload, mixed $default): mixed
  {
    $value = Minz_Request::param($key, null);
    if ($value !== null) {
      return $value;
    }

    return $payload[$key] ?? $default;
  }

  /**
   * @param array<string, mixed> $data
   */
  private function jsonResponse(array $data): void
  {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  private function isEmpty(mixed $item): bool
  {
    return $item === null || (is_string($item) && trim($item) === '');
  }

  private function allowsEmptyApiKey(string $provider): bool
  {
    return in_array($provider, ['ollama', 'lmstudio'], true);
  }

  private function normalizeBaseUrl(string $baseUrl, string $provider): string
  {
    $baseUrl = rtrim(trim($baseUrl), '/');

    if ($provider === 'openai' || $provider === 'lmstudio') {
      $baseUrl = preg_replace('#/chat/completions$#', '', $baseUrl) ?? $baseUrl;
    }

    if (preg_match('/\/v\d+(beta)?\/?$/', $baseUrl)) {
      return $baseUrl;
    }

    if ($provider === 'gemini') {
      return $baseUrl . '/v1beta';
    }

    if ($provider === 'ollama') {
      return $baseUrl;
    }

    return $baseUrl . '/v1';
  }

  private function normalizeKind(string $kind): string
  {
    $allowedKinds = ['title', 'heading', 'paragraph', 'list_item', 'quote'];
    return in_array($kind, $allowedKinds, true) ? $kind : 'paragraph';
  }

  private function buildUserPrompt(string $kind, string $text): string
  {
    $kindLabels = [
      'title' => 'title',
      'heading' => 'heading',
      'paragraph' => 'paragraph',
      'list_item' => 'list item',
      'quote' => 'quote',
    ];
    $label = $kindLabels[$kind] ?? 'paragraph';

    return "Translate the following {$label} into Simplified Chinese.\n"
      . "Return only the Chinese translation. Do not summarize, explain, or merge it with other paragraphs.\n\n"
      . $text;
  }
}
