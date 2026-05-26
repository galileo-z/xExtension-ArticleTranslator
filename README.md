# ArticleTranslator

FreshRSS 用户扩展，用 AI 将文章标题和正文逐段翻译为简体中文。插件会在文章内容上方显示“翻译为中文”按钮，点击后依次翻译标题、段落、标题块、引用和列表项，并把中文译文显示在对应外文下方。

AI 调用方式参考 `xExtension-ArticleSummary`：后端读取 FreshRSS 配置并返回请求参数，前端直接请求 OpenAI 兼容接口、Ollama、Gemini 或 LM Studio，并支持流式显示。

## 安装

把整个 `xExtension-ArticleTranslator` 目录放到 FreshRSS 的 `extensions/` 目录下，然后在 FreshRSS 扩展管理页面启用 `ArticleTranslator`。

## 配置

在扩展配置页填写：

- AI 提供商：OpenAI、Ollama、Gemini 或 LM Studio
- 基础 URL：不要带 `/v1`，插件会按提供商自动补齐
- API 密钥：Ollama 和 LM Studio 可留空
- 模型名称
- 系统提示词：默认提示词会要求模型只输出简体中文译文，不总结、不解释、不合并段落

## 行为

- 标题单独翻译，并显示在原标题下方。
- 正文不会整篇合并发送给模型，而是按 DOM 中的段落、标题、引用、列表项逐个发送。
- 每段译文以和 ArticleSummary 类似的浅色边框块显示在原文下方。
- 再次点击按钮会清除旧译文并重新逐段翻译。
