<?php

return [
  'config' => [
    'ai_provider' => 'Choose AI Provider',
    'base_url' => 'Base URL (http(s)://oai.com/) without \'v1\'',
    'api_key' => 'API Key',
    'model_name' => 'Model Name',
    'enable_thinking' => 'Enable AI thinking',
    'prompt' => 'System prompt',
    'default_prompt' => 'You are a professional translation engine. Translate each user-provided title, heading, paragraph, list item, or quote into Simplified Chinese. Return only the Chinese translation. Do not summarize, explain, or merge paragraphs. Preserve meaning, numbers, names, Markdown, and link text. If the text is already Chinese, return it unchanged.',
    'save' => 'Save',
    'openai' => 'OpenAI',
    'ollama' => 'Ollama',
    'gemini' => 'Gemini',
    'lmstudio' => 'LM Studio',
    'google' => 'Google Translate',
  ],
  'button' => [
    'translate' => 'Translate to Chinese',
  ],
  'label' => [
    'result' => 'Chinese translation',
  ],
  'status' => [
    'loading' => 'Translating...',
    'done' => 'Translation complete',
    'error' => 'Error',
    'request_failed' => 'Request Failed',
    'no_content' => 'No translatable content found',
  ],
  'error' => [
    'missing_config' => 'Missing AI configuration',
    'empty_text' => 'Empty text',
  ],
];
