<?php

return [
  'config' => [
    'ai_provider' => '选择 AI 提供商',
    'base_url' => '基础 URL（http(s)://oai.com/），不需要填写 v1',
    'api_key' => 'API 密钥',
    'model_name' => '模型名称',
    'prompt' => '系统提示词',
    'default_prompt' => '你是专业翻译引擎。请将用户提供的单个标题、小标题、段落、列表项或引用翻译为简体中文。只输出中文译文，不要总结、解释，也不要合并段落。保留原文含义、数字、专有名词、Markdown 和链接文本。如果文本已经是中文，请原样返回。',
    'save' => '保存',
    'openai' => 'OpenAI',
    'ollama' => 'Ollama',
    'gemini' => 'Gemini',
    'lmstudio' => 'LM Studio',
    'google' => 'Google 翻译',
  ],
  'button' => [
    'translate' => '翻译为中文',
  ],
  'label' => [
    'result' => '中文翻译',
  ],
  'status' => [
    'loading' => '正在翻译...',
    'done' => '翻译完成',
    'error' => '错误',
    'request_failed' => '请求失败',
    'no_content' => '未找到可翻译内容',
  ],
  'error' => [
    'missing_config' => '缺少 AI 配置',
    'empty_text' => '文本为空',
  ],
];
