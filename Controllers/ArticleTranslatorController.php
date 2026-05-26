<?php

/**
 * Article Translator Controller.
 */
final class FreshExtension_ArticleTranslator_Controller extends Minz_ActionController
{
  public function translateAction(): void
  {
    ob_start();
    @set_time_limit(180);
    $this->view->_layout(false);
    header('Content-Type: application/json; charset=utf-8');

    $payload = $this->jsonPayload();

    $provider = (string)(FreshRSS_Context::$user_conf->article_translator_provider ?: 'openai');
    $baseUrl = FreshRSS_Context::$user_conf->article_translator_oai_url;
    $apiKey = FreshRSS_Context::$user_conf->article_translator_oai_key;
    $model = FreshRSS_Context::$user_conf->article_translator_oai_model;
    $systemPrompt = FreshRSS_Context::$user_conf->article_translator_prompt;

    if (!$this->hasRequiredConfig($provider, $baseUrl, $apiKey, $model, $systemPrompt)) {
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

    $userPrompt = $this->buildUserPrompt($kind, $text);

    try {
      $translatedText = $this->translateWithProvider(
        (string)$provider,
        (string)$baseUrl,
        (string)$apiKey,
        (string)$model,
        (string)$systemPrompt,
        $userPrompt,
        $text
      );

      $this->jsonResponse([
        'response' => [
          'data' => $translatedText,
          'provider' => $provider,
          'error' => null,
        ],
        'status' => 200,
      ]);
    } catch (Throwable $error) {
      $this->jsonResponse([
        'response' => [
          'data' => $error->getMessage(),
          'error' => 'ai_api',
        ],
        'status' => 200,
      ]);
    }
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
    return in_array($provider, ['ollama', 'lmstudio', 'google'], true);
  }

  private function hasRequiredConfig(
    string $provider,
    mixed $baseUrl,
    mixed $apiKey,
    mixed $model,
    mixed $systemPrompt
  ): bool {
    if ($provider === 'google') {
      return true;
    }

    return !$this->isEmpty($baseUrl)
      && (!$this->isEmpty($apiKey) || $this->allowsEmptyApiKey($provider))
      && !$this->isEmpty($model)
      && !$this->isEmpty($systemPrompt);
  }

  private function translateWithProvider(
    string $provider,
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userPrompt,
    string $sourceText
  ): string {
    if ($provider === 'google') {
      return $this->translateGoogle($baseUrl, $sourceText);
    }

    if ($provider === 'ollama') {
      return $this->translateOllama($baseUrl, $apiKey, $model, $systemPrompt, $userPrompt);
    }

    if ($provider === 'gemini') {
      return $this->translateGemini($baseUrl, $apiKey, $model, $systemPrompt, $userPrompt);
    }

    return $this->translateOpenAiCompatible($baseUrl, $apiKey, $model, $systemPrompt, $userPrompt);
  }

  private function translateOpenAiCompatible(
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userPrompt
  ): string {
    $headers = ['Content-Type: application/json'];
    if (trim($apiKey) !== '') {
      $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $json = $this->postJson($this->normalizeBaseUrl($baseUrl, 'openai') . '/chat/completions', [
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
      'stream' => false,
    ], $headers);

    $content = $json['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
      throw new RuntimeException('AI API response did not include translated text');
    }

    return $content;
  }

  private function translateGoogle(string $baseUrl, string $sourceText): string
  {
    $url = trim($baseUrl) === ''
      ? 'https://translate.googleapis.com/translate_a/single'
      : rtrim(trim($baseUrl), '/');
    if (!preg_match('#/translate_a/single$#', $url)) {
      $url .= '/translate_a/single';
    }

    $json = $this->getJson($url, [
      'client' => 'gtx',
      'sl' => 'auto',
      'tl' => 'zh-CN',
      'dt' => 't',
      'q' => $sourceText,
    ], [
      'Accept: application/json',
    ]);

    $segments = $json[0] ?? null;
    if (!is_array($segments)) {
      throw new RuntimeException('Google Translate response did not include translated text');
    }

    $content = '';
    foreach ($segments as $segment) {
      if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
        $content .= $segment[0];
      }
    }

    if (trim($content) === '') {
      throw new RuntimeException('Google Translate response did not include translated text');
    }

    return $content;
  }

  private function translateOllama(
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userPrompt
  ): string {
    $headers = ['Content-Type: application/json'];
    if (trim($apiKey) !== '') {
      $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $json = $this->postJson(rtrim(trim($baseUrl), '/') . '/api/generate', [
      'model' => $model,
      'system' => $systemPrompt,
      'prompt' => $userPrompt,
      'stream' => false,
      'options' => [
        'temperature' => 0.2,
      ],
    ], $headers);

    $content = $json['response'] ?? null;
    if (!is_string($content) || trim($content) === '') {
      throw new RuntimeException('Ollama response did not include translated text');
    }

    return $content;
  }

  private function translateGemini(
    string $baseUrl,
    string $apiKey,
    string $model,
    string $systemPrompt,
    string $userPrompt
  ): string {
    $url = $this->normalizeBaseUrl($baseUrl, 'gemini') . '/models/' . rawurlencode($model) . ':generateContent';
    if (trim($apiKey) !== '') {
      $url .= '?key=' . rawurlencode($apiKey);
    }

    $json = $this->postJson($url, [
      'systemInstruction' => [
        'parts' => [['text' => $systemPrompt]],
      ],
      'contents' => [
        [
          'parts' => [['text' => $userPrompt]],
        ],
      ],
    ], ['Content-Type: application/json']);

    $parts = $json['candidates'][0]['content']['parts'] ?? null;
    if (!is_array($parts)) {
      throw new RuntimeException('Gemini response did not include translated text');
    }

    $content = '';
    foreach ($parts as $part) {
      if (isset($part['text']) && is_string($part['text'])) {
        $content .= $part['text'];
      }
    }

    if (trim($content) === '') {
      throw new RuntimeException('Gemini response did not include translated text');
    }

    return $content;
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

  /**
   * @param array<string, mixed> $body
   * @param string[] $headers
   * @return array<string, mixed>
   */
  private function postJson(string $url, array $body, array $headers): array
  {
    $requestBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($requestBody === false) {
      throw new RuntimeException('Failed to encode AI API request');
    }

    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      if ($ch === false) {
        throw new RuntimeException('Failed to initialize HTTP client');
      }

      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
      ]);

      $responseBody = curl_exec($ch);
      $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($responseBody === false) {
        throw new RuntimeException('AI API request failed: ' . $curlError);
      }
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => implode("\r\n", $headers),
          'content' => $requestBody,
          'ignore_errors' => true,
          'timeout' => 180,
        ],
      ]);

      $responseBody = file_get_contents($url, false, $context);
      if ($responseBody === false) {
        throw new RuntimeException('AI API request failed');
      }

      $statusCode = $this->statusCodeFromHeaders($http_response_header ?? []);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
      throw new RuntimeException('AI API returned HTTP ' . $statusCode . ': ' . $this->responseErrorMessage($responseBody));
    }

    $json = json_decode($responseBody, true);
    if (!is_array($json)) {
      throw new RuntimeException('AI API returned invalid JSON: ' . substr($responseBody, 0, 500));
    }

    return $json;
  }

  /**
   * @param array<string, scalar|null> $query
   * @param string[] $headers
   * @return array<string|int, mixed>
   */
  private function getJson(string $url, array $query, array $headers): array
  {
    $separator = str_contains($url, '?') ? '&' : '?';
    $requestUrl = $url . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $responseBody = '';
    $statusCode = 0;

    if (function_exists('curl_init')) {
      $ch = curl_init($requestUrl);
      if ($ch === false) {
        throw new RuntimeException('Failed to initialize HTTP client');
      }

      curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'FreshRSS ArticleTranslator',
      ]);

      $responseBody = curl_exec($ch);
      $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($responseBody === false) {
        throw new RuntimeException('Google Translate request failed: ' . $curlError);
      }
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'GET',
          'header' => implode("\r\n", $headers),
          'ignore_errors' => true,
          'timeout' => 180,
          'user_agent' => 'FreshRSS ArticleTranslator',
        ],
      ]);

      $responseBody = file_get_contents($requestUrl, false, $context);
      if ($responseBody === false) {
        throw new RuntimeException('Google Translate request failed');
      }

      $statusCode = $this->statusCodeFromHeaders($http_response_header ?? []);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
      throw new RuntimeException('Google Translate returned HTTP ' . $statusCode . ': ' . $this->responseErrorMessage($responseBody));
    }

    $json = json_decode($responseBody, true);
    if (!is_array($json)) {
      throw new RuntimeException('Google Translate returned invalid JSON: ' . substr($responseBody, 0, 500));
    }

    return $json;
  }

  /**
   * @param string[] $headers
   */
  private function statusCodeFromHeaders(array $headers): int
  {
    foreach ($headers as $header) {
      if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
        return (int)$matches[1];
      }
    }

    return 0;
  }

  private function responseErrorMessage(string $responseBody): string
  {
    $json = json_decode($responseBody, true);
    if (is_array($json)) {
      $message = $json['error']['message'] ?? $json['message'] ?? $json['error'] ?? null;
      if (is_string($message) && $message !== '') {
        return $message;
      }
    }

    return substr($responseBody, 0, 500);
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
