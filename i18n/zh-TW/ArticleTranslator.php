<?php

return [
  'config' => [
    'ai_provider' => '選擇 AI 提供商',
    'base_url' => '基礎 URL（http(s)://oai.com/），不需要填寫 v1',
    'api_key' => 'API 金鑰',
    'model_name' => '模型名稱',
    'prompt' => '系統提示詞',
    'default_prompt' => '你是專業翻譯引擎。請將使用者提供的單個標題、小標題、段落、列表項或引用翻譯為簡體中文。只輸出中文譯文，不要總結、解釋，也不要合併段落。保留原文含義、數字、專有名詞、Markdown 和連結文字。如果文字已經是中文，請原樣返回。',
    'save' => '儲存',
    'openai' => 'OpenAI',
    'ollama' => 'Ollama',
    'gemini' => 'Gemini',
    'lmstudio' => 'LM Studio',
  ],
  'button' => [
    'translate' => '翻譯為中文',
  ],
  'label' => [
    'result' => '中文翻譯',
  ],
  'status' => [
    'loading' => '正在翻譯...',
    'done' => '翻譯完成',
    'error' => '錯誤',
    'request_failed' => '請求失敗',
    'no_content' => '未找到可翻譯內容',
  ],
  'error' => [
    'missing_config' => '缺少 AI 配置',
    'empty_text' => '文字為空',
  ],
];
